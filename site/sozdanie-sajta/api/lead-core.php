<?php
// CANON-STAMP: 2026-08-02 sha:6e577952e325  (авто: lead-kit/check-copies.sh; правки только в каноне)
// ОБЩЕЕ ЯДРО приёма лид-форм лид-инструментов Лабазан (аудит / радар; при желании и калькулятор).
// КАНОНИЧЕСКИЙ ИСТОЧНИК: lead-kit/php/lead-core.php. В проекты кладём КОПИЮ в public/api/lead-core.php,
// правки - только здесь, затем копировать (Beget-деплой не тянет node_modules, поэтому нужен файл).
// Логика безопасности извлечена 1:1 из проверенного labazan-site/public/api/lead.php, не менялась.
//
// Доставка ТОЛЬКО в РФ-приёмники: почта на домене (Beget, РФ) + РФ-CRM через n8n на РФ-ВМ.
// ПД граждан РФ обрабатываются/хранятся в РФ (152-ФЗ); иностранные мессенджеры как канал передачи
// ПД НЕ используются - трансграничной передачи нет. PHP 7.4+.
//
// Тонкая оболочка проекта: подключить конфиг с секретами, require этот файл, вызвать
// labazan_lead_run($opts). Сборку строки для менеджера отдаёт callback $opts['build_details'].

