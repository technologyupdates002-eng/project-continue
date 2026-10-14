<?php
$file = 'app/Services/ContributionObligationService.php';
$content = file_get_contents($file);

// Fix memberSummary due date calculation
$search = <<<'CODE'
            $dueDate = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $y, $m))->modify('last day of this month');
            $graceDeadline = $dueDate->modify('+' . $graceDays . ' days');
CODE;

$replace = <<<'CODE'
            $dueDate = null;
            if ($row && !empty($row->due_date)) {
                $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', $row->due_date);
            }
            if (!$dueDate) {
                $daysInMonth = (int) \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $y, $m))->format('t');
                $dueDay = min($this->dueDay(), $daysInMonth);
                $dueDate = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-%d', $y, $m, $dueDay));
            }
            $graceDeadline = $dueDate->modify('+' . $graceDays . ' days');
CODE;

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences in memberSummary' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    echo 'Patched memberSummary' . PHP_EOL;
} else {
    echo 'Pattern not found in memberSummary' . PHP_EOL;
}

// Fix nextDueDate due date calculation
$search2 = <<<'CODE'
        $nextDue = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $oldestPartial->year, $oldestPartial->month))->modify('last day of this month');
CODE;

$replace2 = <<<'CODE'
        $nextDue = null;
        if (!empty($oldestPartial->due_date)) {
            $nextDue = \DateTimeImmutable::createFromFormat('Y-m-d', $oldestPartial->due_date);
        }
        if (!$nextDue) {
            $daysInMonth = (int) \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $oldestPartial->year, $oldestPartial->month))->format('t');
            $dueDay = min($this->dueDay(), $daysInMonth);
            $nextDue = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-%d', $oldestPartial->year, $oldestPartial->month, $dueDay));
        }
CODE;

$count2 = substr_count($content, $search2);
echo 'Found ' . $count2 . ' occurrences in nextDueDate' . PHP_EOL;
if ($count2 > 0) {
    $content = str_replace($search2, $replace2, $content);
    echo 'Patched nextDueDate' . PHP_EOL;
} else {
    echo 'Pattern not found in nextDueDate' . PHP_EOL;
}

file_put_contents($file, $content);
echo 'Saved' . PHP_EOL;
