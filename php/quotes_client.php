<?php
// quotes_client.php
// JSON API proxy: fetches JSON from FastAPI quotes endpoint and returns it as JSON.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../utils/quotes_client_lib.php';

$response = quotesClientFetchPayload();
if (empty($response['ok'])) {
    http_response_code(502);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
