<?php
header('Content-Type: application/json');
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});

try{
    $data=json_decode(file_get_contents('php://input'),true);
    if(!is_array($data)){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
        exit;
    }
    $userId=(int)($data['user_id'] ?? 0);
    $tradeId=(int)($data['order_id'] ?? 0);
    if(!$userId || !$tradeId){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Missing parameters']);
        exit;
    }
    require_once __DIR__.'/../config/db_connection.php';
    require_once __DIR__.'/../utils/helpers.php';
    $pdo=db();
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT * FROM trades WHERE id=? AND user_id=? FOR UPDATE');
    $stmt->execute([$tradeId,$userId]);
    $trade=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$trade){
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status'=>'error','message'=>'Trade not found']);
        exit;
    }
    // If an admin edited the profit for this trade, `profit_loss` will contain the
    // authoritative value. In that case, use it rather than recalculating from the
    // live price so the user's view remains consistent after closing the order.
    $profit = (float)$trade['profit_loss'];
    if ($profit !== 0.0) {
        if ($trade['side'] === 'buy') {
            $price = $trade['price'] + ($profit / $trade['quantity']);
        } else {
            $price = $trade['price'] - ($profit / $trade['quantity']);
        }
    } else {
        $price = getLivePrice($trade['pair']);
        if ($price <= 0) {
            $price = $trade['price'];
        }
        if ($trade['side'] === 'buy') {
            $profit = ($price - $trade['price']) * $trade['quantity'];
        } else {
            $profit = ($trade['price'] - $price) * $trade['quantity'];
        }
    }
    $deposit = $trade['price'] * $trade['quantity'] + $profit;
    $bal = updateBalance($pdo, $userId, $deposit);
    $pdo->prepare('UPDATE trades SET status="closed", close_price=?, closed_at=NOW(), profit_loss=? WHERE id=?')
        ->execute([$price,$profit,$tradeId]);
    $adminStmt=$pdo->prepare('SELECT linked_to_id FROM personal_data WHERE user_id=?');
    $adminStmt->execute([$userId]);
    $adminId=$adminStmt->fetchColumn();
    $op='T'.$tradeId;
    addHistory($pdo,$userId,$op,$trade['pair'],$trade['side'],$trade['quantity'],$price,'complet',$profit);
    $stmt=$pdo->prepare('INSERT INTO transactions (user_id,admin_id,operationNumber,type,amount,date,status,statusClass) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount), date=VALUES(date), status=VALUES(status), statusClass=VALUES(statusClass)');
    $stmt->execute([$userId,$adminId,$op,'Trading',$deposit,date('Y/m/d'),'complet','bg-success']);
    $pdo->commit();
    require_once __DIR__.'/../utils/poll.php';
    pushEvent('balance_updated',['newBalance'=>$bal],$userId);
    pushEvent('order_cancelled',['order_id'=>$tradeId],$userId);
    echo json_encode(['status'=>'ok','profit'=>$profit]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
?>
