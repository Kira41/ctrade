<?php

const QUOTES_CLIENT_UPSTREAM_URL = 'http://171.22.114.97:8010/quotes';

function quotesClientFetchJson(string $url, int $timeoutSeconds = 6): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (QuotesClientPHP)'
        ],
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return [null, "cURL error: $err", 0, null];
    }
    if ($code < 200 || $code >= 300) {
        return [null, "HTTP error: $code", $code, $resp];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return [null, 'Invalid JSON response', $code, $resp];
    }

    return [$data, null, $code, null];
}

function quotesClientFetchPayload(int $timeoutSeconds = 6): array {
    $started = microtime(true);
    [$data, $error, $httpCode, $rawBody] = quotesClientFetchJson(QUOTES_CLIENT_UPSTREAM_URL, $timeoutSeconds);
    $tookMs = (int)round((microtime(true) - $started) * 1000);

    if ($error) {
        return [
            'ok' => false,
            'error' => $error,
            'upstream_url' => QUOTES_CLIENT_UPSTREAM_URL,
            'upstream_http_code' => $httpCode,
            'upstream_body_snippet' => is_string($rawBody) ? mb_substr($rawBody, 0, 1000, 'UTF-8') : null,
            'took_ms' => $tookMs,
        ];
    }

    if (!isset($data['ok']) || $data['ok'] !== true) {
        return [
            'ok' => false,
            'error' => 'Upstream returned ok=false',
            'upstream_url' => QUOTES_CLIENT_UPSTREAM_URL,
            'upstream_http_code' => $httpCode,
            'upstream_response' => $data,
            'took_ms' => $tookMs,
        ];
    }

    return [
        'ok' => true,
        'upstream_url' => QUOTES_CLIENT_UPSTREAM_URL,
        'upstream_http_code' => $httpCode,
        'took_ms' => $tookMs,
        'rows' => is_array($data['rows'] ?? null) ? $data['rows'] : [],
    ];
}
