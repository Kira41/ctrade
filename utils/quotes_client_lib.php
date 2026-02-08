<?php

const QUOTES_CLIENT_UPSTREAM_URL = 'http://171.22.114.97:8010/quotes';

function quotesClientNormalizeRowName(string $name): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($name))) ?? '';
}

function quotesClientPairCandidateNames(string $pair): array {
    $raw = strtoupper(trim(urldecode($pair)));
    $raw = preg_replace('/\s+/', '', $raw) ?? '';
    if ($raw === '') {
        return [];
    }

    $candidates = [$raw];

    if (strpos($raw, ':') !== false) {
        [, $symbol] = array_pad(explode(':', $raw, 2), 2, '');
        if ($symbol !== '') {
            $raw = $symbol;
            $candidates[] = $symbol;
        }
    }

    if (strpos($raw, '/') !== false) {
        [$base, $quote] = array_pad(explode('/', $raw, 2), 2, 'USD');
        if ($base !== '') {
            $quote = $quote === 'USDT' ? 'USD' : ($quote !== '' ? $quote : 'USD');
            $candidates[] = $base . '/' . $quote;
        }
    } elseif (preg_match('/^([A-Z0-9._\-]+)(USDT|USD)$/', $raw, $m) && !empty($m[1])) {
        $quote = $m[2] === 'USDT' ? 'USD' : $m[2];
        $candidates[] = $m[1] . '/' . $quote;
    }

    return array_values(array_unique(array_filter(array_map('trim', $candidates))));
}

function quotesClientFindRowByPairName(array $rows, string $pair): ?array {
    $targets = quotesClientPairCandidateNames($pair);
    if (!$targets) {
        return null;
    }

    $exact = [];
    $normalized = [];
    $tickerOnly = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = strtoupper(trim((string)($row['Name'] ?? '')));
        if ($name === '') {
            continue;
        }

        if (!isset($exact[$name])) {
            $exact[$name] = $row;
        }

        $normalizedName = quotesClientNormalizeRowName($name);
        if ($normalizedName !== '' && !isset($normalized[$normalizedName])) {
            $normalized[$normalizedName] = $row;
        }

        if (preg_match('/\(([A-Z0-9.\-_]+)\)\s*$/', $name, $m) && !empty($m[1])) {
            $ticker = strtoupper(trim($m[1]));
            if (!isset($tickerOnly[$ticker])) {
                $tickerOnly[$ticker] = $row;
            }
            $tickerNormalized = quotesClientNormalizeRowName($ticker);
            if ($tickerNormalized !== '' && !isset($normalized[$tickerNormalized])) {
                $normalized[$tickerNormalized] = $row;
            }
        }
    }

    foreach ($targets as $target) {
        $exactKey = strtoupper(trim($target));
        if ($exactKey !== '' && isset($exact[$exactKey])) {
            return $exact[$exactKey];
        }

        $normalizedKey = quotesClientNormalizeRowName($target);
        if ($normalizedKey !== '' && isset($normalized[$normalizedKey])) {
            return $normalized[$normalizedKey];
        }

        if ($exactKey !== '' && isset($tickerOnly[$exactKey])) {
            return $tickerOnly[$exactKey];
        }
    }

    return null;
}

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
