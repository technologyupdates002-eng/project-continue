<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebsiteMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class WebsiteCmsController extends Controller
{
    private const SETTINGS_KEYS = ['site', 'navigation', 'footer', 'seo', 'google', 'social', 'email'];

    private const PAGE_KEYS = [
        'home', 'about', 'work', 'projects', 'project', 'welfare', 'news', 'article',
        'events', 'event', 'leadership', 'gallery', 'resources', 'contact', 'membership',
    ];

    public function publicSettings(): JsonResponse
    {
        $keys = ['site', 'navigation', 'footer', 'seo', 'google', 'social'];
        $settings = DB::table('website_cms_settings')
            ->whereIn('setting_key', $keys)
            ->pluck('setting_value', 'setting_key')
            ->map(fn (string $value) => json_decode($value, true, flags: JSON_THROW_ON_ERROR));

        return response()->json(['data' => (object) $settings->all()]);
    }

    public function showSettings(string $section): JsonResponse
    {
        abort_unless(in_array($section, self::SETTINGS_KEYS, true), 404, 'Website setting section not found.');

        $stored = DB::table('website_cms_settings')->where('setting_key', $section)->value('setting_value');
        $settings = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : $this->defaults($section);
        if ($section === 'email') {
            $settings['has_password'] = ! empty($settings['smtp_password']);
            unset($settings['smtp_password']);
        }

        return response()->json(['data' => $settings]);
    }

    public function saveSettings(Request $request, string $section): JsonResponse
    {
        abort_unless(in_array($section, self::SETTINGS_KEYS, true), 404, 'Website setting section not found.');
        $validated = $this->validateSettings($request, $section);
        if ($section === 'email') {
            $previous = DB::table('website_cms_settings')->where('setting_key', 'email')->value('setting_value');
            $previousSettings = $previous ? json_decode($previous, true, flags: JSON_THROW_ON_ERROR) : [];
            $password = $validated['smtp_password'] ?? '';
            unset($validated['smtp_password']);
            if ($password !== '') {
                $validated['smtp_password'] = Crypt::encryptString($password);
            } elseif (! empty($previousSettings['smtp_password'])) {
                $validated['smtp_password'] = $previousSettings['smtp_password'];
            }
        }

        $identity = $request->attributes->get('identity');
        DB::table('website_cms_settings')->updateOrInsert(
            ['setting_key' => $section],
            [
                'setting_value' => json_encode($validated, JSON_THROW_ON_ERROR),
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return $this->showSettings($section);
    }

    public function listTemplates(): JsonResponse
    {
        $templates = DB::table('website_email_templates')
            ->orderBy('name')
            ->get(['template_key', 'name', 'subject', 'body', 'enabled', 'updated_at']);

        return response()->json(['data' => $templates]);
    }

    public function saveTemplate(Request $request, ?string $templateKey = null): JsonResponse
    {
        $validated = $request->validate([
            'template_key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:20000'],
            'enabled' => ['required', 'boolean'],
        ]);
        $key = $templateKey ?? $validated['template_key'];
        abort_if($templateKey && $validated['template_key'] !== $templateKey, 422, 'Template key cannot be changed.');
        Validator::make($validated, [
            'template_key' => [Rule::unique('website_email_templates', 'template_key')->ignore($key, 'template_key')],
        ])->validate();

        $identity = $request->attributes->get('identity');
        DB::table('website_email_templates')->updateOrInsert(
            ['template_key' => $key],
            [...$validated, 'updated_by' => $identity['user_id'], 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json([
            'data' => DB::table('website_email_templates')->where('template_key', $key)
                ->first(['template_key', 'name', 'subject', 'body', 'enabled', 'updated_at']),
        ]);
    }

    public function testEmail(Request $request, WebsiteMailService $mail): JsonResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'email:rfc', 'max:254'],
            'template_key' => ['required', 'string', 'exists:website_email_templates,template_key'],
        ]);

        try {
            $siteJson = DB::table('website_cms_settings')->where('setting_key', 'site')->value('setting_value');
            $siteSettings = $siteJson ? json_decode($siteJson, true, flags: JSON_THROW_ON_ERROR) : [];
            $mail->sendTemplate($validated['template_key'], $validated['to'], [
                'site_name' => $siteSettings['site_name'] ?? 'KHCWW',
                'recipient' => $validated['to'],
                'sender_name' => 'Website email delivery test',
                'sender_email' => $validated['to'],
                'topic' => 'test',
                'message' => 'This is a test message from the website management dashboard.',
            ]);
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return response()->json(['message' => 'Email delivery failed. Check the SMTP host, port, encryption, and credentials.'], 502);
        }

        return response()->json(['message' => 'Test email sent.']);
    }

    public function mediaIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $files = DB::table('storage_objects')
            ->where('bucket', 'website-media')
            ->when(! empty($validated['search']), function ($query) use ($validated) {
                $query->where('object_path', 'like', '%'.addcslashes($validated['search'], '\\%_').'%');
            })
            ->orderByDesc('created_at')
            ->paginate(40, ['id', 'bucket', 'object_path', 'mime_type', 'size_bytes', 'created_at']);

        return response()->json([
            'data' => collect($files->items())->map(fn ($file) => [
                ...(array) $file,
                'url' => url('/api/storage/v1/object/website-media/'.$file->object_path),
            ]),
            'meta' => ['current_page' => $files->currentPage(), 'last_page' => $files->lastPage(), 'total' => $files->total()],
        ]);
    }

    private function validateSettings(Request $request, string $section): array
    {
        $rules = match ($section) {
            'site' => [
                'site_name' => ['required', 'string', 'max:120'],
                'tagline' => ['nullable', 'string', 'max:240'],
                'logo_url' => ['nullable', 'url:http,https', 'max:1000'],
                'contact_email' => ['nullable', 'email:rfc', 'max:254'],
                'contact_phone' => ['nullable', 'string', 'max:40'],
                'address' => ['nullable', 'string', 'max:500'],
            ],
            'navigation' => [
                'items' => ['present', 'array', 'max:20'],
                'items.*.label' => ['required', 'string', 'max:60'],
                'items.*.page' => ['required', 'string', Rule::in(self::PAGE_KEYS)],
            ],
            'footer' => [
                'copyright_text' => ['nullable', 'string', 'max:240'],
                'columns' => ['present', 'array', 'max:6'],
                'columns.*.title' => ['required', 'string', 'max:60'],
                'columns.*.links' => ['present', 'array', 'max:12'],
                'columns.*.links.*.label' => ['required', 'string', 'max:60'],
                'columns.*.links.*.page' => ['required', 'string', Rule::in(self::PAGE_KEYS)],
            ],
            'seo' => [
                'title' => ['required', 'string', 'max:70'],
                'description' => ['nullable', 'string', 'max:320'],
                'keywords' => ['nullable', 'string', 'max:500'],
                'og_image_url' => ['nullable', 'url:http,https', 'max:1000'],
                'robots' => ['required', Rule::in(['index,follow', 'noindex,nofollow'])],
            ],
            'google' => [
                'verification_code' => ['nullable', 'string', 'max:200'],
            ],
            'social' => [
                'links' => ['present', 'array', 'max:10'],
                'links.*.platform' => ['required', 'string', 'max:40'],
                'links.*.url' => ['required', 'url:http,https', 'max:1000'],
            ],
            'email' => [
                'enabled' => ['required', 'boolean'],
                'smtp_host' => ['required_if:enabled,true', 'nullable', 'string', 'regex:/^[A-Za-z0-9.-]+$/', 'max:255'],
                'smtp_port' => ['required_if:enabled,true', 'integer', 'between:1,65535'],
                'smtp_username' => ['nullable', 'string', 'max:255'],
                'smtp_password' => ['nullable', 'string', 'max:500'],
                'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
                'from_email' => ['required_if:enabled,true', 'nullable', 'email:rfc', 'max:254'],
                'from_name' => ['required', 'string', 'max:120'],
                'notification_email' => ['nullable', 'email:rfc', 'max:254'],
            ],
        };

        return Validator::make($request->all(), $rules)->validate();
    }

    private function defaults(string $section): array
    {
        return match ($section) {
            'site' => ['site_name' => 'KHCWW', 'tagline' => 'Kirinyaga Health Care Workers Welfare', 'logo_url' => null, 'contact_email' => null, 'contact_phone' => null, 'address' => null],
            'navigation' => ['items' => []],
            'footer' => ['copyright_text' => 'KHCWW', 'columns' => []],
            'seo' => ['title' => 'Kirinyaga Health Care Workers Welfare', 'description' => '', 'keywords' => '', 'og_image_url' => null, 'robots' => 'index,follow'],
            'google' => ['verification_code' => ''],
            'social' => ['links' => []],
            'email' => ['enabled' => false, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_username' => '', 'encryption' => 'tls', 'from_email' => '', 'from_name' => 'KHCWW Website', 'notification_email' => '', 'has_password' => false],
        };
    }
}
