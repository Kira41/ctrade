<?php
require_once __DIR__.'/balance.php';
require_once __DIR__.'/market_data_provider.php';

function fetchTvQuotePrice(string $currencyPair): float {
    return getMarketPrice($currencyPair, 2.0);
}

function getLivePrice(string $pair, ?string $marketSymbol = null): float {
    $pairUpper = strtoupper(trim($pair));
    $marketSymbol = $marketSymbol ? strtoupper(trim($marketSymbol)) : null;

    if ($marketSymbol) {
        $price = getMarketPrice($marketSymbol, 2.0);
        if ($price > 0) return $price;
    }

    if (strpos($pairUpper, ':') !== false) {
        $price = getMarketPrice($pairUpper, 2.0);
        if ($price > 0) return $price;
    }

    if (preg_match('/^([A-Z0-9\.\-_]{2,20})\/(USD|USDT)$/', $pairUpper, $m)) {
        $base = $m[1];
        $quote = $m[2] === 'USDT' ? 'USD' : $m[2];
        return getMarketPrice('COINBASE:' . $base . $quote, 2.0);
    }

    return getMarketPrice($pairUpper, 2.0);
}

/**
 * Returns market price using the shared quotes client stream.
 * The timestamp argument is preserved for backward compatibility.
 */
function getHistoricalPrice(string $pair, int $timestamp): float {
    (void)$timestamp;

    $pairUpper = strtoupper(trim($pair));
    if (strpos($pairUpper, ':') === false) {
        $pairUpper = normalizeMarketPair($pairUpper);
    }

    return getMarketPrice($pairUpper, 2.0);
}

function addHistory(PDO $pdo, int $uid, string $opNum, string $pair, string $side,
    float $qty, float $price, string $status, ?float $profit = null): void {
    $typeTxt = $side === 'buy' ? 'Acheter' : 'Vendre';
    $typeClass = $side === 'buy' ? 'bg-success' : 'bg-danger';
    $statutClass = $status === 'complet' ? 'bg-success'
        : ($status === 'annule' ? 'bg-danger' : 'bg-warning');
    $profitClass = $profit === null ? '' : ($profit >= 0 ? 'text-success' : 'text-danger');
    $details = json_encode([]);
    $stmt = $pdo->prepare('INSERT INTO tradingHistory '
        . '(user_id, operationNumber, temps, paireDevises, type, statutTypeClass,'
        . ' montant, prix, statut, statutClass, profitPerte, profitClass, details) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE statut=VALUES(statut), statutClass=VALUES(statutClass),'
        . ' prix=VALUES(prix), profitPerte=VALUES(profitPerte), profitClass=VALUES(profitClass),'
        . ' details=VALUES(details)');
    $stmt->execute([
        $uid,
        $opNum,
        date('Y/m/d H:i'),
        $pair,
        $typeTxt,
        $typeClass,
        $qty,
        $price,
        $status,
        $statutClass,
        $profit,
        $profitClass,
        $details
    ]);
}

/**
 * Ensure a user is not submitting orders too frequently.
 * Returns true if the user has not placed an order in the last minute.
 */
