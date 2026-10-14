<?php

namespace Database\Seeders;

use App\Support\Uuid;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PerformanceLoadTestSeeder extends Seeder
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

    private array $statuses = ['active', 'active', 'active', 'active', 'active', 'suspended', 'deactivated'];

    private array $contributionStatuses = ['pending', 'paid', 'paid', 'paid', 'overdue'];

    public function run(): void
    {
        $count = (int) env('PERF_SEED_COUNT', 1000);
        if ($count <= 0) $count = 1000;

        $this->command->info("Seeding {$count} performance test members...");

        $faker = Factory::create('en_KE');
        $now = now();
        $batchSize = 500;

        DB::table('auth_users')->where('email', 'like', '%@perf.local')->delete();
        DB::table('members')->where('member_id', 'like', 'PERF%')->delete();
        DB::table('contributions')->where('member_id', 'not like', 'PERF%')->delete();
        DB::table('notifications')->where('message', 'Synthetic performance test data.')->delete();
        DB::table('events')->where('title', 'like', 'Perf Event %')->delete();
        DB::table('news')->where('title', 'like', 'Perf News %')->delete();

        $staffIds = [
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
            '33333333-3333-3333-3333-333333333333',
        ];

        $allUsers = [];
        $allRoles = [];
        $allMembers = [];
        $contributions = [];
        $notifications = [];
        $events = [];
        $news = [];

        for ($i = 1; $i <= $count; $i++) {
            $firstName = $this->firstNames[array_rand($this->firstNames)];
            $lastName = $this->lastNames[array_rand($this->lastNames)];
            $name = $firstName . ' ' . $lastName;

            $phone = '+2547' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT);
            $email = str_replace('+', '', $phone) . '@perf.local';
            $memberId = 'PERF' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            $userId = Uuid::v4();
            $memberDbId = Uuid::v4();

            $allUsers[] = [
                'id' => $userId,
                'email' => $email,
                'phone' => $phone,
                'encrypted_password' => password_hash('Perf2026', PASSWORD_BCRYPT),
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

            $monthsToSeed = mt_rand(1, 24);
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

            if ($i <= 200) {
                $notifications[] = [
                    'id' => Uuid::v4(),
                    'user_id' => $userId,
                    'title' => 'Performance Test Notification',
                    'message' => 'Synthetic performance test data.',
                    'is_read' => 0,
                    'type' => 'info',
                    'created_at' => $now,
                ];
            }
        }

        for ($i = 0; $i < 100; $i++) {
            $events[] = [
                'id' => Uuid::v4(),
                'title' => 'Perf Event ' . ($i + 1),
                'description' => 'Synthetic performance test event.',
                'event_type' => 'meeting',
                'departed_name' => null,
                'relationship' => 'member',
                'related_member_id' => $allMembers[array_rand($allMembers)]['id'] ?? null,
                'contribution_amount' => 300,
                'status' => 'active',
                'created_by' => $staffIds[array_rand($staffIds)],
                'created_at' => $now->copy()->subDays(mt_rand(1, 30)),
                'updated_at' => $now->copy()->subDays(mt_rand(0, 10)),
                'scheduled_date' => $now->copy()->addDays(mt_rand(7, 60)),
                'rescheduled_date' => null,
                'reschedule_reason' => null,
            ];
        }

        for ($i = 0; $i < 50; $i++) {
            $news[] = [
                'id' => Uuid::v4(),
                'title' => 'Perf News ' . ($i + 1),
                'content' => 'Synthetic performance test news content.',
                'author_id' => $staffIds[array_rand($staffIds)],
                'status' => 'published',
                'created_at' => $now->copy()->subDays(mt_rand(1, 30)),
                'updated_at' => $now->copy()->subDays(mt_rand(0, 10)),
            ];
        }

        DB::transaction(function () use ($allUsers, $allRoles, $allMembers, $contributions, $notifications, $events, $news, $batchSize) {
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
        });

        $this->command->info("Seeded {$count} members with contributions, notifications, events, and news.");
    }
}
