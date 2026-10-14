<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$badNames = DB::table('members')
    ->where(function ($q) {
        $q->whereRaw('LENGTH(name) <= 5')
            ->orWhereRaw('LENGTH(name) <= 4')
            ->orWhereIn('name', ['Welfare', 'Transfer to Co-op Bank'])
            ->orWhere('name', 'LIKE', '%WELFARE%')
            ->orWhere('name', 'LIKE', '%SourceKHWWC%')
            ->orWhere('name', 'LIKE', '%MPESA%')
            ->orWhere('name', 'LIKE', '%Transfer%');
    })
    ->get(['id', 'name', 'phone']);

echo "Found " . count($badNames) . " members with potentially bad names\n";

$updated = 0;
$skipped = 0;

foreach ($badNames as $member) {
    $transactions = DB::table('bank_transactions')
        ->where('member_id', $member->id)
        ->whereNotNull('narration')
        ->orderBy('transaction_date', 'asc')
        ->get(['narration', 'transaction_date']);

    $newName = null;

    foreach ($transactions as $tx) {
        $narration = $tx->narration;

        if (str_contains($narration, '~')) {
            $parts = explode('~', $narration);
            $lastPart = trim(end($parts));
            if ($lastPart && preg_match('/[A-Za-z]{2,}/', $lastPart)) {
                $newName = validateName($lastPart);
                if ($newName) {
                    break;
                }
            }
        }

        if (preg_match('/Confirm\.\s*([A-Za-z\s]+?)(?:\s*\d{10,}|\s*$)/', $narration, $matches)) {
            $candidate = validateName(trim($matches[1]));
            if ($candidate) {
                $newName = $candidate;
                break;
            }
        }
    }

    if ($newName && $newName !== $member->name) {
        DB::table('members')
            ->where('id', $member->id)
            ->update(['name' => $newName, 'updated_at' => now()]);

        DB::table('bank_transactions')
            ->where('member_id', $member->id)
            ->update(['name' => $newName]);

        echo "✓ Updated: {$member->phone} {$member->name} => {$newName}\n";
        $updated++;
    } else {
        echo "✗ Skipped: {$member->phone} {$member->name} (no valid name found)\n";
        $skipped++;
    }
}

echo "\nResults: {$updated} updated, {$skipped} skipped\n";

function validateName(string $name): ?string
{
    $name = trim($name);

    if (strlen($name) < 3) {
        return null;
    }

    if (str_contains($name, '_')) {
        return null;
    }

    $lower = strtolower($name);
    $badPatterns = ['welfare', 'transfer to', 'sourcekhwwc', 'mpesac2b', 'mpesa', 'fund drive', 'fundrive', 'paybill', 'b2c', 'b2b', 'ft'];
    foreach ($badPatterns as $pattern) {
        if (str_contains($lower, $pattern)) {
            return null;
        }
    }

    if (ctype_upper($name) && ! str_contains($name, ' ') && strlen($name) <= 5) {
        return null;
    }

    if (str_contains($name, ' ')) {
        return $name;
    }

    if (preg_match('/^[A-Z]{3,4}$/', $name)) {
        return null;
    }

    return strlen($name) >= 4 ? $name : null;
}
