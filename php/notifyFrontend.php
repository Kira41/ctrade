<?php
require_once __DIR__.'/../utils/poll.php';
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['event'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing event']);
    exit;
}
$userId = $input['user_id'] ?? null;
$data = $input['data'] ?? [];
if (pushEvent($input['event'], $data, $userId)) {
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to emit']);
}
?>
