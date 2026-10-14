<?php

namespace Database\Seeders;

use App\Support\Uuid;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WebsiteInitialContentSeeder extends Seeder
{
    private const GALLERY_IMAGE_COUNT = 128;

    private const MEDIA_DIRECTORY = 'agm-team-building-2025';

    public function run(): void
    {
        $now = now();
        $images = $this->validateGalleryImages();

        $content = [
            [
                'content_type' => 'page',
                'slug' => 'home',
                'title' => 'Kirinyaga Health Care Workers Welfare',
                'category' => 'Member-led welfare',
                'excerpt' => 'A voluntary welfare for healthcare workers in Kirinyaga County, created to build connection and mutual support.',
                'content' => "KHCWW was initiated to strengthen cohesion, relationships, interaction and mutual support among healthcare workers in Kirinyaga County.\n\nMembership is voluntary, and members agree welfare activities through meetings.",
                'is_featured' => true,
                'sort_order' => 0,
            ],
            [
                'content_type' => 'page',
                'slug' => 'about',
                'title' => 'About KHCWW',
                'category' => 'About',
                'excerpt' => 'A welfare that brings healthcare workers in Kirinyaga County together.',
                'content' => "Who we are:\nKirinyaga Health Care Workers Welfare (KHCWW) is a voluntary welfare for healthcare workers in Kirinyaga County who are willing to join. It was initiated to strengthen cohesion, relationships, interaction and mutual support among health workers.\n\nOur purpose:\nThe constitution sets out a shared purpose: bring healthcare workers together and cater for their welfare. Its objectives include uniting members, helping members in times of need, encouraging participation in times of joy and sorrow, and supporting social, economic and professional development.\n\nHow the welfare is organized:\nMembers agree on welfare activities through meetings and the decision-making processes in the constitution. The constitution defines the offices of Chairperson, Vice Chairperson, Secretary, Vice Secretary, Treasurer and Patron. Current public role-holders are listed on the Leadership page.\n\nMembership and participation:\nMembership is open and voluntary for healthcare workers in Kirinyaga County. Members are expected to participate in activities organized by the welfare. Use Become a member to start registration; the constitution and current member guidance set the applicable terms.\n\nMeetings and accountability:\nThe constitution provides for an annual general meeting and other meetings as needs arise. It also sets out elections every three years and member decision-making for welfare activities. These processes help members review the welfare's work and agree on its direction.",
                'is_featured' => false,
                'sort_order' => 0,
            ],
            [
                'content_type' => 'page',
                'slug' => 'work',
                'title' => 'Our work',
                'category' => 'Our work',
                'excerpt' => 'Welfare activities are agreed by members through the processes set out in the constitution.',
                'content' => "KHCWW's constitution describes activities agreed by members at meetings, including bringing healthcare workers together and supporting members in times of need, joy and sorrow.\n\nThe welfare's objectives also include encouraging social, economic and professional development. Specific activities are determined by members through the welfare's agreed decision-making process.",
                'is_featured' => false,
                'sort_order' => 0,
            ],
            [
                'content_type' => 'page',
                'slug' => 'welfare',
                'title' => 'Welfare programmes',
                'category' => 'Member welfare',
                'excerpt' => 'KHCWW is founded on mutual support and participation among its members.',
                'content' => 'The welfare constitution identifies helping members in times of need and encouraging participation in times of joy and sorrow as core objectives. Specific activities and assistance are governed by the constitution and decisions of members.',
                'is_featured' => false,
                'sort_order' => 0,
            ],
            [
                'content_type' => 'page',
                'slug' => 'contact',
                'title' => 'Contact KHCWW',
                'category' => 'Contact',
                'excerpt' => 'Send an enquiry through the contact form to reach the welfare.',
                'content' => 'Use the contact form on this page to send a general, membership, partnership or welfare-support enquiry to the KHCWW team.',
                'is_featured' => false,
                'sort_order' => 0,
            ],
            [
                'content_type' => 'page',
                'slug' => 'membership',
                'title' => 'Become a member',
                'category' => 'Membership',
                'excerpt' => 'Membership is open and voluntary for healthcare workers in Kirinyaga County.',
                'content' => "KHCWW's constitution states that membership is open to healthcare workers in Kirinyaga County and is voluntary.\n\nProspective members can use the Become a member link to start registration. Membership terms and welfare eligibility are governed by the current constitution.",
                'is_featured' => false,
                'sort_order' => 0,
            ],
            [
                'content_type' => 'welfare',
                'slug' => 'member-mutual-support',
                'title' => 'Member mutual support',
                'category' => 'Welfare objective',
                'excerpt' => 'Supporting members in times of need and encouraging participation in times of joy and sorrow.',
                'content' => 'These are stated objectives in the KHCWW constitution. Specific activities and assistance are governed by the constitution and decisions of members.',
                'is_featured' => true,
                'sort_order' => 0,
            ],
            [
                'content_type' => 'article',
                'slug' => 'agm-team-building-gallery-2025',
                'title' => 'KHCWW AGM and team-building, 11 December 2025',
                'category' => 'Community',
                'excerpt' => 'A photographic archive from KHCWW’s AGM and team-building gallery dated 11 December 2025.',
                'content' => "The source gallery identifies this collection as the KHCWW AGM and team-building gallery and dates it 11 December 2025. The photographs are shared on the KHCWW website with permission and credited to ianphotography.\n\nThis archive records the gathering through photographs; it does not make claims about decisions or outcomes.",
                'is_featured' => true,
                'sort_order' => 0,
                'image_url' => $this->mediaUrl($this->imagePath(16)),
                'image_alt' => 'A speaker addresses the KHCWW AGM and team-building gathering, 11 December 2025',
            ],
            [
                'content_type' => 'event',
                'slug' => 'agm-team-building-2025',
                'title' => 'KHCWW AGM and team-building gathering',
                'category' => 'AGM and team building',
                'excerpt' => 'The source photo gallery identifies the gathering date as 11 December 2025.',
                'content' => 'View the published gallery for photographs credited to ianphotography and shared with permission.',
                'is_featured' => true,
                'sort_order' => 0,
                'image_url' => $this->mediaUrl($this->imagePath(16)),
                'image_alt' => 'A speaker addresses the KHCWW AGM and team-building gathering, 11 December 2025',
                'starts_at' => '2025-12-11 00:00:00',
            ],
        ];

        foreach (range(1, self::GALLERY_IMAGE_COUNT) as $number) {
            $path = $this->imagePath($number);
            $content[] = [
                'content_type' => 'gallery',
                'slug' => sprintf('agm-team-building-2025-%02d', $number),
                'title' => sprintf('KHCWW AGM and team-building — photograph %02d', $number),
                'category' => 'AGM and team building',
                'excerpt' => '11 December 2025 · Photograph: ianphotography',
                'content' => null,
                'is_featured' => $number === 1,
                'sort_order' => $number,
                'image_url' => $this->mediaUrl($path),
                'image_alt' => 'Photograph from the KHCWW AGM and team-building gallery, 11 December 2025',
            ];
        }

        DB::transaction(function () use ($images, $content, $now): void {
            $this->registerMediaObjects($images, $now);
            $this->createMissingContent($content, $now);
        });

        $this->command?->info('Seeded missing KHCWW public pages and AGM gallery content without overwriting existing records.');
    }

    private function validateGalleryImages(): array
    {
        $images = [];

        foreach (range(1, self::GALLERY_IMAGE_COUNT) as $number) {
            $path = $this->imagePath($number);
            $file = storage_path('app/private/website-media/'.$path);
            $image = is_file($file) ? @getimagesize($file) : false;
            $size = is_file($file) ? filesize($file) : false;

            if ($image === false || ($image['mime'] ?? null) !== 'image/jpeg' || $size === false || $size <= 0 || $size > 5 * 1024 * 1024) {
                throw new RuntimeException("A valid JPEG under 5 MB is required at storage/app/private/website-media/{$path}.");
            }

            $images[] = ['path' => $path, 'size' => $size];
        }

        return $images;
    }

    private function registerMediaObjects(array $images, $now): void
    {
        foreach ($images as $image) {
            $existing = DB::table('storage_objects')
                ->where('bucket', 'website-media')
                ->where('object_path', $image['path'])
                ->first();

            if ($existing) {
                if ((int) $existing->is_public !== 1
                    || $existing->mime_type !== 'image/jpeg'
                    || (int) $existing->size_bytes !== $image['size']) {
                    throw new RuntimeException("Website media metadata conflicts with {$image['path']}.");
                }

                continue;
            }

            DB::table('storage_objects')->insert([
                'id' => Uuid::v4(),
                'bucket' => 'website-media',
                'object_path' => $image['path'],
                'mime_type' => 'image/jpeg',
                'size_bytes' => $image['size'],
                'is_public' => 1,
                'owner_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function createMissingContent(array $content, $now): void
    {
        foreach ($content as $item) {
            $exists = DB::table('website_content_items')
                ->where('content_type', $item['content_type'])
                ->where('slug', $item['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('website_content_items')->insert([
                ...$item,
                'status' => 'published',
                'published_at' => $now,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function imagePath(int $number): string
    {
        return self::MEDIA_DIRECTORY.'/'.sprintf('agm-team-building-2025-%02d.jpg', $number);
    }

    private function mediaUrl(string $path): string
    {
        return url('/api/storage/v1/object/website-media/'.$path);
    }
}
