/* ══════════════════════════════════════════════════════════════════
   Лабазан — калькулятор «Сколько заявок теряется» (услуга «Приём заявок»).
   Единственный источник расчётной ЛОГИКИ этого инструмента.

   ДЕНЬГИ: коэффициенты и логику вердикта менять только с владельцем.
   Считаем ЧЕСТНО и консервативно (это лид-магнит, не пугалка):
     - Скорость ответа решает: чем дольше ждёт обращение, тем выше шанс,
       что человек ушёл к конкуренту / передумал. Кривая потерь плавная
       и с потолком (не «100% потеряно»).
     - Обращения вне рабочего времени ждут дольше (пока их увидят), поэтому
       у них своя, более длинная задержка.
     - В деньги переводим через КОНСЕРВАТИВНУЮ конверсию: продажей становится
       каждое пятое обращение. Это выручка, не прибыль — так и подписано.
   Данные владельца заранее не подставляем: только плейсхолдеры/дефолты.
   ══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var root = document.querySelector('[data-loss]');
  if (!root) return;

  // ── Константы модели (менять только с владельцем) ─────────────────
  var CONV = 0.2;            // продажей становится каждое 5-е обращение
  var AFTER_WAIT = 10;       // ч: доп. ожидание для обращений вне рабочего времени
  var LOSS_CAP = 0.6;        // потолок доли потерь (никогда не «100% потеряно»)
  var LOSS_K = 12;           // масштаб кривой: loss = h / (h + K)

  // ── Форматтеры ────────────────────────────────────────────────────
  function fmt(t) {
    var e = Math.round(t), s = String(Math.abs(e)), n = '';
    for (; s.length > 3;) { n = ' ' + s.slice(-3) + n; s = s.slice(0, -3); }
    return (e < 0 ? '-' : '') + s + n;
  }
  function rub(t) { return fmt(t) + ' ₽'; }

  var $ = function (id) { return root.querySelector('[data-el="' + id + '"]'); };
  var num = function (v) { return parseInt(String(v).replace(/[^\d]/g, ''), 10) || 0; };
  var dash = '–';
  var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  // Доля потерь по времени ответа (часы) → 0..LOSS_CAP.
  function lossRate(h) { return Math.min(LOSS_CAP, h / (h + LOSS_K)); }

  var lastR = null;

  var anim = null;
  function animateTo(node, target) {
    if (reduceMotion) { node.textContent = fmt(target); return; }
    if (anim) cancelAnimationFrame(anim);
    var start = num(node.textContent) || 0, t0 = performance.now(), d = 500;
    function step(t) {
      var p = Math.min(1, (t - t0) / d), e = 1 - Math.pow(1 - p, 3);
      node.textContent = fmt(start + (target - start) * e);
      if (p < 1) anim = requestAnimationFrame(step);
    }
    anim = requestAnimationFrame(step);
  }

  function applyVerdict(tone, title, text) {
    var box = $('verdict');
    box.dataset.tone = tone;
    $('vTitle').textContent = title;
    $('vText').textContent = text;
  }

  function recalc(animate) {
    var N = num($('leads').value);
    var H = parseFloat($('speed').value) || 0;
    var p = num($('offshare').value); if (p > 100) p = 100; p = p / 100;
    var check = num($('check').value);

    // Потери по двум каналам: в рабочее время и вне его (там ждут дольше).
    var during = N * (1 - p) * lossRate(H);
    var after = N * p * lossRate(H + AFTER_WAIT);
    var lost = during + after;
    var money = lost * check * CONV;

    if (animate) animateTo($('lostVal'), lost); else $('lostVal').textContent = fmt(lost);
    $('moneyVal').textContent = check > 0 && lost > 0 ? rub(money) : dash;

    // Вердикт: по доле теряемых обращений от общего потока.
    var ratio = N > 0 ? lost / N : 0;
    var tone, title, text;
    if (N <= 0) {
      tone = 'empty'; title = 'Сколько заявок утекает?';
      text = 'Впишите число обращений в месяц и средний чек, и я оценю потери в штуках и в деньгах.';
    } else if (lost < 1) {
      tone = 'ok'; title = 'Почти ничего не теряете';
      text = 'При таком темпе ответа потери близки к нулю. Если поток обращений вырастет или ответы станут медленнее, приём заявок подстрахует.';
    } else if (ratio < 0.15) {
      tone = 'ok'; title = 'Теряете немного, но регулярно';
      text = 'Около ' + fmt(lost) + ' обращений в месяц уходит из-за задержки с ответом'
        + (check > 0 ? ', это примерно ' + rub(money) + ' недополученной выручки' : '')
        + '. Приём заявок отвечает за минуту и закрывает даже это.';
    } else if (ratio < 0.3) {
      tone = 'warn'; title = 'Утекает заметная доля';
      text = 'Примерно ' + fmt(lost) + ' обращений в месяц не доходят до разговора'
        + (check > 0 ? ' — около ' + rub(money) + ' выручки' : '')
        + '. Чаще всего это ночь, выходные и часы, когда ответить некому.';
    } else {
      tone = 'bad'; title = 'Теряется много';
      text = 'До ' + fmt(lost) + ' обращений в месяц уходит в никуда'
        + (check > 0 ? ', это порядка ' + rub(money) + ' недополученной выручки' : '')
        + '. Мгновенный ответ 24/7 вернёт большую часть.';
    }
    applyVerdict(tone, title, text);

    lastR = { lost: Math.round(lost), money: Math.round(money), speedH: H, off: Math.round(p * 100) };
    writeSnapshot();
  }

  function bindNumber(id) {
    var el = $(id);
    if (!el) return;
    el.addEventListener('input', function () {
      var raw = String(el.value).replace(/[^\d]/g, '');
      if (id === 'offshare') el.value = raw ? String(Math.min(100, parseInt(raw, 10))) : '';
      else el.value = raw ? fmt(parseInt(raw, 10)) : '';
      recalc(true);
    });
    el.addEventListener('focus', function () { el.select(); });
  }

  // Снимок расчёта (не ПД) в скрытое поле формы. Пишется на каждый пересчёт.
  var snap = document.querySelector('[data-lead-form] [name="calc"]');
  function writeSnapshot() {
    if (!snap || !lastR) return;
    snap.value = 'Теряется обращений/мес: ' + lastR.lost
      + '; недополученная выручка/мес: ' + rub(lastR.money)
      + '; время ответа: ' + lastR.speedH + ' ч; вне рабочего времени: ' + lastR.off + '%';
  }

  // ── Инициализация ─────────────────────────────────────────────────
  ['leads', 'offshare', 'check'].forEach(bindNumber);
  $('speed').addEventListener('change', function () { recalc(true); });
  recalc(false);
})();
