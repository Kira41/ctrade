<?php
// Proxy commodity prices using free exchange APIs to avoid CORS issues on the frontend.
header('Content-Type: application/json');

$symbol = isset($_GET['symbol']) ? strtoupper($_GET['symbol']) : 'GC=F';
$symbol = preg_replace('/[^A-Z0-9=\\-\\.]/', '', $symbol);

$binanceSymbolMap = [
    // Yahoo commodity symbols mapped to Binance spot symbols when available.
    'GC=F' => 'PAXGUSDT', // Gold (PAXG)
    'SI=F' => 'XAGUSDT',  // Silver (if supported)
    'PL=F' => 'XPTUSDT'   // Platinum (if supported)
];

function fetch_binance_ticker($symbol) {
    $url = 'https://api.binance.com/api/v3/ticker/24hr?symbol=' . urlencode($symbol);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (compatible; CoinDashboard/1.0)'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return ['ok' => false, 'error' => $curlError];
    }

    $data = json_decode($response, true);
    if (!isset($data['lastPrice'])) {
        return ['ok' => false, 'error' => 'Invalid response from Binance'];
    }

    return [
        'ok' => true,
        'price' => $data['lastPrice'],
        'changePercent' => $data['priceChangePercent'] ?? 0
    ];
}

$binanceSymbol = $binanceSymbolMap[$symbol] ?? null;
if ($binanceSymbol !== null) {
    $binanceResult = fetch_binance_ticker($binanceSymbol);
    if ($binanceResult['ok']) {
        echo json_encode([
            'price' => $binanceResult['price'],
            'changePercent' => $binanceResult['changePercent']
        ]);
        exit;
    }
}

$url = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode($symbol);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_PROXY, '');
curl_setopt($ch, CURLOPT_NOPROXY, '*');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'User-Agent: Mozilla/5.0 (compatible; CoinDashboard/1.0)'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
if ($response === false || $httpCode !== 200) {
    http_response_code(500);
    $message = 'Unable to fetch price from Binance';
    if ($binanceSymbol === null) {
        $message .= ' (no Binance mapping available)';
    }
    if ($curlError !== '') {
        $message .= '; Yahoo fallback failed: ' . $curlError;
    }
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

$data = json_decode($response, true);
$quote = $data['quoteResponse']['result'][0] ?? null;
if (!$quote || !isset($quote['regularMarketPrice'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Invalid response from Yahoo Finance']);
    exit;
}

echo json_encode([
    'price' => $quote['regularMarketPrice'],
    'changePercent' => $quote['regularMarketChangePercent'] ?? 0
]);
