<?php
/**
 * Utility functions for profit/loss calculations.
 */

/**
 * Calculate the weighted average buy price from an array of prior buy trades.
 * Each trade should contain 'price' and 'quantity' keys.
 */
function calculate_average_buy_price(array $buyTrades): float {
    $totalCost = 0.0;
    $totalQty = 0.0;
    foreach ($buyTrades as $t) {
        $qty = isset($t['quantity']) ? (float)$t['quantity'] : 0.0;
        $price = isset($t['price']) ? (float)$t['price'] : 0.0;
        $totalCost += $price * $qty;
        $totalQty += $qty;
    }
    return $totalQty > 0 ? $totalCost / $totalQty : 0.0;
}

/**
 * Profit/loss when closing a long position.
 */
function profit_loss_long(float $sellPrice, float $avgBuyPrice, float $qtySold): float {
    return ($sellPrice - $avgBuyPrice) * $qtySold;
}

/**
 * Profit/loss when closing a short position.
 */
function profit_loss_short(float $buyPrice, float $sellPrice, float $qty): float {
    return ($sellPrice - $buyPrice) * $qty;
}


