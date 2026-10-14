<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Database\Seeders\WebsiteInitialContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteInitialContentSeederTest extends TestCase
{
    use RefreshDatabase;

    private function identity(): array
    {
        $userId = Uuid::v4();
        $email = "{$userId}@welfare.local";
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $email,
            'phone' => '+2547'.random_int(10000000, 99999999),
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => 'admin',
            'is_active' => 1,
        ]);

        return [
            'token' => JwtService::encode(JwtService::buildPayload($userId, $email, ['admin'])),
        ];
    }

    public function test_initial_public_content_seeder_is_complete_and_does_not_overwrite_edits(): void
    {
        $directory = 'private/website-media/agm-team-building-2025';
        Storage::disk('local')->makeDirectory($directory);
        ob_start();
        $image = imagecreatetruecolor(1, 1);
        imagejpeg($image);
        imagedestroy($image);
        $jpeg = ob_get_clean();
        $createdFiles = [];

        foreach (range(1, 128) as $number) {
            $path = $directory.'/'.sprintf('agm-team-building-2025-%02d.jpg', $number);
            if (! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, $jpeg);
                $createdFiles[] = $path;
            }
        }

        try {
            $this->seed(WebsiteInitialContentSeeder::class);

            $this->assertSame(6, DB::table('website_content_items')->where('content_type', 'page')->count());
            $this->assertSame(128, DB::table('website_content_items')->where('content_type', 'gallery')->count());
            $this->assertSame(1, DB::table('website_content_items')->where('content_type', 'article')->count());
            $this->assertSame(1, DB::table('website_content_items')->where('content_type', 'event')->count());
            $this->assertSame(1, DB::table('website_content_items')->where('content_type', 'welfare')->count());
            $this->assertSame(128, DB::table('storage_objects')->where('bucket', 'website-media')->count());
            $this->assertSame(137, DB::table('website_content_items')->count());
            $this->assertSame(1, DB::table('website_content_items')->where('content_type', 'page')->where('slug', 'membership')->where('status', 'published')->count());
            $about = DB::table('website_content_items')->where('content_type', 'page')->where('slug', 'about')->first();
            $this->assertStringContainsString('Who we are:', $about->content);
            $this->assertStringContainsString('How the welfare is organized:', $about->content);
            $this->assertStringContainsString('Meetings and accountability:', $about->content);
            $this->getJson('/api/public/website/content/page')
                ->assertOk()
                ->assertJsonCount(6, 'data')
                ->assertJsonFragment(['slug' => 'home']);
            $this->assertDatabaseHas('website_content_items', [
                'content_type' => 'article',
                'slug' => 'agm-team-building-gallery-2025',
                'image_url' => url('/api/storage/v1/object/website-media/agm-team-building-2025/agm-team-building-2025-16.jpg'),
                'image_alt' => 'A speaker addresses the KHCWW AGM and team-building gathering, 11 December 2025',
            ]);
            $this->getJson('/api/public/website/content/gallery')
                ->assertOk()
                ->assertJsonCount(128, 'data')
                ->assertJsonPath('data.0.image_url', url('/api/storage/v1/object/website-media/agm-team-building-2025/agm-team-building-2025-01.jpg'));
            $admin = $this->identity(['admin']);
            $this->withHeader('Authorization', 'Bearer '.$admin['token'])
                ->getJson('/api/admin/website/content?content_type=gallery&page=1')
                ->assertOk()
                ->assertJsonCount(128, 'data')
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.last_page', 1)
                ->assertJsonPath('meta.total', 128);
            $this->get('/api/storage/v1/object/website-media/agm-team-building-2025/agm-team-building-2025-01.jpg')
                ->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg');

            DB::table('website_content_items')->where('content_type', 'page')->where('slug', 'home')->update([
                'title' => 'Admin-edited homepage',
                'status' => 'draft',
            ]);

            $this->seed(WebsiteInitialContentSeeder::class);

            $home = DB::table('website_content_items')->where('content_type', 'page')->where('slug', 'home')->first();
            $this->assertSame('Admin-edited homepage', $home->title);
            $this->assertSame('draft', $home->status);
            $this->assertSame(137, DB::table('website_content_items')->count());
            $this->assertSame(128, DB::table('storage_objects')->where('bucket', 'website-media')->count());
        } finally {
            Storage::disk('local')->delete($createdFiles);
        }
    }
}
