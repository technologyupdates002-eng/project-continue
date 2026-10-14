<?php
$start = new DateTimeImmutable('2026-08-31');

echo "Start: " . $start->format('Y-m-d H:i:s') . "\n";

$current = $start->modify('+1 month');
echo "modify('+1 month'): " . $current->format('Y-m-d H:i:s') . "\n";

$current = $start->add(new DateInterval('P1M'));
echo "add(DateInterval('P1M')): " . $current->format('Y-m-d H:i:s') . "\n";

$current = $start->modify('first day of next month');
echo "modify('first day of next month'): " . $current->format('Y-m-d H:i:s') . "\n";

$current = $start->modify('first day of this month')->modify('+1 month');
echo "modify('first day of this month')->modify('+1 month'): " . $current->format('Y-m-d H:i:s') . "\n";