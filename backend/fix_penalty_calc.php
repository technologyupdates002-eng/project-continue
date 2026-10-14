<?php
$file = 'app/Services/ContributionObligationService.php';
$content = file_get_contents($file);

$search = <<<'CODE'
        $pendingCount = (int) collect($obligations)->where('is_pending', true)->count();
        $overdueCount = (int) collect($obligations)->where('is_overdue', true)->count();
        $penaltyCount = $overdueCount;
        $penaltyAmount = (float) ($overdueCount * $this->penaltyAmount());
CODE;

$replace = <<<'CODE'
        $pendingCount = (int) collect($obligations)->where('is_pending', true)->count();
        $overdueCount = (int) collect($obligations)->where('is_overdue', true)->count();
        $penaltyCount = (int) DB::table('penalties')->where('member_id', $memberId)->where('is_paid', 0)->count();
        $penaltyAmount = (float) DB::table('penalties')->where('member_id', $memberId)->where('is_paid', 0)->sum('amount');
CODE;

$count = substr_count($content, $search);
echo 'Found ' . $count . ' occurrences' . PHP_EOL;
if ($count > 0) {
    $content = str_replace($search, $replace, $content);
    file_put_contents($file, $content);
    echo 'Patched' . PHP_EOL;
} else {
    echo 'Pattern not found' . PHP_EOL;
}
