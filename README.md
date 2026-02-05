# Coin Dashboard

This project uses small PHP helpers (`getter.php` and `setter.php`) to read and update user data stored in a MySQL database. The schema is defined in `sql/createtable.sql` and example data is provided in `sql/insertdata.sql`.

## Database setup

1. Make sure the PHP MySQL PDO extension is installed and a MySQL server is running.
2. Create a database named `coin_db` and load the schema and sample data:
   ```sh
   mysql -u root coin_db < sql/createtable.sql
   mysql -u root coin_db < sql/insertdata.sql
   ```
3. The PHP scripts connect to `coin_db` on `localhost` using the `root` user with an empty password. Update the connection settings in `getter.php` and `setter.php` if your environment differs.

The dashboard pages (`dashbord_user.html` and `js/updatePrices.js`) request data from `php/getter.php` and send updates to `php/setter.php`.
The trading interface operates directly on each user's account balance. Wallet functionality has been removed, so trades deduct from the balance and record immediately in the trading history.

All tables now use the **InnoDB** storage engine and any `AUTO_INCREMENT`
columns have been widened to `BIGINT` to prevent errors when new rows are
created.

The `personal_data` table now includes the user's own bank details used when
submitting withdrawal requests: `userBankName`, `userAccountName`,
`userAccountNumber`, `userIban` and `userSwiftCode`. Separate deposit
information is kept in the `bank_withdrawl_info` table. This table stores the
bank coordinates shown on the deposit screen and each user has at most one
record. These deposit details are filled in by an administrator when creating or
editing a user. The admin dashboard's create and edit user modals now include
input fields for these coordinates so they can be entered or updated alongside
other personal data.

An additional table `admins_agents` stores admin and agent accounts. Each row
contains an email, hashed password and an `is_admin` flag, plus a `created_by`
field referencing the admin who created the record. `is_admin` uses the
following levels:

* `0` – regular agent
* `1` – admin who can manage their own agents and users
* `2` – super admin with full access to all users and agents

New admin accounts are restricted to levels `0` and `1`; the super admin level
(`2`) is reserved and cannot be assigned through the dashboard or API. The
`created_by` column is automatically populated with the ID of the admin creating
the account.

The `personal_data` table now includes a `linked_to_id` column storing the
`admins_agents.id` of the creator. When inserting or updating these records via
`admin_setter.php`, make sure the password you send is pre-hashed on the client
using the provided MD5 algorithm.
Each `email` in `admins_agents` must be unique, enforced by a `UNIQUE(email)`
constraint in the schema.

Foreign keys from tables such as `transactions`, `deposits`, `retraits` and
`tradingHistory` now include `ON DELETE CASCADE`. Removing a row from
`personal_data` will automatically clean up any related records, preventing
foreign key errors.

The trading history table shows amounts using the traded coin symbol instead of dollars, e.g. `100 XRP`. The database now stores trade quantities and prices with up to ten decimal places so small orders such as `0.005 BTC` are recorded accurately.

## Admin dashboard

`insertdata.sql` seeds a default administrator account (`admin@scampia.io`) with
ID `1`. To load data for this account you now must be authenticated. The
`admin_getter.php` endpoint looks for a session variable named `admin_id` or an
`Authorization: Bearer <id>` header identifying the admin. If neither is
present, the request is rejected with `401 Unauthorized`. Once authenticated,
`dashboard_admin.html` will display the admin's agents and associated users.
Use the "Créer Agent" form to add new agents under the logged‑in admin.

Deleting an agent with `admin_setter.php` now removes all of the users tied to
that account. Each affected user's rows in `personal_data`, `transactions`,
`tradingHistory`, `notifications`, `loginHistory`, `deposits`
and `bank_withdrawl_info` are deleted before the agent record itself is
removed. The same cleanup occurs when deleting an individual user.

Use `admin_login.php` to sign in. POST `email` and `password`; a successful login starts a session and stores `admin_id` for subsequent requests.

## Admin Login

`dashboard_admin.html` now embeds its own login form. You can also sign in by POSTing `email` and `password` to `admin_login.php`. If the credentials are valid the endpoint creates a session and sets a cookie storing `admin_id`. Keep this cookie for all subsequent calls to `admin_getter.php` and other admin actions so the server knows who you are. Tools like `curl -c cookies.txt -b cookies.txt` can handle the cookie automatically.

For convenience a built-in administrator account is hard-coded in `admin_login.php`. Use the username `alone` with the password `Scampia.Alone.41` to access the dashboard without seeding a database record.


## User Login

`dashbord_user.html` now includes a login form. Submit your email and password to `user_login.php`; on success the script stores your `user_id` in `localStorage` and loads the dashboard for that account. Each successful login is also recorded in the `loginHistory` table along with the IP address and device used.

## Automated trade closing

For Windows users, double-click `run_cron_jobs.bat` in the project root to execute all cron tasks manually.

### Order types

All trades execute immediately using the current price returned by Binance and are stored directly in the `trades` table. Pending order types such as limit or stop orders are not supported.

When querying Binance for live prices remember that pairs use the `USDT` quote currency. A pair like `ADA/USD` should be converted to `ADAUSDT` before requesting the price.

Example pseudo-code for order execution:

```php
// Market order execution
$price = getLivePrice($pair);
$total = $price * $quantity;
if ($side === 'buy') {
    // deduct dollars from the user's account balance
    deductFromAccount($userId, $total);
    addToWallet($userId, $base, $quantity, $price);
} else {
    deductFromWallet($userId, $base, $quantity, $price);
    // credit dollars back to the account
    addToAccount($userId, $total);
}
recordTrade($userId, $pair, $side, $quantity, $price);
```

## Real-time updates

The old WebSocket server has been removed. The dashboard now relies on a
long polling endpoint (`php/long_poll.php`). Client-side JavaScript keeps
sending background requests and immediately processes any returned events to
update balances or trades without reloading the page.

## Profit/Loss calculation

Use the executed trade price, not the candle price, to determine profit or loss.
The basic formula for closing a long position is:

```
(sell price - average buy price) * quantity sold
```

For short selling the logic is reversed:

```
(sell price - buy price) * quantity
```

`utils/pnl.php` contains helper functions implementing these rules. The
`calculate_average_buy_price()` function builds the weighted average from a list
of prior buy trades, while `profit_loss_long()` and `profit_loss_short()` return
the resulting PnL. A small JavaScript version lives in `js/pnl.js` for client
side use. Example usage in PHP:

```php
$avg = calculate_average_buy_price($previousBuys);
$profit = profit_loss_long($executedSellPrice, $avg, $soldQty);
```

## Historical prices

The helper function `getHistoricalPrice()` fetches the closing price of a
currency pair at a specific Unix timestamp from the public CryptoCompare API.
Call this function from your PHP code to retrieve historical values:

```php
$price = getHistoricalPrice('BTC/USD', 1609459200);
```

