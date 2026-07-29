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
  // РФ-ВМ приёма лида в обход исходящего Beget (тот же бэкенд, что у чата).
  const BOT_URL = (window.LABAZAN_BOT_URL || 'https://bot.labazan.ru').replace(/\/+$/, '');

  // Контекст лида в поле message бота (source-колонка у инстанса общая, цель/страница — сюда).
  function buildBotMessage(fd) {
    const parts = ['Заявка с сайта'];
    const goal = fd.get('goal'); if (goal) parts.push(String(goal));
    const source = fd.get('source'); if (source) parts.push('стр. ' + String(source));
    let msg = parts.join(' · ');
    const calc = fd.get('calc'); if (calc) msg += '\n' + String(calc);
    return msg.slice(0, 2000);
  }

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
      const fd = new FormData(form);
      // Доставка ДВУМЯ независимыми РФ-каналами (у Beget-PHP исходящий в CRM режется):
      //   1) api/lead.php — российская почта + ответ формы (как раньше);
      //   2) bot.labazan.ru/escalate — приём на РФ-ВМ прямо из браузера (в обход исходящего
      //      Beget), оттуда в CRM по внутренней сети. Тот же путь, что у чат-виджета.
      // Успех = подтвердил ХОТЯ БЫ один канал. Бот сам валидирует honeypot/согласие/контакт.
      const botBody = {
        name: fd.get('name') || '',
        contact: fd.get('contact') || '',
        consent: fd.get('consent') || '',
        company: fd.get('company') || '',
        message: buildBotMessage(fd),
      };
      const [phpRes, botRes] = await Promise.all([
        fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: fd })
          .then((r) => r.json()).catch(() => null),
        fetch(BOT_URL + '/escalate', {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(botBody),
        }).then((r) => r.json()).catch(() => null),
      ]);

      const errOf = (r) => (r && r.ok === false ? r.error : null);
      const anyErr = (e) => errOf(phpRes) === e || errOf(botRes) === e;

      if ((phpRes && phpRes.ok) || (botRes && botRes.ok)) {
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
      } else if (anyErr('consent')) {
        setStatus('Отметьте согласие на обработку персональных данных.', 'error');
      } else if (anyErr('validation')) {
        setStatus('Проверьте контакт: телефон или Telegram-ник.', 'error');
      } else if (anyErr('rate_limited')) {
        setStatus('Слишком много попыток. Подождите минуту и попробуйте снова.', 'error');
      } else if (phpRes || botRes) {
        // Хотя бы один ответил ошибкой доставки — не тупик, даём прямую связь.
        showHardError('Не удалось отправить. Напишите напрямую в Telegram.');
      } else {
        // Оба канала недоступны (сеть / нет PHP и бот недоступен).
        showHardError('Сеть недоступна. Напишите напрямую в Telegram.');
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
