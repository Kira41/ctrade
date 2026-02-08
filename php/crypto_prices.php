<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../utils/market_data_provider.php';

$currency = isset($_GET['currency']) ? strtolower((string)$_GET['currency']) : 'usd';
if ($currency !== 'usd' && $currency !== 'usdt') {
    http_response_code(400);
    echo json_encode(['error' => 'Only USD/USDT quote currency is supported']);
    exit;
}

$coinMap = [
    'bitcoin' => 'COINBASE:BTCUSD',
    'ethereum' => 'COINBASE:ETHUSD',
    'cardano' => 'COINBASE:ADAUSD',
    'solana' => 'COINBASE:SOLUSD',
];

$result = [];
foreach ($coinMap as $coin => $pair) {
    $payload = getMarketData($pair, 2.0);
    $price = parseNumericValue($payload['value'] ?? ($payload['market_last'] ?? null));
    $result[$coin] = [$currency => $price ?? 0.0];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