if (!function_exists('labazan_lead_run')) {

function lead_wants_json() {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return stripos($accept, 'application/json') !== false || strcasecmp($xrw, 'XMLHttpRequest') === 0;
}

// Базовая очистка: строка, без угловых скобок, обрезка по длине. Для тела письма/CRM.
function lead_clean($v, $max = 500) {
    $v = is_string($v) ? $v : '';
    $v = preg_replace('/[<>]/', '', $v);
    return mb_substr(trim($v), 0, $max);
}

// Контакт: email или телефон (10–15 цифр). Радар дополнительно принимает Telegram-ник (@username).
function lead_contact_valid($c, $accept_telegram = false) {
    if (filter_var($c, FILTER_VALIDATE_EMAIL)) return true;
    // Telegram-ник: обязателен «@», начинается с буквы, 5–32 символа (правила Telegram).
    // Без «@» отсекаем случайные слова (напр. «test»), иначе приходят заявки без способа связи.
    if ($accept_telegram && preg_match('/^@[A-Za-z][A-Za-z0-9_]{4,31}$/', $c)) return true;
    $digits = preg_replace('/\D+/', '', $c);
    $len = strlen($digits);
    return $len >= 10 && $len <= 15;
}

// Заявки только с наших доменов (+ тех-поддомен Beget). Заголовков нет - не блокируем (не терять лид).
function lead_origin_ok(array $hosts) {
    $sources = [];
    if (!empty($_SERVER['HTTP_ORIGIN']))  $sources[] = $_SERVER['HTTP_ORIGIN'];
    if (!empty($_SERVER['HTTP_REFERER'])) $sources[] = $_SERVER['HTTP_REFERER'];
    if (!$sources) return true;
    foreach ($sources as $s) {
        $h = parse_url($s, PHP_URL_HOST);
        if (!$h) continue;
        if (in_array(strtolower($h), $hosts, true) || substr($h, -11) === '.beget.tech') return true;
    }
    return false;
}

// Rate-limit по IP: не более $max заявок за $window секунд. Метки в файле, с блокировкой.
// $bucket - имя подкаталога во временной папке (свой на инструмент, чтобы лимиты не смешивались).
function lead_rate_limit_ok($bucket, $max = 5, $window = 60) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = sys_get_temp_dir() . '/' . $bucket;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (mt_rand(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $old) {
            if (is_file($old) && (time() - filemtime($old)) > 3600) @unlink($old);
        }
    }
    $file = $dir . '/' . hash('sha256', $ip);
    $fp = @fopen($file, 'c+');
    if ($fp === false) return true; // ФС недоступна - не блокируем
    $allowed = true;
    if (flock($fp, LOCK_EX)) {
        $now = time();
        $times = [];
        $raw = stream_get_contents($fp);
        if ($raw !== false && $raw !== '') {
            foreach (explode("\n", trim($raw)) as $t) {
                if ($t !== '' && ($now - (int) $t) < $window) $times[] = (int) $t;
            }
        }
        $allowed = count($times) < $max;
        if ($allowed) $times[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode("\n", $times));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $allowed;
}

// --- Тайминг-токен (HMAC) -------------------------------------------------------------------
// Статике негде подписать поле при рендере, поэтому подпись выдаёт мини-эндпоинт token.php, а
// клиент кладёт токен в скрытое поле lead_ts. Подделать без серверного секрета нельзя.

// Подпись метки времени: "<ts>.<hex-hmac>" на секрете (тот же алгоритм в token.php).
function lead_sign_ts($ts, $secret) {
    return $ts . '.' . hash_hmac('sha256', (string) $ts, (string) $secret);
}

// Проверка тайминг-токена: валидная подпись + возраст в окне [$min, $max] секунд.
// Пустой токен/секрет или битый формат → false (вызывающий решает, блокировать ли).
function lead_timing_ok($token, $secret, $min, $max) {
    if (!is_string($token) || $token === '' || !is_string($secret) || $secret === '') return false;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;
    list($ts, $sig) = $parts;
    if ($ts === '' || !ctype_digit($ts)) return false;
    $expected = hash_hmac('sha256', $ts, $secret);
    if (!hash_equals($expected, (string) $sig)) return false;
    $age = time() - (int) $ts;              // ts мятится сервером (token.php) → возраст >= 0
    return $age >= (int) $min && $age <= (int) $max;
}

// --- Телефон --------------------------------------------------------------------------------
// Нормализация к +7XXXXXXXXXX. Принимаем 11 цифр (7/8 в начале) и 10 цифр (без кода страны).
// Иначе null (невалидная длина). Только для форм с phone_strict; email/Telegram сюда не идут.
function lead_phone_normalize($raw) {
    $d = preg_replace('/\D+/', '', (string) $raw);
    if ($d === '') return null;
    if (strlen($d) === 11 && ($d[0] === '7' || $d[0] === '8')) return '+7' . substr($d, 1);
    if (strlen($d) === 10) return '+7' . $d;
    return null;
}

// Заведомо мусорный номер: не наш формат / мало уникальных цифр (0000000000, 1111111111,
// +70000000000 и т.п.) / мобильный не может начинаться с 0.
function lead_phone_is_junk($normalized) {
    if (!is_string($normalized) || strpos($normalized, '+7') !== 0) return true;
    $d = substr($normalized, 2);
    if (strlen($d) !== 10) return true;
    if ($d[0] === '0') return true;                                   // РФ-мобильные не с 0
    if (count(array_unique(str_split($d))) <= 2) return true;         // 1-2 уникальных цифры
    return false;
}

// Контакт похож на телефон (не email и не @Telegram, достаточно цифр) — гейт для phone_strict.
function lead_looks_like_phone($contact) {
    $contact = trim((string) $contact);
    if ($contact === '' || $contact[0] === '@') return false;
    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) return false;
    return strlen(preg_replace('/\D+/', '', $contact)) >= 7;
}

// Ссылка/BBCode в поле, где их быть не должно (имя, свободный текст) — типичный спам-маркер.
function lead_has_link($s) {
    if (!is_string($s) || $s === '') return false;
    return (bool) preg_match('~(https?://|www\.|t\.me/|\bxn--|\[url|\[link)~i', $s);
}

