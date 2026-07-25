<?php
// Движок аудита: замер скорости через Google PageSpeed Insights API.
// Целевой сайт фетчит САМ Google (мы лишь передаём ему адрес параметром), поэтому
// прямого SSRF на наш сервер тут нет. Всё равно нормализуем и проверяем адрес теми же
// правилами, что и check-html.php: только http/https, только публичные IP, порты 80/443,
// rate-limit по IP + дневной потолок — чтобы не жечь дневную квоту PSI на мусорных/внутренних
// адресах и чтобы один шутник не выжег квоту и не убил инструмент для реальных клиентов.
// Ключ PSI лежит в gitignored audit-config.php (закрыт в .htaccess). PHP 7.4+.

require __DIR__ . '/_audit-lib.php';

// Секрет: ключ PageSpeed Insights. Пусто = эндпоинт ещё не сконфигурирован.
$PSI_API_KEY = '';
// Дневной потолок вызовов PSI (глобальный, не по IP). Настраивается в audit-config.php.
$PSI_DAILY_MAX = 200;
foreach ([__DIR__ . '/audit-config.php', __DIR__ . '/../audit-config.php'] as $cfg) {
    if (is_file($cfg)) { require $cfg; break; }
}

audit_cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    audit_out(['ok' => false, 'error' => 'method', 'message' => 'Некорректный запрос.'], 405);
}
// Скорость дороже HTML-проверки (внешний вызов + квота) - лимит строже.
if (!audit_rate_limit_ok(5, 60, 'speed')) {
    audit_out(['ok' => false, 'error' => 'rate_limited', 'message' => 'Слишком много проверок скорости подряд. Подождите минуту.'], 429);
}

$url_in = audit_clean($_POST['url'] ?? '', 2000);
if ($url_in === '') {
    audit_out(['ok' => false, 'error' => 'empty', 'message' => 'Укажите адрес сайта.'], 422);
}

// Нормализация + SSRF-фильтр (те же правила, что у HTML-движка).
$assumed = false;
[$url, $nerr] = audit_normalize_url($url_in, $assumed);
if ($url === null) {
    $map = [
        'invalid' => ['Не похоже на адрес сайта. Пример: example.ru', 422],
        'scheme'  => ['Поддерживаются только адреса http и https.', 422],
        'port'    => ['Можно проверять только стандартные адреса (порты 80 и 443).', 422],
    ];
    $m = $map[$nerr] ?? ['Не удалось разобрать адрес.', 422];
    audit_out(['ok' => false, 'error' => $nerr, 'message' => $m[0]], $m[1]);
}
// Адрес должен резолвиться в публичный IP: PSI всё равно не достучится до внутренних,
// но так мы не тратим квоту и не даём прощупывать инфраструктуру через сервис.
[$host] = audit_host_port($url);
$ips = audit_resolve_ips($host);
if (!$ips) {
    audit_out(['ok' => false, 'error' => 'dns', 'message' => 'Не удалось найти такой сайт. Проверьте адрес.'], 422);
}
foreach ($ips as $ip) {
    if (!audit_is_public_ip($ip)) {
        audit_out(['ok' => false, 'error' => 'blocked', 'message' => 'Этот адрес проверить нельзя.'], 422);
    }
}

if ($PSI_API_KEY === '') {
    // Ключа нет - фронт покажет «скорость измерим позже», сам разбор не ломается.
    audit_out(['ok' => false, 'error' => 'not_configured', 'message' => 'Замер скорости ещё настраивается.'], 503);
}
if (!function_exists('curl_init')) {
    audit_out(['ok' => false, 'error' => 'no_curl', 'message' => 'Сервис временно недоступен. Попробуйте позже.'], 503);
}

// Дневной потолок PSI: резервируем слот ПЕРЕД вызовом. Один шутник не должен выжечь
// квоту PageSpeed и убить инструмент для реальных клиентов. Проверяем последним, когда
// уже ясно, что запрос дойдёт до PSI (адрес валиден, ключ есть, curl есть).
if (!audit_daily_quota_ok($PSI_DAILY_MAX, 'psi')) {
    audit_out(['ok' => false, 'error' => 'quota_exhausted', 'message' => 'Сегодня лимит проверок скорости исчерпан. Напишите — посмотрю сайт руками.'], 429);
}

