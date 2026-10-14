<?php

namespace Database\Seeders;

use App\Support\Uuid;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestDataSeeder extends Seeder
{
    private array $firstNames = [
        'James','Mary','John','Patricia','Robert','Jennifer','Michael','Linda','William','Elizabeth',
        'David','Barbara','Richard','Susan','Joseph','Jessica','Thomas','Sarah','Charles','Karen',
        'Daniel','Nancy','Matthew','Lisa','Anthony','Betty','Mark','Margaret','Donald','Sandra',
        'Steven','Ashley','Paul','Dorothy','Andrew','Kimberly','Joshua','Emily','Kenneth','Donna',
        'Kevin','Michelle','Brian','Carol','George','Amanda','Timothy','Melissa','Ronald','Deborah',
        'Edward','Stephanie','Jason','Rebecca','Jeffrey','Sharon','Ryan','Laura','Jacob','Cynthia',
        'Nicholas','Kathleen','Gary','Amy','Eric','Angela','Jonathan','Shirley','Stephen','Brenda',
        'Larry','Pamela','Justin','Emma','Scott','Nicole','Brandon','Helen','Benjamin','Samantha',
        'Samuel','Katherine','Raymond','Christine','Gregory','Debra','Frank','Rachel','Alexander','Carolyn',
        'Patrick','Janet','Jack','Catherine','Dennis','Maria','Jerry','Heather','Tyler','Diane',
    ];

    private array $lastNames = [
        'Muriuki','Wambui','Kariuki','Njeri','Kamau','Wangari','Ochieng','Achieng','Otieno','Nyambura',
        'Mwangi','Wanjiku','Kimani','Njoki','Macharia','Wairimu','Njoroge','Muthoni','Karanja','Waithera',
        'Gitau','Ndegwa','Kinyanjui','Wambui','Chege','Mwaniki','Ndungu','Wanjiru','Muchiri','Kagwiria',
        'Maina','Mwihaki','Kariuki','Wambui','Gichuhi','Nyokabi','Kibet','Chelangat','Rono','Kurgat',
        'Mutai','Jepkemoi','Kiptoo','Chebet','Kemboi','Jepchirchir','Kosgei','Kiprop','Chelimo','Lagat',
    ];

    private array $kisiiFirstNames = [
        'Mokua','Bosibori','Ongeri','Matoke','Nyanchama','Ongwae','Nyarangi','Mogeni','Bosibori','Ongeri',
        'Nyariki','Mokaya','Bundi','Nyanchama','Ongwae','Matoke','Mokua','Bosibori','Ongeri','Nyarangi',
    ];

    private array $kisiiLastNames = [
        'Obiero','Ongeri','Matoke','Nyanchama','Ongwae','Nyarangi','Mogeni','Bosibori','Mokua','Bundi',
        'Nyariki','Mokaya','Obiero','Ongeri','Matoke','Nyanchama','Ongwae','Nyarangi','Mogeni','Bosibori',
    ];

    private array $luhyaFirstNames = [
        'Wafula','Namukhaywa','Wanjala','Nanyama','Wekesa','Shitaka','Muyonga','Naliaka','Wabwire','Mukhwana',
        'Wasike','Nambwire','Wakoli','Muyundo','Nanyama','Wanjala','Wekesa','Shitaka','Muyonga','Naliaka',
    ];

    private array $luhyaLastNames = [
        'Wanjala','Nanyama','Wekesa','Shitaka','Muyonga','Naliaka','Wabwire','Mukhwana','Wasike','Nambwire',
        'Wakoli','Muyundo','Wafula','Namukhaywa','Wanjala','Nanyama','Wekesa','Shitaka','Muyonga','Naliaka',
    ];

    private array $kambaFirstNames = [
        'Musili','Nduku','Mutua','Kamwea','Muli','Kitonga','Mutiso','Mumbua','Mwania','Mwinzi',
        'Kiilu','Nzuki','Mutua','Kamwea','Muli','Kitonga','Mutiso','Mumbua','Mwania','Mwinzi',
    ];

    private array $kambaLastNames = [
        'Mutua','Kamwea','Muli','Kitonga','Mutiso','Mumbua','Mwania','Mwinzi','Kiilu','Nzuki',
        'Musili','Nduku','Mutua','Kamwea','Muli','Kitonga','Mutiso','Mumbua','Mwania','Mwinzi',
    ];

    private array $meruFirstNames = [
        'Mwirigi','Nkatha','Muciri','Gakii','Mwendwa','Nkirote','Mwirigi','Nkatha','Muciri','Gakii',
        'Mwendwa','Nkirote','Mwirigi','Nkatha','Muciri','Gakii','Mwendwa','Nkirote','Mwirigi','Nkatha',
    ];

    private array $meruLastNames = [
        'Mwirigi','Nkatha','Muciri','Gakii','Mwendwa','Nkirote','Mwirigi','Nkatha','Muciri','Gakii',
        'Mwendwa','Nkirote','Mwirigi','Nkatha','Muciri','Gakii','Mwendwa','Nkirote','Mwirigi','Nkatha',
    ];

    private array $statuses = ['active', 'active', 'active', 'active', 'active', 'suspended', 'deactivated'];

    private array $contributionStatuses = ['pending', 'paid', 'paid', 'paid', 'overdue'];

    public function run(): void
    {
        $faker = Factory::create('en_KE');

        $now = now();
        $batchSize = 100;

        $staffIds = [
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
            '44444444-4444-4444-4444-444444444444',
            '55555555-5555-5555-5555-555555555555',
        ];

        $walletIds = [
            'wwwwwwww-1111-1111-1111-111111111111',
            'xxxxxxxx-2222-2222-2222-222222222222',
            'yyyyyyyy-3333-3333-3333-333333333333',
        ];

        $allMembers = [];
        $allUsers = [];
        $allRoles = [];
        $contributions = [];
        $walletTransactions = [];
        $notifications = [];
        $news = [];
        $events = [];

        $ethnicGroups = [
            ['first' => $this->firstNames, 'last' => $this->lastNames, 'weight' => 40],
            ['first' => $this->kisiiFirstNames, 'last' => $this->kisiiLastNames, 'weight' => 15],
            ['first' => $this->luhyaFirstNames, 'last' => $this->luhyaLastNames, 'weight' => 15],
            ['first' => $this->kambaFirstNames, 'last' => $this->kambaLastNames, 'weight' => 15],
            ['first' => $this->meruFirstNames, 'last' => $this->meruLastNames, 'weight' => 15],
        ];

        $totalWeight = array_sum(array_column($ethnicGroups, 'weight'));

        for ($i = 1; $i <= 1000; $i++) {
            $rand = mt_rand(1, $totalWeight);
            $cumulative = 0;
            $group = $ethnicGroups[0];
            foreach ($ethnicGroups as $g) {
                $cumulative += $g['weight'];
                if ($rand <= $cumulative) {
                    $group = $g;
                    break;
                }
            }

            $firstName = $group['first'][array_rand($group['first'])];
            $lastName = $group['last'][array_rand($group['last'])];
            $name = $firstName . ' ' . $lastName;

            $phone = '+2547' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT);
            $email = str_replace('+', '', $phone) . '@welfare.local';
            $memberId = 'MB' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);

            $userId = Uuid::v4();
            $memberDbId = Uuid::v4();

            $allUsers[] = [
                'id' => $userId,
                'email' => $email,
                'phone' => $phone,
                'encrypted_password' => password_hash('Member2026', PASSWORD_BCRYPT),
                'is_banned' => 0,
                'raw_user_meta_data' => json_encode(['name' => $name]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $allRoles[] = [
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'role' => 'member',
                'is_active' => 1,
            ];

            $status = $this->statuses[array_rand($this->statuses)];

            $allMembers[] = [
                'id' => $memberDbId,
                'user_id' => $userId,
                'name' => $name,
                'phone' => $phone,
                'member_id' => $memberId,
                'is_active' => $status === 'active' ? 1 : 0,
                'status' => $status,
                'total_contributions' => 0,
                'total_penalties' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $monthsToSeed = mt_rand(1, 12);
            $currentMonth = (int) $now->format('m');
            $currentYear = (int) $now->format('Y');

            for ($m = 0; $m < $monthsToSeed; $m++) {
                $month = $currentMonth - $m;
                $year = $currentYear;
                while ($month <= 0) {
                    $month += 12;
                    $year--;
                }

                $contribStatus = $this->contributionStatuses[array_rand($this->contributionStatuses)];
                $paidDate = null;
                if ($contribStatus === 'paid') {
                    $paidDate = $now->copy()->subDays(mt_rand(1, 28))->format('Y-m-d');
                }

                $contributions[] = [
                    'id' => Uuid::v4(),
                    'member_id' => $memberDbId,
                    'amount' => 300,
                    'month' => $month,
                    'year' => $year,
                    'due_date' => $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-05',
                    'paid_date' => $paidDate,
                    'status' => $contribStatus,
                    'payment_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($i <= 50) {
                $notifications[] = [
                    'id' => Uuid::v4(),
                    'user_id' => $userId,
                    'title' => 'Welcome to KHWWC',
                    'message' => 'Thank you for joining Kirinyaga Health Care Workers Welfare Group.',
                    'is_read' => 0,
                    'type' => 'info',
                    'created_at' => $now,
                ];
            }
        }

        $eventTypes = ['funeral', 'meeting', 'fundraiser', 'health_camp'];
        for ($i = 0; $i < 20; $i++) {
            $events[] = [
                'id' => Uuid::v4(),
                'title' => $faker->randomElement(['Monthly Meeting', 'Health Camp', 'Fundraiser', 'Membership Drive', 'AGM']),
                'description' => $faker->sentence(10),
                'event_type' => $eventTypes[array_rand($eventTypes)],
                'departed_name' => $eventTypes[array_rand($eventTypes)] === 'funeral' ? $faker->name : null,
                'relationship' => 'member',
                'related_member_id' => count($allMembers) > 0 ? $allMembers[array_rand($allMembers)]['id'] : null,
                'contribution_amount' => $eventTypes[array_rand($eventTypes)] === 'fundraiser' ? 1000 : 300,
                'status' => 'active',
                'created_by' => $staffIds[array_rand($staffIds)],
                'created_at' => $now->copy()->subDays(mt_rand(1, 30)),
                'updated_at' => $now->copy()->subDays(mt_rand(0, 10)),
                'scheduled_date' => $now->copy()->addDays(mt_rand(7, 60)),
                'rescheduled_date' => null,
                'reschedule_reason' => null,
            ];
        }

        for ($i = 0; $i < 10; $i++) {
            $news[] = [
                'id' => Uuid::v4(),
                'title' => $faker->randomElement(['Welcome New Members', 'Upcoming AGM', 'Health Camp Success', 'New Welfare Policy', 'Fundraiser Results']),
                'content' => $faker->paragraph(3),
                'author_id' => $staffIds[array_rand($staffIds)],
                'status' => 'published',
                'created_at' => $now->copy()->subDays(mt_rand(1, 30)),
                'updated_at' => $now->copy()->subDays(mt_rand(0, 10)),
            ];
        }

        DB::transaction(function () use ($allUsers, $allRoles, $allMembers, $contributions, $walletTransactions, $notifications, $events, $news, $walletIds, $now, $batchSize) {
            foreach (array_chunk($allUsers, $batchSize) as $chunk) {
                DB::table('auth_users')->insert($chunk);
            }

            foreach (array_chunk($allRoles, $batchSize) as $chunk) {
                DB::table('user_roles')->insert($chunk);
            }

            foreach (array_chunk($allMembers, $batchSize) as $chunk) {
                DB::table('members')->insert($chunk);
            }

            foreach (array_chunk($contributions, $batchSize) as $chunk) {
                DB::table('contributions')->insert($chunk);
            }

            foreach (array_chunk($notifications, $batchSize) as $chunk) {
                DB::table('notifications')->insert($chunk);
            }

            foreach (array_chunk($events, $batchSize) as $chunk) {
                DB::table('events')->insert($chunk);
            }

            foreach (array_chunk($news, $batchSize) as $chunk) {
                DB::table('news')->insert($chunk);
            }

            if (!DB::table('donation_wallet')->where('id', $walletIds[0])->exists()) {
                DB::table('donation_wallet')->insert([
                    'id' => $walletIds[0],
                    'total_balance' => 150000,
                    'total_received' => 200000,
                    'total_withdrawn' => 50000,
                    'updated_at' => $now,
                ]);
            }
            if (!DB::table('penalty_wallet')->where('id', $walletIds[1])->exists()) {
                DB::table('penalty_wallet')->insert([
                    'id' => $walletIds[1],
                    'total_balance' => 75000,
                    'total_received' => 100000,
                    'total_withdrawn' => 25000,
                    'last_updated' => $now,
                ]);
            }
            if (!DB::table('operational_wallet')->where('id', $walletIds[2])->exists()) {
                DB::table('operational_wallet')->insert([
                    'id' => $walletIds[2],
                    'total_balance' => 50000,
                    'total_received' => 80000,
                    'total_withdrawn' => 30000,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
