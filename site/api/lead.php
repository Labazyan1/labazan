<?php
// Тонкая обёртка приёма формы главной labazan-site-v2 «Начнём с разведки».
// Вся логика и безопасность — в lead-core.php (канон lead-kit): origin/referer, honeypot,
// rate-limit, обязательное согласие, санитайз, mail() РФ + опц. n8n→РФ-CRM.
//
// 152-ФЗ: ПД граждан РФ идут ТОЛЬКО в российские приёмники (почта на домене, сервер Beget в РФ,
// и n8n на РФ-ВМ → РФ-CRM). На диске не хранятся — только пересылка. Трансграничной передачи ПД нет.
// Telegram-ник принимается лишь как ТЕКСТ контакта в письме; сайт по нему ничего никуда не шлёт.
// В Telegram уходит ТОЛЬКО статичное уведомление «пришёл лид» без единого поля формы (не ПД).
//
// Секреты — в отдельном lead-config.php рядом (в .gitignore, создаётся на сервере).
// В репозитории только lead-config.sample.php. Без конфига форма отвечает «not_configured».

$LEAD_EMAIL = '';
$LEAD_EMAIL_FROM = '';
$N8N_WEBHOOK_URL = '';
$N8N_WEBHOOK_SECRET = '';
// Уведомление о лиде. По умолчанию берём уже существующий бот (v1-ключи $BOT_TOKEN/$CHAT_ID
// в серверном конфиге); $TG_BOT_TOKEN/$TG_CHAT_ID — необязательный override под отдельного бота.
$BOT_TOKEN = '';
$CHAT_ID = '';
$TG_BOT_TOKEN = '';
$TG_CHAT_ID = '';
foreach ([__DIR__ . '/lead-config.php', __DIR__ . '/../lead-config.php'] as $cfg) {
    if (is_file($cfg)) { require $cfg; break; }
}

require __DIR__ . '/lead-core.php';

labazan_lead_run([
    'allowed_hosts'   => ['labazan.ru', 'www.labazan.ru', 'beta.labazan.ru'],
    'host_label'      => 'labazan.ru',
    'rl_bucket'       => 'labazan_v2_lead_rl',
    'allowed_goals'   => ['Разведка'],
    'default_goal'    => 'Заявка с сайта',
    'theme'           => 'light',
    'accept_telegram' => true, // поле формы «Телефон или Telegram»; доставка всё равно только в РФ
    'lead_email'      => $LEAD_EMAIL,
    'lead_email_from' => $LEAD_EMAIL_FROM,
    'n8n_url'         => $N8N_WEBHOOK_URL,
    'n8n_secret'      => $N8N_WEBHOOK_SECRET,
    // статичный пинг «пришёл лид» (без ПД): отдельный бот, если задан, иначе текущий v1-бот
    'tg_bot_token'    => $TG_BOT_TOKEN !== '' ? $TG_BOT_TOKEN : $BOT_TOKEN,
    'tg_chat_id'      => $TG_CHAT_ID !== '' ? $TG_CHAT_ID : $CHAT_ID,
]);
