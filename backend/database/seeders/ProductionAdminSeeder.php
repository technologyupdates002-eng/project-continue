<?php

namespace Database\Seeders;

use App\Support\Uuid;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductionAdminSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $admins = [
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

        foreach ($admins as $admin) {
            DB::table('auth_users')->updateOrInsert(['id' => $admin['id']], [
                'id' => $admin['id'],
                'email' => $admin['email'],
                'phone' => $admin['phone'],
                'encrypted_password' => $admin['password'],
                'is_banned' => 0,
                'raw_user_meta_data' => json_encode(['name' => $admin['name']]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($admin['roles'] as $role) {
                DB::table('user_roles')->updateOrInsert(
                    ['user_id' => $admin['id'], 'role' => $role],
                    ['id' => Uuid::v4(), 'user_id' => $admin['id'], 'role' => $role, 'is_active' => 1]
                );
            }

            $member = DB::table('members')->where('user_id', $admin['id'])->first();
            if ($member) {
                DB::table('members')->where('id', $member->id)->update([
                    'name' => $admin['name'],
                    'phone' => $admin['phone'],
                    'member_id' => strtoupper(substr($admin['name'], 0, 2)) . '001',
                    'is_active' => 1,
                    'status' => 'active',
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('members')->insert([
                    'id' => $admin['id'],
                    'user_id' => $admin['id'],
                    'name' => $admin['name'],
                    'phone' => $admin['phone'],
                    'member_id' => strtoupper(substr($admin['name'], 0, 2)) . '001',
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
}
