<?php
// Обработчик заявки для российского хостинга (Beget).
// Принимает POST из формы сайта и отправляет заявку в Telegram.
// Персональные данные обрабатываются на сервере в РФ (152-ФЗ).

header('X-Content-Type-Options: nosniff');

// Секреты лежат в отдельном файле lead-config.php (его создаёшь на сервере).
// ОСНОВНОЙ приёмник (152-ФЗ): российская почта на домене (сервер Beget в РФ).
// ПД не покидают РФ → трансграничной передачи нет. Заполняется в lead-config.php.
$LEAD_EMAIL = '';        // куда слать заявки, напр. lead@labazan.ru (ящик Beget/РФ)
$LEAD_EMAIL_FROM = '';   // адрес отправителя на домене, напр. noreply@labazan.ru
$BOT_TOKEN = '';
$CHAT_ID = '';
// Единый приёмник лидов (n8n). Пусто = дублирование в CRM выключено, форма работает как раньше.
$N8N_WEBHOOK_URL = '';
$N8N_WEBHOOK_SECRET = '';
foreach ([__DIR__ . '/lead-config.php', __DIR__ . '/../lead-config.php'] as $cfg) {
    if (is_file($cfg)) { require $cfg; break; }
}

// AJAX-клиент (fetch с Accept: application/json) получает JSON;
// нативная отправка формы без JS — человекочитаемую HTML-страницу.
function wants_json() {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return stripos($accept, 'application/json') !== false || strcasecmp($xrw, 'XMLHttpRequest') === 0;
}

function html_response($data) {
    $ok = !empty($data['ok']);
    $title = $ok ? 'Заявка отправлена' : 'Не удалось отправить';
    if ($ok) {
        $msg = 'Спасибо! Мы получили вашу заявку и вернёмся со сметой в течение дня.';
    } else {
        $map = [
            'validation'     => 'Проверьте имя и контакт: укажите телефон или email.',
            'rate_limited'   => 'Слишком много попыток. Подождите минуту и попробуйте снова.',
            'not_configured' => 'Форма ещё настраивается. Напишите нам на почту, пожалуйста.',
            'origin'         => 'Заявка отклонена. Отправьте форму со страницы сайта labazan.ru.',
            'consent'        => 'Отметьте согласие на обработку персональных данных.',
            'method'         => 'Некорректный запрос.',
            'telegram'       => 'Не удалось доставить заявку. Напишите нам на почту, пожалуйста.',
        ];
        $msg = $map[$data['error'] ?? ''] ?? 'Что-то пошло не так. Напишите нам на почту, пожалуйста.';
    }
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . $t . ' — Лабазан</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#0D1014;color:#F4F6F8;font-family:Segoe UI,Arial,sans-serif;padding:24px}'
        . '.c{max-width:440px;text-align:center}h1{font-size:24px;margin:0 0 12px}'
        . 'p{color:#C7CDD5;line-height:1.6;margin:0 0 24px}'
        . 'a{display:inline-block;background:#FF5A1F;color:#0D1014;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:6px}</style>'
        . '</head><body><div class="c"><h1>' . $t . '</h1><p>' . $m . '</p>'
        . '<a href="/">Вернуться на сайт</a></div></body></html>';
}

function out($data, $code = 200) {
    http_response_code($code);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo html_response($data);
    }
    exit;
}

function clean($v, $max = 500) {
    $v = is_string($v) ? $v : '';
    $v = preg_replace('/[<>]/', '', $v);
    return mb_substr(trim($v), 0, $max);
}

// Контакт должен быть похож на email или на телефон (10–15 цифр).
function contact_valid($c) {
    if (filter_var($c, FILTER_VALIDATE_EMAIL)) return true;
    $digits = preg_replace('/\D+/', '', $c);
    $len = strlen($digits);
    return $len >= 10 && $len <= 15;
}

// Telegram считается успешным только если тело ответа содержит {"ok":true}.
function tg_ok($body) {
    if (!is_string($body) || $body === '') return false;
    $j = json_decode($body, true);
    return is_array($j) && !empty($j['ok']);
}

// Разрешаем заявки только с нашего домена (или тех-поддомена Beget).
// Заголовков нет (например, POST без JS) — не блокируем, чтобы не терять лид.
function origin_ok() {
    $host = 'labazan.ru';
    $sources = [];
    if (!empty($_SERVER['HTTP_ORIGIN']))  $sources[] = $_SERVER['HTTP_ORIGIN'];
    if (!empty($_SERVER['HTTP_REFERER'])) $sources[] = $_SERVER['HTTP_REFERER'];
    if (!$sources) return true;
    foreach ($sources as $s) {
        $h = parse_url($s, PHP_URL_HOST);
        if (!$h) continue;
        if (strcasecmp($h, $host) === 0 || strcasecmp($h, 'www.' . $host) === 0
            || substr($h, -11) === '.beget.tech') return true;
    }
    return false;
}

