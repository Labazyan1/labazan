<?php
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
    if ($accept_telegram && preg_match('/^@?[A-Za-z0-9_]{4,32}$/', $c)) return true;
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
 */
function labazan_lead_run(array $o) {
    header('X-Content-Type-Options: nosniff');

    $theme = ($o['theme'] ?? 'light') === 'dark'
        ? ['bg' => '#0D1014', 'ink' => '#F4F6F8', 'muted' => '#C7CDD5', 'accent' => '#FF5A1F', 'on_accent' => '#0D1014']
        : ['bg' => '#EDEEE9', 'ink' => '#1B1F1D', 'muted' => '#7E847F', 'accent' => '#1E4FD8', 'on_accent' => '#FFFFFF'];

    $host_label = $o['host_label'] ?? 'labazan.ru';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        lead_out(['ok' => false, 'error' => 'method'], $theme, 405);
    }
    if (!lead_origin_ok($o['allowed_hosts'] ?? ['labazan.ru'])) {
        lead_out(['ok' => false, 'error' => 'origin'], $theme, 403);
    }
    if (!lead_rate_limit_ok($o['rl_bucket'] ?? 'labazan_lead_rl')) {
        lead_out(['ok' => false, 'error' => 'rate_limited'], $theme, 429);
    }

    // Honeypot: бот заполнит скрытое поле - молча принимаем и игнорируем.
    if (lead_clean($_POST['company'] ?? '', 100) !== '') {
        lead_out(['ok' => true], $theme);
    }

    $name    = lead_clean($_POST['name'] ?? '', 120);
    $contact = lead_clean($_POST['contact'] ?? '', 160);
    if ($name === '' || $contact === '' || !lead_contact_valid($contact, !empty($o['accept_telegram']))) {
        lead_out(['ok' => false, 'error' => 'validation'], $theme, 422);
    }

    // Согласие на обработку ПД (152-ФЗ) - обязательно, проверяем на сервере до любой пересылки.
    $consent = $_POST['consent'] ?? '';
    if ($consent !== '1' && strcasecmp((string) $consent, 'on') !== 0) {
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
        $ch = curl_init($n8n_url);
        curl_setopt_array($ch, [
            CURLOPT_POST              => true,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POSTFIELDS        => $n8n_body,
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                'X-Webhook-Secret: ' . ($o['n8n_secret'] ?? ''),
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
        lead_out(['ok' => true], $theme);
    }
    // Ни один канал не настроен - форма ещё не сконфигурирована.
    if ($lead_email === '' && $n8n_url === '') {
        lead_out(['ok' => false, 'error' => 'not_configured'], $theme, 503);
    }
    // Каналы настроены, но недоступны - временная ошибка доставки.
    lead_out(['ok' => false, 'error' => 'delivery'], $theme, 502);
}

} // function_exists('labazan_lead_run')
