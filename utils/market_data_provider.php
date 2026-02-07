<?php
require_once __DIR__ . '/../config/db_connection.php';

const COMMODITY_PROXY_UPSTREAM_HOST = '171.22.114.97';
const COMMODITY_PROXY_UPSTREAM_PORT = 8000;
const COMMODITY_PROXY_API_KEY = 'te_6XvQpK9jR2mN4sA7fH8uC1zL0wY3tG5eB9nD7kS2pV4qR8m';

function normalizeMarketPair(?string $rawPair): string {
    $decoded = urldecode((string)($rawPair ?? ''));
    $pair = strtoupper(trim($decoded));
    $pair = preg_replace('/[^A-Z0-9:\/\._\-]/', '', $pair ?? '');

    if ($pair === '') {
        return 'COINBASE:BTCUSD';
    }

    if (strpos($pair, ':') !== false) {
        [$exchange, $symbol] = array_pad(explode(':', $pair, 2), 2, '');
        $exchange = trim($exchange);
        $symbol = trim($symbol);
        if ($exchange === '' || $exchange === 'BINANCE') {
            $exchange = 'COINBASE';
        }
        if ($symbol === '') {
            $symbol = 'BTCUSD';
        }
        $symbol = str_replace('/', '', $symbol);
        if (preg_match('/^(.*)USDT$/', $symbol, $m) && !empty($m[1])) {
            $symbol = $m[1] . 'USD';
        }
        return $exchange . ':' . $symbol;
    }

    if (strpos($pair, '/') !== false) {
        [$base, $quote] = array_pad(explode('/', $pair, 2), 2, 'USD');
        $quote = $quote === 'USDT' ? 'USD' : $quote;
        return 'COINBASE:' . $base . $quote;
    }

    if (preg_match('/^([A-Z0-9\._\-]+)(USDT|USD)$/', $pair, $m)) {
        return 'COINBASE:' . $m[1] . ($m[2] === 'USDT' ? 'USD' : $m[2]);
    }

    return 'COINBASE:' . $pair . 'USD';
}

function marketDataTableReady(PDO $pdo): void {
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS market_data_cache (
            pair VARCHAR(64) PRIMARY KEY,
            source VARCHAR(32) NOT NULL,
            payload JSON NOT NULL,
            value DECIMAL(30,10) NULL,
            change_value DECIMAL(30,10) NULL,
            change_percent DECIMAL(30,10) NULL,
            open_value DECIMAL(30,10) NULL,
            high_value DECIMAL(30,10) NULL,
            low_value DECIMAL(30,10) NULL,
            previous_value DECIMAL(30,10) NULL,
            is_stale TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            last_fetch_ms INT NULL,
            last_error TEXT NULL,
            INDEX idx_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $ready = true;
}

function parseNumericValue($val): ?float {
    if (is_int($val) || is_float($val)) {
        return (float)$val;
    }
    if (!is_string($val)) {
        return null;
    }

    $normalized = trim(str_replace([',', '%', ' '], '', $val));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }
    return (float)$normalized;
}

function normalizeCommodityPayload(string $pair, array $upstream, bool $isStale = false): array {
    $value = null;
    foreach (['value', 'market_last', 'price', 'c', 'close', 'last', 'lp'] as $k) {
        if (array_key_exists($k, $upstream)) {
            $value = parseNumericValue($upstream[$k]);
            if ($value !== null) {
                break;
            }
        }
    }

    $changePercent = null;
    foreach (['changePercent', 'market_daily_Pchg'] as $k) {
        if (array_key_exists($k, $upstream)) {
            $changePercent = parseNumericValue($upstream[$k]);
            if ($changePercent !== null) {
                break;
            }
        }
    }

    $change = parseNumericValue($upstream['change'] ?? null);
    $open = parseNumericValue($upstream['open'] ?? null);
    $high = parseNumericValue($upstream['high'] ?? null);
    $low = parseNumericValue($upstream['low'] ?? null);
    $previous = parseNumericValue($upstream['previous'] ?? null);

    return [
        'ok' => true,
        'source' => 'commodity_proxy',
        'pair' => $pair,
        'name' => $upstream['name'] ?? $pair,
        'value' => $value,
        'change' => $change,
        'changePercent' => $changePercent,
        'open' => $open,
        'high' => $high,
        'low' => $low,
        'previous' => $previous,
        'is_stale' => $isStale,
        // Backward-compatible aliases
        'market_last' => $value,
        'price' => $value,
        'market_daily_Pchg' => $changePercent,
        'upstream' => $upstream,
    ];
}

