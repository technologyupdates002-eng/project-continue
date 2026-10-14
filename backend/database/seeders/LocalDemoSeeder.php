<?php

namespace Database\Seeders;

use App\Support\Uuid;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $count = max(1, (int) env('LOCAL_DEMO_MEMBER_COUNT', 1000));
        $password = (string) env('LOCAL_DEMO_PASSWORD', 'LocalDemo2026');
        $now = now();

        $this->seedStaff($now, $password);

        for ($offset = 0; $offset < $count; $offset += 250) {
            $rows = [];
            $limit = min(250, $count - $offset);

            for ($index = 1; $index <= $limit; $index++) {
                $number = $offset + $index;
                $memberCode = sprintf('LOCAL-DEMO-%04d', $number);
                $email = sprintf('member%04d@local.khwwc.test', $number);
                $phone = sprintf('+254711%07d', $number);
                $userId = $this->userIdForEmail($email);

                DB::table('auth_users')->updateOrInsert(
                    ['email' => $email],
                    [
                        'id' => $userId,
                        'phone' => $phone,
                        'encrypted_password' => password_hash($password, PASSWORD_BCRYPT),
                        'is_banned' => 0,
                        'must_change_password' => 0,
                        'raw_user_meta_data' => json_encode(['name' => "Local Demo Member {$number}"]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );

                $rows[] = [
                    'id' => $userId,
                    'user_id' => $userId,
                    'name' => "Local Demo Member {$number}",
                    'phone' => $phone,
                    'member_id' => $memberCode,
                    'total_contributions' => 0,
                    'total_penalties' => 0,
                    'is_active' => 1,
                    'source' => 'local_demo',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($rows as $row) {
                DB::table('members')->updateOrInsert(
                    ['member_id' => $row['member_id']],
                    $row
                );
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $row['user_id'], 'role' => 'member'],
                    ['id' => Uuid::v4(), 'is_active' => 1]
                );
            }

            $seeded = $offset + $limit;
            $this->command?->info("Seeded {$seeded}/{$count} local demo members");
        }

        $this->command?->table(
            ['role', 'email', 'password'],
            [
                ['admin', 'admin@local.khwwc.test', $password],
                ['super_admin', 'superadmin@local.khwwc.test', $password],
                ['chairperson', 'chairperson@local.khwwc.test', $password],
                ['secretary', 'secretary@local.khwwc.test', $password],
                ['treasurer', 'treasurer@local.khwwc.test', $password],
            ]
        );
    }

    private function seedStaff(\Illuminate\Support\Carbon $now, string $password): void
    {
        $staff = [
            ['role' => 'admin', 'name' => 'Local Administrator', 'email' => 'admin@local.khwwc.test', 'phone' => '+254720000004'],
            ['role' => 'super_admin', 'name' => 'Local Super Administrator', 'email' => 'superadmin@local.khwwc.test', 'phone' => '+254720000005'],
            ['role' => 'chairperson', 'name' => 'Local Chairperson', 'email' => 'chairperson@local.khwwc.test', 'phone' => '+254720000001'],
            ['role' => 'secretary', 'name' => 'Local Secretary', 'email' => 'secretary@local.khwwc.test', 'phone' => '+254720000002'],
            ['role' => 'treasurer', 'name' => 'Local Treasurer', 'email' => 'treasurer@local.khwwc.test', 'phone' => '+254720000003'],
        ];

        foreach ($staff as $person) {
            $userId = $this->userIdForEmail($person['email']);
            DB::table('auth_users')->updateOrInsert(
                ['email' => $person['email']],
                [
                    'id' => $userId,
                    'phone' => $person['phone'],
                    'encrypted_password' => password_hash($password, PASSWORD_BCRYPT),
                    'is_banned' => 0,
                    'must_change_password' => 0,
                    'raw_user_meta_data' => json_encode(['name' => $person['name']]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('members')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'id' => $userId,
                    'user_id' => $userId,
                    'name' => $person['name'],
                    'phone' => $person['phone'],
                    'member_id' => 'LOCAL-'.strtoupper(substr($person['role'], 0, 3)),
                    'total_contributions' => 0,
                    'total_penalties' => 0,
                    'is_active' => 1,
                    'source' => 'local_demo',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $userId, 'role' => $person['role']],
                ['id' => Uuid::v4(), 'is_active' => 1]
            );
            if ($person['role'] === 'super_admin') {
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $userId, 'role' => 'admin'],
                    ['id' => Uuid::v4(), 'is_active' => 1]
                );
            }
            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $userId, 'role' => 'member'],
                ['id' => Uuid::v4(), 'is_active' => 1]
            );
        }
    }

    private function userIdForEmail(string $email): string
    {
        $existing = DB::table('auth_users')->where('email', $email)->value('id');

        return $existing ?: (string) Str::uuid();
    }
}
