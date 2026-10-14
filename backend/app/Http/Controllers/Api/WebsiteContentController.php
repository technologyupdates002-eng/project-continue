<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsiteContentController extends Controller
{
    private const TYPES = ['page', 'project', 'article', 'event', 'welfare', 'gallery', 'resource', 'announcement'];

    private const ADMIN_COLUMNS = [
        'id', 'content_type', 'slug', 'title', 'category', 'excerpt', 'content', 'status',
        'is_featured', 'sort_order', 'image_url', 'image_alt', 'location', 'starts_at',
        'ends_at', 'resource_url', 'published_at', 'created_at', 'updated_at',
    ];

    private const PUBLIC_COLUMNS = [
        'content_type', 'slug', 'title', 'category', 'excerpt', 'content', 'is_featured', 'sort_order',
        'image_url', 'image_alt', 'location', 'starts_at', 'ends_at', 'resource_url', 'published_at',
    ];

    public function adminDashboard(): JsonResponse
    {
        $publishedByType = DB::table('website_content_items')
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->select('content_type', DB::raw('COUNT(*) as total'))
            ->groupBy('content_type')
            ->pluck('total', 'content_type');

        return response()->json([
            'data' => [
                'published_by_type' => $publishedByType,
                'draft_content' => DB::table('website_content_items')->where('status', 'draft')->count(),
                'published_profiles' => DB::table('website_public_profiles')->where('published', true)->count(),
                'unpublished_profiles' => DB::table('website_public_profiles')->where('published', false)->count(),
                'new_contact_messages' => DB::table('website_contact_messages')->where('status', 'new')->count(),
                'upcoming_events' => DB::table('website_content_items')
                    ->where('content_type', 'event')
                    ->where('status', 'published')
                    ->where('starts_at', '>=', now())
                    ->count(),
            ],
        ]);
    }

    public function publicIndex(Request $request, string $contentType): JsonResponse
    {
        $this->assertContentType($contentType);

        $items = $this->publishedQuery($contentType)
            ->when($contentType === 'announcement', fn ($query) => $query
                ->where(fn ($window) => $window->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($window) => $window->whereNull('ends_at')->orWhere('ends_at', '>=', now())))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')->trim()))
            ->when($request->boolean('featured'), fn ($query) => $query->where('is_featured', true))
            ->when($request->filled('q'), function ($query) use ($request) {
                $needle = '%'.addcslashes($request->string('q')->trim()->toString(), '\\%_').'%';
                $query->where(function ($search) use ($needle) {
                    $search->where('title', 'like', $needle)
                        ->orWhere('excerpt', 'like', $needle)
                        ->orWhere('content', 'like', $needle);
                });
            })
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->when($contentType !== 'gallery', fn ($query) => $query->limit(100))
            ->get(self::PUBLIC_COLUMNS);

        return response()->json(['data' => $items]);
    }

    public function publicShow(string $contentType, string $slug): JsonResponse
    {
        $this->assertContentType($contentType);
        $item = $this->publishedQuery($contentType)->where('slug', $slug)->first(self::PUBLIC_COLUMNS);

        if (! $item) {
            return response()->json(['message' => 'Published content not found.'], 404);
        }

        return response()->json(['data' => $item]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content_type' => ['required', 'string', Rule::in(self::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $perPage = $validated['content_type'] === 'gallery' ? 200 : 100;
        $items = DB::table('website_content_items')
            ->where('content_type', $validated['content_type'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($validated) {
                $needle = '%'.addcslashes(trim($validated['search']), '\\%_').'%';
                $query->where(fn ($search) => $search->where('title', 'like', $needle)->orWhere('slug', 'like', $needle));
            })
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->paginate($perPage, self::ADMIN_COLUMNS);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateContent($request);
        $slug = $validated['slug'] ?? Str::slug($validated['title']);
        $this->validateUniqueSlug($validated['content_type'], $slug);

        $identity = $request->attributes->get('identity');
        $now = now();
        $id = DB::table('website_content_items')->insertGetId([
            ...$this->contentValues($validated, $slug, $now),
            'created_by' => $identity['user_id'],
            'updated_by' => $identity['user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['data' => $this->adminItem($id)], 201);
    }

    public function update(Request $request, int $itemId): JsonResponse
    {
        $item = DB::table('website_content_items')->where('id', $itemId)->first();
        abort_unless($item, 404, 'Website content not found.');

        $validated = $this->validateContent($request, $itemId);
        abort_unless($validated['content_type'] === $item->content_type, 422, 'Content type cannot be changed.');
        $slug = $validated['slug'] ?? $item->slug;
        $this->validateUniqueSlug($item->content_type, $slug, $itemId);

        $identity = $request->attributes->get('identity');
        DB::table('website_content_items')->where('id', $itemId)->update([
            ...$this->contentValues($validated, $slug, now(), $item->published_at),
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->adminItem($itemId)]);
    }

    public function archive(Request $request, int $itemId): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $updated = DB::table('website_content_items')
            ->where('id', $itemId)
            ->update([
                'status' => 'archived',
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]);

        abort_unless($updated, 404, 'Website content not found.');

        return response()->json(['message' => 'Website content archived.']);
    }

    private function publishedQuery(string $contentType)
    {
        return DB::table('website_content_items')
            ->where('content_type', $contentType)
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    private function assertContentType(string $contentType): void
    {
        abort_unless(in_array($contentType, self::TYPES, true), 404, 'Website content type not found.');
    }

    private function validateContent(Request $request, ?int $itemId = null): array
    {
        return Validator::make($request->all(), [
            'content_type' => ['required', 'string', Rule::in(self::TYPES)],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            ],
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:120'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'content' => ['nullable', 'string', 'max:100000'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'image_url' => ['nullable', 'string', 'max:2048', 'regex:/^(https:\/\/|\/(?!\/))/i'],
            'image_alt' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:200'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'resource_url' => ['nullable', 'string', 'max:2048', 'regex:/^(https:\/\/|\/(?!\/))/i'],
        ])->validate();
    }

    private function validateUniqueSlug(string $contentType, string $slug, ?int $ignoreId = null): void
    {
        $query = DB::table('website_content_items')->where('content_type', $contentType)->where('slug', $slug);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        abort_if($query->exists(), 422, 'This slug is already used for this content type.');
    }

    private function contentValues(array $validated, string $slug, $now, mixed $existingPublishedAt = null): array
    {
        $status = $validated['status'];

        return [
            'content_type' => $validated['content_type'],
            'slug' => $slug,
            'title' => $validated['title'],
            'category' => $validated['category'] ?? null,
            'excerpt' => $validated['excerpt'] ?? null,
            'content' => $validated['content'] ?? null,
            'status' => $status,
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'image_url' => $validated['image_url'] ?? null,
            'image_alt' => $validated['image_alt'] ?? null,
            'location' => $validated['location'] ?? null,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'resource_url' => $validated['resource_url'] ?? null,
            'published_at' => $status === 'published' ? ($existingPublishedAt ?: $now) : null,
        ];
    }

    private function adminItem(int $itemId): object
    {
        return DB::table('website_content_items')->where('id', $itemId)->first(self::ADMIN_COLUMNS);
    }
}
