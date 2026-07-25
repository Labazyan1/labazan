<?php
// Общие утилиты движка аудита (для check-html.php и check-speed.php).
// Это НЕ эндпоинт: только функции, прямой вызов по URL ничего не выводит.
// Закрыт в .htaccess (FilesMatch "^_"). PHP 7.4+.
//
// Перенесён из проекта audit-labazan (консолидация: единый движок на основном сайте).
// SSRF-защита — red line, менять только с /security-review.

if (!defined('AUDIT_LIB')) {
    define('AUDIT_LIB', 1);

    // Ошибки не выводим в тело ответа: иначе сломается JSON и утекут пути сервера.
    @ini_set('display_errors', '0');

    // --- Ответ всегда JSON: инструмент дёргается фронтом через fetch. ---
    function audit_out($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Разрешаем cross-origin вызовы только с наших доменов. Инструмент теперь на самом
    // labazan.ru (same-origin, CORS не триггерится), но beta-превью бьёт кросс-доменно.
    function audit_cors() {
        $allow = ['https://labazan.ru', 'https://www.labazan.ru', 'https://beta.labazan.ru'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $ok = in_array($origin, $allow, true);
            // тех-поддомены Beget для предпросмотра
            if (!$ok) {
                $h = parse_url($origin, PHP_URL_HOST);
                if ($h && substr($h, -11) === '.beget.tech') $ok = true;
            }
            if ($ok) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Methods: POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Accept');
                header('Access-Control-Max-Age: 600');
            }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    function audit_clean($v, $max = 2000) {
        $v = is_string($v) ? $v : '';
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
        return mb_substr(trim($v), 0, $max);
    }

    // Rate-limit по IP: не более $max запросов за $window секунд. Метки в файле,
    // с блокировкой. $bucket разделяет лимиты разных эндпоинтов.
    function audit_rate_limit_ok($max = 10, $window = 60, $bucket = 'html') {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $dir = sys_get_temp_dir() . '/labazan_audit_rl_' . preg_replace('/[^a-z0-9]/i', '', $bucket);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (mt_rand(1, 50) === 1) {
            foreach (glob($dir . '/*') ?: [] as $old) {
                if (is_file($old) && (time() - filemtime($old)) > 3600) @unlink($old);
            }
        }
        $file = $dir . '/' . hash('sha256', $ip);
        $fp = @fopen($file, 'c+');
        if ($fp === false) return true; // ФС недоступна — не блокируем
        $allowed = true;
        if (flock($fp, LOCK_EX)) {
            $now = time();
            $times = [];
            $raw = stream_get_contents($fp);
            if ($raw !== false && $raw !== '') {
                foreach (explode("\n", trim($raw)) as $t) {
                    if ($t !== '' && ($now - (int) $t) < $window) $times[] = (int) $t;
                }
            }
            $allowed = count($times) < $max;
            if ($allowed) $times[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, implode("\n", $times));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $allowed;
    }

    // Глобальный дневной потолок вызовов (ОДИН счётчик на всех, не по IP), сброс по дате UTC.
    // Зачем поверх per-IP: per-IP обходится ротацией адресов. Здесь два риска —
    //   1) аудит превращают в сканер чужих публичных сайтов (защищает html-бакет);
    //   2) один шутник выжигает дневную квоту PageSpeed и инструмент умирает (psi-бакет).
    // Слот РЕЗЕРВИРУЕТСЯ на проверке (инкремент до внешнего вызова): при злоупотреблении
    // важнее не пустить, чем идеально сосчитать редкие неуспешные вызовы. Возврат: true = можно.
    function audit_daily_quota_ok($max, $bucket = 'psi') {
        $max = (int) $max;
        if ($max <= 0) return true; // 0/отрицательное = потолок выключен
        $dir = sys_get_temp_dir() . '/labazan_audit_quota';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        // Подчистка позавчерашних файлов (счётчик за день — имя с датой).
        if (mt_rand(1, 50) === 1) {
            foreach (glob($dir . '/*') ?: [] as $old) {
                if (is_file($old) && (time() - filemtime($old)) > 172800) @unlink($old);
            }
        }
        $key  = preg_replace('/[^a-z0-9]/i', '', (string) $bucket);
        $file = $dir . '/' . $key . '_' . gmdate('Ymd');
        $fp = @fopen($file, 'c+');
        if ($fp === false) return true; // ФС недоступна — не блокируем
        $ok = true;
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $count = (int) trim((string) $raw);
            if ($count >= $max) {
                $ok = false;
            } else {
                $count++;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, (string) $count);
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return $ok;
    }

    // --- SSRF-защита ---

    // Публичный ли IP: отсекает приватные (10/8, 172.16/12, 192.168/16, fc00::/7)
    // и зарезервированные (127/8, 169.254/16 включая метадата-адрес, 0.0.0.0/8,
    // ::1, fe80::/10, 240/4 и др.).
    function audit_is_public_ip($ip) {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    // Все IP хоста (A + AAAA). Литерал-IP возвращается как есть.
    function audit_resolve_ips($host) {
        $host = trim($host, "[]"); // на случай IPv6-литерала в скобках
        // IP-литерал — проверяем напрямую, DNS не нужен.
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $ips = [];
        $a = @gethostbynamel($host);
        if (is_array($a)) $ips = array_merge($ips, $a);
        if (function_exists('dns_get_record')) {
            $recs = @dns_get_record($host, DNS_AAAA);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    // Нормализуем ввод в валидный http(s)-URL или вернём [null, код-ошибки].
    // Если схемы нет — подставляем https (пометка в $assumed).
    function audit_normalize_url($raw, &$assumed = false) {
        $raw = trim((string) $raw);
        if ($raw === '' || mb_strlen($raw) > 2000) return [null, 'invalid'];
        $assumed = false;
        if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $raw)) {
            $raw = 'https://' . $raw;
            $assumed = true;
        }
        $p = parse_url($raw);
        if ($p === false || empty($p['host'])) return [null, 'invalid'];
        $scheme = strtolower($p['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') return [null, 'scheme'];
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) return [null, 'port'];
        // Пересобираем URL из разобранных частей (отбрасываем креды user:pass@).
        $host = $p['host'];
        $path = $p['path'] ?? '/';
        $query = isset($p['query']) ? '?' . $p['query'] : '';
        $url = $scheme . '://' . $host . (isset($p['port']) ? ':' . $port : '') . $path . $query;
        return [$url, null];
    }

    function audit_host_port($url) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? 'https');
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        return [$host, $port, $scheme];
    }

    // Безопасно скачать HTML: ручной follow редиректов с повторной проверкой IP
    // (anti-rebinding), фиксация проверенного IP через CURLOPT_RESOLVE, лимит
    // размера, таймауты, только 80/443. Возврат: [body|null, err|null, meta].
    function audit_safe_fetch($rawUrl, $maxRedirects = 3, $maxBytes = 2000000) {
        if (!function_exists('curl_init')) return [null, 'no_curl', null];

        $assumed = false;
        [$url, $err] = audit_normalize_url($rawUrl, $assumed);
        if ($url === null) return [null, $err, null];
        $triedHttpFallback = false;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            [$host, $port, $scheme] = audit_host_port($url);
            $ips = audit_resolve_ips($host);
            if (!$ips) {
                // DNS не разрешился. Если схему мы подставили как https —
                // это ещё не значит http-фолбэк; dns един для хоста.
                return [null, 'dns', null];
            }
            foreach ($ips as $ip) {
                if (!audit_is_public_ip($ip)) return [null, 'blocked', null];
            }
            // Предпочитаем IPv4 (первым идёт gethostbynamel).
            $pinned = $ips[0];
            // Для CURLOPT_RESOLVE адрес IPv6 нужно обернуть в скобки.
            $pinnedFmt = strpos($pinned, ':') !== false ? '[' . $pinned . ']' : $pinned;

            $ch = curl_init();
            $body = '';
            $overLimit = false;
            curl_setopt_array($ch, [
                CURLOPT_URL              => $url,
                CURLOPT_FOLLOWLOCATION   => false,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_HEADER           => false,
                CURLOPT_NOBODY           => false,
                CURLOPT_CONNECTTIMEOUT   => 5,
                CURLOPT_TIMEOUT          => 12,
                CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT        => 'LabazanAuditBot/1.0 (+https://labazan.ru/pochemu-net-zayavok/)',
                CURLOPT_ACCEPT_ENCODING  => '', // curl сам распакует gzip/br
                CURLOPT_SSL_VERIFYPEER   => true,
                CURLOPT_SSL_VERIFYHOST   => 2,
                // Пиним проверенный IP: curl не будет резолвить заново (anti-rebinding),
                // но SNI/Host останутся по домену.
                CURLOPT_RESOLVE          => [$host . ':' . $port . ':' . $pinnedFmt],
                CURLOPT_WRITEFUNCTION    => function ($ch, $chunk) use (&$body, $maxBytes, &$overLimit) {
                    $body .= $chunk;
                    if (strlen($body) >= $maxBytes) { $overLimit = true; return 0; }
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $redir = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $errno = curl_errno($ch);
            curl_close($ch);

            $writeAbort = defined('CURLE_WRITE_ERROR') ? CURLE_WRITE_ERROR : 23;

            // Ошибка соединения (не наш обрыв по лимиту) и тела нет.
            if ($ok === false && $errno !== $writeAbort && $body === '') {
                // Консервативные сайты: если мы сами подставили https, пробуем http.
                if ($assumed && !$triedHttpFallback && $scheme === 'https') {
                    $triedHttpFallback = true;
                    $url = 'http://' . $host . ($port !== 80 && $port !== 443 ? ':' . $port : '') . (parse_url($url, PHP_URL_PATH) ?: '/');
                    $assumed = false;
                    $hop--; // повтор без счёта редиректа
                    continue;
                }
                return [null, 'fetch', ['code' => $code]];
            }

            // Редирект — валидируем следующий адрес и идём по кругу.
            if ($code >= 300 && $code < 400 && $redir !== '' && !$overLimit) {
                $assumed = false;
                [$next, $nerr] = audit_normalize_url($redir, $assumed);
                if ($next === null) return [null, 'blocked', null];
                $url = $next;
                continue;
            }

            return [$body, null, [
                'code'    => $code,
                'ctype'   => $ctype,
                'final'   => $url,
                'trimmed' => $overLimit,
            ]];
        }
        return [null, 'too_many_redirects', null];
    }
}
