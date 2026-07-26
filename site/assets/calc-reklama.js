/* ══════════════════════════════════════════════════════════════════
   Лабазан — калькулятор «Окупится ли реклама».
   Единственный источник расчётной ЛОГИКИ. Цифры ниш — в calc-niches.js
   (правятся там). Формула потолка портирована из roi-calc.ts (проверена).

   ДЕНЬГИ: коэффициент запаса и логику вердикта менять только с владельцем.
   Экран считает две вещи:
     1. Сколько лид БУДЕТ стоить по нише (теор. цена = клик ÷ конверсия сайта),
        вилка оптимистичная и пессимистичная. Это ориентир по рынку.
     2. Сколько владелец МОЖЕТ платить за обращение (из своей прибыли и
        конверсии в продажу). Разрыв между ними и есть вердикт.
   Данные владельца (прибыль) заранее не подставляем: только плейсхолдеры.
   ══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var DATA = window.LABAZAN_CALC;
  var root = document.querySelector('[data-calc]');
  if (!DATA || !root) return;

  // ── Форматтеры ────────────────────────────────────────────────────
  function fmt(t) {
    var e = Math.round(t), s = String(Math.abs(e)), n = '';
    for (; s.length > 3;) { n = ' ' + s.slice(-3) + n; s = s.slice(0, -3); }
    return (e < 0 ? '-' : '') + s + n;
  }
  function rub(t) { return fmt(t) + ' ₽'; }

  var PROFIT_KEEP = 0.5; // реклама забирает не больше половины заработка
  function sane(v) { return typeof v === 'number' && isFinite(v) && v > 0 ? v : 0; }

  var $ = function (id) { return root.querySelector('[data-el="' + id + '"]'); };
  var num = function (v) { return parseInt(String(v).replace(/[^\d]/g, ''), 10) || 0; };
  var dash = '–';
  var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  var profitMode = 'sum';
  var lastR = null;

  function currentNiche() {
    var id = $('niche').value;
    for (var i = 0; i < DATA.niches.length; i++) if (DATA.niches[i].id === id) return DATA.niches[i];
    return DATA.niches[0];
  }
  function profitPerDeal() {
    if (profitMode === 'margin') return num($('check').value) * num($('margin').value) / 100;
    return num($('profit').value);
  }

  // Теоретическая цена лида по нише: лид = цена клика ÷ конверсия сайта.
  // Опт: дешёвый клик × высокая конверсия. Пессим: дорогой клик × низкая.
  function leadRange(n) {
    var opt = Math.round(n.click[0] / (n.conv[1] / 100));
    var pess = Math.round(n.click[1] / (n.conv[0] / 100));
    return { opt: opt, pess: pess };
  }

  // ── Заполнить селект ниш из справочника ───────────────────────────
  function buildSelect() {
    var sel = $('niche');
    DATA.groups.forEach(function (g) {
      var og = document.createElement('optgroup');
      og.label = g.label;
      DATA.niches.forEach(function (n) {
        if (n.group !== g.id) return;
        var o = document.createElement('option');
        o.value = n.id; o.textContent = n.label;
        og.appendChild(o);
      });
      sel.appendChild(og);
    });
    sel.value = DATA.defaultNiche;
  }

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
    $('verdictTitle').textContent = title;
    $('verdictText').textContent = text;
  }

  function recalc(animate) {
    var n = currentNiche();
    var lr = leadRange(n);

    // Блок 1: цена лида по нише (ориентир).
    $('nicheHint').textContent = 'Ориентир по рынку: клик ' + n.click[0] + '–' + n.click[1]
      + ' ₽, конверсия сайта ' + n.conv[0] + '–' + n.conv[1] + '%';
    $('leadOpt').textContent = fmt(lr.opt);
    $('leadPess').textContent = fmt(lr.pess);

    // Блок 2: сколько владелец может платить.
    var profit = profitPerDeal();
    var saleConvPct = sane(num($('saleConv').value)); if (saleConvPct > 100) saleConvPct = 100;
    var saleConv = saleConvPct / 100;
    var ceiling = profit * saleConv;
    var working = ceiling * PROFIT_KEEP;
    var everyN = saleConv > 0 ? Math.round(1 / saleConv) : 0;

    if ($('profitCalc')) $('profitCalc').textContent = profit > 0 ? rub(profit) : dash;
    $('saleEvery').textContent = everyN > 0 ? everyN : dash;
    $('ceilVal').textContent = profit > 0 && saleConv > 0 ? rub(ceiling) : dash;
    if (animate) animateTo($('workVal'), working); else $('workVal').textContent = fmt(working);

    // Вердикт: сравниваем потолок с вилкой цены лида.
    var tone, title, text;
    var loR = rub(lr.opt), hiR = rub(lr.pess), wR = rub(working);
    if (profit <= 0 || saleConv <= 0) {
      tone = 'empty'; title = 'Сколько вы можете платить?';
      text = 'Впишите прибыль с продажи и конверсию в продажу, и я сравню это с ценой лида в нише: сходится реклама или нет.';
    } else if (working >= lr.pess) {
      tone = 'ok'; title = 'Сходится с запасом';
      text = 'Даже по пессимистичной оценке лид в нише стоит около ' + hiR + ', а вы можете платить до ' + wR
        + '. Реклама окупается. Более обоснованные цифры по вашему городу даёт разведка.';
    } else if (working >= lr.opt) {
      tone = 'warn'; title = 'Может сойтись';
      text = 'Лид в нише стоит примерно от ' + loR + ' до ' + hiR + ', а ваш потолок ' + wR
        + '. Сойдётся, если реальная цена ближе к нижней границе. Ближе к делу это оценит разведка.';
    } else {
      tone = 'bad'; title = 'Пока не сходится';
      text = 'Даже по оптимистичной оценке лид в нише стоит около ' + loR + ', а платить вы можете до ' + wR
        + '. При таких цифрах реклама съест прибыль. Сначала поднять чек или конверсию в продажу.';
    }
    applyVerdict(tone, title, text);

    lastR = { niche: n.label, leadOpt: lr.opt, leadPess: lr.pess, working: working, title: title };
    writeSnapshot();
  }

  function bindNumber(id) {
    var el = $(id);
    if (!el) return;
    el.addEventListener('input', function () {
      var raw = String(el.value).replace(/[^\d]/g, '');
      if (id === 'saleConv' || id === 'margin') el.value = raw ? String(parseInt(raw, 10)) : '';
      else el.value = raw ? fmt(parseInt(raw, 10)) : '';
      recalc(true);
    });
    el.addEventListener('focus', function () { el.select(); });
  }

  function setMode(mode) {
    profitMode = mode;
    var sum = mode === 'sum';
    $('paneSum').hidden = !sum;
    $('paneMargin').hidden = sum;
    $('tgSum').classList.toggle('is-on', sum);
    $('tgMargin').classList.toggle('is-on', !sum);
    $('tgSum').setAttribute('aria-selected', String(sum));
    $('tgMargin').setAttribute('aria-selected', String(!sum));
    recalc(false);
  }

  // Снимок расчёта (не ПД) в скрытое поле формы. Пишется на каждый пересчёт.
  var snap = document.querySelector('[data-lead-form] [name="calc"]');
  function writeSnapshot() {
    if (!snap || !lastR) return;
    snap.value = 'Ниша: ' + lastR.niche
      + '; цена лида по нише: ' + rub(lastR.leadOpt) + '–' + rub(lastR.leadPess)
      + '; может платить за обращение: ' + rub(lastR.working)
      + '; вердикт: ' + lastR.title;
  }

  // ── Инициализация ─────────────────────────────────────────────────
  buildSelect();
  ['profit', 'check', 'margin', 'saleConv'].forEach(bindNumber);
  $('niche').addEventListener('change', function () { recalc(true); });
  $('tgSum').addEventListener('click', function () { setMode('sum'); });
  $('tgMargin').addEventListener('click', function () { setMode('margin'); });
  recalc(false);
})();
