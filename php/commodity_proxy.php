<?php
// Market proxy for commodities, forex, stocks, indices and crypto.
// Returns a normalized payload: { price: float, changePercent: float }
header('Content-Type: application/json');

function errorResponse(string $message, int $code = 500): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function requestJson(string $url): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_PROXY, '');
    curl_setopt($ch, CURLOPT_NOPROXY, '*');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (compatible; CoinDashboard/1.0)'
    ]);

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

function fetchYahooQuote(string $symbol): ?array {
    $url = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode($symbol);
    $result = requestJson($url);
    if (!$result['ok']) {
        return null;
    }

    $quote = $result['data']['quoteResponse']['result'][0] ?? null;
    if (!is_array($quote) || !isset($quote['regularMarketPrice'])) {
        return null;
    }

    return [
        'price' => (float) $quote['regularMarketPrice'],
        'changePercent' => isset($quote['regularMarketChangePercent']) ? (float) $quote['regularMarketChangePercent'] : 0.0,
    ];
}

function fetchBinanceQuote(string $symbol): ?array {
    $url = 'https://api.binance.com/api/v3/ticker/24hr?symbol=' . urlencode($symbol);
    $result = requestJson($url);
    if (!$result['ok']) {
        return null;
    }

    $data = $result['data'];
    if (!isset($data['lastPrice'])) {
        return null;
    }

    return [
        'price' => (float) $data['lastPrice'],
        'changePercent' => isset($data['priceChangePercent']) ? (float) $data['priceChangePercent'] : 0.0,
    ];
}

function normalizePair(string $input): string {
    return strtoupper(preg_replace('/[^A-Z0-9=\.\/-]/', '', $input));
}

function toYahooSymbolFromPair(string $pair): ?string {
    $pair = normalizePair($pair);
    if ($pair === '') {
        return null;
    }

    $commodityMap = [
        'GOLDUSD' => 'GC=F',
        'SILVERUSD' => 'SI=F',
        'PLATINUMUSD' => 'PL=F',
        'COPPERUSD' => 'HG=F',
        'WTIUSD' => 'CL=F',
        'BRENTUSD' => 'BZ=F',
        'NATGASUSD' => 'NG=F',
        'COALUSD' => 'MTF=F',
        'ALUMINUMUSD' => 'ALI=F',
        'NICKELUSD' => 'NICKEL=F',
        'ZINCUSD' => 'ZNC=F',
        'LEADUSD' => 'PBL=F',
        'IRONOREUSD' => 'TIO=F',
        'WHEATUSD' => 'ZW=F',
        'CORNUSD' => 'ZC=F',
        'SOYBEANUSD' => 'ZS=F',
        'COFFEEUSD' => 'KC=F',
        'COCOAUSD' => 'CC=F',
        'SUGARUSD' => 'SB=F',
        'COTTONUSD' => 'CT=F',
    ];

    if (isset($commodityMap[$pair])) {
        return $commodityMap[$pair];
    }

    $indexMap = [
        'SP500USD' => '^GSPC',
        'DJIAUSD' => '^DJI',
        'NASDAQ100USD' => '^NDX',
        'FTSE100USD' => '^FTSE',
        'DAX30USD' => '^GDAXI',
        'CAC40USD' => '^FCHI',
        'NIKKEI225USD' => '^N225',
        'HANGSENGUSD' => '^HSI',
        'SHCOMPUSD' => '000001.SS',
        'RUSSELL2000USD' => '^RUT',
    ];

    if (isset($indexMap[$pair])) {
        return $indexMap[$pair];
    }

    $forexPair = str_replace('/', '', $pair);
    if (preg_match('/^[A-Z]{6}$/', $forexPair)) {
        return $forexPair . '=X';
    }

    if (str_ends_with($pair, 'USD')) {
        return substr($pair, 0, -3);
    }

    if (str_contains($pair, '/')) {
        [$base, $quote] = array_pad(explode('/', $pair, 2), 2, '');
        if ($base !== '' && $quote !== '') {
            return $base . '-' . $quote;
        }
    }

    return $pair;
}

$pair = isset($_GET['pair']) ? (string) $_GET['pair'] : '';
$explicitSymbol = isset($_GET['symbol']) ? normalizePair((string) $_GET['symbol']) : '';

$binanceSymbol = '';
if ($pair !== '') {
    $pairKey = str_replace('/', '', normalizePair($pair));
    if (str_ends_with($pairKey, 'USD')) {
        $binanceSymbol = substr($pairKey, 0, -3) . 'USDT';
    } elseif (preg_match('/^[A-Z0-9]{5,20}$/', $pairKey)) {
        $binanceSymbol = $pairKey;
    }
}

$yahooSymbol = $explicitSymbol !== '' ? $explicitSymbol : toYahooSymbolFromPair($pair);

if ($binanceSymbol !== '') {
    $binance = fetchBinanceQuote($binanceSymbol);
    if ($binance !== null) {
        echo json_encode($binance);
        exit;
    }
}

if ($yahooSymbol !== null && $yahooSymbol !== '') {
    $yahoo = fetchYahooQuote($yahooSymbol);
    if ($yahoo !== null) {
        echo json_encode($yahoo);
        exit;
    }
}

errorResponse('Unable to fetch price for this pair.');