// --- Журнал решений (JSON Lines, вне вебрута) ------------------------------------------------
// Одна строка на отправку: accepted|rejected + причина. ПД в открытом виде НЕ пишем — телефон и
// IP только sha256(соль|значение) (152-ФЗ). Ротация = файл на месяц (lead-YYYY-MM.jsonl).
function lead_log_decision(array $o, $result, $reason, $contact_for_hash = null) {
    $dir = (string) ($o['log_dir'] ?? '');
    if ($dir === '') $dir = sys_get_temp_dir() . '/labazan_lead_log';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return; // ФС недоступна — тихо
    // Соль ОБЯЗАТЕЛЬНА для хеширования идентификаторов: несолёный sha256(IP) обратим перебором
    // (IPv4 ~4 млрд) → фактически хранение обратимого ПД. Нет соли → идентификаторы не пишем вовсе.
    $salt = (string) ($o['log_salt'] ?? '');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';

    // UTM только из известного набора ключей (не тянем произвольный ввод в журнал).
    $utm = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
        if (!empty($_POST[$k]) && is_string($_POST[$k])) {
            $utm[$k] = mb_substr(preg_replace('/[<>]/', '', $_POST[$k]), 0, 120);
        }
    }

    $rec = [
        'ts'         => date('c'),
        'result'     => $result,                      // accepted | rejected
        'reason'     => $reason,                       // honeypot | timing | rate | phone | link | ...
        'utm'        => (object) $utm,
        'referer'    => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
        'ua'         => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        // Хешируем ТОЛЬКО при наличии соли (иначе хеш обратим — не пишем идентификатор).
        'ip_hash'    => ($salt !== '' && $ip !== '') ? hash('sha256', $salt . '|ip|' . $ip) : '',
        // phone_hash: хеш нормализованного телефона (или контакта, если не телефон) — не сам ПД.
        'phone_hash' => ($salt !== '' && $contact_for_hash !== null && $contact_for_hash !== '')
            ? hash('sha256', $salt . '|ph|' . $contact_for_hash) : '',
    ];
    $line = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) return;

    $file = $dir . '/lead-' . date('Y-m') . '.jsonl';  // имя по месяцу = ротация
    $fp = @fopen($file, 'a');
    if ($fp === false) return;
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, $line . "\n");
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    if (mt_rand(1, 100) === 1) {                        // оппортунистическая чистка старых месяцев
        lead_log_rotate($dir, (int) ($o['log_keep_months'] ?? 12));
    }
}

// Ротация: удаляем месячные файлы старше $keep_months. 0/отрицательное → не чистим.
function lead_log_rotate($dir, $keep_months) {
    if ((int) $keep_months <= 0) return;
    $cutoff = strtotime('-' . (int) $keep_months . ' months');
    if ($cutoff === false) return;
    foreach (glob($dir . '/lead-*.jsonl') ?: [] as $f) {
        if (preg_match('/lead-(\d{4})-(\d{2})\.jsonl$/', $f, $m)) {
            $ft = strtotime($m[1] . '-' . $m[2] . '-01');
            if ($ft !== false && $ft < $cutoff) @unlink($f);
        }
    }
}

// Человекочитаемая страница-ответ для отправки формы без JS. $theme - массив цветов (свет/тьма).
function lead_html_response($data, array $theme) {
    $ok = !empty($data['ok']);
    $title = $ok ? 'Заявка отправлена' : 'Не удалось отправить';
    if ($ok) {
        $msg = 'Спасибо! Мы получили заявку и вернёмся с ответом в течение дня.';
    } else {
        $map = [
            'validation'     => 'Проверьте имя и контакт: укажите телефон или email.',
            'rate_limited'   => 'Слишком много попыток. Подождите минуту и попробуйте снова.',
            'not_configured' => 'Форма ещё настраивается. Напишите нам через сайт labazan.ru.',
            'origin'         => 'Заявка отклонена. Отправьте форму со страницы сайта.',
            'consent'        => 'Отметьте согласие на обработку персональных данных.',
            'method'         => 'Некорректный запрос.',
            'delivery'       => 'Не удалось доставить заявку. Напишите нам через labazan.ru.',
        ];
        $msg = $map[$data['error'] ?? ''] ?? 'Что-то пошло не так. Напишите нам через labazan.ru.';
    }
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    $bg = $theme['bg']; $ink = $theme['ink']; $muted = $theme['muted'];
    $accent = $theme['accent']; $on = $theme['on_accent'];
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . $t . ' - Лабазан</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:' . $bg . ';color:' . $ink . ';font-family:Segoe UI,Arial,sans-serif;padding:24px}'
        . '.c{max-width:440px;text-align:center}h1{font-size:24px;margin:0 0 12px}'
        . 'p{color:' . $muted . ';line-height:1.6;margin:0 0 24px}'
        . 'a{display:inline-block;background:' . $accent . ';color:' . $on . ';text-decoration:none;font-weight:600;padding:12px 22px;border-radius:6px}</style>'
        . '</head><body><div class="c"><h1>' . $t . '</h1><p>' . $m . '</p>'
        . '<a href="/">Вернуться на сайт</a></div></body></html>';
}

