<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    require_once __DIR__.'/../utils/helpers.php';
    $pdo = db();

    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;

    // Determine admin level if request comes from an authenticated admin
    session_start();
    $adminId = null;
    if (isset($_SESSION['admin_id'])) {
        $adminId = (int)$_SESSION['admin_id'];
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) &&
              preg_match('/Bearer\s+(\d+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $adminId = (int)$m[1];
    }

    $adminLevel = 0;
    if ($adminId) {
        $stmt = $pdo->prepare('SELECT is_admin FROM admins_agents WHERE id = ?');
        $stmt->execute([$adminId]);
        $adminLevel = (int)$stmt->fetchColumn();
    }

function fetchAll($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

$includeHiddenBalance = isset($_GET['include_hidden_balance']) && $_GET['include_hidden_balance'] === '1';

$personal = fetchAll($pdo, 'SELECT * FROM personal_data WHERE user_id = ?', [$userId]);
$personal = $personal ? $personal[0] : [];
if ($includeHiddenBalance && $adminLevel === 0 && isset($personal['balance'])) {
    $visibleBalance = (float)$personal['balance'];
    $hiddenBalance = isset($personal['hidden']) ? (float)$personal['hidden'] : 0.0;
    $personal['balance'] = $visibleBalance + $hiddenBalance;
    unset($personal['hidden']);
}
if ($adminLevel !== 2) {
    unset($personal['linked_to_id']);
}
$bankWithdraw = fetchAll($pdo, 'SELECT * FROM bank_withdrawl_info WHERE user_id = ? LIMIT 1', [$userId]);
$bankWithdraw = $bankWithdraw ? $bankWithdraw[0] : [];
$ftdData = fetchAll($pdo, 'SELECT call_notes FROM ftd WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
$ftdData = $ftdData ? $ftdData[0] : new stdClass();
$notifications = fetchAll($pdo, 'SELECT DISTINCT type,title,message,time,alertClass FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$userId]);
foreach ($notifications as &$n) {
    $n['time'] = formatTimeAgoFromDate($n['time']);
}

$kycRows = fetchAll($pdo, 'SELECT status,created_at,file_type FROM kyc WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', [$userId]);
// Determine KYC step statuses based on individual documents
$idStatus = '0';
$idDate = null;
$frontApproved = false;
$backApproved = false;
$addrStatus = '0';
$addrDate = null;
foreach ($kycRows as $r) {
    switch ($r['file_type']) {
        case 'id_front':
        case 'id_back':
            if ($idDate === null) { $idDate = $r['created_at']; }
            if ($r['file_type'] === 'id_front' && $r['status'] === 'approved') { $frontApproved = true; }
            if ($r['file_type'] === 'id_back' && $r['status'] === 'approved') { $backApproved = true; }
            $idStatus = '2';
            break;
        case 'address':
            if ($addrDate === null) { $addrDate = $r['created_at']; }
            $addrStatus = ($r['status'] === 'approved') ? '1' : '2';
            break;
        default:
            break;
    }
}
if ($frontApproved && $backApproved) {
    $idStatus = '1';
}

$verify = fetchAll($pdo, 'SELECT * FROM verification_status WHERE user_id = ? LIMIT 1', [$userId]);
$verify = $verify ? $verify[0] : null;

// If the administrator explicitly approved a step (status "1"),
// keep that status. Otherwise, derive the state from uploaded files so
// that a rejected document doesn't reset the history to "incomplete".
if ($verify) {
    if (($verify['telechargerlesdocumentsdidentite'] ?? '') === '1') {
        $idStatus = '1';
    }
    if (($verify['verificationdeladresse'] ?? '') === '1') {
        $addrStatus = '1';
    }
}

$openTrades = fetchAll($pdo, 'SELECT id,pair,side,quantity,price FROM trades WHERE user_id = ? AND status="open"', [$userId]);
foreach ($openTrades as &$t) {
    $current = getLivePrice($t['pair']);
    $t['current_price'] = $current;
    $sign = $t['side'] === 'buy' ? 1 : -1;
    $t['unrealized_pnl'] = ($current - $t['price']) * $t['quantity'] * $sign;
}
unset($t);

$data = [
    'personalData' => $personal,
    'openTrades' => $openTrades,
    'transactions' => fetchAll($pdo, 'SELECT operationNumber,type,amount,date,status,statusClass FROM transactions WHERE user_id = ? ORDER BY STR_TO_DATE(date, "%Y/%m/%d") DESC, id DESC LIMIT 10', [$userId]),
    'notifications' => $notifications,
    'deposits' => fetchAll($pdo, 'SELECT operationNumber,date,amount,method,status,statusClass FROM deposits WHERE user_id = ? ORDER BY STR_TO_DATE(date, "%Y/%m/%d") DESC, id DESC LIMIT 10', [$userId]),
    'retraits' => fetchAll($pdo, 'SELECT operationNumber,date,amount,method,status,statusClass FROM retraits WHERE user_id = ? ORDER BY STR_TO_DATE(date, "%Y/%m/%d") DESC, id DESC LIMIT 10', [$userId]),
    'tradingHistory' => array_map(function($r){
        if (!empty($r['details'])) {
            $d = json_decode($r['details'], true);
            if (is_array($d)) { $r = array_merge($r, $d); }
        }
        unset($r['details']);
        return $r;
    }, fetchAll($pdo, 'SELECT operationNumber,temps,paireDevises,type,statutTypeClass,montant,prix,statut,statutClass,profitPerte,profitClass,details FROM tradingHistory WHERE user_id = ? ORDER BY id DESC', [$userId])),
    'loginHistory' => fetchAll($pdo, 'SELECT date,ip,device FROM loginHistory WHERE user_id = ? ORDER BY STR_TO_DATE(date, "%Y/%m/%d") DESC, id DESC LIMIT 100', [$userId]),
    'bankWithdrawInfo' => $bankWithdraw,
    'ftd' => $ftdData,
    'cryptoDepositAddresses' => fetchAll($pdo, 'SELECT id,crypto_name,wallet_info FROM deposit_crypto_address WHERE user_id = ?', [$userId]),
    'kycDocs' => $kycRows,
    // placeholders for front-end
    'formData' => new stdClass(),
    'defaultKYCStatus' => [
        'enregistrementducomptestat' => ['status' => $verify['enregistrementducompte'] ?? '2', 'date' => date('Y-m-d')],
        'confirmationdeladresseemailstat' => ['status' => $verify['confirmationdeladresseemail'] ?? '2', 'date' => date('Y-m-d')],
        'telechargerlesdocumentsdidentitestat' => ['status' => $idStatus, 'date' => $idDate],
        'verificationdeladressestat' => ['status' => $addrStatus, 'date' => $addrDate],
        'revisionfinalestat' => ['status' => $verify['revisionfinale'] ?? '2', 'date' => null],
    ],
];

echo json_encode($data);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
