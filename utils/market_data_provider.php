<?php
require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/quotes_client_lib.php';

const MARKET_DATA_SNAPSHOT_PAIR = '__SNAPSHOT__';

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

    $createStatements = [
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS market_data_cache (
            pair VARCHAR(64) PRIMARY KEY,
            source VARCHAR(32) NOT NULL,
            payload LONGTEXT NOT NULL,
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
    ];

    $lastError = null;
    foreach ($createStatements as $statement) {
        try {
            $pdo->exec($statement);
            $ready = true;
            return;
        } catch (PDOException $e) {
            $lastError = $e;
        }
    }

    if ($lastError instanceof PDOException) {
        throw $lastError;
    }
}

function parseNumericValue($val): ?float {
    if (is_int($val) || is_float($val)) {
        return (float)$val;
    }
    if (!is_string($val)) {
        return null;
    }

    $text = trim(str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', $val));
    $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);

    $multiplier = 1.0;
    if (preg_match('/([KMB])\s*$/i', $text, $suffixMatch)) {
        $suffix = strtoupper($suffixMatch[1]);
        $multiplier = $suffix === 'B' ? 1000000000.0 : ($suffix === 'M' ? 1000000.0 : 1000.0);
        $text = preg_replace('/([KMB])\s*$/i', '', $text) ?? $text;
    }

    $normalized = trim(str_replace([',', '%', ' '], '', $text));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }
    return (float)$normalized * $multiplier;
}

function marketPairToRowName(string $pair): string {
    $normalized = normalizeMarketPair($pair);
    [$exchange, $symbol] = array_pad(explode(':', $normalized, 2), 2, '');
    $symbol = strtoupper($symbol);

    if (preg_match('/^([A-Z0-9\._\-]+)(USD|USDT)$/', $symbol, $m)) {
        $quote = $m[2] === 'USDT' ? 'USD' : $m[2];
        return $m[1] . '/' . $quote;
    }

    return str_replace(':', '/', $normalized);
}

function marketPairCandidateNames(string $pair): array {
    $normalized = normalizeMarketPair($pair);
    $primary = marketPairToRowName($normalized);

    $aliasesByPair = [
        'FOREXCOM:DJI' => ['US 30', 'US30', 'DOW JONES 30', 'DOW JONES', 'WALL STREET'],
        'FOREXCOM:SPXUSD' => ['US 500', 'US500', 'S&P 500', 'SPX 500'],
        'FOREXCOM:NSXUSD' => ['US TECH 100', 'US-TECH 100', 'NASDAQ 100', 'NAS 100'],
        'FOREXCOM:UKXGBP' => ['UK 100', 'UK100', 'FTSE 100'],
        'FOREXCOM:US2000' => ['US SMALL CAP 2000', 'US SMALLCAP 2000', 'RUSSELL 2000'],
    ];

    $candidates = [$primary];
    foreach ($aliasesByPair[$normalized] ?? [] as $alias) {
        $clean = trim((string)$alias);
        if ($clean !== '') {
            $candidates[] = $clean;
        }
    }

    return array_values(array_unique($candidates));
}

