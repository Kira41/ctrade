<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../utils/market_data_provider.php';

try {
    $pdo = db();
    $force = isset($_GET['force']) && (string)$_GET['force'] === '1';

    // Setter endpoint: designed for cron/worker every 1s.
    // It updates market_data_cache snapshot and per-pair cache rows.
    $snapshot = getQuotesSnapshotData($pdo, 1.0, $force);

    if (empty($snapshot['ok'])) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'snapshot_refresh_failed',
            'detail' => $snapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'source' => 'market_data_cache',
        'rows' => is_array($snapshot['rows'] ?? null) ? count($snapshot['rows']) : 0,
        'updated_at' => $snapshot['updated_at'] ?? null,
        'is_stale' => !empty($snapshot['is_stale']),
        'took_ms' => $snapshot['took_ms'] ?? null,
        'cache_pairs_updated' => $snapshot['cache_pairs_updated'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'setter_price_failed',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