// Простой rate-limit по IP: не более $max заявок за $window секунд.
// Хранит метки времени в файле во временном каталоге, с блокировкой.
function rate_limit_ok($max = 5, $window = 60) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = sys_get_temp_dir() . '/labazan_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    // Периодическая уборка: с вероятностью 1/50 удаляем метки IP старше часа,
    // чтобы каталог не рос от разовых посетителей.
    if (mt_rand(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $old) {
            if (is_file($old) && (time() - filemtime($old)) > 3600) @unlink($old);
        }
    }
    $file = $dir . '/' . hash('sha256', $ip);
    $fp = @fopen($file, 'c+');
    if ($fp === false) return true; // ФС недоступна — не блокируем
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(['ok' => false, 'error' => 'method'], 405);
}

if (!origin_ok()) {
    out(['ok' => false, 'error' => 'origin'], 403);
}

if (!rate_limit_ok()) {
    out(['ok' => false, 'error' => 'rate_limited'], 429);
}

// Honeypot: бот заполнит скрытое поле — молча принимаем и игнорируем.
if (clean($_POST['company'] ?? '', 100) !== '') {
    out(['ok' => true]);
}

$name    = clean($_POST['name'] ?? '', 120);
$contact = clean($_POST['contact'] ?? '', 160);
$task    = clean($_POST['task'] ?? '', 1500);

if ($name === '' || $contact === '' || !contact_valid($contact)) {
    out(['ok' => false, 'error' => 'validation'], 422);
}

// Согласие на обработку ПД (152-ФЗ) — обязательно. Не полагаемся на клиентский
// required: проверяем факт согласия на сервере до любой пересылки данных.
$consent = $_POST['consent'] ?? '';
if ($consent !== '1' && strcasecmp((string) $consent, 'on') !== 0) {
    out(['ok' => false, 'error' => 'consent'], 422);
}

// Переключатель цели заявки: собираем аккуратный структурированный текст.
// Читаем только поля, относящиеся к выбранной цели, — устойчиво и без JS.
$allowed_goals = ['Сайт', 'Реклама', 'Автоматизация', 'Всё в комплексе', 'Свой вариант'];
$goal = clean($_POST['goal'] ?? '', 40);
if (!in_array($goal, $allowed_goals, true)) {
    $goal = 'Заявка';
}

$parts = [];
if ($goal === 'Сайт') {
    $v = clean($_POST['site_type'] ?? '', 60);      if ($v !== '') $parts[] = 'Тип сайта: ' . $v;
    $v = clean($_POST['site_url'] ?? '', 200);      if ($v !== '') $parts[] = 'Текущий сайт: ' . $v;
    $v = clean($_POST['site_details'] ?? '', 1500); if ($v !== '') $parts[] = $v;
} elseif ($goal === 'Реклама') {
    $v = clean($_POST['ads_channel'] ?? '', 60);    if ($v !== '') $parts[] = 'Канал: ' . $v;
    $v = clean($_POST['ads_landing'] ?? '', 60);    if ($v !== '') $parts[] = 'Посадочная: ' . $v;
    $v = clean($_POST['ads_details'] ?? '', 1500);  if ($v !== '') $parts[] = $v;
} elseif ($goal === 'Автоматизация') {
    $v = clean($_POST['auto_what'] ?? '', 60);      if ($v !== '') $parts[] = 'Автоматизировать: ' . $v;
    $v = clean($_POST['auto_crm'] ?? '', 40);       if ($v !== '') $parts[] = 'CRM: ' . $v;
    $v = clean($_POST['auto_details'] ?? '', 1500); if ($v !== '') $parts[] = $v;
} elseif ($goal === 'Всё в комплексе') {
    $items = $_POST['complex'] ?? [];
    if (is_array($items)) {
        $clean_items = [];
        foreach (array_slice($items, 0, 10) as $it) {
            $it = clean($it, 40);
            if ($it !== '') $clean_items[] = $it;
        }
        if ($clean_items) $parts[] = 'Направления: ' . implode(', ', $clean_items);
    }
    $v = clean($_POST['complex_about'] ?? '', 1500); if ($v !== '') $parts[] = $v;
} else {
    if ($task !== '') $parts[] = $task;
}
$details = implode("\n", $parts);
if ($details === '') {
    $details = $task; // фолбэк: свободная задача, если структурные поля пусты
}

