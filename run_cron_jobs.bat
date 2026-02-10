@echo off
REM Run cron jobs for the Coin Dashboard project in an infinite loop
cd /d "%~dp0"

set /a tick=0

:loop
php -f cornjobs/market_snapshot_refresher.php
set /a tick+=1

REM Run auto trading every 3 seconds while market snapshot keeps refreshing every second.
set /a rem=tick%%3
if %rem%==0 (
    php -f cornjobs/auto_trading.php
)

timeout /t 1 >nul
goto loop
