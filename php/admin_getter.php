<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    require_once __DIR__.'/../utils/permissions.php';
    $staticUsername = 'alone';

    function formatTimeAgoFromDate($dateStr) {
        $ts = strtotime($dateStr);
        if (!$ts) return '';
        $diff = time() - $ts;
        if ($diff < 60) return "À l'instant";
        $mins = floor($diff / 60);
        if ($mins < 60) return 'Il y a ' . $mins . ' minute' . ($mins > 1 ? 's' : '');
        $hours = floor($diff / 3600);
        if ($hours < 24) return 'Il y a ' . $hours . ' heure' . ($hours > 1 ? 's' : '');
        $days = floor($diff / 86400);
        return 'Il y a ' . $days . ' jour' . ($days > 1 ? 's' : '');
    }

$adminId = null;

session_start();
$adminStatic = !empty($_SESSION['admin_static']);
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

    $sendStaticResponse = function() use ($adminId, $staticUsername) {
        echo json_encode([
            'is_admin' => 2,
            'admin_id' => $adminId,
            'email' => $staticUsername,
            'profile_pic' => null,
            'agents' => [],
            'users' => [],
            'kyc' => [],
            'stats' => [
                'total_users' => 0,
                'total_deposits' => 0,
                'deposit_count' => 0,
                'success_rate' => 0,
            ],
            'notifications' => [],
        ]);
    };

    try {
        $pdo = db();
    } catch (Throwable $dbException) {
        if ($adminStatic) {
            $sendStaticResponse();
            exit;
        }
        throw $dbException;
    }

$stmt = $pdo->prepare('SELECT email, is_admin FROM admins_agents WHERE id = ?');
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    if ($adminStatic) {
        $sendStaticResponse();
        exit;
    }
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Admin not found']);
    exit;
}

$result = [
    'is_admin' => (int)$admin['is_admin'],
    'admin_id' => $adminId,
    'email' => $admin['email'],
];

$stmt = $pdo->prepare('SELECT profile_pic FROM personal_data WHERE user_id = ?');
$stmt->execute([$adminId]);
$result['profile_pic'] = $stmt->fetchColumn();

$descendantAdminIds = getDescendantAdminIds($pdo, $adminId);
if ((int)$admin['is_admin'] === 2) {
    $stmt = $pdo->query('SELECT id,email,is_admin,created_by FROM admins_agents');
    $result['agents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $agentIds = array_diff($descendantAdminIds, [$adminId]);
    if ($agentIds) {
        $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
        $stmt = $pdo->prepare("SELECT id,email,is_admin,created_by FROM admins_agents WHERE id IN ($placeholders)");
        $stmt->execute(array_values($agentIds));
        $result['agents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result['agents'] = [];
    }
}

$period = isset($_GET['period']) ? strtolower($_GET['period']) : 'all';
$startDate = null;
switch ($period) {
    case 'daily':
        $startDate = date('Y-m-d');
        break;
    case 'weekly':
        $startDate = date('Y-m-d', strtotime('-6 days'));
        break;
    case 'monthly':
        $startDate = date('Y-m-d', strtotime('-29 days'));
        break;
}

$userColsNoLink = 'user_id,balance,totalDepots,totalRetraits,nbTransactions,fullName,compteverifie,compteverifie01,niveauavance,passwordHash,passwordStrength,passwordStrengthBar,emailNotifications,smsNotifications,loginAlerts,transactionAlerts,twoFactorAuth,emailaddress,address,phone,dob,nationality,created_at,userBankName,userAccountName,userAccountNumber,userIban,userSwiftCode,profile_pic';
$userSql = 'SELECT ' . ((int)$admin['is_admin'] === 2 ? '*' : $userColsNoLink) . ' FROM personal_data';
$userParams = [];
if ((int)$admin['is_admin'] !== 2) {
    $placeholders = implode(',', array_fill(0, count($descendantAdminIds), '?'));
    $userSql .= " WHERE linked_to_id IN ($placeholders)";
    $userParams = array_values($descendantAdminIds);
}
if ($startDate) {
    $userSql .= (strpos($userSql, 'WHERE') === false ? ' WHERE' : ' AND') .
        ' STR_TO_DATE(created_at, "%Y-%m-%d") >= STR_TO_DATE(?, "%Y-%m-%d")';
    $userParams[] = $startDate;
}
$stmt = $pdo->prepare($userSql);
$stmt->execute(array_values($userParams));
$result['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Do not expose the hidden balance column to the admin interface.
foreach ($result['users'] as &$userRow) {
    if (array_key_exists('hidden', $userRow)) {
        unset($userRow['hidden']);
    }
}
unset($userRow);

$stmt = null;
if ((int)$admin['is_admin'] === 2) {
    $stmt = $pdo->query('SELECT k.file_id,k.user_id,p.fullName,p.emailaddress,k.file_name,k.file_type,k.created_at,k.status FROM kyc k JOIN personal_data p ON k.user_id=p.user_id WHERE k.status = "pending"');
} else {
    $placeholders = implode(',', array_fill(0, count($descendantAdminIds), '?'));
    $sql = 'SELECT k.file_id,k.user_id,p.fullName,p.emailaddress,k.file_name,k.file_type,k.created_at,k.status '
        . 'FROM kyc k JOIN personal_data p ON k.user_id=p.user_id '
        . "WHERE p.linked_to_id IN ($placeholders) AND k.status = \"pending\"";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($descendantAdminIds));
}
$result['kyc'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute statistics for the admin's users
$userIds = array_column($result['users'], 'user_id');
$totalUsers = count($userIds);
$sumDeposits = 0;
$depositCount = 0;
$successUsers = [];
if ($userIds) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT user_id, amount FROM deposits WHERE status='complet' AND user_id IN ($placeholders)";
    $params = $userIds;
    if ($startDate) {
        $sql .= " AND STR_TO_DATE(date, '%Y/%m/%d') >= STR_TO_DATE(?, '%Y-%m-%d')";
        $params[] = $startDate;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($params));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sumDeposits += (float)$row['amount'];
        $depositCount++;
        $successUsers[$row['user_id']] = true;
    }
}
$successRate = $totalUsers ? round(count($successUsers) / $totalUsers * 100, 2) : 0;
$result['stats'] = [
    'total_users' => $totalUsers,
    'total_deposits' => $sumDeposits,
    'deposit_count' => $depositCount,
    'success_rate' => $successRate,
];

// Fetch recent notifications
$notifications = [];
if ((int)$admin['is_admin'] === 2) {
    $stmt = $pdo->query("SELECT n.type,n.title,n.message,n.time,n.alertClass,p.fullName
            FROM notifications n
            JOIN personal_data p ON p.user_id = n.user_id
            ORDER BY n.id DESC
            LIMIT 10");
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notifications as &$n) {
        $n['time'] = formatTimeAgoFromDate($n['time']);
    }
} elseif ($userIds) {
    $place = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT n.type,n.title,n.message,n.time,n.alertClass,p.fullName
            FROM notifications n
            JOIN personal_data p ON p.user_id = n.user_id
            WHERE n.user_id IN ($place)
            ORDER BY n.id DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($userIds));
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notifications as &$n) {
        $n['time'] = formatTimeAgoFromDate($n['time']);
    }
}
$result['notifications'] = $notifications;

echo json_encode($result);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
