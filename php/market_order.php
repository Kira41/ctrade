<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }

    $userId   = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $pair     = $data['pair'] ?? '';
    $quantity = isset($data['quantity']) ? (float)$data['quantity'] : 0.0;
    $amount   = isset($data['amount']) ? (float)$data['amount'] : 0.0; // USD amount
    $side     = strtolower($data['side'] ?? 'buy');

    if (!$userId || !$pair || (!($quantity > 0) && !($amount > 0)) || !in_array($side, ['buy','sell'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
        exit;
    }

    require_once __DIR__.'/../config/db_connection.php';
    require_once __DIR__.'/../utils/helpers.php';

    $pdo = db();

    [$base, $quote] = explode('/', strtoupper($pair));
    $price = getLivePrice($pair);
    if ($price <= 0) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch price']);
        exit;
    }
    if ($quantity <= 0 && $amount > 0) {
        $quantity = $amount / $price;
    }
    $total = $price * $quantity;

    $pdo->beginTransaction();
    $order = [
        'id' => null,
        'user_id' => $userId,
        'pair' => $pair,
        'side' => $side,
        'quantity' => $quantity
    ];
    $result = executeTrade($pdo, $order, $price, false);
    if (!$result['ok']) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $result['msg']]);
        return;
    }
    $pdo->commit();
    $newBalance = $result['balance'];
    $opNum      = $result['operation'];
    $profit     = $result['profit'];
    $price      = $result['price'];
    $opened     = $result['opened'];

    require_once __DIR__.'/../utils/poll.php';
    pushEvent('balance_updated', ['newBalance' => $newBalance], $userId);

    if ($opened) {
        // Notify frontend of the new open trade
        pushEvent('new_trade', [
            'operation_number' => $opNum,
            'pair' => $pair,
            'side' => $side,
            'quantity' => $quantity,
            'price' => $price,
            'target_price' => $price,
            'profit_loss' => $profit
        ], $userId);
    } else {
        // Closing an existing trade
        pushEvent('order_cancelled', [
            'order_id' => ltrim($opNum, 'T')
        ], $userId);
    }

    $actionMsg = $side === 'buy'
        ? "Achat de {$quantity} {$base} au prix du march\xC3\xA9 pour {$total} {$quote}"
        : "Vente de {$quantity} {$base} au prix du march\xC3\xA9 pour {$total} {$quote}";
    echo json_encode([
        'status' => 'ok',
        'message' => $actionMsg,
        'price' => $price,
        'new_balance' => $newBalance
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
