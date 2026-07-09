<?php
// ШАБЛОН. На сервере Beget скопируй этот файл в lead-config.php (без .sample)
// и впиши свои значения. Файл lead.php подхватит их автоматически.

// --- ОСНОВНОЙ приёмник: российская почта (152-ФЗ) ---
// Заявки уходят на почту домена через сервер Beget в РФ — ПД не покидают РФ,
// трансграничной передачи нет. Это рекомендуемый и достаточный канал.
//   LEAD_EMAIL      — куда слать заявки. Заведи ящик в панели Beget (напр. lead@labazan.ru).
//                     Читай через веб-почту Beget или РФ-почту (Яндекс/Mail), НЕ пересылай в Gmail.
//   LEAD_EMAIL_FROM — адрес отправителя на том же домене (напр. noreply@labazan.ru),
//                     чтобы письма не попадали в спам. Можно оставить пустым.
$LEAD_EMAIL      = 'lead@labazan.ru';
$LEAD_EMAIL_FROM = 'noreply@labazan.ru';

// --- Telegram — НЕОБЯЗАТЕЛЬНО и с оговоркой по 152-ФЗ ---
// ВНИМАНИЕ: включение Telegram = передача ПД на зарубежные серверы (трансграничная
// передача). Если оставляешь пустым — работает только email (РФ), трансграничной
// передачи нет. Заполняй Telegram только осознанно (см. docs/rkn-notification.md).
//   BOT_TOKEN — у @BotFather после /newbot
//   CHAT_ID   — твой числовой id (узнать у @userinfobot). Нажми Start своему боту.
$BOT_TOKEN = '';
$CHAT_ID   = '';

// --- Единый приёмник лидов (n8n) — необязательно ---
// Если заполнить URL, валидный лид дополнительно уходит в n8n (fire-and-forget),
// не влияя на отправку в Telegram. Пусто = дублирование выключено.
//   N8N_WEBHOOK_URL    — прод: https://n8n.labazan.ru/webhook/lead-intake
//                        локально: http://localhost:5678/webhook/lead-intake
//   N8N_WEBHOOK_SECRET — общий секрет, workflow сверяет его с заголовком X-Webhook-Secret
$N8N_WEBHOOK_URL    = '';
$N8N_WEBHOOK_SECRET = '';
