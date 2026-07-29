<?php
// Движок аудита: HTML-проверки. Фетчит целевой сайт (SSRF-защита в _audit-lib.php),
// парсит разметку и возвращает честный список пунктов ✓/✕ + короткий вердикт.
// Проверяет только то, что видно в разметке снаружи. Что честно определить нельзя
// (например, настроены ли ЦЕЛИ в Метрике) — не проверяет вовсе. PHP 7.4+.
//
// Железный принцип: если проверить нельзя честно (сайт за анти-бот-защитой, собирается
// скриптами в браузере, недоступен) — говорим об этом прямо и уводим на ручной разбор,
// а НЕ выдаём ложный вердикт «всё плохо» на пустом каркасе.

require __DIR__ . '/_audit-lib.php';

// Дневной потолок html-проверок опционально настраивается в audit-config.php.
// Зачем: per-IP лимит обходится ротацией адресов → без общего потолка аудит можно
// превратить в сканер чужих публичных сайтов. Число щедрое, режет только злоупотребление.
$HTML_DAILY_MAX = 500;
foreach ([__DIR__ . '/audit-config.php', __DIR__ . '/../audit-config.php'] as $cfg) {
    if (is_file($cfg)) { require $cfg; break; }
}

audit_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    audit_out(['ok' => false, 'error' => 'method', 'message' => 'Некорректный запрос.'], 405);
}
if (!audit_rate_limit_ok(10, 60, 'html')) {
    audit_out(['ok' => false, 'error' => 'rate_limited', 'message' => 'Слишком много проверок подряд. Подождите минуту.'], 429);
}

// Читаем и валидируем адрес ДО расхода суточной квоты (P1-04): пустые, битые и
// внутренние адреса не должны жечь общий дневной лимит и гасить лид-магнит для
// реальных клиентов. Порядок и проверки — те же, что в check-speed.php.
$url_in = audit_clean($_POST['url'] ?? '', 2000);
if ($url_in === '') {
    audit_out(['ok' => false, 'error' => 'empty', 'message' => 'Укажите адрес сайта.'], 422);
}
$assumed = false;
[$url_norm, $nerr] = audit_normalize_url($url_in, $assumed);
if ($url_norm === null) {
    $nmap = [
        'invalid' => ['Не похоже на адрес сайта. Пример: example.ru', 422],
        'scheme'  => ['Поддерживаются только адреса http и https.', 422],
        'port'    => ['Можно проверять только стандартные адреса (порты 80 и 443).', 422],
    ];
    $nm = $nmap[$nerr] ?? ['Не удалось разобрать адрес.', 422];
    audit_out(['ok' => false, 'error' => $nerr, 'message' => $nm[0]], $nm[1]);
}
// Адрес должен резолвиться в публичный IP: внутренние/приватные не проверяем и квоту на них не тратим.
[$host_pre] = audit_host_port($url_norm);
$ips_pre = audit_resolve_ips($host_pre);
if (!$ips_pre) {
    audit_out(['ok' => false, 'error' => 'dns', 'message' => 'Не удалось найти такой сайт. Проверьте адрес.'], 422);
}
foreach ($ips_pre as $ip_pre) {
    if (!audit_is_public_ip($ip_pre)) {
        audit_out(['ok' => false, 'error' => 'blocked', 'message' => 'Этот адрес проверить нельзя.'], 422);
    }
}

// Суточный потолок: расходуем ТОЛЬКО когда ясно, что адрес валиден и запрос дойдёт до фетча.
if (!audit_daily_quota_ok($HTML_DAILY_MAX, 'html')) {
    audit_out(['ok' => false, 'error' => 'quota_exhausted', 'message' => 'Сегодня лимит проверок исчерпан. Напишите — посмотрю сайт руками.'], 429);
}

