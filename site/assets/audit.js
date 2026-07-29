/* ══════════════════════════════════════════════════════════════════
   Лабазан — автопроверка сайта (страница «Почему нет заявок»).
   Перенос логики из audit-labazan (AuditTool) в ванильный скрипт без
   зависимостей от lead-kit. Дёргает /api/check-html.php (разбор разметки),
   затем /api/check-speed.php (скорость через PSI). Сам SSRF-фильтр и
   проверки — на сервере; здесь только ввод, безопасный рендер и честные
   ветки ошибок.

   ЖЕЛЕЗНЫЙ ПРИНЦИП: если проверить нельзя (сайт закрыт, собирается
   скриптами, недоступен, таймаут, битый SSL) — показываем честное
   «не смог автоматически, напишите — посмотрю руками», а НЕ голую ошибку
   и НЕ выдуманный результат.

   XSS: label/detail/verdict.text приходят из JSON, но внутри может быть
   текст, извлечённый с ПРОВЕРЯЕМОГО (чужого) сайта. ЛЮБОЕ значение из
   ответа рендерим ТОЛЬКО через textContent/createTextNode. innerHTML нигде.
   ══════════════════════════════════════════════════════════════════ */
(() => {
  const root = document.querySelector('[data-audit]');
  if (!root) return;

  const form = root.querySelector('[data-audit-form]');
  const input = root.querySelector('input[name="url"]');
  const errorEl = root.querySelector('[data-audit-fielderror]');
  const statusEl = root.querySelector('[data-audit-status]');
  const resultEl = root.querySelector('[data-audit-result]');
  const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
  if (!form || !input || !resultEl) return;

  const API_HTML = '../api/check-html.php';
  const API_SPEED = '../api/check-speed.php';

  const ICONS = { ok: '✓', bad: '✕', warn: '!' };
  const WORDS = { ok: 'В порядке', bad: 'Критично', warn: 'Стоит поправить' };
  const SR = { ok: 'пройдено', bad: 'не пройдено', warn: 'частично' };
  const ORDER = { bad: 0, warn: 1, ok: 2 }; // приоритет: сначала критичное

  // Источник страницы — уходит в цель Метрики отдельным параметром (как в lead-form.js).
  function source() {
    const sf = document.querySelector('[data-lead-form] input[name="source"]');
    return sf && sf.value ? sf.value : 'pochemu-net-zayavok';
  }
  function goal(name) {
    if (typeof window.labazanGoal === 'function') {
      try { window.labazanGoal(name, { source: source() }); } catch (e) {}
    }
  }

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text; // не innerHTML
    return n;
  }

  function setStatus(text, tone) {
    if (!statusEl) return;
    if (!text) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.removeAttribute('role');
      statusEl.removeAttribute('data-tone');
      return;
    }
    statusEl.hidden = false;
    statusEl.dataset.tone = tone || '';
    statusEl.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    statusEl.textContent = '';
    if (tone === 'loading') statusEl.appendChild(el('span', 'audit-spinner'));
    statusEl.appendChild(document.createTextNode(text));
  }

  function fieldError(msg) {
    if (!errorEl) return;
    if (!msg) {
      errorEl.hidden = true;
      errorEl.textContent = '';
      input.removeAttribute('aria-invalid');
      return;
    }
    errorEl.hidden = false;
    errorEl.textContent = msg;
    input.setAttribute('aria-invalid', 'true');
  }

  // Мягкая клиентская проверка «похоже на адрес» (сервер всё равно проверяет строго).
  function looksLikeUrl(v) {
    return /^https?:\/\/\S+/i.test(v)
      || /^[a-zA-Zа-яА-ЯёЁ0-9][a-zA-Zа-яА-ЯёЁ0-9.-]*\.[a-zA-Zа-яА-ЯёЁ]{2,}([/:?#].*)?$/u.test(v);
  }

  function countStatuses(checks) {
    const n = { ok: 0, bad: 0, warn: 0 };
    checks.forEach((c) => { if (n[c.status] !== undefined) n[c.status]++; });
    return n;
  }

  // Одна строка результата: иконка + метка + приоритет + пояснение. Всё через textContent.
  function renderRow(c) {
    const row = el('div', 'audit-row');
    row.dataset.status = c.status || '';
    const head = el('div', 'audit-row__head');
    const icon = el('span', 'audit-row__icon', ICONS[c.status] || '?');
    icon.setAttribute('aria-hidden', 'true');
    head.appendChild(icon);
    head.appendChild(el('span', 'sr-only', (SR[c.status] || '') + ': '));
    head.appendChild(el('span', 'audit-row__label', String(c.label || '')));
    const prio = el('span', 'audit-row__prio', WORDS[c.status] || '');
    prio.dataset.status = c.status || '';
    head.appendChild(prio);
    row.appendChild(head);
    if (c.detail) row.appendChild(el('p', 'audit-row__detail', String(c.detail)));
    return row;
  }

  function renderVerdict(data) {
    const v = data.verdict || {};
    const n = countStatuses(data.checks || []);
    const box = el('div', 'audit-verdict');
    box.dataset.tone = v.tone || '';
    box.appendChild(el('p', 'audit-verdict__text', String(v.text || 'Разбор готов.')));
    const counts = el('div', 'audit-verdict__counts');
    ['bad', 'warn', 'ok'].forEach((k) => {
      const s = el('span', 'audit-count', ICONS[k] + ' ' + n[k]);
      s.dataset.status = k;
      s.setAttribute('aria-hidden', 'true');
      counts.appendChild(s);
    });
    counts.appendChild(el('span', 'sr-only',
      'Критично: ' + n.bad + ', стоит поправить: ' + n.warn + ', в порядке: ' + n.ok));
    box.appendChild(counts);
    return box;
  }

  // Блок перехода на платную разведку + на бесплатный ручной разбор (форма ниже).
  function renderCta() {
    const cta = el('div', 'audit-cta');
    cta.appendChild(el('p', 'audit-cta__note',
      'Это быстрый автоматический разбор самой страницы. Рекламу и приём звонков смотрю руками.'));

    const line = el('p', 'audit-cta__line');
    line.appendChild(document.createTextNode('Нужен подробный ручной разбор с планом и приоритетами? Это платная разведка, 15–25 тыс ₽, сумма засчитывается в стоимость работы. '));
    const plan = document.createElement('a');
    plan.href = 'https://t.me/labazantg';
    plan.target = '_blank';
    plan.rel = 'noopener';
    plan.setAttribute('data-plan-link', ''); // цель click_plan ловит lead-form.js
    plan.textContent = 'Напишите, расскажу условия';
    line.appendChild(plan);
    cta.appendChild(line);

    const free = document.createElement('a');
    free.className = 'audit-cta__btn';
    free.href = '#lead';
    free.textContent = 'Бесплатный разбор пути клиента';
    cta.appendChild(free);
    return cta;
  }

  // Второй запрос — скорость через PSI. Идёт ПОСЛЕ разбора разметки, отдельным блоком.
  // Если сервис скорости недоступен/не настроен/исчерпан лимит — молча пропускаем
  // (основной разбор уже показан, техническую ошибку стороннего сервиса не показываем).
  function runSpeed(url, slot) {
    if (!url) return;
    const loading = el('div', 'audit-status');
    loading.dataset.tone = 'loading';
    loading.setAttribute('role', 'status');
    loading.appendChild(el('span', 'audit-spinner'));
    loading.appendChild(document.createTextNode('Проверяю скорость на телефоне…'));
    slot.appendChild(loading);

    const hasAbort = 'AbortController' in window;
    const controller = hasAbort ? new AbortController() : null;
    const timer = hasAbort ? setTimeout(() => controller.abort(), 45000) : null;

    const body = new FormData();
    body.append('url', url);

    fetch(API_SPEED, {
      method: 'POST',
      body: body,
      signal: controller ? controller.signal : undefined,
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json().catch(() => null).then((data) => ({ status: res.status, data: data })))
      .then((r) => {
        if (timer) clearTimeout(timer);
        slot.textContent = '';
        if (r.data && r.data.ok === true && r.data.checks && r.data.checks.length) {
          slot.appendChild(el('h3', 'audit-speed__title', 'Скорость на телефоне'));
          const rows = el('div', 'audit-rows');
          r.data.checks.forEach((c) => rows.appendChild(renderRow(c)));
          slot.appendChild(rows);
        }
      })
      .catch(() => {
        if (timer) clearTimeout(timer);
        slot.textContent = '';
      });
  }

  // Успех разбора разметки: вердикт + строки (по приоритету) + слот скорости + CTA.
  function showResult(data) {
    setStatus('', '');
    resultEl.textContent = '';
    resultEl.hidden = false;

    resultEl.appendChild(renderVerdict(data));

    const checks = (data.checks || []).slice().sort((a, b) => {
      const oa = ORDER[a.status] !== undefined ? ORDER[a.status] : 9;
      const ob = ORDER[b.status] !== undefined ? ORDER[b.status] : 9;
      return oa - ob;
    });
    const rows = el('div', 'audit-rows');
    checks.forEach((c) => rows.appendChild(renderRow(c)));
    resultEl.appendChild(rows);

    const speedSlot = el('div', 'audit-speed');
    resultEl.appendChild(speedSlot);

    resultEl.appendChild(renderCta());

    goal('audit_result');
    try { resultEl.setAttribute('tabindex', '-1'); resultEl.focus({ preventScroll: true }); } catch (e) {}
    offerChat(checks);
    runSpeed(data.url, speedSlot);
  }

  // Оффер чат-виджету после отрисовки разбора: превью «сообщения консультанта».
  // В полный текст идёт ТОЛЬКО наш label проверки (не detail: там бывают фрагменты
  // чужого сайта — их в контекст диалога не тащим). Числа клиента бот увидит в истории.
  function plural(n, one, few, many) {
    const m = Math.abs(n) % 100, d = m % 10;
    if (m > 10 && m < 20) return many;
    if (d > 1 && d < 5) return few;
    if (d === 1) return one;
    return many;
  }
  function offerChat(checks) {
    const n = countStatuses(checks);
    const total = n.bad + n.warn;
    if (total < 1) return; // всё зелёное: разбирать нечего, не пристаём
    const first = checks[0]; // отсортировано: сначала критичное
    const label = first && first.label ? String(first.label) : '';
    const probl = plural(total, 'проблему', 'проблемы', 'проблем');
    const opener = n.bad > 0
      ? 'Прошёлся по вашему сайту: нашёл ' + total + ' ' + probl + ', из них '
        + n.bad + ' ' + plural(n.bad, 'критичная', 'критичные', 'критичных') + '.'
      : 'Прошёлся по вашему сайту: нашёл ' + total + ' '
        + plural(total, 'место', 'места', 'мест') + ', которые стоит поправить.';
    const preview = total === 1
      ? 'Нашёл на вашем сайте проблему, которая съедает заявки...'
      : 'Нашёл ' + total + ' ' + probl + ' на вашем сайте, одна из них съедает больше остальных...';
    const detail = {
      tool: 'audit',
      valid: true,
      calc: { tool: 'audit', problems: total, critical: n.bad },
      preview: preview,
      full: opener
        + (label ? ' Сильнее всего по заявкам бьёт эта: «' + label + '».' : '')
        + ' Могу объяснить простыми словами, что это значит, что чинить в первую очередь и что это даст в заявках.'
        + ' Расскажите, что у вас за бизнес?',
    };
    try { document.dispatchEvent(new CustomEvent('labazan:calc', { detail })); } catch (e) {}
  }

  // Их сайт (недоступен/закрыт/не тот адрес): честное сообщение сервера + мостик на ручной
  // разбор. Оставить заявку можно и без успешной проверки.
  function showServerError(message) {
    resultEl.hidden = true;
    resultEl.textContent = '';
    setStatus('', '');
    const box = el('div', 'audit-honest');
    box.setAttribute('role', 'status');
    box.appendChild(el('p', 'audit-honest__msg', message));
    const link = document.createElement('a');
    link.className = 'audit-cta__btn';
    link.href = '#lead';
    link.textContent = 'Оставить заявку — посмотрю руками';
    box.appendChild(link);
    resultEl.hidden = false;
    resultEl.textContent = '';
    resultEl.appendChild(box);
    try { resultEl.setAttribute('tabindex', '-1'); resultEl.focus({ preventScroll: true }); } catch (e) {}
  }

  // Наш замер упал (нет связи с эндпоинтом / 5xx / сеть): НЕ показываем голую ошибку и НЕ
  // выдумываем результат — честный мостик на ручной разбор.
  function showDegrade() {
    showServerError('Автоматически проверить сейчас не получилось. Напишите или оставьте заявку — посмотрю сайт руками.');
  }

  function runCheck(url) {
    goal('audit_start');
    setStatus('Проверяю сайт… обычно занимает несколько секунд.', 'loading');
    resultEl.hidden = true;
    resultEl.textContent = '';
    if (submitBtn) submitBtn.disabled = true;

    const hasAbort = 'AbortController' in window;
    const controller = hasAbort ? new AbortController() : null;
    const timer = hasAbort ? setTimeout(() => controller.abort(), 20000) : null;

    const body = new FormData();
    body.append('url', url);

    fetch(API_HTML, {
      method: 'POST',
      body: body,
      signal: controller ? controller.signal : undefined,
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json().catch(() => null).then((data) => ({ status: res.status, data: data })))
      .then((r) => {
        if (timer) clearTimeout(timer);
        if (r.data && r.data.ok === true) {
          showResult(r.data);
        } else if (r.data && r.data.message) {
          showServerError(String(r.data.message)); // честное сообщение сервера
        } else {
          showDegrade(); // нет валидного JSON (превью без PHP и т.п.)
        }
      })
      .catch((err) => {
        if (timer) clearTimeout(timer);
        if (err && err.name === 'AbortError') {
          showServerError('Сайт отвечает слишком долго. Попробуйте ещё раз чуть позже или оставьте заявку — посмотрю руками.');
        } else {
          showDegrade();
        }
      })
      .finally(() => {
        if (submitBtn) submitBtn.disabled = false;
      });
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const v = (input.value || '').trim();
    fieldError('');
    if (v === '') { fieldError('Укажите адрес сайта.'); input.focus(); return; }
    if (v.length > 300) { fieldError('Слишком длинный адрес.'); input.focus(); return; }
    if (!looksLikeUrl(v)) { fieldError('Похоже, это не адрес сайта. Пример: mysite.ru'); input.focus(); return; }
    runCheck(v);
  });

  // Автозапуск из hero главной: GET /pochemu-net-zayavok/?url=… —
  // подставляем адрес, скроллим к проверке и запускаем сразу.
  try {
    const q = new URLSearchParams(window.location.search).get('url');
    if (q && q.trim() !== '') {
      input.value = q.trim().slice(0, 300);
      root.scrollIntoView({ behavior: 'auto', block: 'start' });
      form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  } catch (e) {}
})();
