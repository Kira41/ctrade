<?php
// Market proxy for commodities, forex, stocks, indices and crypto.
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

function fetchYahooQuote(string $symbol): ?array {
    $url = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode($symbol);
    $result = requestJson($url);
    if (!$result['ok']) {
        return null;
    }

    $row = $result['data']['quoteResponse']['result'][0] ?? null;
    if (!is_array($row)) {
        return null;
    }

    $price = $row['regularMarketPrice'] ?? null;
    if (!is_numeric($price)) {
        return null;
    }

    return [
        'price' => (float) $price,
        'changePercent' => isset($row['regularMarketChangePercent']) ? (float) $row['regularMarketChangePercent'] : 0.0,
    ];
}

function normalizePair(string $input): string {
    return strtoupper(preg_replace('/[^A-Z0-9=\.\/:-]/', '', $input));
}

function toTradingViewSymbolsFromPair(string $pair): array {
    $pair = normalizePair($pair);
    if ($pair === '') {
        return [];
    }

    $directMap = [
        // Extracted from TradingView widget presets used in the provided code.
        'EURUSD' => ['FX_IDC:EURUSD'],
        'USDJPY' => ['FX_IDC:USDJPY'],
        'GBPUSD' => ['FX_IDC:GBPUSD'],
        'AUDUSD' => ['FX_IDC:AUDUSD'],
        'USDCAD' => ['FX_IDC:USDCAD'],
        'USDCHF' => ['FX_IDC:USDCHF'],
        'EURGBP' => ['FX_IDC:EURGBP'],
        'EURJPY' => ['FX_IDC:EURJPY'],
        'SP500USD' => ['FOREXCOM:SPXUSD'],
        'NASDAQ100USD' => ['FOREXCOM:NSXUSD'],
        'DJIAUSD' => ['FOREXCOM:DJI'],
        'DXYUSD' => ['INDEX:DXY'],
        'FTSE100USD' => ['FOREXCOM:UKXGBP'],
        'DAX30USD' => ['INDEX:DEU40'],
        'CAC40USD' => ['INDEX:CAC40'],
        'NIKKEI225USD' => ['INDEX:NKY'],
        'HANGSENGUSD' => ['INDEX:HSI'],
        'WTIUSD' => ['PYTH:WTI3!'],
        'GOLDUSD' => ['CMCMARKETS:GOLD'],
        'SILVERUSD' => ['CMCMARKETS:SILVER'],
        'PLATINUMUSD' => ['CMCMARKETS:PLATINUM'],
        'COPPERUSD' => ['CMCMARKETS:COPPER'],
        'COFFEEUSD' => ['BMFBOVESPA:ICF1!'],
        'COTTONUSD' => ['CMCMARKETS:COTTON'],
        'SOYBEANUSD' => ['BMFBOVESPA:SJC1!'],
        'CORNUSD' => ['BMFBOVESPA:CCM1!'],
    ];

    if (isset($directMap[$pair])) {
        return $directMap[$pair];
    }

    $pairNoSlash = str_replace('/', '', $pair);

    if (preg_match('/^[A-Z]{6}$/', $pairNoSlash)) {
        return ['FX_IDC:' . $pairNoSlash];
    }

    if (str_ends_with($pairNoSlash, 'USD')) {
        $base = substr($pairNoSlash, 0, -3);
        if ($base !== '') {
            return [
                'BINANCE:' . $base . 'USDT',
                'NASDAQ:' . $base,
                'NYSE:' . $base,
                'AMEX:' . $base,
            ];
        }
    }

    if (preg_match('/^[A-Z0-9\.\-]{1,20}$/', $pairNoSlash)) {
        return [
            'BINANCE:' . $pairNoSlash,
            'NASDAQ:' . $pairNoSlash,
            'NYSE:' . $pairNoSlash,
            'AMEX:' . $pairNoSlash,
        ];
    }

    return [];
}

