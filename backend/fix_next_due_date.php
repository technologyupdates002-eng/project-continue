<?php
$file = 'app/Services/ContributionObligationService.php';
$content = file_get_contents($file);

$search = <<<'CODE'
        $partialMonths = $contributions->filter(fn ($c) => $c->status === 'partial');
        if ($partialMonths->isNotEmpty()) {
            $oldestPartial = $partialMonths->sortBy(fn ($c) => $c->year . '-' . str_pad($c->month, 2, '0', STR_PAD_LEFT))->first();
            return \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $oldestPartial->year, $oldestPartial->month))->modify('last day of this month');
        }

        $paidMonths = $contributions->filter(fn ($c) => $c->status === 'paid' || $c->paid_date !== null);
        $monthsCovered = (int) $paidMonths->unique(fn ($c) => (int) $c->year . '-' . (int) $c->month)->count();

        $nextDue = $start->modify('+' . $monthsCovered . ' months');
        $nextDue = \DateTimeImmutable::createFromFormat('Y-n-j', $nextDue->format('Y-m-01'))->modify('last day of this month');

        $today = new \DateTimeImmutable('today');
        $unpaidOverdue = $contributions->filter(function ($c) use ($today) {
            if ($c->status === 'paid' || $c->paid_date !== null || $c->status === 'partial') return false;
            $due = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $c->year, $c->month))->modify('last day of this month');
            $grace = $due->modify('+' . $this->graceDays() . ' days');
            return $today > $grace;
        });

        if ($unpaidOverdue->isNotEmpty()) {
            $oldest = $unpaidOverdue->sortBy(fn ($c) => $c->year . '-' . str_pad($c->month, 2, '0', STR_PAD_LEFT))->first();
            $oldestDue = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $oldest->year, $oldest->month))->modify('last day of this month');
            if ($oldestDue < $today) {
                return $oldestDue;
            }
        }
CODE;

$replace = <<<'CODE'
        $partialMonths = $contributions->filter(fn ($c) => $c->status === 'partial');
        if ($partialMonths->isNotEmpty()) {
            $oldestPartial = $partialMonths->sortBy(fn ($c) => $c->year . '-' . str_pad($c->month, 2, '0', STR_PAD_LEFT))->first();
            $dueDate = null;
            if (!empty($oldestPartial->due_date)) {
                $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', $oldestPartial->due_date);
            }
            if (!$dueDate) {
                $daysInMonth = (int) \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $oldestPartial->year, $oldestPartial->month))->format('t');
                $dueDay = min($this->dueDay(), $daysInMonth);
                $dueDate = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-%d', $oldestPartial->year, $oldestPartial->month, $dueDay));
            }
            return $dueDate;
        }

        $paidMonths = $contributions->filter(fn ($c) => $c->status === 'paid' || $c->paid_date !== null);
        $monthsCovered = (int) $paidMonths->unique(fn ($c) => (int) $c->year . '-' . (int) $c->month)->count();

        $nextDue = $start->modify('+' . $monthsCovered . ' months');
        $daysInMonth = (int) $nextDue->format('t');
        $dueDay = min($this->dueDay(), $daysInMonth);
        $nextDue = \DateTimeImmutable::createFromFormat('Y-n-j', $nextDue->format('Y-m-') . sprintf('%02d', $dueDay));

        $today = new \DateTimeImmutable('today');
        $unpaidOverdue = $contributions->filter(function ($c) use ($today) {
            if ($c->status === 'paid' || $c->paid_date !== null || $c->status === 'partial') return false;
            $dueDate = null;
            if (!empty($c->due_date)) {
                $dueDate = \DateTimeImmutable::createFromFormat('Y-m-d', $c->due_date);
            }
            if (!$dueDate) {
                $daysInMonth = (int) \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $c->year, $c->month))->format('t');
                $dueDay = min($this->dueDay(), $daysInMonth);
                $dueDate = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-%d', $c->year, $c->month, $dueDay));
            }
            $grace = $dueDate->modify('+' . $this->graceDays() . ' days');
            return $today > $grace;
        });

        if ($unpaidOverdue->isNotEmpty()) {
            $oldest = $unpaidOverdue->sortBy(fn ($c) => $c->year . '-' . str_pad($c->month, 2, '0', STR_PAD_LEFT))->first();
            $oldestDue = null;
            if (!empty($oldest->due_date)) {
                $oldestDue = \DateTimeImmutable::createFromFormat('Y-m-d', $oldest->due_date);
            }
            if (!$oldestDue) {
                $daysInMonth = (int) \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $oldest->year, $oldest->month))->format('t');
                $dueDay = min($this->dueDay(), $daysInMonth);
                $oldestDue = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-%d', $oldest->year, $oldest->month, $dueDay));
            }
            if ($oldestDue < $today) {
                return $oldestDue;
            }
        }
CODE;

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched nextDueDate' . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
