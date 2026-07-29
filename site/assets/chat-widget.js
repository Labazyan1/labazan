/* ── Чат-виджет ИИ-консультанта labazan.ru ───────────────────────────────────
   Самодостаточный: инжектит свои стили (chat-widget.css рядом) и разметку —
   подключение на странице = одна строка <script src="assets/chat-widget.js" defer>.

   Бэкенд — bot.labazan.ru (YandexGPT в РФ, защита от инъекций на сервере).
   ПД собирает ТОЛЬКО мини-форма заявки (consent + honeypot) в обход LLM;
   сам чат ПД не запрашивает. Диалог зеркалится в CRM «Переписку».

   Оператор может перехватить диалог из CRM: бот замолкает (ответ silent),
   а реплики оператора приезжают поллингом POST /poll (раз в 5 с) и показываются
   в этом же окне с меткой «Менеджер». sessionId — crypto.randomUUID (знание id =
   доступ к репликам своей сессии, подобрать чужой нельзя) — живёт в sessionStorage,
   чтобы диалог переживал переходы между страницами (одна вкладка = одна сессия). */

(function () {
  'use strict';
  if (window.__chatWidgetInit) return;
  window.__chatWidgetInit = true;

  var BOT_URL = (window.LABAZAN_BOT_URL || 'https://bot.labazan.ru').replace(/\/+$/, '');
  var EMAIL = 'info@labazan.ru';
  var BRAND = 'Лабазан';
  var GREETING = 'Здравствуйте! Подскажу по услугам, срокам и ценам агентства. Что вас интересует?';
  var POLL_MS = 5000;
  var STORE_KEY = 'labazanChat'; // sessionStorage: { sessionId, history, engaged }

  // ── стили: <link> на css рядом со скриптом ──
  var scriptSrc = (document.currentScript && document.currentScript.src) || 'assets/chat-widget.js';
  var link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = scriptSrc.replace(/chat-widget\.js.*$/, 'chat-widget.css');
  document.head.appendChild(link);

  // ── состояние сессии (переживает переходы между страницами в одной вкладке) ──
  function loadState() {
    try {
      var raw = sessionStorage.getItem(STORE_KEY);
      if (!raw) return null;
      var s = JSON.parse(raw);
      if (s && typeof s.sessionId === 'string' && Array.isArray(s.history)) return s;
    } catch (e) { /* приватный режим/квота — работаем без персиста */ }
    return null;
  }
  function saveState() {
    try {
      sessionStorage.setItem(STORE_KEY, JSON.stringify({ sessionId: sessionId, history: history, engaged: engaged, calc: calcCtx }));
    } catch (e) { /* ок, просто не переживём переход */ }
  }

  var stored = loadState();
  var sessionId = (stored && stored.sessionId) ||
    ((window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2));
  var history = (stored && stored.history) || []; // {role:'user'|'assistant'|'operator', content}
  var engaged = !!(stored && stored.engaged); // был ли обмен → включает поллинг
  // Числовой контекст расчёта (фиксируется в момент тапа по превью, живёт с сессией):
  // уходит с каждым /chat, сервер валидирует диапазоны (fail-closed) и подставляет
  // СВОЙ текст с этими числами в промпт — бот называет цифры клиента на переспрос.
  var calcCtx = (stored && stored.calc) || null;
  var greeted = false;
  var busy = false;
  var seenOps = {}; // дедуп реплик оператора по id (окно между выдачей и ack)

  var MAILTO = 'mailto:' + EMAIL;

  // ── разметка ──
  var root = document.createElement('div');
  root.innerHTML =
    '<button class="chat-toggle" type="button" aria-expanded="false" aria-controls="chat-panel" aria-label="Открыть чат с консультантом" title="Задать вопрос консультанту">' +
      '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H8l-4 4V5a1 1 0 0 1 1-1Zm3 5v2h10V9H7Zm0 4v2h7v-2H7Z"/></svg>' +
    '</button>' +
    '<div id="chat-panel" class="chat-panel" role="dialog" aria-modal="true" aria-label="Чат с консультантом ' + BRAND + '" hidden>' +
      '<div class="chat-head">' +
        '<div><strong>Консультант ' + BRAND + '</strong><span>Отвечаем по услугам и ценам</span></div>' +
        '<button type="button" class="chat-close" aria-label="Закрыть чат" title="Закрыть">✕</button>' +
      '</div>' +
      '<div class="chat-log" id="chat-log" role="log" aria-live="polite" aria-label="История переписки"></div>' +
      '<p class="chat-status" id="chat-status" role="status" aria-live="polite" hidden></p>' +
      '<form class="chat-lead" id="chat-lead" hidden novalidate>' +
        '<p class="chat-lead-title">Оставьте заявку — свяжемся с ответом и деталями:</p>' +
        '<label class="chat-field"><span>Имя</span><input type="text" name="name" autocomplete="name" maxlength="120" required /></label>' +
        '<label class="chat-field"><span>Телефон или email</span><input type="text" name="contact" autocomplete="tel" maxlength="160" required /></label>' +
        '<div class="chat-hp" aria-hidden="true"><label>Компания<input type="text" name="company" tabindex="-1" autocomplete="off" /></label></div>' +
        '<label class="chat-consent"><input type="checkbox" name="consent" value="1" required />' +
          '<span>Я согласен на обработку персональных данных согласно <a href="/privacy/" target="_blank" rel="noopener">политике обработки персональных данных</a> в соответствии со 152-ФЗ.</span></label>' +
        '<button type="submit" class="chat-send chat-lead-submit">Отправить заявку</button>' +
        '<button type="button" class="chat-lead-cancel" id="chat-lead-cancel">← Вернуться к чату</button>' +
      '</form>' +
      '<form class="chat-input" id="chat-input">' +
        '<label class="chat-sr" for="chat-msg">Ваш вопрос</label>' +
        '<input id="chat-msg" name="message" type="text" maxlength="2000" autocomplete="off" placeholder="Спросите про услугу, срок или цену…" required />' +
        '<button type="submit" class="chat-send" aria-label="Отправить"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 20.5 21 12 3 3.5 6 12l-3 8.5Z"/></svg></button>' +
      '</form>' +
    '</div>';
  while (root.firstChild) document.body.appendChild(root.firstChild);

  var toggle = document.querySelector('.chat-toggle');
  var panel = document.getElementById('chat-panel');
  var log = document.getElementById('chat-log');
  var statusEl = document.getElementById('chat-status');
  var inputForm = document.getElementById('chat-input');
  var msgInput = document.getElementById('chat-msg');
  var leadForm = document.getElementById('chat-lead');
  var closeBtn = panel.querySelector('.chat-close');

  function addMsg(text, who) {
    var el = document.createElement('div');
    el.className = 'chat-msg ' + who;
    if (who === 'operator') {
      var tag = document.createElement('span');
      tag.className = 'chat-msg-tag';
      tag.textContent = 'Менеджер';
      el.appendChild(tag);
      el.appendChild(document.createTextNode(text));
    } else {
      el.textContent = text;
    }
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
  }

  function showStatus(html, isErr) {
    statusEl.innerHTML = html;
    statusEl.classList.toggle('err', !!isErr);
    statusEl.hidden = false;
  }
  function clearStatus() { statusEl.hidden = true; statusEl.innerHTML = ''; }

  var typingEl = null;
  function showTyping(on) {
    if (on) {
      if (typingEl) return;
      typingEl = document.createElement('div');
      typingEl.className = 'chat-typing';
      typingEl.setAttribute('aria-label', 'Консультант печатает');
      typingEl.innerHTML = '<i></i><i></i><i></i>';
      log.appendChild(typingEl);
      log.scrollTop = log.scrollHeight;
    } else if (typingEl) {
      typingEl.remove(); typingEl = null;
    }
  }

  // Восстановить визуальный лог после перехода между страницами.
  function restoreLog() {
    if (!history.length) return;
    greeted = true;
    addMsg(GREETING, 'bot');
    for (var i = 0; i < history.length; i++) {
      var h = history[i];
      addMsg(h.content, h.role === 'user' ? 'user' : (h.role === 'operator' ? 'operator' : 'bot'));
    }
  }
  restoreLog();

  function focusable() {
    return [].slice.call(panel.querySelectorAll('button:not([disabled]),input:not([disabled]),a[href]'))
      .filter(function (el) { return el.offsetParent !== null; });
  }

  function openPanel(open) {
    if (open) {
      panel.removeAttribute('hidden');
      toggle.setAttribute('aria-expanded', 'true');
      toggle.classList.remove('has-unread');
      if (!greeted) { greeted = true; addMsg(GREETING, 'bot'); }
      if (msgInput) msgInput.focus();
    } else {
      panel.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    }
  }

  toggle.addEventListener('click', function () { openPanel(panel.hasAttribute('hidden')); });
  if (closeBtn) closeBtn.addEventListener('click', function () { openPanel(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hasAttribute('hidden')) openPanel(false);
  });
  panel.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab') return;
    var f = focusable();
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  function offerLead() {
    if (!leadForm.hasAttribute('hidden')) return;
    leadForm.removeAttribute('hidden');
    var nameInput = leadForm.querySelector('input[name="name"]');
    if (nameInput) nameInput.focus();
  }

  var leadCancel = document.getElementById('chat-lead-cancel');
  if (leadCancel) leadCancel.addEventListener('click', function () {
    leadForm.setAttribute('hidden', '');
    clearStatus();
    addMsg('Хорошо, спрашивайте дальше — я на связи.', 'bot');
    if (msgInput) msgInput.focus();
  });

  // ── Поллинг реплик оператора (труба CRM → бот → виджет) ──
  var polling = false;
  function pollOnce() {
    if (polling || !engaged || document.hidden || !BOT_URL) return;
    polling = true;
    fetch(BOT_URL + '/poll', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sessionId: sessionId })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok || !Array.isArray(data.messages)) return;
      var got = false;
      for (var i = 0; i < data.messages.length; i++) {
        var m = data.messages[i];
        if (!m || typeof m.id !== 'string' || typeof m.text !== 'string' || seenOps[m.id]) continue;
        seenOps[m.id] = true;
        addMsg(m.text, 'operator');
        history.push({ role: 'operator', content: m.text });
        got = true;
      }
      if (got) {
        if (history.length > 20) history = history.slice(-20);
        saveState();
        clearStatus();
        if (panel.hasAttribute('hidden')) toggle.classList.add('has-unread');
      }
    }).catch(function () { /* сеть мигнула — следующий тик добёрет */ })
      .then(function () { polling = false; });
  }
  setInterval(pollOnce, POLL_MS);

  // ── Отправка вопроса в /chat ──
  inputForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy || !BOT_URL) { if (!BOT_URL) showStatus('Чат временно недоступен. Напишите нам: <a href="' + MAILTO + '">' + EMAIL + '</a>', true); return; }
    var text = (msgInput.value || '').trim();
    if (!text) return;
    busy = true; clearStatus();
    addMsg(text, 'user');
    history.push({ role: 'user', content: text });
    if (history.length > 20) history = history.slice(-20);
    engaged = true;
    saveState();
    msgInput.value = '';
    inputForm.querySelector('.chat-send').disabled = true;
    showTyping(true);

    // history для LLM: только user/assistant (реплики оператора — не контекст модели)
    var llmHistory = [];
    for (var i = 0; i < history.length; i++) {
      if (history[i].role === 'user' || history[i].role === 'assistant') llmHistory.push(history[i]);
    }
    if (llmHistory.length > 10) llmHistory = llmHistory.slice(-10);

    var chatBody = { sessionId: sessionId, message: text, history: llmHistory };
    if (calcCtx) chatBody.calc = calcCtx;
    fetch(BOT_URL + '/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(chatBody)
    }).then(function (r) { return r.json(); }).then(function (data) {
      showTyping(false);
      if (data && data.ok && data.silent) {
        // Оператор в диалоге: бот молчит, ответ человека приедет поллингом.
        showStatus('Менеджер подключился к диалогу — ответит здесь.');
        pollOnce();
      } else if (data && data.ok && data.reply) {
        addMsg(data.reply, 'bot');
        history.push({ role: 'assistant', content: data.reply });
        saveState();
        if (data.escalate) offerLead();
      } else {
        throw new Error('bad_reply');
      }
    }).catch(function () {
      showTyping(false);
      showStatus('Не удалось получить ответ. Напишите нам: <a href="' + MAILTO + '">' + EMAIL + '</a>', true);
    }).then(function () {
      busy = false;
      inputForm.querySelector('.chat-send').disabled = false;
      if (msgInput) msgInput.focus();
    });
  });

  // ── Превью-оффер от калькуляторов ──────────────────────────────────
  // Страницы с расчётом шлют CustomEvent 'labazan:calc' c detail:
  //   { tool, preview, full, valid } — preview = обрезанная завязка,
  //   full = развёрнутое сообщение с числами клиента (уйдёт в чат как
  //   реплика бота и в history как assistant → LLM видит контекст расчёта).
  // Показ: с задержкой после последнего пересчёта, один раз за сессию
  // (тап или ✕ = больше не пристаём), только при закрытой панели.
  var OFFER_DONE_KEY = 'labazanChatOfferDone';
  var offerData = null;
  var offerEl = null;
  var offerTimer = null;
  var OFFER_DELAY = 2000;

  function offerDone() {
    try { return sessionStorage.getItem(OFFER_DONE_KEY) === '1'; } catch (e) { return false; }
  }
  function markOfferDone() {
    try { sessionStorage.setItem(OFFER_DONE_KEY, '1'); } catch (e) { /* ок */ }
  }

  function hideOffer() {
    if (!offerEl) return;
    offerEl.remove();
    offerEl = null;
  }

  function openFromOffer() {
    if (!offerData) return;
    markOfferDone();
    hideOffer();
    // Полное сообщение с числами клиента вместо стандартного приветствия.
    greeted = true;
    openPanel(true);
    addMsg(offerData.full, 'bot');
    history.push({ role: 'assistant', content: offerData.full });
    if (history.length > 20) history = history.slice(-20);
    engaged = true; // поллинг реплик оператора включается сразу
    if (offerData.calc) calcCtx = offerData.calc; // числа расчёта закрепляются за сессией
    saveState();
    try { if (typeof window.labazanGoal === 'function') window.labazanGoal('chat_calc_open', { source: offerData.tool }); } catch (e) { /* ок */ }
  }

  function showOffer() {
    if (!offerData || offerDone() || !panel.hasAttribute('hidden')) return;
    if (offerEl) { // уже видно: обновить текст свежим расчётом
      offerEl.querySelector('.chat-offer-text').textContent = offerData.preview;
      return;
    }
    offerEl = document.createElement('div');
    offerEl.className = 'chat-offer';
    offerEl.setAttribute('role', 'group');
    offerEl.setAttribute('aria-label', 'Сообщение консультанта ' + BRAND);
    var ava = document.createElement('span');
    ava.className = 'chat-offer-ava';
    ava.setAttribute('aria-hidden', 'true');
    ava.textContent = 'Л';
    var body = document.createElement('span');
    body.className = 'chat-offer-body';
    var who = document.createElement('span');
    who.className = 'chat-offer-who';
    who.textContent = 'Консультант';
    var text = document.createElement('button');
    text.type = 'button';
    text.className = 'chat-offer-text';
    text.textContent = offerData.preview;
    text.setAttribute('aria-label', 'Открыть чат и дочитать: ' + offerData.preview);
    text.addEventListener('click', openFromOffer);
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'chat-offer-close';
    close.setAttribute('aria-label', 'Скрыть сообщение');
    close.textContent = '✕';
    close.addEventListener('click', function () { markOfferDone(); hideOffer(); });
    body.appendChild(who);
    body.appendChild(text);
    offerEl.appendChild(ava);
    offerEl.appendChild(body);
    offerEl.appendChild(close);
    document.body.appendChild(offerEl);
    requestAnimationFrame(function () { if (offerEl) offerEl.classList.add('is-visible'); });
  }

  document.addEventListener('labazan:calc', function (e) {
    var d = e && e.detail;
    if (!d || typeof d.preview !== 'string' || typeof d.full !== 'string') return;
    if (offerTimer) clearTimeout(offerTimer);
    if (d.valid === false) { offerData = null; hideOffer(); return; }
    offerData = d;
    if (offerDone()) return;
    offerTimer = setTimeout(showOffer, OFFER_DELAY);
  });

  // ── Отправка заявки в /escalate (ПД, мимо LLM) ──
  leadForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy || !BOT_URL) return;
    var name = leadForm.querySelector('input[name="name"]').value.trim();
    var contact = leadForm.querySelector('input[name="contact"]').value.trim();
    var company = leadForm.querySelector('input[name="company"]').value;
    var consent = leadForm.querySelector('input[name="consent"]').checked;
    if (!name || !contact) { showStatus('Заполните имя и контакт.', true); return; }
    if (!consent) { showStatus('Отметьте согласие на обработку персональных данных.', true); return; }
    busy = true; clearStatus();
    var submit = leadForm.querySelector('.chat-lead-submit');
    submit.disabled = true;

    fetch(BOT_URL + '/escalate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: name, contact: contact, message: 'Заявка из чата', consent: '1', company: company })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data && data.ok) {
        leadForm.setAttribute('hidden', '');
        addMsg('Спасибо! Заявка принята, скоро свяжемся.', 'bot');
        clearStatus();
      } else {
        throw new Error('escalate_failed');
      }
    }).catch(function () {
      showStatus('Не удалось отправить заявку. Напишите нам: <a href="' + MAILTO + '">' + EMAIL + '</a>', true);
    }).then(function () {
      busy = false; submit.disabled = false;
    });
  });
})();
