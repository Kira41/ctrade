<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    require_once __DIR__.'/../utils/permissions.php';
    require_once __DIR__.'/../utils/poll.php';
    require_once __DIR__.'/../utils/balance.php';
    $pdo = db();

    $updateVerify = function(int $uid) use ($pdo){
        $idTypes=['id_front','id_back','selfie'];
        $ph=implode(',',array_fill(0,count($idTypes),'?'));
        $stmt=$pdo->prepare("SELECT status FROM kyc WHERE user_id=? AND file_type IN ($ph)");
        $stmt->execute(array_merge([$uid],$idTypes));
        $statuses=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if($statuses){
            $val=0;
            if(in_array('pending',$statuses)) {
                $val=2;
            } elseif(in_array('approved',$statuses)) {
                $val=1;
            }
            $pdo->prepare('INSERT INTO verification_status (user_id, telechargerlesdocumentsdidentite) VALUES (?,?) ON DUPLICATE KEY UPDATE telechargerlesdocumentsdidentite=VALUES(telechargerlesdocumentsdidentite)')->execute([$uid,$val]);
        }
        $stmt=$pdo->prepare("SELECT status FROM kyc WHERE user_id=? AND file_type='address'");
        $stmt->execute([$uid]);
        $a=$stmt->fetchAll(PDO::FETCH_COLUMN);
        if($a){
            $val=0;
            if(in_array('pending',$a)) {
                $val=2;
            } elseif(in_array('approved',$a)) {
                $val=1;
            }
            $pdo->prepare('INSERT INTO verification_status (user_id, verificationdeladresse) VALUES (?,?) ON DUPLICATE KEY UPDATE verificationdeladresse=VALUES(verificationdeladresse)')->execute([$uid,$val]);
        }
    };

    function deleteUserData(PDO $pdo, int $userId) {
        $tables = [
            'transactions',
            'retraits',
            'tradingHistory',
            'notifications',
            'loginHistory',
            'deposits',
            'bank_withdrawl_info',
            'personal_data'
        ];
        foreach ($tables as $table) {
            $pdo->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$userId]);
        }
    }

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

    $forbidden = function() {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
        exit;
    };

    $generateOperationNumber = function(PDO $pdo, string $prefix): string {
        $attempts = 0;
        do {
            $attempts++;
            $candidate = $prefix . random_int(10000, 99999);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE operationNumber = ?');
            $stmt->execute([$candidate]);
            $exists = ((int)$stmt->fetchColumn()) > 0;
        } while ($exists && $attempts < 10);
        if ($exists) {
            $candidate = $prefix . date('His') . random_int(10, 99);
        }
        return $candidate;
    };
    $hasInsertTrigger = function(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND EVENT_MANIPULATION = "INSERT"');
        $stmt->execute([$table]);
        return ((int)$stmt->fetchColumn()) > 0;
    };

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }

    $allowedUserCols = [
        'user_id','balance','totalDepots','totalRetraits','nbTransactions',
        'fullName','compteverifie','compteverifie01','niveauavance','passwordHash',
        'or_p','passwordStrength','passwordStrengthBar','emailNotifications','smsNotifications',
        'loginAlerts','transactionAlerts','twoFactorAuth','emailaddress','address',
        'phone','dob','nationality','created_at',
        'userBankName','userAccountName','userAccountNumber','userIban','userSwiftCode',
        'linked_to_id'
    ];

    $action = $data['action'] ?? '';

    if ($action === 'create_admin') {
        if ($isAdmin < 1) { $forbidden(); }
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $newIsAdmin = isset($data['is_admin']) ? (int)$data['is_admin'] : 0;
        if ($newIsAdmin === 2) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Cannot assign Super Admin privilege']);
            exit;
        }
        if (!$email || !$password) {
            throw new Exception('Missing parameters');
        }
        $stmt = $pdo->prepare('INSERT INTO admins_agents (email, password, is_admin, created_by) VALUES (?,?,?,?)');
        $stmt->execute([$email, $password, $newIsAdmin, $adminId]);
        echo json_encode(['status' => 'ok', 'id' => $pdo->lastInsertId()]);
    } elseif ($action === 'create_user') {
        $user = $data['user'] ?? [];
        if (!$user || (!isset($user['password']) && !isset($user['passwordHash']))) {
            throw new Exception('Missing parameters');
        }
        if ($isAdmin !== 2) {
            // regular admins can only create users for themselves
            $user['linked_to_id'] = $adminId;
        } elseif (!isset($user['linked_to_id'])) {
            throw new Exception('Missing linked_to_id');
        }
        $passwordHash = $user['passwordHash'] ?? $user['password'];
        $passwordPlain = $user['or_p'] ?? ($user['passwordPlain'] ?? null);
        if (!$passwordHash) {
            throw new Exception('Missing password hash');
        }
        unset($user['password'], $user['passwordPlain']);
        $user['passwordHash'] = $passwordHash;
        if ($passwordPlain !== null) {
            $user['or_p'] = $passwordPlain;
        }
        if (!isset($user['created_at']) || $user['created_at'] === '') {
            $user['created_at'] = date('Y-m-d');
        }
        $user = array_intersect_key($user, array_flip($allowedUserCols));
        $cols = array_keys($user);
        $place = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO personal_data (' . implode(',', $cols) . ') VALUES (' . $place . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($user));

        if (!empty($data['bankWithdrawInfo']) && is_array($data['bankWithdrawInfo'])) {
            $bw = $data['bankWithdrawInfo'];
            $bwCols = ['user_id','widhrawBankName','widhrawAccountName','widhrawAccountNumber','widhrawIban','widhrawSwiftCode'];
            $values = [$user['user_id'] ?? null];
            foreach (array_slice($bwCols,1) as $c) {
                $values[] = $bw[$c] ?? null;
            }
            $placeholders = implode(',', array_fill(0, count($bwCols), '?'));
            $sql = 'REPLACE INTO bank_withdrawl_info (' . implode(',', $bwCols) . ') VALUES (' . $placeholders . ')';
            $pdo->prepare($sql)->execute($values);
        }

        if (!empty($data['cryptoAddresses']) && is_array($data['cryptoAddresses'])) {
            $stmt = $pdo->prepare('INSERT INTO deposit_crypto_address (user_id,crypto_name,wallet_info) VALUES (?,?,?)');
            foreach ($data['cryptoAddresses'] as $addr) {
                $stmt->execute([
                    $user['user_id'] ?? null,
                    $addr['crypto_name'] ?? '',
                    $addr['wallet_info'] ?? ''
                ]);
            }
        }

        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'update_user') {
        $user = $data['user'] ?? [];
        if (!$user || !isset($user['user_id'])) {
            throw new Exception('Missing parameters');
        }
        $userId = (int)$user['user_id'];
        if ($isAdmin !== 2) {
            $stmt = $pdo->prepare('SELECT linked_to_id FROM personal_data WHERE user_id = ?');
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() !== $adminId) { $forbidden(); }
        }
        unset($user['user_id']);
        if ($isAdmin !== 2) {
            unset($user['linked_to_id']);
        }
        $user = array_intersect_key($user, array_flip($allowedUserCols));
        $cols = array_keys($user);
        if (!$cols) {
            throw new Exception('No fields to update');
        }
        $set = implode(',', array_map(fn($c) => "$c = ?", $cols));
        $sql = "UPDATE personal_data SET $set WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $values = array_values($user);
        $values[] = $userId;
        $stmt->execute($values);

        if (!empty($data['bankWithdrawInfo']) && is_array($data['bankWithdrawInfo'])) {
            $bw = $data['bankWithdrawInfo'];
            $bwCols = ['user_id','widhrawBankName','widhrawAccountName','widhrawAccountNumber','widhrawIban','widhrawSwiftCode'];
            $valuesBw = [$userId];
            foreach (array_slice($bwCols,1) as $c) {
                $valuesBw[] = $bw[$c] ?? null;
            }
            $place = implode(',', array_fill(0, count($bwCols), '?'));
            $sqlBw = 'REPLACE INTO bank_withdrawl_info (' . implode(',', $bwCols) . ') VALUES (' . $place . ')';
            $pdo->prepare($sqlBw)->execute($valuesBw);
        }

        if (!empty($data['cryptoAddresses']) && is_array($data['cryptoAddresses'])) {
            $pdo->prepare('DELETE FROM deposit_crypto_address WHERE user_id = ?')->execute([$userId]);
            $stmt = $pdo->prepare('INSERT INTO deposit_crypto_address (user_id,crypto_name,wallet_info) VALUES (?,?,?)');
            foreach ($data['cryptoAddresses'] as $addr) {
                $stmt->execute([$userId, $addr['crypto_name'] ?? '', $addr['wallet_info'] ?? '']);
            }
        }

        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'update_admin') {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if (!$id) {
            throw new Exception('Missing id');
        }
        if ($isAdmin !== 2 && $id !== $adminId) { $forbidden(); }
        $fields = [];
        $values = [];
        if (isset($data['email'])) {
            $fields[] = 'email = ?';
            $values[] = $data['email'];
        }
        if (isset($data['password']) && $data['password'] !== '') {
            $oldPwd = $data['old_password'] ?? '';
            if ($oldPwd === '') {
                throw new Exception('Missing old_password');
            }
            $stmt = $pdo->prepare('SELECT password FROM admins_agents WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals($row['password'], $oldPwd)) {
                throw new Exception('Incorrect old password');
            }
            $fields[] = 'password = ?';
            $values[] = $data['password'];
        }
        if (isset($data['is_admin'])) {
            if ($isAdmin !== 2) { $forbidden(); }
            $newIsAdmin = (int)$data['is_admin'];
            if ($newIsAdmin === 2) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Cannot assign Super Admin privilege']);
                exit;
            }
            $fields[] = 'is_admin = ?';
            $values[] = $newIsAdmin;
        }
        if (!$fields) {
            throw new Exception('No fields to update');
        }
        $values[] = $id;
        $sql = 'UPDATE admins_agents SET ' . implode(',', $fields) . ' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'delete_admin') {
        if ($isAdmin !== 2) { $forbidden(); }
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if (!$id) {
            throw new Exception('Missing id');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT user_id FROM personal_data WHERE linked_to_id = ?');
            $stmt->execute([$id]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($userIds) {
                foreach ($userIds as $uid) {
                    deleteUserData($pdo, (int)$uid);
                }
            }
            $stmt = $pdo->prepare('DELETE FROM admins_agents WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();
            echo json_encode(['status' => 'ok']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'delete_user') {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        if (!$userId) {
            throw new Exception('Missing user_id');
        }
        if ($isAdmin !== 2) {
            $stmt = $pdo->prepare('SELECT linked_to_id FROM personal_data WHERE user_id = ?');
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() !== $adminId) { $forbidden(); }
        }
        $pdo->beginTransaction();
        try {
            deleteUserData($pdo, $userId);
            $pdo->commit();
            echo json_encode(['status' => 'ok']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'edit_trade_profit') {
        $op = isset($data['operationNumber']) ? trim($data['operationNumber']) : '';
        $profit = isset($data['profit']) ? (float)$data['profit'] : null;
        if ($op === '' || $profit === null) {
            throw new Exception('Missing parameters');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT th.user_id, th.prix, th.montant, th.profitPerte, th.statut, p.linked_to_id FROM tradingHistory th JOIN personal_data p ON p.user_id = th.user_id WHERE th.operationNumber = ? FOR UPDATE');
            $stmt->execute([$op]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Trade not found');
            }
            $userId = (int)$row['user_id'];
            if ($isAdmin !== 2) {
                $allowedIds = getDescendantAdminIds($pdo, $adminId);
                if (!in_array((int)$row['linked_to_id'], $allowedIds, true)) {
                    $pdo->rollBack();
                    $forbidden();
                }
            }
            $oldPrice = (float)$row['prix'];
            $oldProfit = (float)$row['profitPerte'];
            $qty = (float)$row['montant'];
            $diff = $profit - $oldProfit;
            $newPrice = $qty != 0 ? $oldPrice + ($diff / $qty) : $oldPrice;
            $profitClass = $profit >= 0 ? 'text-success' : 'text-danger';
            $tradeId = (int)substr($op, 1);
            $pdo->prepare('UPDATE tradingHistory SET profitPerte = ?, prix = ?, profitClass = ? WHERE operationNumber = ?')->execute([$profit, $newPrice, $profitClass, $op]);
            $pdo->prepare('UPDATE transactions SET amount = ? WHERE operationNumber = ?')->execute([$profit, $op]);
            if (strcasecmp($row['statut'], 'complet') === 0) {
                $pdo->prepare('UPDATE trades SET profit_loss = ?, close_price = ? WHERE id = ?')->execute([$profit, $newPrice, $tradeId]);
                updateBalance($pdo, $userId, $diff);
            } else {
                $pdo->prepare('UPDATE trades SET profit_loss = ? WHERE id = ?')->execute([$profit, $tradeId]);
            }
            $pdo->commit();
            pushEvent('trade_profit_fixed', [
                'operation_number' => $op,
                'profit' => $profit,
                'price' => $newPrice,
                'profitClass' => $profitClass
            ], $userId);
            echo json_encode(['status' => 'ok', 'prix' => $newPrice, 'profitClass' => $profitClass]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'admin_manual_deposit') {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $amount = isset($data['amount']) ? (float)$data['amount'] : null;
        $depositType = trim($data['deposit_type'] ?? '');
        $allowedTypes = ['Depot','Bonus','Ajustement','Retrait'];
        if (!$userId || $amount === null || $amount <= 0 || !in_array($depositType, $allowedTypes, true)) {
            throw new Exception('Invalid parameters');
        }
        if ($isAdmin !== 2) {
            $stmt = $pdo->prepare('SELECT linked_to_id FROM personal_data WHERE user_id = ?');
            $stmt->execute([$userId]);
            $linkedTo = (int)$stmt->fetchColumn();
            $allowedIds = getDescendantAdminIds($pdo, $adminId);
            if (!in_array($linkedTo, $allowedIds, true)) { $forbidden(); }
        }
        $isWithdrawal = $depositType === 'Retrait';
        $historyTable = $isWithdrawal ? 'retraits' : 'deposits';
        $prefix = $isWithdrawal ? 'R' : 'D';
        $txType = $isWithdrawal ? 'Retrait' : 'Dépôt';
        $op = $generateOperationNumber($pdo, $prefix);
        $date = date('Y/m/d');
        $status = 'complet';
        $statusClass = 'bg-success';
        $needsManualTotals = !$hasInsertTrigger($pdo, $historyTable);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO $historyTable (user_id, admin_id, operationNumber, date, amount, method, status, statusClass) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$userId, $adminId, $op, $date, $amount, $depositType, $status, $statusClass]);
            $stmt = $pdo->prepare('INSERT INTO transactions (user_id, admin_id, operationNumber, type, amount, date, status, statusClass) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$userId, $adminId, $op, $txType, $amount, $date, $status, $statusClass]);
            if ($needsManualTotals) {
                $balanceDelta = $isWithdrawal ? -$amount : $amount;
                updateBalance($pdo, $userId, $balanceDelta);
                if ($isWithdrawal) {
                    $pdo->prepare('UPDATE personal_data SET totalRetraits = IFNULL(totalRetraits,0) + ?, nbTransactions = IFNULL(nbTransactions,0) + 1 WHERE user_id = ?')
                        ->execute([$amount, $userId]);
                } else {
                    $pdo->prepare('UPDATE personal_data SET totalDepots = IFNULL(totalDepots,0) + ?, nbTransactions = IFNULL(nbTransactions,0) + 1 WHERE user_id = ?')
                        ->execute([$amount, $userId]);
                }
            }
            $timeNow = date('Y-m-d H:i:s');
            $msgAmount = number_format($amount, 0, '.', ' ') . ' $';
            if ($isWithdrawal) {
                $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        $userId,
                        'success',
                        'Retrait approuvé',
                        "Votre retrait de $msgAmount a été approuvé.",
                        $timeNow,
                        'alert-success'
                    ]);
            } else {
                $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        $userId,
                        'success',
                        'Dépôt réussi',
                        "Un montant de $msgAmount a été déposé avec succès.",
                        $timeNow,
                        'alert-success'
                    ]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        $stmt = $pdo->prepare('SELECT balance FROM personal_data WHERE user_id = ?');
        $stmt->execute([$userId]);
        $bal = $stmt->fetchColumn();
        pushEvent('balance_updated', ['newBalance' => $bal], $userId);
        pushEvent('data_saved', [], $userId);
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'edit_transaction_amount') {
        if ($isAdmin !== 2) { $forbidden(); }
        $op = isset($data['operationNumber']) ? trim($data['operationNumber']) : '';
        $amount = isset($data['amount']) ? (float)$data['amount'] : null;
        if ($op === '' || $amount === null) {
            throw new Exception('Missing parameters');
        }
        $stmt = $pdo->prepare('UPDATE transactions SET amount = ? WHERE operationNumber = ?');
        $stmt->execute([$amount, $op]);
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'update_transaction') {
        // Agents, admins, and superadmins can update transactions.
        // Ownership checks for non-superadmins are handled below.
        $op = isset($data['id']) ? trim($data['id']) : '';
        if ($op === '') {
            throw new Exception('Missing id');
        }
        $prefix = strtoupper(substr($op, 0, 1));
        if ($prefix === 'T') {
            $pdo->beginTransaction();
            try {
                $id = (int)substr($op, 1);
                $pdo->prepare('DELETE FROM trades WHERE id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM transactions WHERE operationNumber = ?')->execute([$op]);
                $pdo->prepare('DELETE FROM tradingHistory WHERE operationNumber = ?')->execute([$op]);
                $pdo->commit();
                echo json_encode(['status' => 'ok']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            $historyTable = null;
            if ($prefix === 'D') {
                $historyTable = 'deposits';
            } elseif ($prefix === 'R') {
                $historyTable = 'retraits';
            }
            $pdo->beginTransaction();
            try {
                $stmt = ($historyTable
                    ? $pdo->prepare("SELECT h.user_id, h.amount, h.status, h.date, p.linked_to_id FROM $historyTable h JOIN personal_data p ON p.user_id = h.user_id WHERE h.operationNumber = ? FOR UPDATE")
                    : $pdo->prepare("SELECT t.user_id, t.amount, t.status, t.date, p.linked_to_id FROM transactions t JOIN personal_data p ON p.user_id = t.user_id WHERE t.operationNumber = ? FOR UPDATE"));
                $stmt->execute([$op]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new Exception('Transaction not found');
                }
                if ($isAdmin !== 2) {
                    $allowedIds = getDescendantAdminIds($pdo, $adminId);
                    if (!in_array((int)$row['linked_to_id'], $allowedIds, true)) {
                        $pdo->rollBack();
                        $forbidden();
                    }
                }
                $userId = (int)$row['user_id'];
                $amount = (float)$row['amount'];
                $oldStatus = $row['status'];
                if (!empty($data['delete'])) {
                    if ($historyTable) {
                        $pdo->prepare("DELETE FROM $historyTable WHERE operationNumber = ?")->execute([$op]);
                    }
                    $pdo->prepare("DELETE FROM transactions WHERE operationNumber = ?")->execute([$op]);
                    if ($oldStatus === 'complet') {
                        // balance adjustments now handled by database triggers
                    }
                } else {
                    $status = $data['status'] ?? null;
                    $class = $data['statusClass'] ?? null;
                    if ($status === null || $class === null) {
                        throw new Exception('Missing status');
                    }
                    if ($historyTable) {
                        $pdo->prepare("UPDATE $historyTable SET status = ?, statusClass = ? WHERE operationNumber = ?")
                            ->execute([$status, $class, $op]);
                    }
                    $pdo->prepare("UPDATE transactions SET status = ?, statusClass = ? WHERE operationNumber = ?")
                        ->execute([$status, $class, $op]);
                    if ($prefix === 'D') {
                        if ($oldStatus !== 'complet' && $status === 'complet') {
                            // balance and stats handled by database triggers
                            $timeNow = date('Y-m-d H:i:s');
                            $msgAmount = number_format($amount, 0, '.', ' ') . ' $';
                            $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)')
                                ->execute([
                                    $userId,
                                    'success',
                                    'Dépôt réussi',
                                    "Un montant de $msgAmount a été déposé avec succès.",
                                    $timeNow,
                                    'alert-success'
                                ]);
                        } elseif ($oldStatus === 'complet' && $status !== 'complet') {
                            // triggers automatically revert deposit effects
                        }
                    } elseif ($prefix === 'R') {
                        if ($oldStatus !== 'complet' && $status === 'complet') {
                            // balance and stats handled by database triggers
                            $timeNow = date('Y-m-d H:i:s');
                            $msgAmount = number_format($amount, 0, '.', ' ') . ' $';
                            $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)')
                                ->execute([
                                    $userId,
                                    'success',
                                    'Retrait approuvé',
                                    "Votre retrait de $msgAmount a été approuvé.",
                                    $timeNow,
                                    'alert-success'
                                ]);
                        } elseif ($oldStatus === 'complet' && $status !== 'complet') {
                            // triggers automatically revert withdrawal effects
                        }
                    }
                }
                $pdo->commit();
                echo json_encode(['status' => 'ok']);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } elseif ($action === 'broadcast_update') {
        if ($isAdmin !== 2) { $forbidden(); }
        $date = $data['date'] ?? '';
        if (!$date) { throw new Exception('Missing date'); }
        $stmt = $pdo->query('SELECT user_id FROM personal_data');
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $timeNow = date('Y-m-d H:i:s');
        $insert = $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)');
        foreach ($userIds as $uid) {
            $insert->execute([
                (int)$uid,
                'info',
                'Mise à jour du système',
                "Le système sera mis à jour le $date.",
                $timeNow,
                'alert-info'
            ]);
        }
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'broadcast_custom') {
        if ($isAdmin !== 2) { $forbidden(); }
        $message = trim($data['message'] ?? '');
        if ($message === '') { throw new Exception('Missing message'); }
        $userIds = $data['user_ids'] ?? [];
        if (!is_array($userIds) || count($userIds) === 0) {
            $stmt = $pdo->query('SELECT user_id FROM personal_data');
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $userIds = array_map('intval', $userIds);
        }
        $timeNow = date('Y-m-d H:i:s');
        $insert = $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)');
        foreach ($userIds as $uid) {
            $insert->execute([
                (int)$uid,
                'info',
                'Notification',
                $message,
                $timeNow,
                'alert-info'
            ]);
        }
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'broadcast_kyc') {
        if ($isAdmin !== 2) { $forbidden(); }
        $stmt = $pdo->query('SELECT user_id FROM personal_data');
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $timeNow = date('Y-m-d H:i:s');
        $insert = $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)');
        foreach ($userIds as $uid) {
            $insert->execute([
                (int)$uid,
                'info',
                'Vérification KYC requise',
                "Veuillez compléter la vérification de votre identité.",
                $timeNow,
                'alert-info'
            ]);
        }
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'broadcast_maintenance') {
        if ($isAdmin !== 2) { $forbidden(); }
        $stmt = $pdo->query('SELECT user_id FROM personal_data');
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $timeNow = date('Y-m-d H:i:s');
        $insert = $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)');
        foreach ($userIds as $uid) {
            $insert->execute([
                (int)$uid,
                'warning',
                'Maintenance de paiement',
                "Des problèmes de paiement/maintenance sont en cours.",
                $timeNow,
                'alert-warning'
            ]);
        }
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'update_kyc') {
        if ($isAdmin !== 2) { $forbidden(); }
        $fileId = isset($data['file_id']) ? (int)$data['file_id'] : 0;
        $status = $data['status'] ?? '';
        if (!$fileId || !in_array($status, ['approved','rejected'])) {
            throw new Exception('Invalid parameters');
        }
        $stmt = $pdo->prepare('UPDATE kyc SET status = ? WHERE file_id = ?');
        $stmt->execute([$status, $fileId]);
        $uidStmt = $pdo->prepare('SELECT user_id FROM kyc WHERE file_id = ?');
        $uidStmt->execute([$fileId]);
        $uid = $uidStmt->fetchColumn();
        if ($uid) {
            $updateVerify($uid);
            if ($status === 'approved') {
                $timeNow = date('Y-m-d H:i:s');
                $pdo->prepare('INSERT INTO notifications (user_id,type,title,message,time,alertClass) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        $uid,
                        'kyc',
                        'Vérification approuvée',
                        "Votre vérification d'identité a été approuvée.",
                        $timeNow,
                        'alert-success'
                    ]);
            }
        }
        echo json_encode(['status' => 'ok']);
    } elseif ($action === 'set_revision_finale') {
        if ($isAdmin !== 2) { $forbidden(); }
        $uid = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        if (!$uid) { throw new Exception('Missing user_id'); }
        $pdo->prepare('INSERT INTO verification_status (user_id, revisionfinale) VALUES (?,1) ON DUPLICATE KEY UPDATE revisionfinale=1')->execute([$uid]);
        echo json_encode(['status' => 'ok']);
    } else {
        throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
