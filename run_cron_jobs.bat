@echo off
REM Run all cron jobs for the Coin Dashboard project in an infinite loop
cd /d "%~dp0"

:loop
php -f cornjobs/auto_trading.php
timeout /t 3 >nul
goto loop
