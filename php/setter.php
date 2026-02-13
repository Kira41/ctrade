<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }

    $userId = $data['personalData']['user_id'] ?? $data['user_id'] ?? ($_POST['user_id'] ?? 1);

    $pdo->beginTransaction();

    if (isset($data['personalData'])) {
        $personal = $data['personalData'];
        unset($personal['linked_to_id']);

        // Exclude any nested arrays/objects to avoid "Array to string conversion"
        // notices when binding parameters. Only scalar values are persisted.
        $personal = array_filter($personal, fn($v) => !is_array($v));
        $personal['user_id'] = $userId;

        $cols = array_keys($personal);
        $place = implode(',', array_fill(0, count($cols), '?'));
        $update = implode(',', array_map(fn($c) => "$c = VALUES($c)", $cols));
        $sql = 'INSERT INTO personal_data (' . implode(',', $cols) . ') VALUES (' . $place . ') '
             . 'ON DUPLICATE KEY UPDATE ' . $update;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($personal));
    } else {
    }

    $stmt = $pdo->prepare('SELECT linked_to_id FROM personal_data WHERE user_id = ?');
    $stmt->execute([$userId]);
    $adminId = $stmt->fetchColumn() ?: null;


    if (isset($data['defaultKYCStatus']) && is_array($data['defaultKYCStatus'])) {
        $v = $data['defaultKYCStatus'];
        $stmt = $pdo->prepare('INSERT INTO verification_status (user_id,enregistrementducompte,confirmationdeladresseemail,telechargerlesdocumentsdidentite,verificationdeladresse,revisionfinale) VALUES (?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE enregistrementducompte=VALUES(enregistrementducompte), confirmationdeladresseemail=VALUES(confirmationdeladresseemail), telechargerlesdocumentsdidentite=VALUES(telechargerlesdocumentsdidentite), verificationdeladresse=VALUES(verificationdeladresse), revisionfinale=VALUES(revisionfinale)');
        $stmt->execute([
            $userId,
            $v['enregistrementducomptestat']['status'] ?? 0,
            $v['confirmationdeladresseemailstat']['status'] ?? 0,
            $v['telechargerlesdocumentsdidentitestat']['status'] ?? 0,
            $v['verificationdeladressestat']['status'] ?? 0,
            $v['revisionfinalestat']['status'] ?? 0,
        ]);
    }

    $tables = [
        'transactions' => ['operationNumber','type','amount','date','status','statusClass'],
        'notifications' => ['type','title','message','time','alertClass'],
        'deposits' => ['operationNumber','date','amount','method','status','statusClass'],
        'retraits' => ['operationNumber','date','amount','method','status','statusClass'],
        'tradingHistory' => ['operationNumber','temps','paireDevises','type','statutTypeClass','montant','prix','statut','statutClass','profitPerte','profitClass','details'],
        'loginHistory' => ['date','ip','device'],
    ];

    foreach ($tables as $table => $cols) {
        if (!isset($data[$table]) || !is_array($data[$table])) {
            continue;
        }

        $hasAdmin = in_array($table, ['transactions','deposits','retraits','tradingHistory']);
        $colList = 'user_id' . ($hasAdmin ? ',admin_id' : '') . ',' . implode(',', $cols);
        $place = '(' . implode(',', array_fill(0, count($cols) + 1 + ($hasAdmin ? 1 : 0), '?')) . ')';

        $updateCols = [];
        if (in_array($table, ['transactions','deposits','retraits','tradingHistory'])) {
            if ($hasAdmin) $updateCols[] = 'admin_id = VALUES(admin_id)';
            foreach ($cols as $c) {
                $updateCols[] = "$c = VALUES($c)";
            }
            $onDup = ' ON DUPLICATE KEY UPDATE ' . implode(',', $updateCols);
        } else {
            $onDup = '';
        }

        $sql = "INSERT INTO $table ($colList) VALUES $place$onDup";
        $stmt = $pdo->prepare($sql);

        foreach ($data[$table] as $row) {
            $values = [$userId];
            if ($hasAdmin) {
                $values[] = $adminId;
            }
            foreach ($cols as $c) {
                if ($c === 'details') {
                    $values[] = isset($row[$c]) ? json_encode($row[$c]) : null;
                } else {
                    $values[] = $row[$c] ?? null;
                }
            }
            $stmt->execute($values);
        }
    }

    if (isset($data['bankWithdrawInfo']) && is_array($data['bankWithdrawInfo'])) {
        $bw = $data['bankWithdrawInfo'];
        $cols = ['user_id','widhrawBankName','widhrawAccountName','widhrawAccountNumber','widhrawIban','widhrawSwiftCode'];
        $place = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $pdo->prepare('DELETE FROM bank_withdrawl_info WHERE user_id = ?')->execute([$userId]);
        $sql = 'INSERT INTO bank_withdrawl_info (' . implode(',', $cols) . ') VALUES ' . $place;
        $stmt = $pdo->prepare($sql);
        $values = [$userId];
        foreach (array_slice($cols,1) as $c) {
            $values[] = $bw[$c] ?? null;
        }
        $stmt->execute($values);
    }

    $pdo->commit();

    require_once __DIR__.'/../utils/poll.php';
    $stmt = $pdo->prepare('SELECT balance FROM personal_data WHERE user_id = ?');
    $stmt->execute([$userId]);
    $bal = $stmt->fetchColumn();
    pushEvent('balance_updated', ['newBalance' => $bal], $userId);
    pushEvent('data_saved', [], $userId);

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
