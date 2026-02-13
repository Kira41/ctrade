<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../utils/market_data_provider.php';

$rawPair = $_GET['currencyPair'] ?? ($_GET['pair'] ?? ($_GET['symbol'] ?? 'COINBASE:BTCUSD'));
$ttl = isset($_GET['ttl']) ? (float)$_GET['ttl'] : 2.0;
if ($ttl <= 0) {
    $ttl = 2.0;
}

$data = getMarketData((string)$rawPair, $ttl);

if (!empty($data['ok'])) {
    http_response_code(200);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(502);
echo json_encode([
    'ok' => false,
    'error' => $data['error'] ?? 'Market data unavailable',
    'pair' => $data['pair'] ?? normalizeMarketPair((string)$rawPair),
    'is_stale' => !empty($data['is_stale']),
    'detail' => $data['detail'] ?? null,
], JSON_UNESCAPED_UNICODE);
