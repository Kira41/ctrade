let pollAbort = false;

function startLongPoll(userId) {
  pollAbort = false;
  const poll = () => {
    if (pollAbort) return;
    fetch(`php/long_poll.php?user_id=${encodeURIComponent(userId)}`, { cache: 'no-store' })
      .then(res => res.json())
      .then(handleEvents)
      .catch(err => console.error('poll error', err))
      .finally(() => {
        if (!pollAbort) poll();
      });
  };
  poll();
}

function stopLongPoll() {
  pollAbort = true;
}

function handleEvents(response) {
  if (!response || !Array.isArray(response.events)) return;
  response.events.forEach(ev => {
    switch (ev.event) {
      case 'balance_updated':
        if (window.updateBalance) {
          window.updateBalance(ev.data.newBalance);
        } else if (window.dashboardData && window.dashboardData.personalData) {
          window.dashboardData.personalData.balance = parseFloat(ev.data.newBalance);
          if (typeof window.refreshUI === 'function') window.refreshUI();
        }
        break;
      case 'new_trade':
        if (window.addTrade) window.addTrade(ev.data);
        break;
      case 'order_filled':
        if (window.handleOrderFilled) window.handleOrderFilled(ev.data);
        break;
      case 'new_order':
        if (window.handleNewOrder) window.handleNewOrder(ev.data);
        break;
      case 'order_cancelled':
        if (window.handleOrderCancelled) window.handleOrderCancelled(ev.data);
        break;
      case 'trade_profit_fixed':
        if (window.handleTradeProfitFixed) window.handleTradeProfitFixed(ev.data);
        break;
      default:
        break;
    }
  });
}
