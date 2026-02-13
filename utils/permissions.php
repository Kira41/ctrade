<?php
function getDescendantAdminIds(PDO $pdo, int $adminId): array {
    $ids = [$adminId];
    $queue = [$adminId];
    $stmt = $pdo->prepare('SELECT id FROM admins_agents WHERE created_by = ?');
    while ($queue) {
        $current = array_pop($queue);
        $stmt->execute([$current]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($children as $child) {
            $child = (int)$child;
            if (!in_array($child, $ids, true)) {
                $ids[] = $child;
                $queue[] = $child;
            }
        }
    }
    return $ids;
}