function normalizeCommodityPayload(string $pair, array $upstream, bool $isStale = false): array {
    $value = parseNumericValue($upstream['Value'] ?? ($upstream['value'] ?? null));
    $changePercent = parseNumericValue($upstream['Chg%'] ?? ($upstream['changePercent'] ?? null));
    $change = parseNumericValue($upstream['Change'] ?? ($upstream['change'] ?? null));
    $open = parseNumericValue($upstream['Open'] ?? ($upstream['open'] ?? null));
    $high = parseNumericValue($upstream['High'] ?? ($upstream['high'] ?? null));
    $low = parseNumericValue($upstream['Low'] ?? ($upstream['low'] ?? null));
    $previous = parseNumericValue($upstream['Prev'] ?? ($upstream['previous'] ?? null));

    return [
        'ok' => true,
        'source' => 'quotes_client',
        'pair' => $pair,
        'name' => $upstream['Name'] ?? ($upstream['name'] ?? $pair),
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
    $quotesPayload = quotesClientFetchPayload();
    if (empty($quotesPayload['ok'])) {
        return [
            'ok' => false,
            'error' => 'quotes_client_failure',
            'detail' => $quotesPayload,
            'took_ms' => $quotesPayload['took_ms'] ?? null,
        ];
    }

    $targetNames = marketPairCandidateNames($pair);
    $rows = $quotesPayload['rows'] ?? [];

    foreach ($targetNames as $candidate) {
        $row = quotesClientFindRowByPairName($rows, $candidate);
        if ($row !== null) {
            return ['ok' => true, 'data' => $row, 'took_ms' => $quotesPayload['took_ms'] ?? null];
        }
    }

    return [
        'ok' => false,
        'error' => 'pair_not_found',
        'detail' => ['pair' => $pair, 'target_name' => $targetNames[0] ?? null, 'target_candidates' => $targetNames],
        'took_ms' => $quotesPayload['took_ms'] ?? null,
    ];
}

function readQuotesSnapshotCache(PDO $pdo): ?array {
    $stmt = $pdo->prepare('SELECT payload, updated_at, is_stale FROM market_data_cache WHERE pair = ? LIMIT 1');
    $stmt->execute([MARKET_DATA_SNAPSHOT_PAIR]);
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

function upsertQuotesSnapshotCache(PDO $pdo, array $payload, bool $isStale, ?int $fetchMs, ?string $lastError): void {
    $stmt = $pdo->prepare(
        'INSERT INTO market_data_cache (pair, source, payload, is_stale, updated_at, last_fetch_ms, last_error)
         VALUES (?, "quotes_client", ?, ?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE
            source = VALUES(source),
            payload = VALUES(payload),
            is_stale = VALUES(is_stale),
            updated_at = VALUES(updated_at),
            last_fetch_ms = VALUES(last_fetch_ms),
            last_error = VALUES(last_error)'
    );

    $stmt->execute([
        MARKET_DATA_SNAPSHOT_PAIR,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        $isStale ? 1 : 0,
        $fetchMs,
        $lastError,
    ]);
}

function refreshQuotesSnapshot(PDO $pdo): array {
    $quotesPayload = quotesClientFetchPayload();
    if (!empty($quotesPayload['ok'])) {
        upsertQuotesSnapshotCache($pdo, $quotesPayload, false, $quotesPayload['took_ms'] ?? null, null);

        $updatedPairs = 0;
        $seenPairs = [];
        foreach (($quotesPayload['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rawName = trim((string)($row['Name'] ?? ($row['name'] ?? '')));
            if ($rawName === '') {
                continue;
            }

            $pair = normalizeMarketPair($rawName);
            if ($pair === '' || isset($seenPairs[$pair])) {
                continue;
            }
            $seenPairs[$pair] = true;

            $normalized = normalizeCommodityPayload($pair, $row, false);
            upsertMarketCache($pdo, $pair, $normalized, false, $quotesPayload['took_ms'] ?? null, null);
            $updatedPairs++;
        }

        $quotesPayload['updated_at'] = date('Y-m-d H:i:s');
        $quotesPayload['cache_pairs_updated'] = $updatedPairs;
        return $quotesPayload;
    }

    $errorDetail = json_encode($quotesPayload, JSON_UNESCAPED_UNICODE);
    $cached = readQuotesSnapshotCache($pdo);
    if ($cached) {
        $cached['ok'] = true;
        $cached['is_stale'] = true;
        upsertQuotesSnapshotCache($pdo, $cached, true, $quotesPayload['took_ms'] ?? null, $errorDetail);
        return $cached;
    }

    return [
        'ok' => false,
        'is_stale' => true,
        'error' => 'quotes_client_failure',
        'detail' => $quotesPayload,
    ];
}

/**
 * Return snapshot rows from market_data_cache and auto-refresh when stale.
 * This is the single source of truth for bulk prices used by controllers.
 */
function getQuotesSnapshotData(PDO $pdo, float $ttlSeconds = 1.0, bool $forceRefresh = false): array {
    marketDataTableReady($pdo);

    $snapshot = readQuotesSnapshotCache($pdo);
    if (!$forceRefresh && $snapshot && marketCacheFreshEnough($snapshot, $ttlSeconds)) {
        return $snapshot;
    }

    $lockName = 'market_data_snapshot_refresh';
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 3)');
    $lockStmt->execute([$lockName]);
    $lockAcquired = ((int)$lockStmt->fetchColumn()) === 1;

    if (!$lockAcquired) {
        if ($snapshot) {
            $snapshot['ok'] = true;
            $snapshot['is_stale'] = true;
            return $snapshot;
        }
        return [
            'ok' => false,
            'error' => 'Could not acquire refresh lock',
            'rows' => [],
            'is_stale' => true,
        ];
    }

    try {
        // Re-check under lock to avoid duplicate upstream calls.
        $snapshotAfterLock = readQuotesSnapshotCache($pdo);
        if (!$forceRefresh && $snapshotAfterLock && marketCacheFreshEnough($snapshotAfterLock, $ttlSeconds)) {
            return $snapshotAfterLock;
        }

        return refreshQuotesSnapshot($pdo);
    } finally {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStmt->execute([$lockName]);
    }
}

function findPairRowInSnapshot(?array $snapshot, string $pair): ?array {
    if (!is_array($snapshot)) {
        return null;
    }

    $rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
    foreach (marketPairCandidateNames($pair) as $candidate) {
        $row = quotesClientFindRowByPairName($rows, $candidate);
        if ($row !== null) {
            return $row;
        }
    }

    return null;
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
            (?, "quotes_client", ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
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

        return ['ok' => false, 'pair' => $pair, 'source' => 'quotes_client', 'is_stale' => true, 'error' => 'Unable to refresh market data and no cache available', 'detail' => $upstream];
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

    $snapshot = getQuotesSnapshotData($pdo, $ttlSeconds, false);
    $row = findPairRowInSnapshot($snapshot, $pair);
    if ($row) {
        $payload = normalizeCommodityPayload($pair, $row, false);
        $payload['updated_at'] = $snapshot['updated_at'] ?? date('Y-m-d H:i:s');
        return $payload;
    }

    // Pair may be missing from current snapshot (new symbol), force one refresh.
    $refreshedSnapshot = getQuotesSnapshotData($pdo, $ttlSeconds, true);
    $refreshedRow = findPairRowInSnapshot($refreshedSnapshot, $pair);
    if ($refreshedRow) {
        $payload = normalizeCommodityPayload($pair, $refreshedRow, !empty($refreshedSnapshot['is_stale']));
        $payload['updated_at'] = $refreshedSnapshot['updated_at'] ?? date('Y-m-d H:i:s');
        return $payload;
    }

    return ['ok' => false, 'pair' => $pair, 'is_stale' => true, 'error' => 'Pair not found in snapshot cache'];
}

function getMarketPrice(string $inputPair, float $ttlSeconds = 2.0): float {
    $payload = getMarketData($inputPair, $ttlSeconds);
    $price = parseNumericValue($payload['value'] ?? ($payload['market_last'] ?? null));
    return $price ?? 0.0;
}
