<?php
require_once __DIR__.'/balance.php';

function fetchTvQuotePrice(string $currencyPair): float {
    $pair = strtoupper(trim($currencyPair));
    if ($pair === '' || strpos($pair, ':') === false) return 0.0;

    $url = 'http://171.22.114.97:8000/tv/quote?currencyPair=' . urlencode($pair);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: te_6XvQpK9jR2mN4sA7fH8uC1zL0wY3tG5eB9nD7kS2pV4qR8m',
            'Accept: application/json',
            'User-Agent: CoinTrade-PHP-Helpers/1.0'
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode < 200 || $httpCode >= 300) return 0.0;

    $data = json_decode($response, true);
    if (!is_array($data)) return 0.0;

    foreach (['market_last', 'price', 'c', 'close', 'last', 'lp'] as $k) {
        if (isset($data[$k]) && is_numeric($data[$k])) {
            return (float)$data[$k];
        }
    }
    return 0.0;
}

function getLivePrice(string $pair, ?string $marketSymbol = null): float {
    $pairUpper = strtoupper(trim($pair));
    $marketSymbol = $marketSymbol ? strtoupper(trim($marketSymbol)) : null;

    // Use the same market symbol used by the frontend whenever available.
    if ($marketSymbol && strpos($marketSymbol, ':') !== false) {
        $tvPrice = fetchTvQuotePrice($marketSymbol);
        if ($tvPrice > 0) return $tvPrice;
    }

    $symbol = str_replace('/', '', $pairUpper);
    if (strpos($symbol, ':') !== false) {
        [, $symbol] = array_pad(explode(':', $symbol, 2), 2, '');
    }
    if (!preg_match('/USDT$/', $symbol) && preg_match('/USD$/', $symbol)) {
        $symbol = substr($symbol, 0, -3) . 'USDT';
    }

    if ($symbol !== '') {
        $url = 'https://api.binance.com/api/v3/ticker/price?symbol=' . $symbol;
        $context = stream_context_create(['http' => ['timeout' => 3]]);
        $json = @file_get_contents($url, false, $context);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (isset($data['price']) && is_numeric($data['price'])) {
                return (float)$data['price'];
            }
        }
    }

    // Fallback: query the internal TV quote service with common exchanges.
    if (preg_match('/^([A-Z0-9\.\-_]{2,20})\/(USD|USDT)$/', $pairUpper, $m)) {
        $base = $m[1];
        $quotes = ['USDT', 'USD'];
        $exchanges = ['BINANCE', 'COINBASE', 'BITSTAMP', 'POLONIEX', 'KRAKEN'];
        foreach ($quotes as $quote) {
            foreach ($exchanges as $exchange) {
                $tvPrice = fetchTvQuotePrice($exchange . ':' . $base . $quote);
                if ($tvPrice > 0) return $tvPrice;
            }
        }
    }

    return 0.0;
}

/**
 * Fetch the historical closing price for a currency pair from CryptoCompare.
 * The timestamp should be a Unix epoch (seconds).
 * Returns 0 on failure.
 */
function getHistoricalPrice(string $pair, int $timestamp): float {
    [$base, $quote] = explode('/', strtoupper($pair));
    // CryptoCompare expects USD rather than USDT
    if ($quote === 'USDT') {
        $quote = 'USD';
    }
    $url = sprintf(
        'https://min-api.cryptocompare.com/data/pricehistorical?fsym=%s&tsyms=%s&ts=%d',
        urlencode($base), urlencode($quote), $timestamp
    );
    $json = @file_get_contents($url);
    if ($json === false) return 0.0;
    $data = json_decode($json, true);
    return isset($data[$base][$quote]) ? (float)$data[$base][$quote] : 0.0;
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
