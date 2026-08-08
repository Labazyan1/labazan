/* ══════════════════════════════════════════════════════════════════
   Посадочная «создание сайта под заявки» · клиентская логика.
   Метрику грузит инлайн-сниппет в <head> (сразу, вне cookie-согласия).
   Здесь: отправка формы, цель form_submit, липкая кнопка, cookie-уведомление.
   ══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var YM_ID = 110924821; // счётчик Метрики (тот же, что в инлайн-сниппете head)

  // Цель Метрики. Требование: отправка формы = отдельное событие form_submit.
  function reachGoal(name, params) {
    if (typeof window.ym !== 'function') return;
    try {
      if (params) window.ym(YM_ID, 'reachGoal', name, params);
      else window.ym(YM_ID, 'reachGoal', name);
    } catch (e) {}
  }

  /* ── Форма заявки ──────────────────────────────────────── */
  (function () {
    var form = document.querySelector('[data-lead-form]');
    if (!form) return;
    var statusEl = form.querySelector('[data-lead-status]');
    var submitBtn = form.querySelector('button[type="submit"]');
    var section = form.closest('.lead');
    var resultBox = section ? section.querySelector('[data-lead-result]') : null;
    var errorBox = section ? section.querySelector('[data-lead-error]') : null;
    var submitting = false; // защита от двойной отправки (Enter шлёт мимо кнопки)

    function setStatus(msg, kind) {
      if (!statusEl) return;
      statusEl.textContent = msg;
      if (kind) statusEl.dataset.kind = kind;
      else statusEl.removeAttribute('data-kind');
    }

    function showResult() {
      form.reset();
      if (errorBox) errorBox.hidden = true;
      if (!resultBox) { setStatus('Готово. Отвечу в течение рабочего дня.', 'ok'); return; }
      form.hidden = true;
      resultBox.hidden = false;
      resultBox.setAttribute('tabindex', '-1');
      try { resultBox.focus({ preventScroll: false }); } catch (e) {}
    }

    function showHardError(msg) {
      if (errorBox) { errorBox.hidden = false; setStatus('', null); }
      else setStatus(msg, 'error');
    }

    // РФ-ВМ приёма лида в обход исходящего Beget (тот же бэкенд, что у чата главной).
    var BOT_URL = (window.LABAZAN_BOT_URL || 'https://bot.labazan.ru').replace(/\/+$/, '');
    // Контекст лида для оператора: помечаем, что это посадочная (без ПД в message — только метки).
    function buildBotMessage(fd) {
      var parts = ['Заявка с посадочной «создание сайта»'];
      var goal = fd.get('goal'); if (goal) parts.push(String(goal));
      var source = fd.get('source'); if (source) parts.push('стр. ' + String(source));
      return parts.join(' · ').slice(0, 2000);
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (submitting) return;

      // Родная проверка required-полей и обязательного согласия (галочка 152-ФЗ).
      if (!form.checkValidity()) { form.reportValidity(); return; }

      submitting = true;
      setStatus('Отправляю…', 'pending');
      if (submitBtn) submitBtn.disabled = true;

      var fd = new FormData(form);
      // ДВА независимых РФ-канала: (1) api/lead.php — российская почта (lead@labazan.ru);
      // (2) bot.labazan.ru/escalate — приём на РФ-ВМ (в обход исходящего Beget) → CRM crm.labazan.ru
      // + уведомление оператору. Успех, если подтвердил ХОТЯ БЫ один. Бот сам валидирует
      // honeypot/согласие/контакт server-side. ПД идут только в РФ-приёмники (152-ФЗ), не в мессенджер.
      var botBody = {
        name: fd.get('name') || '',
        contact: fd.get('contact') || '',
        consent: fd.get('consent') || '',
        company: fd.get('company') || '',
        message: buildBotMessage(fd),
      };
      Promise.all([
        fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: fd })
          .then(function (r) { return r.json(); }).catch(function () { return null; }),
        fetch(BOT_URL + '/escalate', {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(botBody),
        }).then(function (r) { return r.json(); }).catch(function () { return null; }),
      ])
        .then(function (rs) {
          var phpRes = rs[0], botRes = rs[1];
          var errOf = function (r) { return (r && r.ok === false) ? r.error : null; };
          var anyErr = function (e) { return errOf(phpRes) === e || errOf(botRes) === e; };
          if ((phpRes && phpRes.ok) || (botRes && botRes.ok)) {
            // Цель Метрики ДО перерисовки UI. Honeypot: php отдаёт g:0 → бота не считаем конверсией.
            var honeypot = phpRes && phpRes.g === 0;
            if (!honeypot) reachGoal('form_submit', { source: 'sozdanie-sajta' });
            showResult();
          } else if (anyErr('consent')) {
            setStatus('Отметьте согласие на обработку персональных данных.', 'error');
          } else if (anyErr('validation')) {
            setStatus('Проверьте контакт: телефон или Telegram-ник.', 'error');
          } else if (anyErr('rate_limited')) {
            setStatus('Слишком много попыток. Подождите минуту и попробуйте снова.', 'error');
          } else if (phpRes || botRes) {
            showHardError('Не удалось отправить.');
          } else {
            showHardError('Сеть недоступна.');
          }
        })
        .catch(function () { showHardError('Сеть недоступна.'); })
        .finally(function () {
          submitting = false;
          if (submitBtn) submitBtn.disabled = false;
        });
    });
  })();

  /* ── Липкая кнопка: показываем после hero, прячем когда форма в кадре ── */
  (function () {
    var cta = document.querySelector('[data-sticky-cta]');
    var lead = document.getElementById('lead');
    if (!cta || !lead) return;

    var formInView = false;
    var scrolledPast = false;

    function apply() {
      cta.hidden = !(scrolledPast && !formInView);
    }

    // Прячем, когда секция формы в зоне видимости.
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        formInView = entries[0].isIntersecting;
        apply();
      }, { rootMargin: '0px 0px -20% 0px' }).observe(lead);
    }

    // Показываем после того, как ушли с первого экрана.
    function onScroll() {
      scrolledPast = window.scrollY > window.innerHeight * 0.6;
      apply();
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  })();

  /* ── Cookie-уведомление (только информирует, счётчик НЕ блокирует) ── */
  (function () {
    var KEY = 'sozdanie_cookie_ok';
    var box = document.querySelector('[data-cookie]');
    if (!box) return;
    var seen = null;
    try { seen = localStorage.getItem(KEY); } catch (e) {}
    if (seen === '1') return;

    box.hidden = false;
    document.body.classList.add('has-cookie'); // приподнимает липкую кнопку над баннером
    var btn = box.querySelector('[data-cookie-ok]');
    if (btn) {
      btn.addEventListener('click', function () {
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
        box.hidden = true;
        document.body.classList.remove('has-cookie');
      });
    }
  })();
})();
