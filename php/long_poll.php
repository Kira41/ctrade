<?php
// Long polling endpoint delivering queued events
set_time_limit(0);
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../utils/poll.php';

$userId = isset($_GET['user_id']) ? preg_replace('/[^0-9]/', '', $_GET['user_id']) : null;
$timeout = 15; // seconds
$start = time();

while (true) {
    $events = pullEvents($userId);
    if (!empty($events)) {
        echo json_encode(['events' => $events]);
        break;
    }
    if (time() - $start >= $timeout) {
        echo json_encode(['events' => []]);
        break;
    }
    sleep(1);
}
?>
