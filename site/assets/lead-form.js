/* ══════════════════════════════════════════════════════════════════
   Лабазан — единый скрипт лид-формы (главная + подстраницы).
   ЕДИНСТВЕННЫЙ экземпляр логики: обработчик отправки, валидация,
   состояния, цель Метрики, защита от двойной отправки, плавный
   внутристраничный скролл к форме. Разметка формы дублируется на
   страницах, логика — только здесь.
   ══════════════════════════════════════════════════════════════════ */

/* Форма заявки #lead: клиентская валидация + честный фолбэк, если приём не настроен.
   Никогда не сообщаем «отправлено», пока сервер не подтвердил {ok:true}. */
(() => {
  const form = document.querySelector('[data-lead-form]');
  if (!form) return;
  const status = form.querySelector('[data-lead-status]');
  const submit = form.querySelector('button[type="submit"]');
  // Источник заявки (скрытое поле source) — уходит в цель Метрики отдельным параметром.
  const sourceField = form.querySelector('input[name="source"]');
  // Блоки подтверждения/ошибки живут в разметке страницы (дублируется только вёрстка).
  // На главной их нет — тогда работает прежний текстовый статус (обратная совместимость).
  const section = form.closest('.lead');
  const resultBox = section ? section.querySelector('[data-lead-result]') : null;
  const errorBox = section ? section.querySelector('[data-lead-error]') : null;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let submitting = false; // защита от двойной отправки: Enter умеет слать форму мимо кнопки

  function setStatus(msg, kind) {
    if (!status) return;
    status.textContent = msg;
    if (kind) status.dataset.kind = kind;
    else status.removeAttribute('data-kind');
  }

  // Плавная прокрутка к блоку (≤ 400 мс). При prefers-reduced-motion — мгновенно.
  function scrollToBox(el) {
    const top = Math.max(0, window.scrollY + el.getBoundingClientRect().top - 20);
    if (reduceMotion.matches) { window.scrollTo(0, top); return; }
    const start = window.scrollY;
    const dist = top - start;
    if (Math.abs(dist) < 1) return;
    const dur = Math.min(400, Math.max(160, Math.abs(dist) * 0.5));
    const t0 = performance.now();
    const ease = (t) => 1 - Math.pow(1 - t, 3); // easeOutCubic
    function step(now) {
      const t = Math.min(1, (now - t0) / dur);
      window.scrollTo(0, start + dist * ease(t));
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  // Успех: показываем блок подтверждения на месте формы, без перезагрузки.
  // Возвращаем false, если блока нет (главная) — вызывающий покажет текстовый статус.
  function showResult() {
    if (!resultBox) return false;
    if (errorBox) errorBox.hidden = true;
    form.hidden = true;
    resultBox.hidden = false;
    resultBox.setAttribute('tabindex', '-1');
    scrollToBox(resultBox);
    try { resultBox.focus({ preventScroll: true }); } catch (_) {}
    return true;
  }

  // Жёсткая ошибка доставки/сети: блок с прямой ссылкой на связь, форму оставляем
  // на месте (можно повторить). Блока нет — понятный текстовый статус.
  function showHardError(msg) {
    if (errorBox) {
      errorBox.hidden = false;
      setStatus('', null);
      scrollToBox(errorBox);
    } else {
      setStatus(msg, 'error');
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    // Запрос уже в полёте — второй submit (двойной клик / повторный Enter) игнорируем.
    if (submitting) return;

    // Родная проверка required-полей и обязательного согласия.
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    submitting = true;
    setStatus('Отправляю…', 'pending');
    if (submit) submit.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(form),
      });

      let data = null;
      try { data = await res.json(); } catch (_) { /* не JSON: статик-хостинг без PHP */ }

      if (res.ok && data && data.ok) {
        const source = sourceField ? sourceField.value : '';
        form.reset();
        // Блок подтверждения на месте формы; на главной — прежний текстовый статус.
        if (!showResult()) {
          setStatus('Готово. Рамазан свяжется с вами в течение рабочего дня.', 'ok');
        }
        // Цель Метрики с параметром source — видно, какая страница привела заявку.
        if (typeof window.labazanGoal === 'function') {
          window.labazanGoal('form_submit', source ? { source: source } : undefined);
        }
      } else if (data && data.error === 'consent') {
        setStatus('Отметьте согласие на обработку персональных данных.', 'error');
      } else if (data && data.error === 'validation') {
        setStatus('Проверьте контакт: телефон или Telegram-ник.', 'error');
      } else if (data && data.error === 'rate_limited') {
        setStatus('Слишком много попыток. Подождите минуту и попробуйте снова.', 'error');
      } else if (data && data.error) {
        // Доставка не удалась — тупика быть не должно: даём прямую ссылку на связь.
        showHardError('Не удалось отправить. Напишите напрямую в Telegram.');
      } else {
        // Нет валидного JSON: на превью статик-сервер без PHP. Не врём про отправку.
        setStatus('Форма заработает после переноса на рабочий хостинг. Пока напишите напрямую в Telegram или позвоните.', 'error');
      }
    } catch (_) {
      showHardError('Сеть недоступна. Напишите напрямую в Telegram.');
    } finally {
      submitting = false;
      if (submit) submit.disabled = false;
    }
  });
})();

