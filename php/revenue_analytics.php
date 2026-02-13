<?php
header('Content-Type: application/json');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__.'/../config/db_connection.php';
    $pdo = db();

    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 1;
    $period = isset($_GET['period']) ? strtolower(trim($_GET['period'])) : 'monthly';
    $allowedPeriods = ['monthly', 'weekly', 'daily'];
    if (!in_array($period, $allowedPeriods, true)) {
        $period = 'monthly';
    }

    function buildPeriods(string $period, int $count = 12): array {
        $now = new DateTime('today');
        $periods = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            if ($period === 'monthly') {
                $start = (clone $now)->modify('first day of this month')->modify("-{$i} months");
                $end = (clone $start)->modify('last day of this month');
                $key = $start->format('Y-m');
                $label = $start->format('M');
            } elseif ($period === 'weekly') {
                $start = (clone $now)->modify('monday this week')->modify("-{$i} weeks");
                $end = (clone $start)->modify('sunday this week');
                $key = $start->format('oW');
                $label = 'Wk ' . $start->format('W');
            } else {
                $start = (clone $now)->modify("-{$i} days");
                $end = clone $start;
                $key = $start->format('Y-m-d');
                $label = $start->format('M j');
            }

            $periods[] = [
                'key' => $key,
                'label' => $label,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $periods;
    }

    function fetchRevenueTotals(PDO $pdo, int $userId, string $period, DateTime $startDate, DateTime $endDate): array {
        $dateColumn = 'STR_TO_DATE(date, "%Y/%m/%d")';
        if ($period === 'monthly') {
            $periodKey = 'DATE_FORMAT(' . $dateColumn . ', "%Y-%m")';
        } elseif ($period === 'weekly') {
            $periodKey = 'YEARWEEK(' . $dateColumn . ', 1)';
        } else {
            $periodKey = 'DATE_FORMAT(' . $dateColumn . ', "%Y-%m-%d")';
        }

        $sql = "SELECT {$periodKey} AS period_key, SUM(amount) AS total
                FROM transactions
                WHERE user_id = ?
                  AND {$dateColumn} BETWEEN ? AND ?
                GROUP BY period_key
                ORDER BY period_key ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $userId,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
        ]);

        $totals = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = (string)$row['period_key'];
            if ($period === 'weekly') {
                $key = str_pad($key, 6, '0', STR_PAD_LEFT);
            }
            $totals[$key] = (float)$row['total'];
        }

        return $totals;
    }

    $periods = buildPeriods($period, 12);
    $startDate = $periods[0]['start'];
    $endDate = $periods[count($periods) - 1]['end'];
    $totals = fetchRevenueTotals($pdo, $userId, $period, $startDate, $endDate);

    $labels = [];
    $values = [];
    $maxValue = 0;
    foreach ($periods as $periodItem) {
        $key = $periodItem['key'];
        $labels[] = $periodItem['label'];
        $value = $totals[$key] ?? 0.0;
        $values[] = $value;
        if ($value > $maxValue) {
            $maxValue = $value;
        }
    }

    echo json_encode([
        'period' => $period,
        'labels' => $labels,
        'values' => $values,
        'max' => $maxValue,
    ]);
} catch (Throwable $e) {
    error_log(__FILE__ . ' - ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