function canPlaceOrder(PDO $pdo, int $uid): bool {
    $stmt = $pdo->prepare('SELECT created_at FROM trades WHERE user_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$uid]);
    $last = $stmt->fetchColumn();
    if (!$last) return true;
    return (time() - strtotime($last)) >= 60;
}

/**
 * Deduct funds from the user balance if sufficient.
 * The current balance is updated on success.
 */
function debitBalance(PDO $pdo, int $uid, float $amount, float &$bal): bool {
    try {
        $bal = updateBalance($pdo, $uid, -$amount);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function executeTrade(PDO $pdo, array $order, float $price, bool $closePositions = true) {
    $total = $price * $order['quantity'];
    $bal = 0.0;

    // BUY orders either open a long position or close an existing short
    if ($order['side'] === 'buy') {
        if ($closePositions) {
            // First check for open short positions to close
            $stOpen = $pdo->prepare('SELECT id,price,quantity,profit_loss FROM trades WHERE user_id=? AND pair=? AND side="sell" AND status="open" ORDER BY id ASC LIMIT 1');
            $stOpen->execute([$order['user_id'],$order['pair']]);
            $open = $stOpen->fetch(PDO::FETCH_ASSOC);
            if ($open) {
                $closeQty   = min($order['quantity'], $open['quantity']);
                $deposit    = $open['price'] * $closeQty;
                $manualProf = (float)$open['profit_loss'];
                $closePrice = $price;
                if ($manualProf !== 0.0) {
                    // Admin-set profit takes precedence; derive the close price from it
                    $profit     = $manualProf;
                    $closePrice = $open['price'] - ($profit / $closeQty);
                } else {
                    $profit = ($open['price'] - $closePrice) * $closeQty;
                }
                $bal = updateBalance($pdo, $order['user_id'], $deposit + $profit);
                $remaining = $open['quantity'] - $closeQty;
                if ($remaining > 0) {
                    $pdo->prepare('UPDATE trades SET quantity=?, total_value=?, profit_loss=profit_loss+? WHERE id=?')->execute([$remaining, $open['price']*$remaining, $profit, $open['id']]);
                } else {
                    $pdo->prepare('UPDATE trades SET status="closed", close_price=?, closed_at=NOW(), profit_loss=? WHERE id=?')->execute([$closePrice,$profit,$open['id']]);
                }
                $opNum = 'T'.$open['id'];
                addHistory($pdo,$order['user_id'],$opNum,$order['pair'],'buy',$closeQty,$closePrice,'complet',$profit);
                $remainingOrder = $order['quantity'] - $closeQty;
                if ($remainingOrder > 0) {
                    $totalRemain = $price * $remainingOrder; // use market price for any new long
                    if (!debitBalance($pdo, $order['user_id'], $totalRemain, $bal)) return ['ok'=>false,'msg'=>'Solde insuffisant'];
                    $stmt = $pdo->prepare('INSERT INTO trades (user_id,pair,side,quantity,price,total_value,fee,profit_loss,status) VALUES (?,?,?,?,?,?,0,0,"open")');
                    $stmt->execute([$order['user_id'],$order['pair'],'buy',$remainingOrder,$price,$totalRemain]);
                    $tradeId = $pdo->lastInsertId();
                    addHistory($pdo,$order['user_id'],'T'.$tradeId,$order['pair'],'buy',$remainingOrder,$price,'En cours');
                    return ['ok'=>true,'balance'=>$bal,'price'=>$price,'profit'=>$profit,'operation'=>'T'.$tradeId,'opened'=>true];
                }
                return ['ok'=>true,'balance'=>$bal,'price'=>$closePrice,'profit'=>$profit,'operation'=>$opNum,'opened'=>false];
            }
        }

        // No short to close - open a long position
        if (!debitBalance($pdo, $order['user_id'], $total, $bal)) return ['ok' => false, 'msg' => 'Solde insuffisant'];
        $stmt = $pdo->prepare('INSERT INTO trades (user_id,pair,side,quantity,price,total_value,fee,profit_loss,status) VALUES (?,?,?,?,?,?,0,0,"open")');
        $stmt->execute([$order['user_id'],$order['pair'],'buy',$order['quantity'],$price,$total]);
        $tradeId = $pdo->lastInsertId();
        $opNum = 'T'.$tradeId;
        // Record this trade as open in the trading history so that the UI can
        // track its profit/loss over time until it is closed.
        addHistory($pdo,$order['user_id'],$opNum,$order['pair'],'buy',$order['quantity'],$price,'En cours');
        return ['ok'=>true,'balance'=>$bal,'price'=>$price,'profit'=>0,'operation'=>$opNum,'opened'=>true];
    }

    // SELL orders either close a long position or open a new short
    if ($closePositions) {
        $stOpen = $pdo->prepare('SELECT id,price,quantity,side,profit_loss FROM trades WHERE user_id=? AND pair=? AND status="open" ORDER BY id ASC LIMIT 1');
        $stOpen->execute([$order['user_id'],$order['pair']]);
        $open = $stOpen->fetch(PDO::FETCH_ASSOC);

        if ($open && $open['side'] === 'buy') {
            // Closing a long position
            $closeQty    = min($order['quantity'], $open['quantity']);
            $manualProf  = (float)$open['profit_loss'];
            $closePrice  = $price;
            if ($manualProf !== 0.0) {
                $profit     = $manualProf;
                $closePrice = $open['price'] + ($profit / $closeQty);
            } else {
                $profit = ($closePrice - $open['price']) * $closeQty;
            }
            $closeTotal = $closePrice * $closeQty;
            $bal = updateBalance($pdo, $order['user_id'], $closeTotal);
            $remaining = $open['quantity'] - $closeQty;
            if ($remaining > 0) {
                $pdo->prepare('UPDATE trades SET quantity=?, total_value=?, profit_loss=profit_loss+? WHERE id=?')->execute([$remaining, $open['price']*$remaining, $profit, $open['id']]);
            } else {
                $pdo->prepare('UPDATE trades SET status="closed", close_price=?, closed_at=NOW(), profit_loss=? WHERE id=?')->execute([$closePrice,$profit,$open['id']]);
            }
            $opNum = 'T'.$open['id'];
            addHistory($pdo,$order['user_id'],$opNum,$order['pair'],'sell',$closeQty,$closePrice,'complet',$profit);
            $remainingOrder = $order['quantity'] - $closeQty;
            if ($remainingOrder > 0) {
                $totalShort = $price * $remainingOrder; // use market price for new short
                if (!debitBalance($pdo, $order['user_id'], $totalShort, $bal)) return ['ok'=>false,'msg'=>'Solde insuffisant'];
                $stmt = $pdo->prepare('INSERT INTO trades (user_id,pair,side,quantity,price,total_value,fee,profit_loss,status) VALUES (?,?,?,?,?,?,0,0,"open")');
                $stmt->execute([$order['user_id'],$order['pair'],'sell',$remainingOrder,$price,$totalShort]);
                $tradeId = $pdo->lastInsertId();
                addHistory($pdo,$order['user_id'],'T'.$tradeId,$order['pair'],'sell',$remainingOrder,$price,'En cours');
                return ['ok'=>true,'balance'=>$bal,'price'=>$price,'profit'=>$profit,'operation'=>'T'.$tradeId,'opened'=>true];
            }
            return ['ok'=>true,'balance'=>$bal,'price'=>$closePrice,'profit'=>$profit,'operation'=>$opNum,'opened'=>false];
        }
    }

    // No long position to close - open a short position
    if (!debitBalance($pdo, $order['user_id'], $total, $bal)) return ['ok' => false, 'msg' => 'Solde insuffisant'];
    $stmt = $pdo->prepare('INSERT INTO trades (user_id,pair,side,quantity,price,total_value,fee,profit_loss,status) VALUES (?,?,?,?,?,?,0,0,"open")');
    $stmt->execute([$order['user_id'],$order['pair'],'sell',$order['quantity'],$price,$total]);
    $tradeId = $pdo->lastInsertId();
    $opNum = 'T'.$tradeId;
    addHistory($pdo,$order['user_id'],$opNum,$order['pair'],'sell',$order['quantity'],$price,'En cours');
    return ['ok'=>true,'balance'=>$bal,'price'=>$price,'profit'=>0,'operation'=>$opNum,'opened'=>true];
}
?>
