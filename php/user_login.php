<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();

    // Ensure the loginHistory table exists in case the schema was not loaded
    $pdo->exec("CREATE TABLE IF NOT EXISTS loginHistory (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        user_id BIGINT,
        date TEXT,
        ip TEXT,
        device TEXT
    )");

    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing credentials']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT user_id, passwordHash FROM personal_data WHERE emailaddress = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && hash_equals($row['passwordHash'], $password)) {
        session_start();
        $_SESSION['user_id'] = $row['user_id'];

        // record login event
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        $device = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $date = date('Y/m/d H:i');
        $stmt = $pdo->prepare('INSERT INTO loginHistory (user_id,date,ip,device) VALUES (?,?,?,?)');
        $stmt->execute([$row['user_id'], $date, $ip, $device]);

        echo json_encode(['status' => 'ok', 'user_id' => (int)$row['user_id']]);
        exit;
    }

    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