function lead_out($data, array $theme, $code = 200) {
    http_response_code($code);
    if (lead_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo lead_html_response($data, $theme);
    }
    exit;
}

/**
 * Статичное Telegram-уведомление о новом лиде. Текст задаёт вызывающий ($text) — БЕЗ полей
 * из формы (ПД остаются в РФ-почте, в мессенджер не уходят). Таймаут 3с. Любая
 * ошибка/недоступность (напр. Beget режет исходящий на api.telegram.org) проглатывается
 * и НЕ влияет ни на заявку, ни на ответ формы. Токен/chat_id задаёт владелец на сервере.
 */
function labazan_tg_notify($token, $chat_id, $text = 'Новый лид. Детали на почте.') {
    if (!is_string($token) || !is_string($chat_id) || $token === '' || $chat_id === '') return;
    if (!is_string($text) || $text === '') $text = 'Новый лид. Детали на почте.';
    if (!function_exists('curl_init')) return;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    if ($ch === false) return;
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id' => $chat_id,
            'text'    => $text,
            'disable_web_page_preview' => 'true',
        ]),
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 3,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

/**
 * Успешный ответ {ok:true} формы, затем (уже после закрытия соединения с клиентом)
 * фоновый Telegram-пинг. Так пинг не задерживает и не меняет ответ формы. Заявка важнее.
 */