// --- Загрузка страницы (повторная авторитетная проверка IP + anti-rebinding внутри) ---
[$body, $err, $meta] = audit_safe_fetch($url_in);
if ($err !== null) {
    $map = [
        'invalid'  => ['Не похоже на адрес сайта. Пример: example.ru', 422],
        'scheme'   => ['Поддерживаются только адреса http и https.', 422],
        'port'     => ['Можно проверять только стандартные адреса (порты 80 и 443).', 422],
        'dns'      => ['Не удалось найти такой сайт. Проверьте адрес.', 422],
        'blocked'  => ['Этот адрес проверить нельзя.', 422],
        'no_curl'  => ['Сервис временно недоступен. Попробуйте позже.', 503],
        'fetch'    => ['Не удалось загрузить сайт. Возможно, он недоступен или закрыт сертификатом. Напишите — посмотрю руками.', 502],
        'too_many_redirects' => ['Сайт слишком много раз перенаправляет. Проверьте адрес.', 422],
    ];
    $m = $map[$err] ?? ['Не удалось проверить сайт.', 502];
    audit_out(['ok' => false, 'error' => $err, 'message' => $m[0]], $m[1]);
}

// Сайт ответил, но статусом ошибки (403 анти-бот, 5xx, 404): парсить страницу-заглушку
// нечестно — она не имеет отношения к реальному сайту. Честно уводим на ручной разбор.
$http_code = (int) ($meta['code'] ?? 0);
if ($http_code >= 400) {
    audit_out(['ok' => false, 'error' => 'blocked_by_site',
        'message' => 'Сайт закрыт от автоматических проверок или ответил ошибкой. Напишите — посмотрю руками.'], 422);
}

// Не HTML (PDF, картинка, редирект в файл) — честно говорим, что проверять нечего.
if (stripos((string) ($meta['ctype'] ?? ''), 'text/html') === false
    && stripos((string) $body, '<html') === false && stripos((string) $body, '<!doctype') === false) {
    audit_out(['ok' => false, 'error' => 'not_html', 'message' => 'По этому адресу не веб-страница.'], 422);
}

// --- Кодировка → UTF-8 (важно для РФ-сайтов на windows-1251) ---
function audit_to_utf8($html, $ctype) {
    $enc = '';
    if (preg_match('~charset\s*=\s*["\']?\s*([\w\-]+)~i', $ctype, $m)) $enc = $m[1];
    if ($enc === '' && preg_match('~<meta[^>]+charset\s*=\s*["\']?\s*([\w\-]+)~i', $html, $m)) $enc = $m[1];
    if ($enc === '' && preg_match('~<meta[^>]+content\s*=\s*["\'][^"\']*charset\s*=\s*([\w\-]+)~i', $html, $m)) $enc = $m[1];
    $enc = strtoupper(trim($enc));
    $aliases = ['CP1251' => 'Windows-1251', 'WINDOWS-1251' => 'Windows-1251', 'UTF8' => 'UTF-8'];
    $enc = $aliases[$enc] ?? $enc;
    if ($enc !== '' && $enc !== 'UTF-8') {
        $conv = @mb_convert_encoding($html, 'UTF-8', $enc);
        if ($conv !== false && $conv !== '') return $conv;
    }
    return $html;
}
$html = audit_to_utf8((string) $body, (string) ($meta['ctype'] ?? ''));
$html_low = mb_strtolower($html);

function audit_has($haystack, $needle) {
    return mb_stripos($haystack, $needle) !== false;
}

// SPA / JS-каркас: тело почти без видимого текста, но со скриптами и корневым узлом
// монтирования (React/Vue/Next/Nuxt). Парсить нечего — все проверки ушли бы в ✕ и
// оболгали бы сайт. Честно уводим на ручной разбор. Текст считаем БЕЗ содержимого
// script/style/noscript (иначе JS-код раздувает объём и прячет пустоту каркаса).
$no_code = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', ' ', $html);
// preg_replace вернёт null при переполнении backtrack-лимита на патологической разметке —
// тогда берём исходный html, чтобы не посчитать большую страницу пустым каркасом (ложный SPA).
if ($no_code === null) $no_code = $html;
$visible_text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $no_code)));
$script_count = substr_count($html_low, '<script');
$has_mount = (bool) preg_match('~<div[^>]+\bid\s*=\s*["\'](root|app|__next|__nuxt|q-app)["\']~i', $html)
    || audit_has($html_low, 'window.__nuxt__') || audit_has($html_low, '__next_data__');
if (mb_strlen($visible_text) < 300 && $script_count >= 1 && $has_mount) {
    audit_out(['ok' => false, 'error' => 'js_rendered',
        'message' => 'Сайт собирается скриптами прямо в браузере — со стороны его разметку проверить нельзя. Напишите, посмотрю руками.'], 422);
}

