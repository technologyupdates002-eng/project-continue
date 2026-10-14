<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Support\Uuid;

class BackfillMemberCodes extends Command
{
    protected $signature = 'backfill:member-codes {--batch=1000}';
    protected $description = 'Generate persistent member codes (member_id) for existing members missing one';

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $this->info("Starting backfill of member codes (batch={$batch})");
        $count = 0;

        $query = DB::table('members')->where(function ($q) {
            $q->whereNull('member_id')->orWhere('member_id', '')->orWhere('member_id', 'regexp', '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
        });
        $total = $query->count();
        $this->info("Found {$total} members needing human-readable member codes");

        $processed = 0;
        while (true) {
            $rows = $query->limit($batch)->get();
            if ($rows->isEmpty()) break;

            DB::transaction(function () use ($rows, &$processed, &$count) {
                foreach ($rows as $r) {
                    $fullName = trim(preg_replace('/\s+/', ' ', $r->name ?? ''));
                    $parts = explode(' ', $fullName);
                    $first = $parts[0] ?? '';
                    $last = end($parts) ?: '';

                    $initials = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
                    $initials = preg_replace('/[^A-Z0-9]/', '', $initials);
                    if ($initials === '') $initials = 'M';

                    $base = $initials;
                    $candidate = $base;
                    $i = 1;
                    while (DB::table('members')->where('member_id', $candidate)->exists()) {
                        $i++;
                        $candidate = sprintf('%s-%03d', $base, $i);
                        if ($i > 9999) break;
                    }

                    DB::table('members')->where('id', $r->id)->update(['member_id' => $candidate, 'updated_at' => now()]);
                    $processed++;
                }
            });

            $this->info("Processed {$processed} / {$total}");
            // Recreate query since previous one was consumed
            $query = DB::table('members')->where(function ($q) {
                $q->whereNull('member_id')->orWhere('member_id', '')->orWhere('member_id', 'regexp', '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
            });
        }

        $this->info("Backfill complete. Generated {$processed} member codes.");
        return 0;
    }
}
