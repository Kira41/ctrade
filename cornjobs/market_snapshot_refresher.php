<?php
require_once __DIR__ . '/../utils/market_data_provider.php';

try {
    $pdo = db();
    marketDataTableReady($pdo);
    $result = refreshQuotesSnapshot($pdo);

    if (empty($result['ok'])) {
        error_log(json_encode([
            'event' => 'market_snapshot_refresh_failed',
            'detail' => $result,
        ], JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $e) {
    error_log(json_encode([
        'event' => 'market_snapshot_refresh_exception',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE));
}