function toYahooSymbolFromPair(string $pair): ?string {
    $pair = normalizePair($pair);
    if ($pair === '') {
        return null;
    }

    $map = [
        // Crypto exceptions and unsupported Binance pairs
        'OKBUSD' => 'OKB-USD',

        // Stocks
        'AAPLUSD' => 'AAPL', 'MSFTUSD' => 'MSFT', 'AMZNUSD' => 'AMZN', 'TSLAUSD' => 'TSLA',
        'GOOGLUSD' => 'GOOGL', 'METAUSD' => 'META', 'NVDAUSD' => 'NVDA', 'BRK.BUSD' => 'BRK-B',
        'JPMUSD' => 'JPM', 'JNJUSD' => 'JNJ', 'VUSD' => 'V', 'MAUSD' => 'MA', 'PGUSD' => 'PG',
        'KOUSD' => 'KO', 'PEPUSD' => 'PEP', 'INTCUSD' => 'INTC', 'CSCOUSD' => 'CSCO', 'WMTUSD' => 'WMT',
        'XOMUSD' => 'XOM', 'CVXUSD' => 'CVX', 'DISUSD' => 'DIS', 'NKEUSD' => 'NKE', 'MCDUSD' => 'MCD',
        'PFEUSD' => 'PFE', 'MRNAUSD' => 'MRNA', 'ABBVUSD' => 'ABBV', 'CRMUSD' => 'CRM', 'ADBEUSD' => 'ADBE',
        'ORCLUSD' => 'ORCL', 'TUSD' => 'T', 'VZUSD' => 'VZ', 'BAUSD' => 'BA', 'CATUSD' => 'CAT',
        'HONUSD' => 'HON', 'LMTUSD' => 'LMT', 'SBUXUSD' => 'SBUX', 'GEUSD' => 'GE', 'IBMUSD' => 'IBM',
        'SHELUSD' => 'SHEL', 'BPUSD' => 'BP', 'TTEUSD' => 'TTE', 'SIEGYUSD' => 'SIEGY', 'SAPUSD' => 'SAP',
        'VWAGYUSD' => 'VWAGY', 'BMWYYUSD' => 'BMWYY', 'TMUSD' => 'TM', 'SONYUSD' => 'SONY',
        'SSNLFUSD' => 'SSNLF', 'BABAUSD' => 'BABA', 'TCEHYUSD' => 'TCEHY', 'PYPLUSD' => 'PYPL',

        // Indices
        'SP500USD' => '^GSPC', 'DJIAUSD' => '^DJI', 'NASDAQ100USD' => '^NDX', 'FTSE100USD' => '^FTSE',
        'DAX30USD' => '^GDAXI', 'CAC40USD' => '^FCHI', 'NIKKEI225USD' => '^N225', 'HANGSENGUSD' => '^HSI',
        'SHCOMPUSD' => '000001.SS', 'RUSSELL2000USD' => '^RUT',

        // Commodities
        'GOLDUSD' => 'GC=F', 'SILVERUSD' => 'SI=F', 'PLATINUMUSD' => 'PL=F', 'COPPERUSD' => 'HG=F',
        'WTIUSD' => 'CL=F', 'BRENTUSD' => 'BZ=F', 'NATGASUSD' => 'NG=F', 'COALUSD' => 'MTF=F',
        'ALUMINUMUSD' => 'ALI=F', 'NICKELUSD' => 'NICKEL=F', 'ZINCUSD' => 'ZNC=F', 'LEADUSD' => 'PBL=F',
        'IRONOREUSD' => 'TIO=F', 'WHEATUSD' => 'ZW=F', 'CORNUSD' => 'ZC=F', 'SOYBEANUSD' => 'ZS=F',
        'COFFEEUSD' => 'KC=F', 'COCOAUSD' => 'CC=F', 'SUGARUSD' => 'SB=F', 'COTTONUSD' => 'CT=F',
    ];

    if (isset($map[$pair])) {
        return $map[$pair];
    }

    if (preg_match('/^[A-Z0-9]{2,10}USD$/', $pair)) {
        return substr($pair, 0, -3) . '-USD';
    }

    return null;
}

$pair = isset($_GET['pair']) ? (string) $_GET['pair'] : '';
$explicitSymbol = isset($_GET['symbol']) ? normalizePair((string) $_GET['symbol']) : '';

$yahooSymbol = null;
if ($explicitSymbol !== '' && preg_match('/[=\^]|\./', $explicitSymbol)) {
    $yahooSymbol = $explicitSymbol;
} elseif ($pair !== '') {
    $yahooSymbol = toYahooSymbolFromPair($pair);
}

if ($yahooSymbol !== null) {
    $yahoo = fetchYahooQuote($yahooSymbol);
    if ($yahoo !== null) {
        echo json_encode($yahoo);
        exit;
    }
}

$binanceSymbol = '';
if ($pair !== '') {
    $pairKey = str_replace('/', '', normalizePair($pair));
    if (str_ends_with($pairKey, 'USD')) {
        $binanceSymbol = substr($pairKey, 0, -3) . 'USDT';
    } elseif (preg_match('/^[A-Z0-9]{5,20}$/', $pairKey)) {
        $binanceSymbol = $pairKey;
    }
}

if ($binanceSymbol !== '') {
    $binance = fetchBinanceQuote($binanceSymbol);
    if ($binance !== null) {
        echo json_encode($binance);
        exit;
    }
}

$tvSymbols = [];
if ($explicitSymbol !== '') {
    $tvSymbols[] = $explicitSymbol;
}
if ($pair !== '') {
    $tvSymbols = array_values(array_unique(array_merge($tvSymbols, toTradingViewSymbolsFromPair($pair))));
}

foreach ($tvSymbols as $symbol) {
    $tvQuote = fetchTradingViewQuote($symbol);
    if ($tvQuote !== null) {
        echo json_encode($tvQuote);
        exit;
    }
}

errorResponse('Unable to fetch price for this pair.');
