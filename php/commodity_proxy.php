<?php
// Simple proxy for Yahoo Finance commodities to avoid CORS issues on the frontend
header('Content-Type: application/json');

$symbol = isset($_GET['symbol']) ? strtoupper($_GET['symbol']) : 'GC=F';
$symbol = preg_replace('/[^A-Z0-9=\\-\\.]/', '', $symbol);
$url = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode($symbol);

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
if ($response === false || $httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to fetch price from Yahoo Finance']);
    curl_close($ch);
    exit;
}
curl_close($ch);

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
