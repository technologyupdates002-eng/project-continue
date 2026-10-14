<?php
$start = new DateTimeImmutable('2026-08-31');
$today = new DateTimeImmutable('2026-09-06');

echo "Start: " . $start->format('Y-m-d H:i:s') . "\n";
echo "Today: " . $today->format('Y-m-d') . "\n";

$current = $start;
$idx = 0;
while (true) {
    $y = (int) $current->format('Y');
    $m = (int) $current->format('n');
    echo "Iteration $idx: y=$y, m=$m, current=" . $current->format('Y-m-d H:i:s') . "\n";
    
    if ($y > (int) $today->format('Y') || ($y === (int) $today->format('Y') && $m > (int) $today->format('n'))) {
        echo "Breaking because date is in the future\n";
        break;
    }
    
    $current = $current->modify('+1 month');
    $idx++;
    if ($idx > 120) {
        echo "Breaking because idx > 120\n";
        break;
    }
}

echo "\nTotal iterations: $idx\n";