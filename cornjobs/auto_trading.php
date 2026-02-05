<?php
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../utils/helpers.php';
require_once __DIR__.'/../utils/poll.php';

$pdo = db();

$orders = $pdo->query("SELECT * FROM trades WHERE type_order='limit' AND status='pending'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($orders as $o) {
    $price = getLivePrice($o['pair']);
    if ($price <= 0) { continue; }
    $trigger = ($o['side'] === 'buy' && $price >= $o['price']) ||
               ($o['side'] === 'sell' && $price <= $o['price']);
    if (!$trigger) continue;

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM trades WHERE id=? FOR UPDATE");
        $stmt->execute([$o['id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) { $pdo->rollBack(); continue; }
        $price = getLivePrice($order['pair']);
        $trigger = ($order['side'] === 'buy' && $price >= $order['price']) ||
                   ($order['side'] === 'sell' && $price <= $order['price']);
        if (!$trigger) { $pdo->rollBack(); continue; }
        // Funds were reserved when the limit order was placed. Refund them
        // before executing the trade so executeTrade can debit correctly.
        updateBalance($pdo, $order['user_id'], $order['total_value']);
        $tradeOrder = [
            'user_id' => $order['user_id'],
            'pair' => $order['pair'],
            'side' => $order['side'],
            'quantity' => $order['quantity']
        ];
        $result = executeTrade($pdo, $tradeOrder, $price);
        if (!$result['ok']) { $pdo->rollBack(); continue; }
        $balance = $result['balance'] ?? 0.0;
        $pdo->prepare('DELETE FROM trades WHERE id=?')->execute([$order['id']]);
        addHistory($pdo, $order['user_id'], 'L'.$order['id'], $order['pair'], $order['side'], $order['quantity'], $price, 'complet', $result['profit']);
        $pdo->commit();
        pushEvent('balance_updated', ['newBalance' => $balance], $order['user_id']);
        if ($result['opened']) {
            pushEvent('new_trade', [
                'operation_number' => $result['operation'],
                'pair' => $order['pair'],
                'side' => $order['side'],
                'quantity' => $order['quantity'],
                'price' => $price,
                'target_price' => $price,
                'profit_loss' => $result['profit']
            ], $order['user_id']);
        } else {
            pushEvent('order_cancelled', ['order_id' => ltrim($result['operation'], 'T')], $order['user_id']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

$stopOrders = $pdo->query("SELECT * FROM trades WHERE status='open' AND stop_price IS NOT NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($stopOrders as $t) {
    $price = getLivePrice($t['pair']);
    if ($price <= 0) { continue; }
    $trigger = ($t['side'] === 'buy' && $price <= $t['stop_price']) ||
               ($t['side'] === 'sell' && $price >= $t['stop_price']);
    if (!$trigger) continue;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM trades WHERE id=? FOR UPDATE");
        $stmt->execute([$t['id']]);
        $trade = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trade) { $pdo->rollBack(); continue; }
        $price = getLivePrice($trade['pair']);
        $trigger = ($trade['side'] === 'buy' && $price <= $trade['stop_price']) ||
                   ($trade['side'] === 'sell' && $price >= $trade['stop_price']);
        if (!$trigger) { $pdo->rollBack(); continue; }
        $closeOrder = [
            'user_id' => $trade['user_id'],
            'pair' => $trade['pair'],
            'side' => $trade['side'] === 'buy' ? 'sell' : 'buy',
            'quantity' => $trade['quantity']
        ];
        $result = executeTrade($pdo, $closeOrder, $price);
        if (!$result['ok']) { $pdo->rollBack(); continue; }
        $balance = $result['balance'] ?? 0.0;
        $pdo->commit();
        pushEvent('balance_updated', ['newBalance' => $balance], $trade['user_id']);
        pushEvent('order_cancelled', ['order_id' => ltrim($result['operation'], 'T')], $trade['user_id']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}
