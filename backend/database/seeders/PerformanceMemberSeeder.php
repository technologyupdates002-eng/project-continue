<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PerformanceMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $count = (int)($this->command->option('count') ?? 1000);
        $this->command->info("Creating {$count} synthetic test members (LOADTEST)");

        $batchSize = 500;
        $created = 0;
        while ($created < $count) {
            $toCreate = min($batchSize, $count - $created);
            $rows = [];
            for ($i = 0; $i < $toCreate; $i++) {
                $n = $created + $i + 1;
                $memberId = sprintf('LT-%05d', $n);
                $phone = '07' . str_pad((string)($n % 10000000), 7, '0', STR_PAD_LEFT);
                $email = "loadtest+{$n}@welfare.local";
                $rows[] = [
                    'name' => "[LOADTEST] Member {$memberId}",
                    'phone' => $phone,
                    'member_id' => $memberId,
                    'email' => $email,
                    'is_active' => 1,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('members')->insert($rows);
            $created += $toCreate;
            $this->command->info("Inserted {$created}/{$count}");
        }
        $this->command->info('Performance seeding complete. Test records include prefix "[LOADTEST]" and emails loadtest+{n}@welfare.local');
    }
}