// --- DOM ---
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
libxml_clear_errors();
$xp = new DOMXPath($dom);

$checks = [];
function audit_add(&$checks, $id, $status, $label, $detail, $meta = null) {
    $c = ['id' => $id, 'status' => $status, 'label' => $label, 'detail' => $detail];
    if ($meta !== null) $c['meta'] = $meta;
    $checks[] = $c;
}

// === 1. Целевое действие: кнопка заявки/звонка ===
$cta_words = ['заявк', 'оставить', 'заказать', 'обратный звонок', 'перезвон', 'позвон',
    'консультац', 'связаться', 'рассчитать', 'расчёт', 'расчет', 'замер', 'записаться',
    'вызвать', 'отправить', 'узнать цен', 'получить'];
$cta_found = [];
foreach ($xp->query('//a | //button | //input[@type="submit"] | //input[@type="button"]') as $el) {
    $txt = trim($el->textContent);
    if ($txt === '' && $el->nodeName === 'input') {
        $txt = trim($el->getAttribute('value'));
    }
    if ($txt === '') continue;
    $t = mb_strtolower($txt);
    foreach ($cta_words as $w) {
        if (mb_strpos($t, $w) !== false) {
            $short = mb_substr(preg_replace('/\s+/u', ' ', $txt), 0, 40);
            if (!in_array($short, $cta_found, true)) $cta_found[] = $short;
            break;
        }
    }
    if (count($cta_found) >= 4) break;
}
if ($cta_found) {
    audit_add($checks, 'cta', 'ok', 'Кнопка заявки или звонка',
        'Нашлось: ' . implode(', ', array_map(function ($s) { return '«' . $s . '»'; }, $cta_found)));
} else {
    audit_add($checks, 'cta', 'bad', 'Кнопка заявки или звонка',
        'Явной кнопки целевого действия не видно. Посетителю непонятно, как оставить заявку.');
}

// === 2. Номер телефона кликается (tel:) ===
$tel_links = $xp->query('//a[starts-with(translate(@href,"TEL","tel"),"tel:")]');
$phone_in_text = preg_match('~(?:\+7|8)[\s\-\(]*\d{3}[\s\-\)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}~u', $html);
if ($tel_links->length > 0) {
    audit_add($checks, 'tel', 'ok', 'Номер телефона кликается',
        'Есть ссылка tel: — с телефона можно позвонить в один тап.');
} elseif ($phone_in_text) {
    audit_add($checks, 'tel', 'warn', 'Номер телефона кликается',
        'Номер на странице есть, но без ссылки tel: — с телефона по нему не позвонить одним касанием.');
} else {
    audit_add($checks, 'tel', 'bad', 'Номер телефона кликается',
        'Кликабельного номера (tel:) не найдено.');
}

// === 3. Формы заявок и что собирают ===
$forms = $xp->query('//form');
$lead_forms = 0;
$field_summary = [];
foreach ($forms as $form) {
    $fields = [];
    $has_meaningful = false;
    foreach (['input', 'textarea', 'select'] as $tag) {
        foreach ($form->getElementsByTagName($tag) as $f) {
            $type = strtolower($f->getAttribute('type'));
            if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image'], true)) continue;
            $name = mb_strtolower($f->getAttribute('name') . ' ' . $f->getAttribute('placeholder') . ' ' . $f->getAttribute('id'));
            if ($tag === 'textarea' || audit_has($name, 'коммент') || audit_has($name, 'сообщ') || audit_has($name, 'message') || audit_has($name, 'comment')) {
                $fields['сообщение'] = 1; $has_meaningful = true;
            } elseif ($type === 'email' || audit_has($name, 'mail') || audit_has($name, 'почт') || audit_has($name, 'e-mail')) {
                $fields['email'] = 1; $has_meaningful = true;
            } elseif ($type === 'tel' || audit_has($name, 'phone') || audit_has($name, 'тел') || audit_has($name, 'phone')) {
                $fields['телефон'] = 1; $has_meaningful = true;
            } elseif (audit_has($name, 'name') || audit_has($name, 'имя') || audit_has($name, 'fio') || audit_has($name, 'фио')) {
                $fields['имя'] = 1; $has_meaningful = true;
            } elseif ($type === 'search' || audit_has($name, 'search') || audit_has($name, 'поиск') || $name === 'q' || audit_has($name, ' q ')) {
                // поле поиска — форму не считаем лид-формой
            } elseif ($type === 'text' || $type === '') {
                $fields['текст'] = 1;
            }
        }
    }
    if ($has_meaningful) {
        $lead_forms++;
        if (!$field_summary) $field_summary = array_keys($fields);
    }
}
if ($lead_forms > 0) {
    $detail = 'Форм заявки: ' . $lead_forms;
    if ($field_summary) $detail .= '. Собирает: ' . implode(', ', $field_summary);
    audit_add($checks, 'forms', 'ok', 'Форма заявки', $detail, ['count' => $lead_forms]);
} else {
    audit_add($checks, 'forms', 'warn', 'Форма заявки',
        'Формы для заявки не нашлось. Заявку можно оставить только звонком или в мессенджере.', ['count' => 0]);
}

