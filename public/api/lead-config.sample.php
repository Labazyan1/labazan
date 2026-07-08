<?php
// ШАБЛОН. На сервере Beget скопируй этот файл в lead-config.php (без .sample)
// и впиши свои значения. Файл lead.php подхватит их автоматически.
//
// Где взять:
//   BOT_TOKEN — у @BotFather после /newbot
//   CHAT_ID   — твой числовой id (узнать у @userinfobot). Не забудь нажать
//               Start у своего бота, иначе он не сможет тебе писать.

$BOT_TOKEN = '123456789:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
$CHAT_ID   = '123456789';

// --- Единый приёмник лидов (n8n) — необязательно ---
// Если заполнить URL, валидный лид дополнительно уходит в n8n (fire-and-forget),
// не влияя на отправку в Telegram. Пусто = дублирование выключено.
//   N8N_WEBHOOK_URL    — прод: https://n8n.labazan.ru/webhook/lead-intake
//                        локально: http://localhost:5678/webhook/lead-intake
//   N8N_WEBHOOK_SECRET — общий секрет, workflow сверяет его с заголовком X-Webhook-Secret
$N8N_WEBHOOK_URL    = '';
$N8N_WEBHOOK_SECRET = '';
