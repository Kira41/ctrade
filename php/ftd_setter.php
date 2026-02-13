<?php
header('Content-Type: application/json');

try {
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();
    session_start();
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }
    $cols = [
        'user_id','full_name','email','phone','crm_id','nationality','age','profession',
        'client_difficulty','client_potential','technically_comfortable','anydesk_installed',
        'call_duration','resistance_level','resistance_types','call_notes',
        'general_impression','appointment_set','appointment_datetime','additional_comments'
    ];
    $values = [];
    foreach ($cols as $c) {
        $values[$c] = $data[$c] ?? null;
    }
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO ftd (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($values));
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