// === 4. Адаптивность: метатег viewport (width=device-width) ===
// Честно проверяется по разметке: без него на телефоне сайт показывается как уменьшенная
// десктопная версия — по строительным/интерьерным сайтам это прямая потеря заявок с мобильных.
$viewport_ok = false;
foreach ($xp->query('//meta[@name="viewport"] | //meta[@name="Viewport"]') as $mv) {
    if (mb_stripos($mv->getAttribute('content'), 'width=device-width') !== false) { $viewport_ok = true; break; }
}
if ($viewport_ok) {
    audit_add($checks, 'viewport', 'ok', 'Мобильная вёрстка (адаптивность)',
        'Метатег viewport на месте — страница подстраивается под ширину телефона.');
} else {
    audit_add($checks, 'viewport', 'bad', 'Мобильная вёрстка (адаптивность)',
        'Нет метатега viewport с width=device-width. На телефоне сайт покажется как уменьшенная десктопная версия — читать и жать кнопки неудобно.');
}

// === 5. Политика обработки ПД (152-ФЗ) ===
$policy_found = false; $policy_href = '';
foreach ($xp->query('//a') as $a) {
    $href = mb_strtolower($a->getAttribute('href'));
    $txt = mb_strtolower(trim($a->textContent));
    if (audit_has($href, 'privacy') || audit_has($href, 'policy') || audit_has($href, 'politik')
        || audit_has($href, 'personal') || audit_has($href, 'konfiden')
        || audit_has($txt, 'политик') || audit_has($txt, 'персональных данных')
        || audit_has($txt, 'конфиденциальн') || audit_has($txt, 'обработк')) {
        $policy_found = true;
        $policy_href = trim($a->getAttribute('href'));
        break;
    }
}
if ($policy_found) {
    audit_add($checks, 'policy', 'ok', 'Политика обработки ПД',
        'Ссылка на политику обработки персональных данных есть.');
} else {
    audit_add($checks, 'policy', 'bad', 'Политика обработки ПД',
        'Ссылки на политику обработки ПД не найдено. Для сайта, собирающего заявки, это нарушение 152-ФЗ.');
}

// === 6. Согласие на обработку ПД у формы ===
if ($lead_forms > 0) {
    $consent_ok = false;
    foreach ($forms as $form) {
        $has_checkbox = false;
        foreach ($form->getElementsByTagName('input') as $f) {
            if (strtolower($f->getAttribute('type')) === 'checkbox') { $has_checkbox = true; break; }
        }
        $ftext = mb_strtolower($form->textContent);
        $has_text = audit_has($ftext, 'соглас') || audit_has($ftext, 'персональн')
            || audit_has($ftext, 'обработк') || audit_has($ftext, 'политик');
        if ($has_checkbox && $has_text) { $consent_ok = true; break; }
    }
    if ($consent_ok) {
        audit_add($checks, 'consent', 'ok', 'Согласие на обработку ПД',
            'У формы есть чекбокс согласия на обработку персональных данных.');
    } else {
        audit_add($checks, 'consent', 'bad', 'Согласие на обработку ПД',
            'У формы не видно чекбокса согласия на обработку ПД. По 152-ФЗ он обязателен.');
    }
}