// --- Запрос к PageSpeed Insights v5 (mobile, категория performance) ---
// URL цели уходит СТРОГО через http_build_query → корректное экранирование, никакой
// инъекции параметров/ключа. Хост googleapis.com фиксирован.
$endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
    'url'      => $url,
    'key'      => $PSI_API_KEY,
    'strategy' => 'mobile',
    'category' => 'performance',
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 40,          // PSI сам грузит сайт - ответ бывает долгим
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT      => 'LabazanAuditBot/1.0 (+https://labazan.ru/pochemu-net-zayavok/)',
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $resp === '') {
    audit_out(['ok' => false, 'error' => 'psi_unreachable', 'message' => 'Не удалось замерить скорость. Попробуйте позже.'], 502);
}
$json = json_decode($resp, true);
if (!is_array($json)) {
    audit_out(['ok' => false, 'error' => 'psi_bad', 'message' => 'Не удалось разобрать ответ сервиса скорости.'], 502);
}
// PSI вернул ошибку (например, не смог загрузить целевой сайт).
if ($code !== 200 || isset($json['error'])) {
    audit_out(['ok' => false, 'error' => 'psi_error', 'message' => 'Сервис скорости не смог проверить этот сайт.'], 502);
}

$lh = $json['lighthouseResult'] ?? [];
$score = $lh['categories']['performance']['score'] ?? null; // 0..1 или null
$audits = $lh['audits'] ?? [];

// Достаём метрику: числовое значение + человекочитаемое displayValue.
function psi_metric($audits, $id) {
    $a = $audits[$id] ?? null;
    if (!is_array($a)) return null;
    return [
        'value'   => isset($a['numericValue']) ? (float) $a['numericValue'] : null,
        'display' => isset($a['displayValue']) ? (string) $a['displayValue'] : '',
    ];
}
$lcp = psi_metric($audits, 'largest-contentful-paint');
$cls = psi_metric($audits, 'cumulative-layout-shift');
$tbt = psi_metric($audits, 'total-blocking-time');

// --- Собираем «строки сметы» скорости с честной оценкой ok/warn/bad ---
$rows = [];

// Общий балл (Lighthouse: >=90 хорошо, 50-89 средне, <50 плохо).
if ($score !== null) {
    $s = (int) round($score * 100);
    $st = $s >= 90 ? 'ok' : ($s >= 50 ? 'warn' : 'bad');
    $rows[] = ['id' => 'speed_score', 'status' => $st, 'label' => 'Оценка скорости (мобильные)',
        'detail' => 'Общий балл PageSpeed: ' . $s . ' из 100.'];
}
// LCP - загрузка главного экрана (порог Google: 2.5с / 4с).
if ($lcp && $lcp['value'] !== null) {
    $sec = $lcp['value'] / 1000;
    $st = $sec <= 2.5 ? 'ok' : ($sec <= 4 ? 'warn' : 'bad');
    $rows[] = ['id' => 'speed_lcp', 'status' => $st, 'label' => 'Загрузка главного экрана (LCP)',
        'detail' => 'Основной контент виден за ' . ($lcp['display'] !== '' ? $lcp['display'] : round($sec, 1) . ' с') . '. Норма - до 2,5 с.'];
}
// CLS - скачки вёрстки (порог: 0.1 / 0.25).
if ($cls && $cls['value'] !== null) {
    $v = $cls['value'];
    $st = $v <= 0.1 ? 'ok' : ($v <= 0.25 ? 'warn' : 'bad');
    $rows[] = ['id' => 'speed_cls', 'status' => $st, 'label' => 'Скачки вёрстки (CLS)',
        'detail' => 'Смещение элементов при загрузке: ' . ($cls['display'] !== '' ? $cls['display'] : round($v, 2)) . '. Норма - до 0,1.'];
}
// TBT - отзывчивость (порог Lighthouse: 200мс / 600мс).
if ($tbt && $tbt['value'] !== null) {
    $ms = $tbt['value'];
    $st = $ms <= 200 ? 'ok' : ($ms <= 600 ? 'warn' : 'bad');
    $rows[] = ['id' => 'speed_tbt', 'status' => $st, 'label' => 'Отзывчивость (блокировка потока)',
        'detail' => 'Скрипты блокируют страницу на ' . ($tbt['display'] !== '' ? $tbt['display'] : round($ms) . ' мс') . '. Норма - до 200 мс.'];
}

if (!$rows) {
    audit_out(['ok' => false, 'error' => 'psi_empty', 'message' => 'Сервис скорости не вернул данных по этому сайту.'], 502);
}

audit_out([
    'ok'         => true,
    'url'        => $url,
    'strategy'   => 'mobile',
    'fetched_at' => gmdate('c'),
    'checks'     => $rows,
]);
