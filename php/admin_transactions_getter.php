<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();
    require_once __DIR__.'/../utils/permissions.php';

    $adminId = null;
    session_start();
if (isset($_SESSION['admin_id'])) {
    $adminId = (int)$_SESSION['admin_id'];
} elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) &&
          preg_match('/Bearer\s+(\d+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $adminId = (int)$m[1];
}

if (!$adminId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

    $stmt = $pdo->prepare('SELECT is_admin FROM admins_agents WHERE id = ?');
    $stmt->execute([$adminId]);
    $isAdmin = (int)$stmt->fetchColumn();

    $targetId = $adminId;
    if ($isAdmin === 2 && isset($_GET['admin_id'])) {
        $targetId = (int)$_GET['admin_id'];
    }

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;
$pageSize = $pageSize > 0 ? min($pageSize, 100) : 10;
$offset = ($page - 1) * $pageSize;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $filterAll = ($isAdmin === 2 && !isset($_GET['admin_id']));

if ($filterAll) {
    $baseSql = "FROM transactions AS t
        JOIN personal_data AS p ON p.user_id = t.user_id";
    $params = [];
    if ($search !== '') {
        $baseSql .= " WHERE t.operationNumber LIKE ?";
        $params[] = '%' . $search . '%';
    }
} else {
    $linkedIds = getDescendantAdminIds($pdo, $targetId);
    $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));

    $baseSql = "FROM transactions AS t
        JOIN personal_data AS p ON p.user_id = t.user_id
        WHERE p.linked_to_id IN ($placeholders)";

    $params = $linkedIds;
    if ($search !== '') {
        $baseSql .= " AND t.operationNumber LIKE ?";
        $params[] = '%' . $search . '%';
    }
}

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

    // MySQL doesn't allow binding parameters for LIMIT/OFFSET reliably when
    // using emulated prepares. Since the values are cast to integers above
    // it is safe to directly inject them into the SQL string.
    $sql = "SELECT t.operationNumber, t.user_id, t.type, t.amount, t.status, t.date, t.statusClass
        $baseSql
        ORDER BY STR_TO_DATE(t.date, '%Y/%m/%d') DESC
        LIMIT $pageSize OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['transactions' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
