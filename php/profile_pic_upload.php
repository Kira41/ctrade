<?php
header('Content-Type: application/json');
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
try {
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();

    $userId = $_POST['user_id'] ?? '';
    if ($userId === '') {
        throw new Exception('Missing user_id');
    }
    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . ($file['error'] ?? 0));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new Exception('Invalid upload');
    }
    $data = file_get_contents($file['tmp_name']);
    $base64 = base64_encode($data);
    $stmt = $pdo->prepare('UPDATE personal_data SET profile_pic=? WHERE user_id=?');
    $stmt->execute([$base64, $userId]);
    echo json_encode(['status' => 'ok', 'data' => $base64]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
