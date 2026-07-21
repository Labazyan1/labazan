/* Яндекс.Метрика через consent-гейт (152-ФЗ, РФ-сервис).
   Определяет window.labazanLoadAnalytics — consent.js (C3) вызывает её
   ТОЛЬКО при согласии «all» (клик «Принять» или уже сохранённое согласие).
   Подключать ПЕРЕД consent.js, чтобы функция была определена к вызову.

   ВЛАДЕЛЬЦУ: впишите числовой ID счётчика в COUNTER_ID ниже (одна строка).
   Пока ID нечисловой — счётчик не грузится, цели молчат, ошибок нет. */
(function () {
  'use strict';

  // ЕДИНСТВЕННОЕ место с ID счётчика (боевой счётчик labazan.ru).
  // Можно переопределить глобально: window.YM_COUNTER_ID = ... до подключения скрипта.
  var COUNTER_ID = (window.YM_COUNTER_ID != null) ? window.YM_COUNTER_ID : 110924821;

  function validId() {
    return /^\d+$/.test(String(COUNTER_ID)) ? Number(COUNTER_ID) : 0;
  }

  function loadMetrika() {
    if (window.__ymLoaded) return;
    var id = validId();
    if (!id) return; // нет валидного ID — тихо выходим
    window.__ymLoaded = true;

    (function (m, e, t, r, i, k, a) {
      m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
      m[i].l = 1 * new Date();
      for (var j = 0; j < e.scripts.length; j++) {
        if (e.scripts[j].src === r) { return; }
      }
      k = e.createElement(t); a = e.getElementsByTagName(t)[0];
      k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');

    // webvisor:true → вебвизор + карта скроллов; clickmap:true → карта кликов.
    window.ym(id, 'init', {
      clickmap: true,
      trackLinks: true,
      accurateTrackBounce: true,
      webvisor: true,
      defer: true
    });
  }

  /* Отправка цели. Срабатывает только если счётчик уже загружен (согласие дано)
     и ID валиден — иначе тихо выходим, чтобы не копить очередь без согласия. */
  window.labazanGoal = function (name) {
    if (!window.__ymLoaded || typeof window.ym !== 'function') return;
    var id = validId();
    if (!id) return;
    try { window.ym(id, 'reachGoal', name); } catch (e) {}
  };

  window.labazanLoadAnalytics = loadMetrika;
  // Подстраховка: если гейт отправит событие раньше/позже определения функции.
  window.addEventListener('labazan:consent-all', loadMetrika);

  // Если C3 уже выставил согласие 'all' до подключения этого скрипта — догрузим.
  if (window.__labazanConsent === 'all') loadMetrika();
})();
