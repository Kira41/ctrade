<?php
header('Content-Type: application/json');
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});

try {
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input)){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
        exit;
    }
    $userId=(int)($input['user_id']??0);
    $pair=$input['pair']??'';
    $qty=isset($input['quantity'])?(float)$input['quantity']:0.0;
    $amount=isset($input['amount'])?(float)$input['amount']:0.0;
    $side=strtolower($input['side']??'buy');
    $type=strtolower($input['type']??'market');
    if(!$userId || !$pair || !in_array($side,['buy','sell'])){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Missing parameters']);
        exit;
    }
    if(!preg_match('/^[A-Z]{2,10}\/[A-Z]{2,10}$/', strtoupper($pair))){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Invalid pair']);
        exit;
    }
    require_once __DIR__.'/../config/db_connection.php';
    require_once __DIR__.'/../utils/helpers.php';
    $pdo=db();

    if($type==='limit'){
        $limitPrice=isset($input['limit_price'])?(float)$input['limit_price']:0.0;
        if($limitPrice<=0){
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Invalid limit price']);
            return;
        }
        if($qty<=0 && $amount>0){
            $qty=$amount/$limitPrice;
        }
        if($qty<=0){
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Invalid amount']);
            return;
        }
        $total=$limitPrice*$qty;
        $bal=0.0;
        $pdo->beginTransaction();
        if (!debitBalance($pdo, $userId, $total, $bal)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Insufficient balance']);
            return;
        }
        $stmt=$pdo->prepare('INSERT INTO trades (user_id,pair,side,quantity,price,total_value,fee,profit_loss,status,type_order) VALUES (?,?,?,?,?,?,0,0,"pending","limit")');
        $stmt->execute([$userId,$pair,$side,$qty,$limitPrice,$total]);
        $orderId=$pdo->lastInsertId();
        addHistory($pdo,$userId,'L'.$orderId,$pair,$side,$qty,$limitPrice,'En attente');
        $pdo->commit();
        require_once __DIR__.'/../utils/poll.php';
        pushEvent('balance_updated',['newBalance'=>$bal],$userId);
        echo json_encode(['status'=>'ok','message'=>'Limit order placed','new_balance'=>$bal]);
        return;
    }
    $stopPrice=null;
    if($type==='stop'){
        $stopPrice=isset($input['stop_price'])?(float)$input['stop_price']:0.0;
        if($stopPrice<=0){
            http_response_code(400);
            echo json_encode(['status'=>'error','message'=>'Invalid stop price']);
            return;
        }
    }
    $livePrice=getLivePrice($pair);
    if($livePrice<=0){
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Failed to fetch price']);
        return;
    }
    if($qty<=0 && $amount>0){
        $qty=$amount/$livePrice;
    }
    if($qty<=0){
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Invalid amount']);
        return;
    }
    $pdo->beginTransaction();
    $order=['user_id'=>$userId,'pair'=>$pair,'side'=>$side,'quantity'=>$qty];
    $result=executeTrade($pdo,$order,$livePrice,false);
    if(!$result['ok']){
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>$result['msg']]);
        return;
    }
    if($type==='stop' && $result['opened']){
        $tradeId=(int)ltrim($result['operation'],'T');
        $stmt=$pdo->prepare('UPDATE trades SET stop_price=? WHERE id=?');
        $stmt->execute([$stopPrice,$tradeId]);
    }
    $pdo->commit();
    require_once __DIR__.'/../utils/poll.php';
    pushEvent('balance_updated', ['newBalance'=>$result['balance']], $userId);
    $opNum=$result['operation'];
    pushEvent('new_trade', [
        'operation_number'=>$opNum,
        'pair'=>$pair,
        'side'=>$side,
        'quantity'=>$qty,
        'price'=>$result['price'],
        'target_price'=>$result['price'],
        'profit_loss'=>$result['profit']
    ], $userId);
    echo json_encode(['status'=>'ok','price'=>$result['price'],'new_balance'=>$result['balance']]);
} catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
?>
