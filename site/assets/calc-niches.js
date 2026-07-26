/* ══════════════════════════════════════════════════════════════════
   Лабазан — справочник ниш для калькулятора рекламы.
   ЭТО ЕДИНСТВЕННОЕ МЕСТО, ГДЕ ПРАВЯТСЯ ЦИФРЫ. Логика в calc-reklama.js
   их не хранит, только читает отсюда.

   На каждую нишу два диапазона (вилки под регион, Дагестан дешевле МСК):
     click [от, до]  — цена клика в Яндекс.Директе, ₽
     conv  [от, до]  — конверсия сайта в обращение, %

   ⚠️ Это ОРИЕНТИРЫ по рынку, не факты конкретного города. В интерфейсе
   идут с пометкой «ориентир, точное даёт разведка». Калибруются здесь,
   по мере реальных данных из кампаний. Деньги: правит владелец.
   ══════════════════════════════════════════════════════════════════ */
window.LABAZAN_CALC = {
  defaultNiche: 'potolki',
  groups: [
    { id: 'stroy', label: 'Стройка и интерьер' },
    { id: 'uslugi', label: 'Услуги и смежные' },
  ],
  niches: [
    { id: 'okna',        group: 'stroy',  label: 'Окна',                          click: [25, 70],  conv: [3, 8] },
    { id: 'dveri',       group: 'stroy',  label: 'Двери',                         click: [20, 60],  conv: [3, 8] },
    { id: 'potolki',     group: 'stroy',  label: 'Натяжные потолки',              click: [20, 55],  conv: [4, 9] },
    { id: 'santehnika',  group: 'stroy',  label: 'Сантехника',                    click: [25, 65],  conv: [3, 7] },
    { id: 'plitka',      group: 'stroy',  label: 'Плитка и укладка',              click: [20, 55],  conv: [3, 7] },
    { id: 'kuhni',       group: 'stroy',  label: 'Кухни на заказ',                click: [30, 90],  conv: [2, 6] },
    { id: 'mebel',       group: 'stroy',  label: 'Мебель и шкафы',                click: [25, 75],  conv: [2, 6] },
    { id: 'shtory',      group: 'stroy',  label: 'Шторы и текстиль',              click: [20, 55],  conv: [3, 8] },
    { id: 'dekor',       group: 'stroy',  label: 'Декор',                         click: [20, 55],  conv: [2, 6] },
    { id: 'medicina',    group: 'uslugi', label: 'Медицина (стом., космет.)',     click: [60, 200], conv: [3, 8] },
    { id: 'avto',        group: 'uslugi', label: 'Автобизнес (сервис, тюнинг)',   click: [30, 100], conv: [3, 8] },
    { id: 'svadba',      group: 'uslugi', label: 'Свадебная индустрия',           click: [25, 80],  conv: [3, 7] },
    { id: 'turizm',      group: 'uslugi', label: 'Туризм и гостевые дома',        click: [15, 50],  conv: [3, 9] },
    { id: 'rieltor',     group: 'uslugi', label: 'Риелторы и агентства',          click: [30, 120], conv: [2, 6] },
    { id: 'yuristy',     group: 'uslugi', label: 'Юристы по недвижимости',        click: [80, 300], conv: [3, 8] },
    { id: 'ocenka',      group: 'uslugi', label: 'Оценщики',                      click: [40, 150], conv: [4, 10] },
    { id: 'opt',         group: 'uslugi', label: 'Оптовые стройматериалы',        click: [25, 80],  conv: [2, 6] },
    { id: 'spectehnika', group: 'uslugi', label: 'Аренда спецтехники',            click: [30, 100], conv: [3, 8] },
    { id: 'metall',      group: 'uslugi', label: 'Металлоконструкции',            click: [25, 80],  conv: [2, 6] },
    { id: 'design',      group: 'uslugi', label: 'Дизайнеры и проектные бюро',    click: [30, 110], conv: [2, 6] },
  ],
};
