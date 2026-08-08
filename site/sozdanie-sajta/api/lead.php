<?php
// Тонкая обёртка приёма формы посадочной «создание сайта под заявки» (Рамазан Муталиев).
// Вся логика и безопасность — в lead-core.php (канон lead-kit): origin/referer, honeypot,
// rate-limit, обязательное согласие (152-ФЗ), санитайз, mail() РФ.
//
// 152-ФЗ: ПД граждан РФ идут ТОЛЬКО в российский приёмник — почта на домене (сервер Beget в РФ).
// На диске не хранятся, только пересылка. Трансграничной передачи ПД нет. В Telegram уходит
// ТОЛЬКО статичное уведомление «пришёл лид» без единого поля формы (не ПД).
//
// Секреты — в отдельном lead-config.php рядом (в .gitignore, создаётся на сервере).
// В репозитории только lead-config.sample.php. Без конфига форма отвечает «not_configured».

$LEAD_EMAIL = '';
$LEAD_EMAIL_FROM = '';
$LEAD_HOST_LABEL = '';
// Уведомление о лиде (не канал доставки ПД): статичный пинг «пришёл лид».
// $TG_BOT_TOKEN/$TG_CHAT_ID — свои ключи; $BOT_TOKEN/$CHAT_ID — v1-имена из общего конфига
// основного сайта (переиспользуем их, если своих нет). Ниже берётся первый непустой.
$TG_BOT_TOKEN = '';
$TG_CHAT_ID = '';
$BOT_TOKEN = '';
$CHAT_ID = '';
// Поиск конфига секретов по порядку. Сначала свой конфиг лендинга (приоритет), затем конфиг
// ОСНОВНОГО САЙТА уровнями выше (public_html/api/ или public_html/) — чтобы посадочная
// переиспользовала уже настроенный $LEAD_EMAIL основного сайта, если отдельного конфига нет.
// Пути фиксированные (не из пользовательского ввода) — LFI нет. Реальные конфиги в .gitignore.
foreach ([
    __DIR__ . '/lead-config.php',           // sozdanie-sajta/api/lead-config.php  (свой, приоритет)
    __DIR__ . '/../lead-config.php',         // sozdanie-sajta/lead-config.php
    __DIR__ . '/../../api/lead-config.php',  // public_html/api/lead-config.php     (основной сайт)
    __DIR__ . '/../../lead-config.php',      // public_html/lead-config.php
] as $cfg) {
    if (is_file($cfg)) { require $cfg; break; }
}

require __DIR__ . '/lead-core.php';

// Источник заявки: скрытое поле source → строка в письме. Строго whitelist слаг → подпись,
// произвольный ввод в тело письма не попадает.
$LEAD_SOURCE_LABELS = [
    'sozdanie-sajta' => 'Посадочная «создание сайта»',
];

labazan_lead_run([
    // Боевой адрес — подстраница основного домена: labazan.ru/sozdanie-sajta/ (осознанно, не
    // поддомен: один счётчик Метрики собирает аудитории по всему домену для ретаргета, SEO-вес в
    // один домен, доверие к бренду). Тех-поддомены *.beget.tech разрешены ядром автоматически
    // (для тестовой заливки). Без Origin/Referer не блокируем, чтобы не терять живой лид.
    'allowed_hosts'   => ['labazan.ru', 'www.labazan.ru'],
    'host_label'      => $LEAD_HOST_LABEL !== '' ? $LEAD_HOST_LABEL : 'labazan.ru/sozdanie-sajta',
    'rl_bucket'       => 'sozdanie_sajta_lead_rl',
    'allowed_goals'   => ['Разведка ниши'],
    'default_goal'    => 'Разведка ниши',
    'theme'           => 'dark', // страница-ответ без JS в тёмной гамме формы
    'build_details'   => function ($post) use ($LEAD_SOURCE_LABELS) {
        $slug  = lead_clean($post['source'] ?? '', 40);
        $label = $LEAD_SOURCE_LABELS[$slug] ?? 'посадочная';
        return 'Источник: ' . $label;
    },
    'accept_telegram' => true, // поле «Телефон или Telegram»; доставка всё равно только в РФ-почту
    'lead_email'      => $LEAD_EMAIL,
    'lead_email_from' => $LEAD_EMAIL_FROM,
    // Внешние CRM/n8n-каналы для этой самостоятельной посадочной не используем.
    'n8n_url'         => '',
    'n8n_secret'      => '',
    'ingest_key'      => '',
    // Статичный пинг «пришёл лид» (без ПД). Берём свои ключи, иначе v1-имена общего конфига.
    // Пусто в обоих = выключено.
    'tg_bot_token'    => $TG_BOT_TOKEN !== '' ? $TG_BOT_TOKEN : $BOT_TOKEN,
    'tg_chat_id'      => $TG_CHAT_ID !== '' ? $TG_CHAT_ID : $CHAT_ID,
    'tg_ping_text'    => 'Новый лид с посадочной «создание сайта». Детали на почте.',
]);
