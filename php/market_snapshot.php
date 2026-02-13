<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../utils/market_data_provider.php';

try {
    $pdo = db();
    $force = isset($_GET['force']) && (string)$_GET['force'] === '1';

    // Auto setter behavior on read: refresh stale/missing snapshot automatically.
    $snapshot = getQuotesSnapshotData($pdo, 1.0, $force);
    $snapshot['ok'] = !empty($snapshot['ok']);
    $snapshot['rows'] = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];

    if (empty($snapshot['ok'])) {
        http_response_code(503);
    }

    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'snapshot_cache_read_failed',
        'message' => $e->getMessage(),
        'rows' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
