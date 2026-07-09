# Деплой labazan.ru (Beget shared)

Статический сайт (Astro) + PHP-форма `public/api/lead.php`. Хостинг: Beget shared,
docroot = `public_html` домена labazan.ru.

**Каналы приёма заявок (152-ФЗ):**
- **ОСНОВНОЙ — российская почта** (`lead@labazan.ru`, сервер Beget в РФ). ПД не покидают РФ.
- **Telegram-бот + n8n-рельс → CRM «Пульс»** — ВЫКЛЮЧЕНЫ до ~24.07.2026 (пауза РКН по
  трансграничной передаче: Telegram=ОАЭ, Instagram=США). Включать по чек-листу в
  `docs/rkn-notification.md` (+ сменить текст согласия в `ContactForm.astro`).

> ⚠️ Пока идёт пауза РКН, боевой `lead-config.php` должен иметь **пустые** `BOT_TOKEN`,
> `CHAT_ID` и `N8N_WEBHOOK_URL` (если n8n-workflow шлёт Telegram-уведомление) — иначе ПД
> уйдут за границу до разрешения. Единственный активный канал сейчас — `LEAD_EMAIL`.

## Секрет формы: `lead-config.php` живёт ТОЛЬКО на сервере

`public_html/api/lead-config.php` содержит секреты (`BOT_TOKEN`, `CHAT_ID`,
`N8N_WEBHOOK_URL`, `N8N_WEBHOOK_SECRET`). Он:

- **не в git** (`.gitignore`);
- **не в деплой-архиве** — `npm run build` вычищает его из `dist/` (`scripts/strip-config.mjs`);
- заводится на Beget **один раз** вручную и дальше не трогается заливками.

Локальный `public/api/lead-config.php` — это **dev-конфиг** (для локального PHP-теста рельса,
`host.docker.internal`). Он НИКОГДА не должен уехать на прод — за этим следит strip-config.

Эталон значений — `public/api/lead-config.sample.php`.

**Боевой `lead-config.php` СЕЙЧАС (период паузы РКН, только РФ-почта):**
```php
<?php
$LEAD_EMAIL      = 'lead@labazan.ru';
$LEAD_EMAIL_FROM = 'noreply@labazan.ru';
$BOT_TOKEN = '';   // включить после ~24.07 + сменить текст согласия
$CHAT_ID   = '';
$N8N_WEBHOOK_URL    = '';   // включить, если n8n НЕ шлёт Telegram-уведомление в паузу
$N8N_WEBHOOK_SECRET = '';
```

**После разблокировки (~24.07):** вернуть `BOT_TOKEN`/`CHAT_ID` и
`N8N_WEBHOOK_URL=https://n8n.labazan.ru/webhook/lead-intake` (секрет `N8N_WEBHOOK_SECRET`
из `/opt/n8n/.env.prod` на прод-ВМ). РФ-почту оставить как основной канал.

**Предварительно (один раз):** в панели Beget → «Почта» завести ящик **lead@labazan.ru**
(читать через веб-почту Beget/РФ-почту, НЕ пересылать в Gmail) и желательно
**noreply@labazan.ru** для отправителя. При спаме — включить SPF/DKIM для домена в панели.

> ⚠️ Урок 08.07.2026: при распаковке архива файловый менеджер Beget может **пропустить
> перезапись** уже существующего `lead-config.php`. Раньше это грозило устаревшим конфигом.
> Теперь архив его не несёт вовсе → сервер сохраняет рабочий конфиг. Если конфиг на Beget
> устарел (форма даёт 502 / лид не доходит в CRM) — обновить `public_html/api/lead-config.php`
> **вручную** (перезаписать значениями из sample + боевые секреты).

## Сборка и упаковка

```bash
npm run build            # astro build + strip-config (чистит lead-config.php из dist)
python scripts/package.py  # → архив на Рабочем столе: labazan-site-YYYYMMDD-HHMM.zip
```

Архив — **одна корневая папка** `labazan-site/` (правило агентства), внутри содержимое `dist/`,
пути прямыми слэшами, без `lead-config.php`.

## Заливка на Beget

1. Загрузить архив в файловый менеджер Beget, извлечь.
2. Переместить **содержимое** папки `labazan-site/` в `public_html` (перезаписать файлы сайта).
3. `lead-config.php` на сервере не трогать (его в архиве нет).
4. Проверить: открыть labazan.ru, отправить тестовую заявку → **сейчас (пауза РКН)**
   должно прийти письмо на `lead@labazan.ru` (веб-почта Beget). Форма отвечает `ok:true`.
   После разблокировки (~24.07) добавится Telegram-уведомление и лид в CRM
   (`source = site:labazan.ru / <цель>`).

Временный показ — тех-поддомен Beget вида `*.beget.tech`.
