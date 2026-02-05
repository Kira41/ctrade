/**
 * Utility helpers for client-side PnL calculations.
 */
export function averageBuyPrice(buys) {
    let cost = 0;
    let qty = 0;
    for (const t of buys) {
        const q = parseFloat(t.quantity) || 0;
        const p = parseFloat(t.price) || 0;
        cost += p * q;
        qty += q;
    }
    return qty > 0 ? cost / qty : 0;
}

export function profitLossLong(sellPrice, avgBuyPrice, qtySold) {
    return (sellPrice - avgBuyPrice) * qtySold;
}

export function profitLossShort(buyPrice, sellPrice, qty) {
    return (sellPrice - buyPrice) * qty;
}

