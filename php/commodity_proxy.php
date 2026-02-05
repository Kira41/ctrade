<?php
// TradingView-only market proxy.
// Returns a normalized payload: { price: float, changePercent: float }
header('Content-Type: application/json');

function errorResponse(string $message, int $code = 500): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function requestJson(string $url, string $method = 'GET', ?array $payload = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_PROXY, '');
    curl_setopt($ch, CURLOPT_NOPROXY, '*');

    $headers = [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (compatible; CoinDashboard/1.0)'
    ];

    if ($method === 'POST') {
        $jsonBody = json_encode($payload ?? []);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : ('HTTP ' . $httpCode)];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid JSON response'];
    }

    return ['ok' => true, 'data' => $decoded];
}

function fetchTradingViewQuote(string $symbol): ?array {
    $url = 'https://scanner.tradingview.com/global/scan';
    $payload = [
        'symbols' => [
            'tickers' => [$symbol],
            'query' => ['types' => []],
        ],
        'columns' => ['close', 'change'],
    ];

    $result = requestJson($url, 'POST', $payload);
    if (!$result['ok']) {
        return null;
    }

    $row = $result['data']['data'][0]['d'] ?? null;
    if (!is_array($row) || !isset($row[0])) {
        return null;
    }

    return [
        'price' => (float) $row[0],
        'changePercent' => isset($row[1]) ? (float) $row[1] : 0.0,
    ];
}

function normalizePair(string $input): string {
    return strtoupper(preg_replace('/[^A-Z0-9=\.\/:!-]/', '', $input));
}

function guessCryptoTradingViewSymbol(string $pair): ?string {
    $pair = str_replace('/', '', normalizePair($pair));
    if (!str_ends_with($pair, 'USD')) {
        return null;
    }

    $base = substr($pair, 0, -3);
    if ($base === '') {
        return null;
    }

    if ($base === 'USDT') {
        return 'COINBASE:USDTUSD';
    }
    if ($base === 'USDC') {
        return 'COINBASE:USDCUSD';
    }

    return 'BINANCE:' . $base . 'USDT';
}

function toTradingViewSymbolFromPair(string $pair): ?string {
    $pair = str_replace('/', '', normalizePair($pair));
    if ($pair === '') {
        return null;
    }

    $directMap = [
        'GOLDUSD' => 'CMCMARKETS:GOLD',
        'SILVERUSD' => 'CMCMARKETS:SILVER',
        'PLATINUMUSD' => 'CMCMARKETS:PLATINUM',
        'COPPERUSD' => 'CMCMARKETS:COPPER',
        'WTIUSD' => 'TVC:USOIL',
        'BRENTUSD' => 'TVC:UKOIL',
        'NATGASUSD' => 'SKILLING:NATGAS',
        'COALUSD' => 'ICEEUR:NCF1!',
        'ALUMINUMUSD' => 'FUSIONMARKETS:XALUSD',
        'NICKELUSD' => 'EIGHTCAP:XNIUSD',
        'ZINCUSD' => 'FUSIONMARKETS:XZNUSD',
        'LEADUSD' => 'FUSIONMARKETS:XPBUSD',
        'IRONOREUSD' => 'COMEX:TIO1!',
        'WHEATUSD' => 'SKILLING:WHEAT',
        'CORNUSD' => 'SKILLING:CORN',
        'SOYBEANUSD' => 'SKILLING:SOYBEAN',
        'COFFEEUSD' => 'SKILLING:COFFEE',
        'COCOAUSD' => 'SKILLING:COCOA',
        'SUGARUSD' => 'SKILLING:SUGAR',
        'COTTONUSD' => 'SKILLING:COTTON',
        'SP500USD' => 'FOREXCOM:SPXUSD',
        'NASDAQ100USD' => 'FOREXCOM:NSXUSD',
        'DJIAUSD' => 'FOREXCOM:DJI',
        'FTSE100USD' => 'FOREXCOM:UKXGBP',
        'DAX30USD' => 'INDEX:DEU40',
        'CAC40USD' => 'INDEX:CAC40',
        'NIKKEI225USD' => 'INDEX:NKY',
        'HANGSENGUSD' => 'INDEX:HSI',
        'SHCOMPUSD' => 'SSE:000001',
        'RUSSELL2000USD' => 'FOREXCOM:US2000',
        'USDJPY' => 'FX_IDC:USDJPY',
        'USDGBP' => 'FX_IDC:GBPUSD',
        'USDEUR' => 'FX_IDC:EURUSD',
        'USDCHF' => 'FX_IDC:USDCHF',
        'USDCAD' => 'FX_IDC:USDCAD',
        'AUDUSD' => 'FX_IDC:AUDUSD',
        'NZDUSD' => 'FX_IDC:NZDUSD',
        'EURGBP' => 'FX_IDC:EURGBP',
        'EURJPY' => 'FX_IDC:EURJPY',
        'GBPJPY' => 'FX_IDC:GBPJPY',
        'BTCUSD' => 'COINBASE:BTCUSD',
        'ETHUSD' => 'COINBASE:ETHUSD',
        'USDTUSD' => 'COINBASE:USDTUSD',
        'USDCUSD' => 'COINBASE:USDCUSD',
    ];

    if (isset($directMap[$pair])) {
        return $directMap[$pair];
    }

    if (preg_match('/^[A-Z\.]+USD$/', $pair) === 1) {
        $base = substr($pair, 0, -3);
        if ($base !== '' && $base !== 'USD') {
            return 'NASDAQ:' . $base;
        }
    }

    return guessCryptoTradingViewSymbol($pair);
}

$pair = isset($_GET['pair']) ? (string) $_GET['pair'] : '';

if ($pair === '') {
    errorResponse('Missing pair parameter.', 400);
}

$tvSymbol = toTradingViewSymbolFromPair($pair);
if ($tvSymbol === null) {
    errorResponse('No TradingView mapping found for this pair.', 404);
}

$tvQuote = fetchTradingViewQuote($tvSymbol);
if ($tvQuote !== null) {
    $tvQuote['tvSymbol'] = $tvSymbol;
    echo json_encode($tvQuote);
    exit;
}

errorResponse('Unable to fetch TradingView price for this pair.');
