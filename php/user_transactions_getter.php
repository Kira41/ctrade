<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();

    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;
    $pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
    $offset = ($page - 1) * $pageSize;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ?');
    $countStmt->execute([$userId]);
    $total = (int)$countStmt->fetchColumn();

    // Safe to inject integers directly for LIMIT/OFFSET
    $sql = "SELECT operationNumber, type, amount, date, status, statusClass
            FROM transactions WHERE user_id = ?
            ORDER BY STR_TO_DATE(date, '%Y/%m/%d') DESC, id DESC
            LIMIT $pageSize OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['transactions' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
