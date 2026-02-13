<?php
/**
 * Simple long polling utility storing events in JSON files.
 */

const POLL_EVENT_DIR = __DIR__ . '/../data/events';

if (!is_dir(POLL_EVENT_DIR)) {
    mkdir(POLL_EVENT_DIR, 0777, true);
}

/**
 * Add an event to the queue for a specific user or globally.
 */
function pushEvent(string $event, array $data = [], $userId = null): bool {
    $key = $userId !== null ? (string)$userId : 'global';
    $file = POLL_EVENT_DIR . '/' . $key . '.json';
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $contents = stream_get_contents($fp);
    $events = $contents ? json_decode($contents, true) : [];
    if (!is_array($events)) $events = [];
    $events[] = ['event' => $event, 'data' => $data];
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($events));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Retrieve and clear queued events for a user.
 */
function pullEvents($userId): array {
    $events = [];
    foreach (['global', $userId] as $key) {
        if ($key === null) continue;
        $file = POLL_EVENT_DIR . '/' . $key . '.json';
        if (!file_exists($file)) continue;
        $fp = fopen($file, 'c+');
        if (!$fp) continue;
        flock($fp, LOCK_EX);
        $contents = stream_get_contents($fp);
        if ($contents) {
            $arr = json_decode($contents, true);
            if (is_array($arr)) {
                $events = array_merge($events, $arr);
            }
        }
        ftruncate($fp, 0);
        fclose($fp);
        unlink($file);
    }
    return $events;
}