function fetchCommodityUpstream(string $pair): array {
    $url = 'http://' . COMMODITY_PROXY_UPSTREAM_HOST . ':' . COMMODITY_PROXY_UPSTREAM_PORT
        . '/tv/quote?currencyPair=' . urlencode($pair);

    $started = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . COMMODITY_PROXY_API_KEY,
            'Accept: application/json',
            'User-Agent: CoinTrade-MarketDataProvider/1.0',
        ],
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tookMs = (int)((microtime(true) - $started) * 1000);

    if ($response === false) {
        return ['ok' => false, 'error' => 'curl_error', 'detail' => $curlErr, 'took_ms' => $tookMs];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'invalid_json', 'http_code' => $httpCode, 'raw' => mb_substr((string)$response, 0, 500), 'took_ms' => $tookMs];
    }

    if ($httpCode < 200 || $httpCode >= 300 || empty($data['ok'])) {
        return ['ok' => false, 'error' => 'upstream_failure', 'http_code' => $httpCode, 'payload' => $data, 'took_ms' => $tookMs];
    }

    return ['ok' => true, 'data' => $data, 'took_ms' => $tookMs];
}

function readCachedMarketData(PDO $pdo, string $pair): ?array {
    $stmt = $pdo->prepare('SELECT pair, payload, updated_at, is_stale FROM market_data_cache WHERE pair = ? LIMIT 1');
    $stmt->execute([$pair]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $payload = json_decode((string)$row['payload'], true);
    if (!is_array($payload)) {
        return null;
    }

    $payload['updated_at'] = $row['updated_at'];
    $payload['is_stale'] = (bool)$row['is_stale'];
    return $payload;
}

function upsertMarketCache(PDO $pdo, string $pair, array $payload, bool $isStale, ?int $fetchMs, ?string $lastError): void {
    $stmt = $pdo->prepare(
        'INSERT INTO market_data_cache
            (pair, source, payload, value, change_value, change_percent, open_value, high_value, low_value, previous_value, is_stale, updated_at, last_fetch_ms, last_error)
         VALUES
            (?, "commodity_proxy", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE
            source = VALUES(source),
            payload = VALUES(payload),
            value = VALUES(value),
            change_value = VALUES(change_value),
            change_percent = VALUES(change_percent),
            open_value = VALUES(open_value),
            high_value = VALUES(high_value),
            low_value = VALUES(low_value),
            previous_value = VALUES(previous_value),
            is_stale = VALUES(is_stale),
            updated_at = VALUES(updated_at),
            last_fetch_ms = VALUES(last_fetch_ms),
            last_error = VALUES(last_error)'
    );

    $stmt->execute([
        $pair,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        $payload['value'] ?? null,
        $payload['change'] ?? null,
        $payload['changePercent'] ?? null,
        $payload['open'] ?? null,
        $payload['high'] ?? null,
        $payload['low'] ?? null,
        $payload['previous'] ?? null,
        $isStale ? 1 : 0,
        $fetchMs,
        $lastError,
    ]);
}

function marketCacheFreshEnough(?array $payload, float $ttlSeconds): bool {
    if (!$payload || empty($payload['updated_at'])) {
        return false;
    }

    $updatedTs = strtotime((string)$payload['updated_at']);
    if ($updatedTs === false) {
        return false;
    }

    return (time() - $updatedTs) < $ttlSeconds;
}


function marketCacheDir(): string {
    $dir = sys_get_temp_dir() . '/ctrade_market_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function marketCachePath(string $pair): string {
    return marketCacheDir() . '/' . preg_replace('/[^A-Z0-9_]/', '_', $pair) . '.json';
}

function readFileCachedMarketData(string $pair): ?array {
    $path = marketCachePath($pair);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function upsertFileMarketCache(string $pair, array $payload): void {
    $payload['updated_at'] = $payload['updated_at'] ?? date('Y-m-d H:i:s');
    @file_put_contents(marketCachePath($pair), json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function getMarketDataWithFileCache(string $pair, float $ttlSeconds): array {
    $cache = readFileCachedMarketData($pair);
    if (marketCacheFreshEnough($cache, $ttlSeconds)) {
        error_log(json_encode(['event' => 'market_cache_hit_file', 'pair' => $pair]));
        return $cache;
    }

    $lockPath = marketCachePath($pair) . '.lock';
    $lockFp = fopen($lockPath, 'c+');
    if ($lockFp === false) {
        return $cache ?: ['ok' => false, 'pair' => $pair, 'is_stale' => true, 'error' => 'Unable to open cache lock'];
    }

    try {
        if (!flock($lockFp, LOCK_EX)) {
            return $cache ?: ['ok' => false, 'pair' => $pair, 'is_stale' => true, 'error' => 'Unable to lock cache'];
        }

        $cacheAfterLock = readFileCachedMarketData($pair);
        if (marketCacheFreshEnough($cacheAfterLock, $ttlSeconds)) {
            return $cacheAfterLock;
        }

        $upstream = fetchCommodityUpstream($pair);
        if (!empty($upstream['ok'])) {
            $payload = normalizeCommodityPayload($pair, $upstream['data'], false);
            $payload['updated_at'] = date('Y-m-d H:i:s');
            upsertFileMarketCache($pair, $payload);
            return $payload;
        }

        if ($cacheAfterLock) {
            $cacheAfterLock['ok'] = true;
            $cacheAfterLock['is_stale'] = true;
            upsertFileMarketCache($pair, $cacheAfterLock);
            return $cacheAfterLock;
        }

        return ['ok' => false, 'pair' => $pair, 'source' => 'commodity_proxy', 'is_stale' => true, 'error' => 'Unable to refresh market data and no cache available', 'detail' => $upstream];
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

function getMarketData(string $inputPair, float $ttlSeconds = 2.0): array {
    $pair = normalizeMarketPair($inputPair);

    try {
        $pdo = db();
    } catch (Throwable $e) {
        error_log(json_encode(['event' => 'market_db_unavailable', 'pair' => $pair, 'error' => $e->getMessage()]));
        return getMarketDataWithFileCache($pair, $ttlSeconds);
    }

    marketDataTableReady($pdo);

    $cache = readCachedMarketData($pdo, $pair);
    if (marketCacheFreshEnough($cache, $ttlSeconds)) {
        error_log(json_encode(['event' => 'market_cache_hit', 'pair' => $pair, 'ttl_seconds' => $ttlSeconds]));
        return $cache;
    }

    $lockName = 'market_data_refresh_' . preg_replace('/[^A-Z0-9_]/', '_', $pair);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 3)');
    $lockStmt->execute([$lockName]);
    $lockAcquired = ((int)$lockStmt->fetchColumn()) === 1;

    if (!$lockAcquired) {
        error_log(json_encode(['event' => 'market_lock_wait_timeout', 'pair' => $pair]));
        if ($cache) {
            $cache['is_stale'] = true;
            return $cache;
        }
        return ['ok' => false, 'pair' => $pair, 'error' => 'Could not acquire refresh lock', 'is_stale' => true];
    }

    try {
        $cacheAfterLock = readCachedMarketData($pdo, $pair);
        if (marketCacheFreshEnough($cacheAfterLock, $ttlSeconds)) {
            error_log(json_encode(['event' => 'market_cache_hit_after_lock', 'pair' => $pair]));
            return $cacheAfterLock;
        }

        $upstream = fetchCommodityUpstream($pair);
        if (!empty($upstream['ok'])) {
            $payload = normalizeCommodityPayload($pair, $upstream['data'], false);
            upsertMarketCache($pdo, $pair, $payload, false, $upstream['took_ms'] ?? null, null);
            $payload['updated_at'] = date('Y-m-d H:i:s');
            error_log(json_encode(['event' => 'market_refresh_success', 'pair' => $pair, 'fetch_ms' => $upstream['took_ms'] ?? null]));
            return $payload;
        }

        $errorDetail = json_encode($upstream, JSON_UNESCAPED_UNICODE);
        error_log(json_encode(['event' => 'market_refresh_failed', 'pair' => $pair, 'detail' => $upstream]));

        if ($cacheAfterLock) {
            $cacheAfterLock['ok'] = true;
            $cacheAfterLock['is_stale'] = true;
            upsertMarketCache($pdo, $pair, $cacheAfterLock, true, $upstream['took_ms'] ?? null, $errorDetail);
            return $cacheAfterLock;
        }

        return [
            'ok' => false,
            'pair' => $pair,
            'source' => 'commodity_proxy',
            'is_stale' => true,
            'error' => 'Unable to refresh market data and no cache available',
            'detail' => $upstream,
        ];
    } finally {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStmt->execute([$lockName]);
    }
}

function getMarketPrice(string $inputPair, float $ttlSeconds = 2.0): float {
    $payload = getMarketData($inputPair, $ttlSeconds);
    $price = parseNumericValue($payload['value'] ?? ($payload['market_last'] ?? null));
    return $price ?? 0.0;
}
