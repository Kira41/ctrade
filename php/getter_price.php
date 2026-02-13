<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../utils/market_data_provider.php';

try {
    $pdo = db();
    $pair = isset($_GET['pair']) ? trim((string)$_GET['pair']) : '';
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 1000)) : 300;

    // Auto setter logic: read from cache, refresh if stale/missing.
    $snapshot = getQuotesSnapshotData($pdo, 1.0, false);
    $rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];

    if ($pair !== '') {
        $row = quotesClientFindRowByPairName($rows, $pair);
        if (!$row) {
            $snapshot = getQuotesSnapshotData($pdo, 1.0, true);
            $rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
            $row = quotesClientFindRowByPairName($rows, $pair);
        }

        if (!$row) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'error' => 'pair_not_found',
                'pair' => $pair,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'pair' => $pair,
            'row' => $row,
            'updated_at' => $snapshot['updated_at'] ?? null,
            'is_stale' => !empty($snapshot['is_stale']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($limit < count($rows)) {
        $rows = array_slice($rows, 0, $limit);
    }

    echo json_encode([
        'ok' => true,
        'count' => count($rows),
        'rows' => $rows,
        'updated_at' => $snapshot['updated_at'] ?? null,
        'is_stale' => !empty($snapshot['is_stale']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'getter_price_failed',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
