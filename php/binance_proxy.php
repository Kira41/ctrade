<?php
// Simple proxy for Binance API to avoid CORS issues on the frontend
header('Content-Type: application/json');

$symbol = isset($_GET['symbol']) ? preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['symbol'])) : 'BTCUSDT';
$mode = $_GET['mode'] ?? '24hr';
$endpoint = $mode === 'price' ? 'ticker/price' : 'ticker/24hr';
$url = "https://api.binance.com/api/v3/$endpoint?symbol=$symbol";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($response === false || $httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to fetch price from Binance']);
    curl_close($ch);
    exit;
}
curl_close($ch);

echo $response;