function lead_out_ok_then_ping(array $theme, $tg_token, $tg_chat, $tg_text = 'Новый лид. Детали на почте.') {
    http_response_code(200);
    if (lead_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        // g:1 = засчитываемая конверсия (по нему JS дёргает цель Метрики). Honeypot отдаёт g:0.
        echo json_encode(['ok' => true, 'g' => 1], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo lead_html_response(['ok' => true], $theme);
    }
    // PHP-FPM: отдаём ответ клиенту немедленно, пинг — фоном после этого.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    labazan_tg_notify($tg_token, $tg_chat, $tg_text);
    exit;
}

/**
 * Полный приём заявки. $o - настройки инструмента:
 *   allowed_hosts[]  - домены для origin-проверки
 *   host_label       - подпись домена в письме/теме/источнике (напр. 'audit.labazan.ru')
 *   rl_bucket        - имя подкаталога rate-limit (свой на инструмент)
 *   allowed_goals[]  - допустимые значения поля goal (иначе default_goal)
 *   default_goal     - запасная цель
 *   subject_prefix   - префикс темы письма (по умолчанию 'Заявка с {host}: ')
 *   theme            - 'light' | 'dark' (цвета страницы-ответа без JS)
 *   accept_telegram  - принимать ли Telegram-ник как контакт (радар: true)
 *   build_details    - callable($_POST):string - строка контекста для менеджера (чистить внутри!)
 *   lead_email, lead_email_from, n8n_url, n8n_secret - секреты (из конфига проекта)
 *   ingest_key       - Bearer-ключ прямого CRM-ingest; задан → шлём в n8n_url как
 *                      Authorization: Bearer (вместо X-Webhook-Secret). n8n_url тогда = URL ingest.
 *   tg_ping_text     - текст фонового Telegram-пинга (без ПД); по умолчанию
 *                      'Новый лид на {host_label}. Детали на почте.'
 *   --- антиспам/журнал (значения из lead-config; пороги - фолбэки в коде) ---
 *   hmac_secret      - секрет подписи тайминг-токена; пусто → тайминг ВЫКЛючен (совместимость)
 *   timing_min       - мин. секунд между focusin и отправкой (по умолч. 2)
 *   timing_max       - макс. возраст токена, сек (по умолч. 86400 = 24ч)
 *   timing_required  - true → отсутствие токена тоже блокирует (по умолч. false: живую заявку не теряем)
 *   rl_max           - макс. отправок с IP за окно (по умолч. 3); CGNAT: неск. людей с одного IP
 *   rl_window        - окно rate-limit, сек (по умолч. 600 = 10 мин)
 *   phone_strict     - true → нормализация телефона +7XXXXXXXXXX и отсев мусорных (флаг проекта)
 *   spam_scan_fields - список $_POST-полей со свободным текстом для отсечки ссылок (кроме name)
 *   log_dir          - каталог JSON-Lines журнала ВНЕ вебрута; пусто → sys_get_temp_dir()
 *   log_salt         - соль для sha256-хешей IP/телефона в журнале (ПД в журнал не пишем)
 *   log_keep_months  - хранить месячные файлы журнала N месяцев (по умолч. 12)
 */
function labazan_lead_run(array $o) {
    header('X-Content-Type-Options: nosniff');

    $theme = ($o['theme'] ?? 'light') === 'dark'
        ? ['bg' => '#0D1014', 'ink' => '#F4F6F8', 'muted' => '#C7CDD5', 'accent' => '#FF5A1F', 'on_accent' => '#0D1014']
        : ['bg' => '#EDEEE9', 'ink' => '#1B1F1D', 'muted' => '#7E847F', 'accent' => '#1E4FD8', 'on_accent' => '#FFFFFF'];

    $host_label = $o['host_label'] ?? 'labazan.ru';

    // Пороги: значения приходят из lead-config через $o; в ядре только безопасные фолбэки.
    $hmac      = (string) ($o['hmac_secret'] ?? '');   // пусто → тайминг выключен
    // Отсчёт стартует с focusin (реальное заполнение), поэтому порог низкий: 2с. Автозаполнение
    // браузера и вставка из буфера у живых людей укладываются в секунды. Смотреть долю reason
    // 'timing' в журнале первый месяц — при нулевом фоне можно вернуть 3.
    $timing_min = (int) ($o['timing_min'] ?? 2);        // мин. секунд focusin→отправка
    $timing_max = (int) ($o['timing_max'] ?? 86400);    // макс. возраст токена (24ч)
    $timing_required = !empty($o['timing_required']);   // true → отсутствие токена тоже блокирует
    // rl_max/rl_window: при CGNAT у мобильных операторов несколько посетителей выходят с ОДНОГО IP.
    // Если в журнале растёт доля reason 'rate' — поднимать лимит (напр. до 5 / 600).
    $rl_max    = (int) ($o['rl_max'] ?? 3);             // отправок с IP за окно
    $rl_window = (int) ($o['rl_window'] ?? 600);        // окно rate-limit, сек (10 мин)

    // Не-POST (GET-сканеры) в журнал НЕ пишем — это не «отправка формы», только шум/рост файла.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        lead_out(['ok' => false, 'error' => 'method'], $theme, 405);
    }
    if (!lead_origin_ok($o['allowed_hosts'] ?? ['labazan.ru'])) {
        lead_log_decision($o, 'rejected', 'origin');
        lead_out(['ok' => false, 'error' => 'origin'], $theme, 403);
    }

    // (1) Honeypot: бот заполнил скрытое поле. Best practice — «фейковый успех» (не обучаем бота,
    // что ловушку раскрыли), но фиксируем в журнале как отсев. g:0 = НЕ конверсия: по нему JS не
    // дёргает цель Метрики (иначе при оплате за конверсию в РСЯ платили бы за бота).
    if (lead_clean($_POST['company'] ?? '', 100) !== '') {
        lead_log_decision($o, 'rejected', 'honeypot');
        lead_out(['ok' => true, 'g' => 0], $theme);
    }

    // (2) Тайминг (HMAC). Активен только при заданном секрете (фича-флаг; иначе старые
    // закешированные страницы без токена не ломаются).
    // $no_token помечает заявку без токена и прокидывается в ФИНАЛЬНУЮ запись журнала — отдельной
    // строки не пишем, чтобы одна заявка не считалась дважды (иначе accepted был бы завышен).
    $no_token = false;
    if ($hmac !== '') {
        $lead_ts = (string) ($_POST['lead_ts'] ?? '');
        if ($lead_ts === '') {
            // Токен не приехал (обрыв сети / ошибка JS / блокировщик / кеш). Терять живую заявку
            // хуже, чем пропустить бота: по умолчанию НЕ блокируем, лишь пометим итог как 'ok:no_token'.
            // timing_required=true → строгий режим: без токена отсекаем сразу (reason 'no_token').
            if ($timing_required) {
                lead_log_decision($o, 'rejected', 'no_token');
                lead_out(['ok' => false], $theme, 422);
            }
            $no_token = true;
        } elseif (!lead_timing_ok($lead_ts, $hmac, $timing_min, $timing_max)) {
            // Токен есть, но подпись/возраст не сходятся → бот или протухшая форма. Нейтральный ответ.
            lead_log_decision($o, 'rejected', 'timing');
            lead_out(['ok' => false], $theme, 422);
        }
    }

    // (3) Rate-limit по IP (пороги из конфига: по умолчанию 3 / 600с). Нейтральный ответ.
    if (!lead_rate_limit_ok($o['rl_bucket'] ?? 'labazan_lead_rl', $rl_max, $rl_window)) {
        lead_log_decision($o, 'rejected', 'rate');
        lead_out(['ok' => false], $theme, 429);
    }

    $name    = lead_clean($_POST['name'] ?? '', 120);
    $contact = lead_clean($_POST['contact'] ?? '', 160);

    // (4) Телефон. При phone_strict цифровой контакт нормализуем к +7XXXXXXXXXX и отсеиваем мусор.
    // Email и Telegram-ник этой веткой не трогаем. Нейтральный ответ.
    $phone_norm = null;
    if (!empty($o['phone_strict']) && lead_looks_like_phone($contact)) {
        $phone_norm = lead_phone_normalize($contact);
        if ($phone_norm === null || lead_phone_is_junk($phone_norm)) {
            lead_log_decision($o, 'rejected', 'phone', $contact);
            lead_out(['ok' => false], $theme, 422);
        }
    }

    // (5) Ссылки/BBCode в полях, где их быть не должно (имя + перечисленные свободные поля).
    // Сканируем сырой ввод (до очистки), чтобы ловить маркеры. Нейтральный ответ.
    $free_text = '';
    if (!empty($o['spam_scan_fields']) && is_array($o['spam_scan_fields'])) {
        foreach ($o['spam_scan_fields'] as $f) {
            if (!empty($_POST[$f]) && is_string($_POST[$f])) $free_text .= ' ' . $_POST[$f];
        }
    }
    if (lead_has_link($name) || lead_has_link($free_text)) {
        lead_log_decision($o, 'rejected', 'link', $phone_norm ?? $contact);
        lead_out(['ok' => false], $theme, 422);
    }

    // Базовая валидация имени/контакта — легит-ошибка живого человека, сообщение остаётся понятным.
    if ($name === '' || $contact === '' || !lead_contact_valid($contact, !empty($o['accept_telegram']))) {
        lead_log_decision($o, 'rejected', 'validation', $phone_norm ?? $contact);
        lead_out(['ok' => false, 'error' => 'validation'], $theme, 422);
    }

    // Согласие на обработку ПД (152-ФЗ) - обязательно, проверяем на сервере до любой пересылки.
    // Легит-ошибка — сообщение понятное (не прячем от живого человека).
    $consent = $_POST['consent'] ?? '';
    if ($consent !== '1' && strcasecmp((string) $consent, 'on') !== 0) {
        lead_log_decision($o, 'rejected', 'consent', $phone_norm ?? $contact);
        lead_out(['ok' => false, 'error' => 'consent'], $theme, 422);
    }

    // Цель - строго из allowlist; произвольный ввод не попадает ни в тему письма, ни в заголовки.
    $allowed_goals = $o['allowed_goals'] ?? [];
    $goal = lead_clean($_POST['goal'] ?? '', 40);
    if (!in_array($goal, $allowed_goals, true)) {
        $goal = $o['default_goal'] ?? 'Заявка';
    }

    // Контекст для менеджера собирает инструмент (свои поля). Внутри callback обязан чистить ввод.
    $details = '';
    if (isset($o['build_details']) && is_callable($o['build_details'])) {
        $details = (string) call_user_func($o['build_details'], $_POST);
    }

    // ОСНОВНОЙ канал (152-ФЗ): российская почта через сервер Beget (в РФ). Пользовательский ввод
    // идёт ТОЛЬКО в тело письма; тема/заголовки не строятся из $_POST (goal из allowlist) → нет инъекции.
    $mail_ok = false;
    $lead_email = $o['lead_email'] ?? '';
    if ($lead_email !== '' && filter_var($lead_email, FILTER_VALIDATE_EMAIL)) {
        $subject = ($o['subject_prefix'] ?? ('Заявка с ' . $host_label . ': ')) . $goal;
        $body = "Новая заявка с {$host_label}\n\n"
              . "Цель: {$goal}\n"
              . "Имя: {$name}\n"
              . "Контакт: {$contact}\n"
              . ($details !== '' ? "\n{$details}\n" : '')
              . "\nСогласие на обработку ПД: да\n"
              . 'Время: ' . date('Y-m-d H:i:s');
        $from = (!empty($o['lead_email_from']) && filter_var($o['lead_email_from'], FILTER_VALIDATE_EMAIL))
            ? $o['lead_email_from'] : $lead_email;
        $headers = "From: " . $from . "\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n"
                 . "X-Mailer: labazan-lead";
        $enc_subject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B') : $subject;
        $mail_ok = @mail($lead_email, $enc_subject, $body, $headers);
    }

    // ДОП. канал (РФ): единый приёмник n8n на РФ-ВМ → РФ-CRM. Вебхук отвечает сразу (~0.2с).
    $n8n_ok = false;
    $n8n_url = $o['n8n_url'] ?? '';
    if ($n8n_url !== '' && function_exists('curl_init')) {
        // JSON_INVALID_UTF8_SUBSTITUTE: битый UTF-8 (боты) не обнулит тело - лид не потеряется молча.
        $n8n_body = json_encode([
            'name'    => $name,
            'contact' => $contact,
            'task'    => $details,
            'source'  => $host_label . ' / ' . $goal,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        // Авторизация приёмника. Прямой CRM-ingest (crm.labazan.ru/api/ingest/lead) ждёт
        // Bearer-ключ тенанта в Authorization; старый вебхук n8n — X-Webhook-Secret. Если задан
        // ingest_key → прямой ingest (n8n выведен из контура 25.07.2026), иначе прежний n8n.
        // Тело/проверка ответа ({ok:true}) у обоих одинаковы — меняется только заголовок.
        $auth_header = !empty($o['ingest_key'])
            ? 'Authorization: Bearer ' . $o['ingest_key']
            : 'X-Webhook-Secret: ' . ($o['n8n_secret'] ?? '');
        $ch = curl_init($n8n_url);
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POSTFIELDS        => $n8n_body,
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                $auth_header,
            ],
            CURLOPT_CONNECTTIMEOUT_MS => 1500,
            CURLOPT_TIMEOUT_MS        => 4000,
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200 && is_string($resp) && $resp !== '') {
            $nj = json_decode($resp, true);
            $n8n_ok = is_array($nj) && !empty($nj['ok']);
        }
    }

    // Успех, если заявка ушла ХОТЯ БЫ одним российским каналом (почта РФ / n8n→РФ-CRM).
    if ($mail_ok || $n8n_ok) {
        lead_log_decision($o, 'accepted', $no_token ? 'ok:no_token' : 'ok', $phone_norm ?? $contact);
        // Ответ форме сразу, потом фоновый статичный Telegram-пинг (без ПД). Текст пинга —
        // из tg_ping_text или по умолчанию с доменом host_label (а не 'labazan.ru' намертво).
        $tg_text = $o['tg_ping_text'] ?? ('Новый лид на ' . $host_label . '. Детали на почте.');
        lead_out_ok_then_ping($theme, $o['tg_bot_token'] ?? '', $o['tg_chat_id'] ?? '', $tg_text);
    }
    // Ни один канал не настроен - форма ещё не сконфигурирована.
    if ($lead_email === '' && $n8n_url === '') {
        lead_log_decision($o, 'rejected', 'not_configured', $phone_norm ?? $contact);
        lead_out(['ok' => false, 'error' => 'not_configured'], $theme, 503);
    }
    // Каналы настроены, но недоступны - временная ошибка доставки.
    lead_log_decision($o, 'rejected', 'delivery', $phone_norm ?? $contact);
    lead_out(['ok' => false, 'error' => 'delivery'], $theme, 502);
}

} // function_exists('labazan_lead_run')
