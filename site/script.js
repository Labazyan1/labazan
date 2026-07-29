/* Лабазан — главная «Гроссбух на льне»: докрутка цифр, параллакс льна, липкий CTA бота.
   Всё движение гаснет при prefers-reduced-motion (проверки внутри блоков). */
    (function () {
      'use strict';
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var NBSP = String.fromCharCode(160);

      function fmt(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
      }

      function setFinal(el) {
        el.textContent = fmt(parseInt(el.getAttribute('data-count'), 10));
      }

      /* докрутка числа: ease-out cubic, не дольше 1.2 с */
      function run(el) {
        var end = parseInt(el.getAttribute('data-count'), 10);
        if (reduce || !window.requestAnimationFrame) { setFinal(el); return; }
        var dur = Math.min(1200, 500 + end / 60);
        var chars = fmt(end).length;
        el.style.display = 'inline-block';
        el.style.minWidth = chars * 0.62 + 'em'; /* без прыжков ширины */
        el.style.textAlign = 'right';
        var t0 = null;
        function step(t) {
          if (t0 === null) t0 = t;
          var p = Math.min(1, (t - t0) / dur);
          var e = 1 - Math.pow(1 - p, 3);
          el.textContent = fmt(Math.round(end * e));
          if (p < 1) { requestAnimationFrame(step); } else { el.textContent = fmt(end); }
        }
        requestAnimationFrame(step);
      }

      /* герой: сразу при загрузке */
      var hero = document.querySelector('[data-count-hero]');
      if (hero) run(hero);

      /* отчёт: при появлении в кадре */
      var rest = [].slice.call(document.querySelectorAll('[data-count]:not([data-count-hero])'));
      if (reduce || !('IntersectionObserver' in window)) {
        rest.forEach(setFinal);
      } else {
        var io = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) { run(en.target); io.unobserve(en.target); }
          });
        }, { threshold: 0.4 });
        rest.forEach(function (el) { io.observe(el); });
      }
    })();

    /* Параллакс льняного стола: rAF-лерп 12% скорости скролла.
       Только точные указатели (desktop), гаснет при reduced-motion. */
    (function () {
      var bg = document.querySelector('.bg-linen');
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var fine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
      if (!bg || reduce || !fine || !window.requestAnimationFrame) return;
      var cur = 0, target = 0, ticking = false;
      function tick() {
        cur += (target - cur) * 0.12;
        if (Math.abs(target - cur) < 0.1) { cur = target; ticking = false; }
        bg.style.transform = 'translate3d(0, ' + (-cur).toFixed(2) + 'px, 0)';
        if (ticking) requestAnimationFrame(tick);
      }
      window.addEventListener('scroll', function () {
        /* кэп 1100 < запас высоты слоя 1200px (см. .bg-linen) — низ льна
           не оголяется даже на сверхдлинных страницах (порог ~9200px скролла) */
        target = Math.min(window.scrollY * 0.12, 1100);
        if (!ticking) { ticking = true; requestAnimationFrame(tick); }
      }, { passive: true });
    })();

/* Демо автоответа (кнопка в потоке после «Как выглядит учёт»): цель Метрики click_bot. */
(function () {
  var cta = document.querySelector('[data-sticky-bot]');
  if (!cta) return;
  cta.addEventListener('click', function () {
    if (window.labazanGoal) window.labazanGoal('click_bot');
  });
})();

/* Цели Метрики: делегирование на document, как на прежней главной.
   tel: → click_phone, t.me/tg: → click_telegram (кроме кнопки бота, у неё
   своя цель click_bot), .cta/[data-cta] → click_cta. Плюс scroll_packages
   по доскроллу до секции цен (#process), один раз. labazanGoal молчит без согласия. */
(function () {
  function goal(name) {
    if (typeof window.labazanGoal === 'function') window.labazanGoal(name);
  }

  document.addEventListener('click', function (event) {
    var a = event.target.closest && event.target.closest('a, button');
    if (!a) return;
    if (a.hasAttribute('data-sticky-bot')) return; /* шлёт click_bot отдельно */
    var href = a.getAttribute('href') || '';
    if (href.indexOf('tel:') === 0) goal('click_phone');
    else if (/(^https?:)?\/\/t\.me\//.test(href) || href.indexOf('tg://') === 0) goal('click_telegram');
    else if (a.classList.contains('cta') || a.classList.contains('cta__button') || a.hasAttribute('data-cta')) goal('click_cta');
  }, true);

  /* Блок тарифов слит с процессом (29.07): цены живут в секции #process. */
  var pricing = document.getElementById('process');
  if (pricing && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { goal('scroll_packages'); io.disconnect(); }
      });
    }, { threshold: 0.4 });
    io.observe(pricing);
  }
})();