/* Плавная прокрутка к форме по внутренним якорям #lead (кнопки CTA).
   Короткая (≤ 400 мс), при prefers-reduced-motion — мгновенно.
   Только якоря на текущей странице; внешние ссылки не трогаем. */
(() => {
  const target = document.getElementById('lead');
  if (!target) return;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const MAX_MS = 400;

  function animateTo(top) {
    const start = window.scrollY;
    const distance = top - start;
    if (Math.abs(distance) < 1) return;
    // Длительность пропорциональна дистанции, но не дольше 400 мс.
    const duration = Math.min(MAX_MS, Math.max(160, Math.abs(distance) * 0.5));
    const startTime = performance.now();
    const ease = (t) => 1 - Math.pow(1 - t, 3); // easeOutCubic

    function step(now) {
      const t = Math.min(1, (now - startTime) / duration);
      window.scrollTo(0, start + distance * ease(t));
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  document.addEventListener('click', (event) => {
    const link = event.target.closest && event.target.closest('a[href$="#lead"], a[href="#lead"]');
    if (!link) return;
    const href = link.getAttribute('href') || '';
    // Только чистый внутристраничный якорь на этой же странице (#lead или ./#lead),
    // не ссылки на другую страницу вида ../#lead.
    if (href !== '#lead' && href !== './#lead') return;

    event.preventDefault();
    if (reduceMotion.matches) {
      target.scrollIntoView();
    } else {
      const rect = target.getBoundingClientRect();
      animateTo(window.scrollY + rect.top);
    }
    // Отражаем якорь в адресе без прыжка и без засорения истории.
    if (history.replaceState) history.replaceState(null, '', '#lead');
    // Фокус на заголовок формы для доступности (без повторного скролла).
    const heading = target.querySelector('.lead__title');
    if (heading) {
      heading.setAttribute('tabindex', '-1');
      heading.focus({ preventScroll: true });
    }
  });
})();

/* Цель Метрики click_plan: клик по предложению платного разбора с планом
   (строка в блоке подтверждения и свёрнутая строка в теле pochemu-net-zayavok).
   Ключевая метрика: сколько получивших бесплатный результат идут за платным. */
(() => {
  document.addEventListener('click', (event) => {
    const link = event.target.closest && event.target.closest('[data-plan-link]');
    if (!link) return;
    if (typeof window.labazanGoal !== 'function') return;
    const sf = document.querySelector('[data-lead-form] input[name="source"]');
    window.labazanGoal('click_plan', sf && sf.value ? { source: sf.value } : undefined);
  }, true);
})();
