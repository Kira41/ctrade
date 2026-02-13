<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../utils/quotes_client_lib.php';

function priceParseNumeric($value): ?float {
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }

    if (!is_string($value)) {
        return null;
    }

    $text = trim(str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', $value));
    $text = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $text);

    $multiplier = 1.0;
    if (preg_match('/([KMB])$/i', $text, $m)) {
        $suffix = strtoupper($m[1]);
        $multiplier = $suffix === 'B' ? 1000000000.0 : ($suffix === 'M' ? 1000000.0 : 1000.0);
        $text = preg_replace('/([KMB])$/i', '', $text) ?? $text;
    }

    $text = str_replace(['%', ','], '', $text);
    if ($text === '' || !is_numeric($text)) {
        return null;
    }

    return (float)$text * $multiplier;
}

function ensurePriceTable(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS quotes_prices (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        row_name VARCHAR(255) NOT NULL,
        row_name_normalized VARCHAR(255) NOT NULL,
        value_num DECIMAL(30,10) NULL,
        change_num DECIMAL(30,10) NULL,
        change_percent_num DECIMAL(30,10) NULL,
        open_num DECIMAL(30,10) NULL,
        high_num DECIMAL(30,10) NULL,
        low_num DECIMAL(30,10) NULL,
        previous_num DECIMAL(30,10) NULL,
        raw_payload JSON NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_row_name_normalized (row_name_normalized),
        KEY idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function normalizeRowNameForKey(string $name): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($name))) ?? '';
}

try {
    $pdo = db();
    ensurePriceTable($pdo);

    $payload = quotesClientFetchPayload();
    if (empty($payload['ok'])) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'error' => 'upstream_fetch_failed',
            'detail' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $stmt = $pdo->prepare('INSERT INTO quotes_prices
        (row_name, row_name_normalized, value_num, change_num, change_percent_num, open_num, high_num, low_num, previous_num, raw_payload, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            row_name = VALUES(row_name),
            value_num = VALUES(value_num),
            change_num = VALUES(change_num),
            change_percent_num = VALUES(change_percent_num),
            open_num = VALUES(open_num),
            high_num = VALUES(high_num),
            low_num = VALUES(low_num),
            previous_num = VALUES(previous_num),
            raw_payload = VALUES(raw_payload),
            updated_at = VALUES(updated_at)');

    $updated = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string)($row['Name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $normalized = normalizeRowNameForKey($name);
        if ($normalized === '') {
            continue;
        }

        $stmt->execute([
            $name,
            $normalized,
            priceParseNumeric($row['Value'] ?? null),
            priceParseNumeric($row['Change'] ?? null),
            priceParseNumeric($row['Chg%'] ?? null),
            priceParseNumeric($row['Open'] ?? null),
            priceParseNumeric($row['High'] ?? null),
            priceParseNumeric($row['Low'] ?? null),
            priceParseNumeric($row['Prev'] ?? null),
            json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);

        $updated++;
    }

    echo json_encode([
        'ok' => true,
        'source' => QUOTES_CLIENT_UPSTREAM_URL,
        'rows_received' => count($rows),
        'rows_upserted' => $updated,
        'took_ms' => $payload['took_ms'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'setter_price_failed',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
