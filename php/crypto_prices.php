<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$currency = isset($_GET['currency']) ? strtolower($_GET['currency']) : 'usd';
$coins = ['bitcoin','ethereum','cardano','solana'];
$url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . implode(',', $coins) . '&vs_currencies=' . $currency;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to retrieve prices']);
    exit;
}
curl_close($ch);
echo $response;
