<?php
// Backward-compatible endpoint. Internally uses Commodity Proxy market data.
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../utils/market_data_provider.php';

$symbol = isset($_GET['symbol']) ? preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$_GET['symbol'])) : 'BTCUSDT';
$mode = $_GET['mode'] ?? '24hr';
$pair = normalizeMarketPair('COINBASE:' . $symbol);
$data = getMarketData($pair, 2.0);

if (empty($data['ok'])) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Unable to fetch market data', 'pair' => $pair]);
    exit;
}

if ($mode === 'price') {
    echo json_encode([
        'symbol' => $symbol,
        'price' => (string)($data['value'] ?? 0),
        'source' => 'commodity_proxy',
        'is_stale' => !empty($data['is_stale']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'symbol' => $symbol,
    'lastPrice' => (string)($data['value'] ?? 0),
    'priceChange' => (string)($data['change'] ?? 0),
    'priceChangePercent' => (string)($data['changePercent'] ?? 0),
    'openPrice' => (string)($data['open'] ?? 0),
    'highPrice' => (string)($data['high'] ?? 0),
    'lowPrice' => (string)($data['low'] ?? 0),
    'prevClosePrice' => (string)($data['previous'] ?? 0),
    'source' => 'commodity_proxy',
    'is_stale' => !empty($data['is_stale']),
], JSON_UNESCAPED_UNICODE);
