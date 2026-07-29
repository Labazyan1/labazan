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
// Прямой CRM-ingest (n8n выведен из контура). Заданы в серверном lead-config.php.
// $LEAD_HOST_LABEL — необязательный override подписи источника (бета ставит 'beta.labazan.ru',
// чтобы отделять тестовые заявки от прода; прод оставляет пусто → 'labazan.ru').
$CRM_INGEST_URL = '';
$CRM_INGEST_KEY = '';
$LEAD_HOST_LABEL = '';
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

// Источник заявки: скрытое поле source формы → строка «Источник страницы» в письме и в n8n.
// Строго whitelist слаг → русская подпись: произвольный ввод в тело письма не попадает.
// Значения синхронны со скрытыми полями форм (glavnaya + три подстраницы).
$LEAD_SOURCE_LABELS = [
    'glavnaya'            => 'Главная',
    'nuzhen-li-sajt'      => 'Нужен ли сайт',
    'pochemu-net-zayavok' => 'Почему нет заявок',
    'okupitsya-reklama'   => 'Окупается ли реклама',
    'kalkulyator-reklamy' => 'Калькулятор рекламы',
    'teryayutsya-zayavki' => 'Приём заявок',
    'razvedka'            => 'Разведка',
    'zapusk'             => 'Запуск',
    'vedenie'            => 'Ведение и обработка заявок',
];

labazan_lead_run([
    'allowed_hosts'   => ['labazan.ru', 'www.labazan.ru', 'beta.labazan.ru'],
    'host_label'      => $LEAD_HOST_LABEL !== '' ? $LEAD_HOST_LABEL : 'labazan.ru',
    'rl_bucket'       => 'labazan_v2_lead_rl',
    // Бесплатная форма метится нейтрально; платные ступени услуг — своими целями (страницы
    // /razvedka, /zapusk, /vedenie). Значения строго из этого списка (иначе default_goal).
    'allowed_goals'   => ['Бесплатный разбор', 'Разведка ниши', 'Запуск', 'Ведение и обработка заявок'],
    'default_goal'    => 'Бесплатный разбор',
    'theme'           => 'light',
    // Источник страницы отдельной строкой в письме/CRM (см. whitelist выше).
    'build_details'   => function ($post) use ($LEAD_SOURCE_LABELS) {
        // Источник — ТОЛЬКО подпись из whitelist (произвольный ввод в тело не выводим).
        $slug  = lead_clean($post['source'] ?? '', 40);
        $label = $LEAD_SOURCE_LABELS[$slug] ?? 'неизвестно';
        $out = 'Источник страницы: ' . $label;
        // Снимок калькулятора (не ПД: только цифры/вердикт). Санитайзим, схлопываем пробелы
        // и переводы строк в один пробел (поле машинное, однострочное) и ограничиваем длину:
        // идёт лишь в тело письма (plain text), не в тему/заголовки → ни инъекции, ни подделки строк.
        $calc = lead_clean($post['calc'] ?? '', 300);
        $calc = trim(preg_replace('/\s+/u', ' ', $calc));
        if ($calc !== '') { $out .= "\nРасчёт: " . $calc; }
        return $out;
    },
    'accept_telegram' => true, // поле формы «Телефон или Telegram»; доставка всё равно только в РФ
    'lead_email'      => $LEAD_EMAIL,
    'lead_email_from' => $LEAD_EMAIL_FROM,
    // Приёмник: прямой CRM-ingest, если задан (Bearer-ключ), иначе прежний n8n-вебхук.
    'n8n_url'         => $CRM_INGEST_URL !== '' ? $CRM_INGEST_URL : $N8N_WEBHOOK_URL,
    'n8n_secret'      => $N8N_WEBHOOK_SECRET,
    'ingest_key'      => $CRM_INGEST_KEY,
    // статичный пинг «пришёл лид» (без ПД): отдельный бот, если задан, иначе текущий v1-бот
    'tg_bot_token'    => $TG_BOT_TOKEN !== '' ? $TG_BOT_TOKEN : $BOT_TOKEN,
    'tg_chat_id'      => $TG_CHAT_ID !== '' ? $TG_CHAT_ID : $CHAT_ID,
]);
