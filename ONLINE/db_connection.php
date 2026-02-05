<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=localhost;dbname=admin_coindb;charset=utf8mb4';
        $pdo = new PDO($dsn, 'admin_coin', 'hyEnU8Gplm', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Enable query buffering so multiple queries can run sequentially
            // without explicitly consuming all previous results.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ]);
    }
    return $pdo;
}
?>
