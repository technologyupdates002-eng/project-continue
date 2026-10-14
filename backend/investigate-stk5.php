<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$memberId = '11111111-1111-1111-1111-111111111111';

echo "=== CHECK MEMBER STATS ===\n";
$stats = DB::table('member_stats')->where('member_id', $memberId)->first();
if ($stats) {
    echo "Member stats found:\n";
    echo "  Total contributions: " . ($stats->total_contributions ?? 0) . "\n";
    echo "  Pending months: " . ($stats->pending_months ?? 0) . "\n";
    echo "  Overdue months: " . ($stats->overdue_months ?? 0) . "\n";
    echo "  Contribution outstanding: " . ($stats->contribution_outstanding ?? 0) . "\n";
    echo "  Penalty outstanding: " . ($stats->penalty_outstanding ?? 0) . "\n";
    echo "  Updated: " . $stats->updated_at . "\n";
} else {
    echo "No member stats found\n";
}

echo "\n=== CHECK RECENT TRANSFERS FOR THIS MEMBER ===\n";
$transfers = DB::table('coop_transfer_requests')
    ->where('internal_reference', 'like', '%' . $memberId . '%')
    ->orderByDesc('created_at')
    ->limit(5)
    ->get();
foreach ($transfers as $t) {
    echo "  Transfer: " . $t->message_reference . " | " . $t->status . " | " . $t->amount . " | " . $t->created_at . "\n";
}

echo "\n=== CHECK RECENT CONTRIBUTIONS ===\n";
$contributions = DB::table('contributions')->where('member_id', $memberId)->orderByDesc('created_at')->limit(5)->get();
foreach ($contributions as $c) {
    echo "  Contribution: " . $c->id . " | " . $c->status . " | " . $c->amount . " | " . $c->stk_checkout_request_id . " | " . $c->created_at . "\n";
}

echo "\n=== CHECK WALLET TRANSACTIONS ===\n";
$wallets = DB::table('wallet_transactions')->where('member_id', $memberId)->orderByDesc('created_at')->limit(5)->get();
foreach ($wallets as $w) {
    echo "  Wallet: " . $w->id . " | " . $w->type . " | " . $w->amount . " | " . $w->direction . " | " . $w->created_at . "\n";
}

echo "\n=== CHECK IF MEMBER STATS NEED REFRESH ===\n";
echo "Member stats updated: " . ($stats->updated_at ?? 'N/A') . "\n";
echo "Last contribution updated: " . ($contributions[0]->updated_at ?? 'N/A') . "\n";