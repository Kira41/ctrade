#!/bin/bash

cd "$(dirname "$0")"

tick=0
while true; do
  php -f cornjobs/market_snapshot_refresher.php
  tick=$((tick + 1))

  if (( tick % 3 == 0 )); then
    php -f cornjobs/auto_trading.php
  fi

  sleep 1
done
