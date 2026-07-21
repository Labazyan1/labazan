/* Cookie-согласие + consent-гейт (152-ФЗ). Общий на все страницы.
   Хранит выбор в localStorage. Пока согласия «all» нет — аналитика (Метрика)
   не грузится. C4 определяет window.labazanLoadAnalytics — гейт вызовет её
   при выборе «Принять» или если согласие «all» уже сохранено. */
(function () {
  'use strict';
  var KEY = 'labazan_consent'; // 'all' | 'necessary'
  var stored = null;
  try { stored = localStorage.getItem(KEY); } catch (e) {}

  function grantAll() {
    window.__labazanConsent = 'all';
    if (typeof window.labazanLoadAnalytics === 'function') {
      window.labazanLoadAnalytics();
    }
    try { window.dispatchEvent(new Event('labazan:consent-all')); } catch (e) {}
  }

  // Уже решено раньше — баннер не показываем, раскладку не трогаем.
  if (stored === 'all') { grantAll(); return; }
  if (stored === 'necessary') { window.__labazanConsent = 'necessary'; return; }

  function build() {
    var banner = document.createElement('div');
    banner.className = 'cookie-banner';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-label', 'Согласие на использование cookie');
    banner.innerHTML =
      '<p class="cookie-banner__text">Сайт использует cookie и Яндекс.Метрику, чтобы понимать, какие страницы полезны. Данные обезличены. Подробнее в <a href="/privacy/">политике обработки данных</a>.</p>' +
      '<div class="cookie-banner__actions">' +
        '<button type="button" class="cookie-banner__btn cookie-banner__btn--accept" data-consent="all">Принять</button>' +
        '<button type="button" class="cookie-banner__btn" data-consent="necessary">Только необходимые</button>' +
      '</div>';

    banner.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-consent]') : null;
      if (!btn) return;
      var choice = btn.getAttribute('data-consent');
      try { localStorage.setItem(KEY, choice); } catch (err) {}
      window.__labazanConsent = choice;
      if (choice === 'all') grantAll();
      banner.classList.remove('is-visible');
      var done = function () { if (banner.parentNode) banner.parentNode.removeChild(banner); };
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) { done(); } else { setTimeout(done, 320); }
    });

    document.body.appendChild(banner);
    // Плавное появление без сдвига раскладки (position:fixed поверх контента).
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { banner.classList.add('is-visible'); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
