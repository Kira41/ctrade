<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/../config/db_connection.php';
    $pdo = db();
    require_once __DIR__ . '/../utils/permissions.php';

    session_start();
    $adminId = null;
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

    $op = isset($_GET['op']) ? trim($_GET['op']) : '';
    if ($op === '') {
        throw new Exception('Missing op');
    }

    $stmt = $pdo->prepare('SELECT is_admin FROM admins_agents WHERE id = ?');
    $stmt->execute([$adminId]);
    $isAdmin = (int)$stmt->fetchColumn();

    if ($isAdmin === 2) {
        $stmt = $pdo->prepare('SELECT profitPerte, prix, montant FROM tradingHistory WHERE operationNumber = ?');
        $stmt->execute([$op]);
    } else {
        $linkedIds = getDescendantAdminIds($pdo, $adminId);
        $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
        $sql = 'SELECT th.profitPerte, th.prix, th.montant FROM tradingHistory th '
            . 'JOIN personal_data p ON p.user_id = th.user_id '
            . 'WHERE th.operationNumber = ? AND p.linked_to_id IN (' . $placeholders . ')';
        $params = array_merge([$op], $linkedIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['trade' => $row]);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
