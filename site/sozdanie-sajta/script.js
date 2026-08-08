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

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (submitting) return;

      // Родная проверка required-полей и обязательного согласия (галочка 152-ФЗ).
      if (!form.checkValidity()) { form.reportValidity(); return; }

      submitting = true;
      setStatus('Отправляю…', 'pending');
      if (submitBtn) submitBtn.disabled = true;

      var fd = new FormData(form);
      fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: fd })
        .then(function (r) { return r.json(); })
        .catch(function () { return null; })
        .then(function (res) {
          if (res && res.ok) {
            // Цель Метрики шлём ДО перерисовки UI (form.reset/скрытие формы/фокус), чтобы hit
            // гарантированно ушёл. g:1 = засчитываемая заявка; honeypot отдаёт g:0 — не конверсия.
            if (res.g !== 0) reachGoal('form_submit', { source: 'sozdanie-sajta' });
            showResult();
          } else if (res && res.error === 'consent') {
            setStatus('Отметьте согласие на обработку персональных данных.', 'error');
          } else if (res && res.error === 'validation') {
            setStatus('Проверьте контакт: телефон или Telegram-ник.', 'error');
          } else if (res && res.error === 'not_configured') {
            showHardError('Форма ещё настраивается. Напишите в Telegram.');
          } else if (res) {
            showHardError('Не удалось отправить. Напишите в Telegram.');
          } else {
            showHardError('Сеть недоступна. Напишите в Telegram.');
          }
        })
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
