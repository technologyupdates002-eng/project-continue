<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsitePublicProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $profiles = $this->publicProfileQuery()
            ->where('website_public_profiles.published', true)
            ->where('members.is_active', true)
            ->where('members.status', 'active')
            ->orderBy('website_public_profiles.sort_order')
            ->orderBy('members.name')
            ->get();

        return response()->json([
            'data' => $profiles->map(fn (object $profile) => $this->publicProfile($profile))->values(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $profile = $this->publicProfileQuery()
            ->where('website_public_profiles.slug', $slug)
            ->where('website_public_profiles.published', true)
            ->where('members.is_active', true)
            ->where('members.status', 'active')
            ->first();

        if (! $profile) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        return response()->json(['data' => $this->publicProfile($profile)]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $profiles = $this->publicProfileQuery()
            ->when($request->has('published'), fn ($query) => $query->where(
                'website_public_profiles.published',
                $request->boolean('published')
            ))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%' . $request->string('search')->trim() . '%';
                $query->where(function ($nested) use ($search) {
                    $nested->where('members.name', 'like', $search)
                        ->orWhere('website_public_profiles.public_role', 'like', $search);
                });
            })
            ->orderBy('website_public_profiles.sort_order')
            ->orderBy('members.name')
            ->paginate(50);

        return response()->json([
            'data' => $profiles->getCollection()
                ->map(fn (object $profile) => $this->adminProfile($profile))
                ->values(),
            'meta' => [
                'current_page' => $profiles->currentPage(),
                'last_page' => $profiles->lastPage(),
                'total' => $profiles->total(),
            ],
        ]);
    }

    public function leadershipMembers(): JsonResponse
    {
        $leadershipRoles = [
            'chairperson',
            'vice_chairperson',
            'secretary',
            'vice_secretary',
            'treasurer',
            'executive',
            'patron',
            'admin',
            'super_admin',
        ];

        $roleRows = DB::table('members')
            ->join('user_roles', 'user_roles.user_id', '=', 'members.user_id')
            ->where('members.is_active', true)
            ->where('members.status', 'active')
            ->where('user_roles.is_active', true)
            ->whereIn('user_roles.role', $leadershipRoles)
            ->orderBy('members.name')
            ->get([
                'members.id as member_id',
                'members.name',
                'members.member_id as member_number',
                'members.profile_picture_url',
                'user_roles.role',
            ]);

        $members = $roleRows
            ->groupBy('member_id')
            ->map(fn ($rows) => [
                'member_id' => $rows->first()->member_id,
                'name' => $rows->first()->name,
                'member_number' => $rows->first()->member_number,
                'image_url' => $this->publicImageUrl($rows->first()->profile_picture_url),
                'roles' => $rows->pluck('role')->unique()->values()->all(),
            ]);

        $profiles = $this->publicProfileQuery()
            ->whereIn('members.id', $members->keys())
            ->get()
            ->keyBy('member_id');

        $roleLabels = [
            'chairperson' => 'Chairperson',
            'vice_chairperson' => 'Vice Chairperson',
            'secretary' => 'Secretary',
            'vice_secretary' => 'Vice Secretary',
            'treasurer' => 'Treasurer',
            'executive' => 'Executive',
            'patron' => 'Patron',
            'admin' => 'Administrator',
            'super_admin' => 'Super Administrator',
        ];
        $rolePriority = array_flip([
            'chairperson',
            'vice_chairperson',
            'secretary',
            'vice_secretary',
            'treasurer',
            'executive',
            'patron',
            'admin',
            'super_admin',
        ]);

        return response()->json([
            'data' => $members->map(function (array $member) use ($profiles, $roleLabels, $rolePriority) {
                $roles = $member['roles'];
                usort($roles, fn (string $left, string $right) => $rolePriority[$left] <=> $rolePriority[$right]);
                $profile = $profiles->get($member['member_id']);

                return [
                    ...$member,
                    'roles' => $roles,
                    'suggested_public_role' => $roleLabels[$roles[0]],
                    'profile' => $profile ? $this->adminProfile($profile) : null,
                ];
            })->values(),
        ]);
    }

    public function searchMembers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $search = '%' . $validated['q'] . '%';

        $members = DB::table('members')
            ->where('is_active', true)
            ->where('status', 'active')
            ->where('name', 'like', $search)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'member_id']);

        return response()->json(['data' => $members]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateProfile($request);
        $member = DB::table('members')->where('id', $validated['member_id'])->first(['id', 'name']);
        abort_unless($member, 422, 'Select an existing member.');

        $profileId = DB::table('website_public_profiles')->insertGetId(
            $this->profileValues($validated, $member->name, $request, true)
        );
        $profile = $this->adminProfileById($profileId);

        return response()->json(['data' => $profile], 201);
    }

    public function update(Request $request, int $profileId): JsonResponse
    {
        $profile = DB::table('website_public_profiles')
            ->where('id', $profileId)
            ->first();
        abort_unless($profile, 404, 'Public profile not found.');

        $validated = $this->validateProfile($request, $profileId);
        $validated['slug'] ??= $profile->slug;
        $member = DB::table('members')->where('id', $validated['member_id'])->first(['id', 'name']);
        abort_unless($member, 422, 'Select an existing member.');

        DB::table('website_public_profiles')
            ->where('id', $profileId)
            ->update($this->profileValues($validated, $member->name, $request, false));

        return response()->json(['data' => $this->adminProfileById($profileId)]);
    }

    public function togglePublish(Request $request, int $profileId): JsonResponse
    {
        $profile = DB::table('website_public_profiles')
            ->where('id', $profileId)
            ->first();
        abort_unless($profile, 404, 'Public profile not found.');

        $published = ! (bool) $profile->published;
        DB::table('website_public_profiles')
            ->where('id', $profileId)
            ->update([
                'published' => $published,
                'updated_by' => $request->attributes->get('identity')['user_id'],
                'updated_at' => now(),
            ]);

        return response()->json(['published' => $published]);
    }

    public function archive(Request $request, int $profileId): JsonResponse
    {
        $updated = DB::table('website_public_profiles')
            ->where('id', $profileId)
            ->update([
                'published' => false,
                'updated_by' => $request->attributes->get('identity')['user_id'],
                'updated_at' => now(),
            ]);

        abort_unless($updated, 404, 'Public profile not found.');

        return response()->json(['message' => 'Public profile archived.']);
    }

    private function validateProfile(Request $request, ?int $profileId = null): array
    {
        return Validator::make($request->all(), [
            'member_id' => [
                'required',
                'string',
                'exists:members,id',
                Rule::unique('website_public_profiles', 'member_id')->ignore($profileId),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:200',
                Rule::unique('website_public_profiles', 'slug')->ignore($profileId),
            ],
            'title_prefix' => ['nullable', 'string', 'max:20'],
            'display_name' => ['nullable', 'string', 'max:200'],
            'public_role' => ['required', 'string', 'max:200'],
            'short_bio' => ['nullable', 'string', 'max:500'],
            'biography' => ['nullable', 'string', 'max:10000'],
            'qualifications' => ['nullable', 'string', 'max:2000'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url', 'max:500'],
            'published' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ])->validate();
    }

    private function profileValues(array $validated, string $memberName, Request $request, bool $creating): array
    {
        $identity = $request->attributes->get('identity');
        $slug = $validated['slug'] ?? Str::slug(($validated['display_name'] ?? null) ?: $memberName);

        if ($creating && ! isset($validated['slug']) && DB::table('website_public_profiles')->where('slug', $slug)->exists()) {
            $slug .= '-' . substr($validated['member_id'], 0, 8);
        }

        $values = [
            'member_id' => $validated['member_id'],
            'slug' => $slug,
            'title_prefix' => $validated['title_prefix'] ?? null,
            'display_name' => $validated['display_name'] ?? null,
            'public_role' => $validated['public_role'],
            'short_bio' => $validated['short_bio'] ?? null,
            'biography' => $validated['biography'] ?? null,
            'qualifications' => $validated['qualifications'] ?? null,
            'social_links' => isset($validated['social_links']) ? json_encode($validated['social_links'], JSON_THROW_ON_ERROR) : null,
            'published' => $validated['published'] ?? false,
            'is_featured' => $validated['is_featured'] ?? false,
            'sort_order' => $validated['sort_order'] ?? 0,
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ];

        if ($creating) {
            $values['created_by'] = $identity['user_id'];
            $values['created_at'] = now();
        }

        return $values;
    }

    private function publicProfileQuery()
    {
        return DB::table('website_public_profiles')
            ->join('members', 'members.id', '=', 'website_public_profiles.member_id')
            ->select([
                'website_public_profiles.id',
                'website_public_profiles.member_id',
                'website_public_profiles.slug',
                'website_public_profiles.title_prefix',
                'website_public_profiles.display_name',
                'website_public_profiles.public_role',
                'website_public_profiles.short_bio',
                'website_public_profiles.biography',
                'website_public_profiles.qualifications',
                'website_public_profiles.social_links',
                'website_public_profiles.published',
                'website_public_profiles.is_featured',
                'website_public_profiles.sort_order',
                'members.name as member_name',
                'members.profile_picture_url',
                'members.is_active',
                'members.status as member_status',
            ]);
    }

    private function adminProfileById(int $profileId): array
    {
        $profile = $this->publicProfileQuery()
            ->where('website_public_profiles.id', $profileId)
            ->first();

        abort_unless($profile, 404, 'Public profile not found.');

        return $this->adminProfile($profile);
    }

    private function adminProfile(object $profile): array
    {
        return array_merge($this->publicProfile($profile), [
            'id' => $profile->id,
            'member_id' => $profile->member_id,
            'member_name' => $profile->member_name,
            'title_prefix' => $profile->title_prefix,
            'display_name' => $profile->display_name,
            'published' => (bool) $profile->published,
            'is_featured' => (bool) $profile->is_featured,
            'sort_order' => (int) $profile->sort_order,
        ]);
    }

    private function publicProfile(object $profile): array
    {
        $name = $profile->display_name ?: $profile->member_name;
        if ($profile->title_prefix && ! Str::startsWith(Str::lower($name), Str::lower($profile->title_prefix . ' '))) {
            $name = $profile->title_prefix . ' ' . $name;
        }

        return [
            'slug' => $profile->slug,
            'name' => $name,
            'role' => $profile->public_role,
            'short_bio' => $profile->short_bio,
            'biography' => $profile->biography,
            'qualifications' => $profile->qualifications,
            'social_links' => json_decode($profile->social_links ?? '[]', true) ?: [],
            'image_url' => $this->publicImageUrl($profile->profile_picture_url),
            'is_featured' => (bool) $profile->is_featured,
        ];
    }

    private function publicImageUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return parse_url($value, PHP_URL_SCHEME) === 'https' ? $value : null;
        }

        if (Str::startsWith($value, '/api/storage/v1/object/profile-images/')) {
            return rtrim(request()->getSchemeAndHttpHost(), '/') . $value;
        }

        return null;
    }
}
