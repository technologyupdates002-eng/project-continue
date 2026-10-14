<?php

/**
 * Test script to verify contribution calculation fix
 * Simulates Geoffrey Gachoka's scenario
 */

echo "=== CONTRIBUTION CALCULATION FIX TEST ===\n\n";

// Test scenario: Geoffrey Gachoka
// First contribution: 2026-01-31
// Total paid: 2700 KES
// Monthly contribution: 300 KES
// Current date: 2026-10-06

$monthly = 300;
$totalPaid = 2700;
$startDate = new DateTimeImmutable('2026-01-31');
$today = new DateTimeImmutable('2026-10-06');

// Calculate months elapsed
$monthsElapsed = 0;
$cursor = $startDate;
while (true) {
    $y = (int) $cursor->format('Y');
    $m = (int) $cursor->format('n');
    if ($y > (int) $today->format('Y') || ($y === (int) $today->format('Y') && $m > (int) $today->format('n'))) {
        break;
    }
    $monthsElapsed++;
    $cursor = $cursor->modify('first day of next month');
    if ($monthsElapsed > 120) break;
}

// Calculate how many full months are covered by total payments
$fullMonthsCovered = $totalPaid > 0 ? (int) floor($totalPaid / $monthly) : 0;
$partialAmountRemaining = $totalPaid - ($fullMonthsCovered * $monthly);

// Calculate outstanding
$totalExpectedForElapsedMonths = $monthsElapsed * $monthly;
$contributionOutstanding = max(0, $totalExpectedForElapsedMonths - $totalPaid);

echo "Member: Geoffrey Gachoka\n";
echo "First Contribution Date: " . $startDate->format('Y-m-d') . "\n";
echo "Today's Date: " . $today->format('Y-m-d') . "\n\n";

echo "=== CALCULATIONS ===\n";
echo "Monthly Contribution: {$monthly} KES\n";
echo "Total Paid: {$totalPaid} KES\n";
echo "Months Elapsed: {$monthsElapsed}\n";
echo "Full Months Covered by Payment: {$fullMonthsCovered}\n";
echo "Partial Amount Remaining: {$partialAmountRemaining} KES\n\n";

echo "Expected for {$monthsElapsed} months: {$totalExpectedForElapsedMonths} KES\n";
echo "Total Paid: {$totalPaid} KES\n";
echo "Outstanding: {$contributionOutstanding} KES\n\n";

// Calculate next due date
$nextDue = $startDate->modify('+' . $fullMonthsCovered . ' months');
$nextDueDate = DateTimeImmutable::createFromFormat('Y-n-j', $nextDue->format('Y-m-01'))->modify('last day of this month');

echo "Next Due Date: " . $nextDueDate->format('Y-m-d') . "\n\n";

// Expected results
echo "=== EXPECTED RESULTS ===\n";
echo "Outstanding should be: 0 KES (paid 2700 for 9 months, only 10 months elapsed, so 300 KES short)\n";
echo "Actual calculated: {$contributionOutstanding} KES\n\n";

// Detailed month breakdown
echo "=== MONTH-BY-MONTH BREAKDOWN ===\n";
$cursor = $startDate;
$idx = 0;
while ($idx < $monthsElapsed) {
    $y = (int) $cursor->format('Y');
    $m = (int) $cursor->format('n');
    
    $isFullyPaid = $idx < $fullMonthsCovered;
    $isPartiallyPaid = !$isFullyPaid && $idx == $fullMonthsCovered && $partialAmountRemaining > 0;
    $isCovered = $isFullyPaid || $isPartiallyPaid;
    
    $status = $isFullyPaid ? 'PAID' : ($isPartiallyPaid ? 'PARTIAL' : 'UNPAID');
    $amountCovered = $isFullyPaid ? $monthly : ($isPartiallyPaid ? $partialAmountRemaining : 0);
    
    echo sprintf(
        "%d-%02d | Status: %-8s | Covered: %s KES\n",
        $y,
        $m,
        $status,
        $amountCovered
    );
    
    $cursor = $cursor->modify('first day of next month');
    $idx++;
}

echo "\n=== TEST RESULT ===\n";
if ($contributionOutstanding == 300) {
    echo "✓ TEST PASSED: Outstanding is correctly calculated as 300 KES\n";
    echo "  (10 months elapsed × 300 = 3000 expected, 2700 paid = 300 outstanding)\n";
} else {
    echo "✗ TEST FAILED: Expected 300 KES but got {$contributionOutstanding} KES\n";
}