// ОСНОВНОЙ канал (152-ФЗ): российская почта через сервер Beget (в РФ).
// mail() уходит на локальный MTA хостинга — ПД не покидают РФ, трансграничной
// передачи нет. Тема/адреса статичны или из allowlist; пользовательский ввод идёт
// только в тело письма (заголовки не строятся из $_POST → нет header-инъекции).
$mail_ok = false;
if ($LEAD_EMAIL !== '' && filter_var($LEAD_EMAIL, FILTER_VALIDATE_EMAIL)) {
    $subject = 'Заявка с labazan.ru: ' . $goal; // $goal — из allowlist, безопасно
    $body = "Новая заявка с сайта labazan.ru\n\n"
          . "Цель: {$goal}\n"
          . "Имя: {$name}\n"
          . "Контакт: {$contact}\n"
          . ($details !== '' ? "\n{$details}\n" : '')
          . "\nСогласие на обработку ПД: да\n"
          . 'Время: ' . date('Y-m-d H:i:s');
    $from = ($LEAD_EMAIL_FROM !== '' && filter_var($LEAD_EMAIL_FROM, FILTER_VALIDATE_EMAIL))
        ? $LEAD_EMAIL_FROM : $LEAD_EMAIL;
    $headers = "From: " . $from . "\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "X-Mailer: labazan-lead";
    $enc_subject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B') : $subject;
    $mail_ok = @mail($LEAD_EMAIL, $enc_subject, $body, $headers);
}

// ДОП. канал: единый приёмник n8n (ВМ) → CRM + Telegram-уведомление о заявке.
// Ждём ответ вебхука (он отвечает сразу, ~0.2с, до записи в CRM), чтобы подтвердить
// успех клиенту. Прямой Telegram ниже — тихий фолбэк на случай недоступности n8n.
$n8n_ok = false;
if ($N8N_WEBHOOK_URL !== '' && function_exists('curl_init')) {
    // JSON_INVALID_UTF8_SUBSTITUTE: даже если во входных данных проскочит битый UTF-8
    // (боты, экзотические клиенты), json_encode НЕ вернёт false — иначе тело ушло бы
    // пустым и лид потерялся бы молча. Нормальная форма (UTF-8) отдаёт валидный JSON.
    $n8n_body = json_encode([
        'name'    => $name,
        'contact' => $contact,
        'task'    => $details,
        'source'  => 'site:labazan.ru / ' . $goal,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $n8n_ch = curl_init($N8N_WEBHOOK_URL);
    curl_setopt_array($n8n_ch, [
        CURLOPT_POST             => true,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_POSTFIELDS       => $n8n_body,
        CURLOPT_HTTPHEADER       => [
            'Content-Type: application/json',
            'X-Webhook-Secret: ' . $N8N_WEBHOOK_SECRET,
        ],
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS        => 4000,
    ]);
    $n8n_resp = curl_exec($n8n_ch);
    $n8n_code = (int) curl_getinfo($n8n_ch, CURLINFO_HTTP_CODE);
    curl_close($n8n_ch);
    // Успех рельса — HTTP 200 и тело {"ok":true}.
    if ($n8n_code === 200 && is_string($n8n_resp) && $n8n_resp !== '') {
        $nj = json_decode($n8n_resp, true);
        $n8n_ok = is_array($nj) && !empty($nj['ok']);
    }
}

// ФОЛБЭК-канал: прямой Telegram с сайта. Нужен ТОЛЬКО если рельс n8n не сработал —
// иначе уведомление уже ушло через n8n (ВМ), и повторно слать не нужно. Важно ещё и для
// скорости: на Beget исходящий на api.telegram.org заблокирован, и лишний вызов впустую
// висел бы до таймаута на каждой успешной заявке. Успех = сработал ХОТЯ БЫ ОДИН канал.
$tg_ok = false;
if (!$n8n_ok && $BOT_TOKEN !== '' && $CHAT_ID !== '') {
    $text = "🟠 Новая заявка с labazan.ru\n\n"
          . "🎯 Цель: {$goal}\n"
          . "Имя: {$name}\n"
          . "Контакт: {$contact}\n"
          . ($details !== '' ? "\n{$details}\n" : '')
          . "\nСогласие на обработку ПД: да";

    $payload = http_build_query([
        'chat_id' => $CHAT_ID,
        'text' => $text,
        'disable_web_page_preview' => 'true',
    ]);

    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $response = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        // Запасной путь, если cURL выключен на хостинге.
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        $httpCode = $response !== false ? 200 : 0;
    }
    $tg_ok = ($httpCode === 200 && tg_ok($response));
}

// Успех, если заявка доставлена ХОТЯ БЫ одним каналом (email РФ / n8n / Telegram).
if ($mail_ok || $n8n_ok || $tg_ok) {
    out(['ok' => true]);
}
// Ни один канал не настроен — форма ещё не сконфигурирована.
if ($LEAD_EMAIL === '' && $N8N_WEBHOOK_URL === '' && ($BOT_TOKEN === '' || $CHAT_ID === '')) {
    out(['ok' => false, 'error' => 'not_configured'], 503);
}
// Каналы настроены, но недоступны — временная ошибка доставки.
out(['ok' => false, 'error' => 'telegram'], 502);
