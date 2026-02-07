<?php
// tv_proxy.php
// Usage:
//   tv_proxy.php?currencyPair=BINANCE:BTCUSDT
//   tv_proxy.php?pair=NASDAQ:AAPL
// (Backward-compat: if you still send ?symbol=..., it will treat it as pair)

header('Content-Type: application/json; charset=UTF-8');

// 1) Inputs (prefer currencyPair)
$pair = $_GET['currencyPair'] ?? ($_GET['pair'] ?? ($_GET['symbol'] ?? 'BINANCE:BTCUSDT'));

$pair = trim((string)$pair);
$pair = strtoupper($pair);

// Basic sanitize: allow A-Z 0-9 : . _ -
$pair = preg_replace('/[^A-Z0-9:\._\-]/', '', $pair);

if ($pair === '' || strpos($pair, ':') === false) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "error" => "Invalid currencyPair. Expected EXCHANGE:SYMBOL (e.g. BINANCE:BTCUSDT)"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize EXCHANGE:SYMBOL
list($exchange, $symbol) = explode(':', $pair, 2);
$exchange = trim($exchange);
$symbol   = trim($symbol);

if ($exchange === '' || $symbol === '') {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "error" => "Invalid currencyPair parts. Expected EXCHANGE:SYMBOL"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pair = $exchange . ':' . $symbol;

// 2) API server config
$apiHost = '171.22.114.97';
$apiPort = 8000;
$apiKey  = 'te_6XvQpK9jR2mN4sA7fH8uC1zL0wY3tG5eB9nD7kS2pV4qR8m';

// New endpoint (FastAPI): /tv/quote?currencyPair=EXCHANGE:SYMBOL
$url = "http://{$apiHost}:{$apiPort}/tv/quote?currencyPair=" . urlencode($pair);

// 3) cURL call
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING       => '', // allow gzip/br if server supports
    CURLOPT_HTTPHEADER     => [
        "X-API-Key: {$apiKey}",
        "Accept: application/json",
        "User-Agent: CoinTrade-PHP-Proxy/1.1"
    ],
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 4) Handle errors
if ($response === false) {
    http_response_code(502);
    echo json_encode([
        "ok" => false,
        "error" => "cURL error",
        "detail" => $curlErr,
        "upstream_url" => $url,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5) Ensure JSON output (if upstream returns non-JSON, wrap it)
$decoded = json_decode($response, true);
if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code($httpCode ?: 502);
    echo json_encode([
        "ok" => false,
        "error" => "Upstream returned non-JSON",
        "http_code" => $httpCode,
        "pair" => $pair,
        "upstream_url" => $url,
        "raw" => mb_substr($response, 0, 2000), // avoid huge output
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 6) Forward status + body (already JSON)
http_response_code($httpCode ?: 200);
echo $response;
