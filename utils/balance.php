<?php
/**
 * Atomically adjust a user's balance by the given delta.
 * Uses row-level locking to prevent race conditions and
 * optionally wraps the operation in its own transaction
 * when none is active.
 *
 * @throws Exception if the resulting balance would be negative.
 * @return float The updated balance.
 */
function updateBalance(PDO $pdo, int $userId, float $delta): float {
    $ownTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTx = true;
    }

    $stmt = $pdo->prepare('SELECT balance FROM personal_data WHERE user_id=? FOR UPDATE');
    $stmt->execute([$userId]);
    $current = (float)$stmt->fetchColumn();
    $new = $current + $delta;
    if ($new < 0) {
        if ($ownTx) {
            $pdo->rollBack();
        }
        throw new Exception('Insufficient balance');
    }
    $pdo->prepare('UPDATE personal_data SET balance=? WHERE user_id=?')->execute([$new, $userId]);

    if ($ownTx) {
        $pdo->commit();
    }

    return $new;
}