// === 7. Cookie-баннер ===
$cookie_hit = (audit_has($html_low, 'cookie') || audit_has($html_low, 'куки'))
    && (audit_has($html_low, 'принять') || audit_has($html_low, 'соглас')
        || audit_has($html_low, 'использ') || audit_has($html_low, 'consent')
        || audit_has($html_low, 'accept'));
if ($cookie_hit) {
    audit_add($checks, 'cookie', 'ok', 'Cookie-уведомление',
        'Уведомление об использовании cookie в разметке есть.');
} else {
    audit_add($checks, 'cookie', 'warn', 'Cookie-уведомление',
        'Уведомления о cookie в разметке не видно. Оно могло подгружаться скриптом — проверю вручную.');
}

// === 8. Аналитика: счётчик виден в коде страницы. Про ЦЕЛИ снаружи судить нельзя. ===
// Ищем в нижнем регистре ($html_low). Явные счётчики распознаём поимённо; отдельно
// ловим диспетчер тегов (через него счётчики часто подключают, и в статике их не видно).
$systems = [];
if (audit_has($html_low, 'mc.yandex.ru') || audit_has($html_low, 'ym(') || audit_has($html_low, 'yacounter') || audit_has($html_low, 'metrika')) {
    $systems[] = 'Яндекс.Метрика';
}
if (audit_has($html_low, 'google-analytics.com') || audit_has($html_low, 'gtag(') || audit_has($html_low, '/analytics.js') || audit_has($html_low, 'ga(')) {
    $systems[] = 'Google Analytics';
}
if (audit_has($html_low, 'top.mail.ru') || audit_has($html_low, 'vk.com/rtrg') || audit_has($html_low, '_tmr')) {
    $systems[] = 'VK Реклама (top.mail.ru)';
}
if (audit_has($html_low, 'roistat')) { $systems[] = 'Roistat'; }
if (audit_has($html_low, 'calltouch')) { $systems[] = 'Calltouch'; }

// Диспетчер тегов сам по себе не счётчик, но его наличие означает, что аналитику,
// скорее всего, подключают через него, а конкретику со стороны страницы не увидеть.
$has_gtm = audit_has($html_low, 'googletagmanager.com') || audit_has($html_low, 'gtm.js') || audit_has($html_low, 'gtm-') || audit_has($html_low, 'datalayer');

if ($systems) {
    audit_add($checks, 'analytics', 'ok', 'Аналитика установлена',
        'Найдено: ' . implode(', ', $systems) . '. Настроены ли цели на заявки, видно только в кабинете, проверю вручную.');
} elseif ($has_gtm) {
    audit_add($checks, 'analytics', 'ok', 'Аналитика установлена',
        'Стоит диспетчер тегов (Google Tag Manager). Через него обычно и подключают счётчики, но какие именно и настроены ли цели, со стороны страницы не видно. Проверю в кабинете.');
} else {
    audit_add($checks, 'analytics', 'warn', 'Аналитика установлена',
        'Счётчика аналитики в коде страницы не вижу. Он мог подключаться через диспетчер тегов или после согласия на cookie, тогда проверю вручную. Если счётчика нет совсем, не видно, откуда приходят заявки.');
}

// --- Вердикт (базовый; финальные формулировки шлифуются вручную) ---
$bad = 0; $warn = 0;
foreach ($checks as $c) {
    if ($c['status'] === 'bad') $bad++;
    elseif ($c['status'] === 'warn') $warn++;
}
if ($bad === 0 && $warn === 0) {
    $verdict = ['tone' => 'ok', 'text' => 'По базовым пунктам сайт в порядке. Дальше стоит смотреть на скорость и содержание — это в подробном разборе.'];
} elseif ($bad === 0) {
    $verdict = ['tone' => 'warn', 'text' => 'Крупных провалов нет, но есть ' . $warn . ' узких места, из-за которых часть заявок теряется.'];
} else {
    $verdict = ['tone' => 'bad', 'text' => 'Нашлось ' . $bad . ' серьёзных проблем' . ($warn ? ' и ещё ' . $warn . ' помельче' : '') . '. Пока сайт с высокой вероятностью теряет обращения.'];
}

audit_out([
    'ok'         => true,
    'url'        => $meta['final'] ?? $url_in,
    'fetched_at' => gmdate('c'),
    'checks'     => $checks,
    'verdict'    => $verdict,
]);
