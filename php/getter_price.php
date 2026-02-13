<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/db_connection.php';

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

function normalizeQueryKey(string $name): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($name))) ?? '';
}

try {
    $pdo = db();
    ensurePriceTable($pdo);

    $pair = isset($_GET['pair']) ? trim((string)$_GET['pair']) : '';
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 1000)) : 300;

    if ($pair !== '') {
        $normalized = normalizeQueryKey($pair);
        if ($normalized === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid_pair']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT row_name, value_num, change_num, change_percent_num, open_num, high_num, low_num, previous_num, raw_payload, updated_at
                               FROM quotes_prices
                               WHERE row_name_normalized = ?
                               LIMIT 1');
        $stmt->execute([$normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'pair_not_found', 'pair' => $pair], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $raw = json_decode((string)$row['raw_payload'], true);
        echo json_encode([
            'ok' => true,
            'pair' => $row['row_name'],
            'value' => isset($row['value_num']) ? (float)$row['value_num'] : null,
            'change' => isset($row['change_num']) ? (float)$row['change_num'] : null,
            'changePercent' => isset($row['change_percent_num']) ? (float)$row['change_percent_num'] : null,
            'open' => isset($row['open_num']) ? (float)$row['open_num'] : null,
            'high' => isset($row['high_num']) ? (float)$row['high_num'] : null,
            'low' => isset($row['low_num']) ? (float)$row['low_num'] : null,
            'previous' => isset($row['previous_num']) ? (float)$row['previous_num'] : null,
            'updated_at' => $row['updated_at'],
            'raw' => is_array($raw) ? $raw : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $pdo->prepare('SELECT row_name, value_num, change_num, change_percent_num, open_num, high_num, low_num, previous_num, updated_at
                           FROM quotes_prices
                           ORDER BY updated_at DESC, row_name ASC
                           LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'Name' => $r['row_name'],
            'Value' => isset($r['value_num']) ? (float)$r['value_num'] : null,
            'Change' => isset($r['change_num']) ? (float)$r['change_num'] : null,
            'Chg%' => isset($r['change_percent_num']) ? (float)$r['change_percent_num'] : null,
            'Open' => isset($r['open_num']) ? (float)$r['open_num'] : null,
            'High' => isset($r['high_num']) ? (float)$r['high_num'] : null,
            'Low' => isset($r['low_num']) ? (float)$r['low_num'] : null,
            'Prev' => isset($r['previous_num']) ? (float)$r['previous_num'] : null,
            'updated_at' => $r['updated_at'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'count' => count($rows),
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'getter_price_failed',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
