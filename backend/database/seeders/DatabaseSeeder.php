<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TestDataSeeder::class,
        ]);

        $now = now();

        $staff = [
            [
                'id' => '11111111-1111-1111-1111-111111111111',
                'email' => 'admin@khwwc.local',
                'phone' => '+254700000001',
                'password' => password_hash('Admin2026', PASSWORD_BCRYPT),
                'name' => 'Admin User',
                'roles' => ['super_admin', 'admin', 'treasurer', 'secretary', 'member'],
            ],
            [
                'id' => '33333333-3333-3333-3333-333333333333',
                'email' => 'superadmin@khwwc.local',
                'phone' => '+254700000002',
                'password' => password_hash('Super2026', PASSWORD_BCRYPT),
                'name' => 'Super Admin',
                'roles' => ['super_admin', 'admin', 'treasurer', 'secretary', 'member'],
            ],
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'email' => 'treasurer@khwwc.local',
                'phone' => '+254700000003',
                'password' => password_hash('Treasurer2026', PASSWORD_BCRYPT),
                'name' => 'Treasurer User',
                'roles' => ['treasurer', 'member'],
            ],
            [
                'id' => '44444444-4444-4444-4444-444444444444',
                'email' => 'secretary@khwwc.local',
                'phone' => '+254700000004',
                'password' => password_hash('Secretary2026', PASSWORD_BCRYPT),
                'name' => 'Secretary User',
                'roles' => ['secretary', 'member'],
            ],
        ];

        foreach ($staff as $s) {
            DB::table('auth_users')->updateOrInsert(['id' => $s['id']], [
                'id' => $s['id'],
                'email' => $s['email'],
                'phone' => $s['phone'],
                'encrypted_password' => $s['password'],
                'is_banned' => 0,
                'raw_user_meta_data' => json_encode(['name' => $s['name']]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($s['roles'] as $role) {
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $s['id'], 'role' => $role],
                    ['id' => \App\Support\Uuid::v4(), 'user_id' => $s['id'], 'role' => $role, 'is_active' => 1]
                );
            }

            DB::table('members')->updateOrInsert(['user_id' => $s['id']], [
                'id' => $s['id'],
                'user_id' => $s['id'],
                'name' => $s['name'],
                'phone' => $s['phone'],
                'member_id' => strtoupper(substr($s['name'], 0, 2)) . '001',
                'is_active' => 1,
                'status' => 'active',
                'total_contributions' => 0,
                'total_penalties' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
