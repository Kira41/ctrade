<?php
// Cached JSON API proxy: reads from market_data_cache and auto-refreshes when stale.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../utils/market_data_provider.php';

try {
    $pdo = db();
    $force = isset($_GET['force']) && (string)$_GET['force'] === '1';
    $response = getQuotesSnapshotData($pdo, 1.0, $force);

    if (empty($response['ok'])) {
        http_response_code(502);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'quotes_client_proxy_failed',
        'message' => $e->getMessage(),
        'rows' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
