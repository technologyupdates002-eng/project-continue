<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebsiteMailService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class WebsiteContactController extends Controller
{
    public function store(Request $request, WebsiteMailService $mail): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'topic' => ['required', 'in:general,membership,partnerships,welfare'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'website' => ['nullable', 'max:0'],
        ]);

        $messageId = DB::table('website_contact_messages')->insertGetId([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'topic' => $validated['topic'],
            'message' => trim($validated['message']),
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emailJson = DB::table('website_cms_settings')->where('setting_key', 'email')->value('setting_value');
        $emailSettings = $emailJson ? json_decode($emailJson, true, flags: JSON_THROW_ON_ERROR) : [];
        $recipient = $emailSettings['notification_email'] ?? null;
        $hasTemplate = DB::table('website_email_templates')
            ->where('template_key', 'contact-notification')
            ->where('enabled', true)
            ->exists();

        if (! empty($emailSettings['enabled']) && $recipient && $hasTemplate) {
            try {
                $mail->sendTemplate('contact-notification', $recipient, [
                    'sender_name' => trim($validated['name']),
                    'sender_email' => strtolower(trim($validated['email'])),
                    'topic' => $validated['topic'],
                    'message' => trim($validated['message']),
                ]);
            } catch (TransportExceptionInterface|DecryptException $exception) {
                Log::error('Website contact notification email failed after the enquiry was saved.', [
                    'message_id' => $messageId,
                    'exception_type' => $exception::class,
                ]);
            }
        }

        return response()->json(['message' => 'Your message has been sent.'], 201);
    }

    public function index(): JsonResponse
    {
        $messages = DB::table('website_contact_messages')
            ->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(50, ['id', 'name', 'email', 'topic', 'message', 'status', 'created_at', 'read_at']);

        return response()->json([
            'data' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function markRead(Request $request, int $messageId): JsonResponse
    {
        $message = DB::table('website_contact_messages')->where('id', $messageId)->first();
        abort_unless($message, 404, 'Contact message not found.');

        if ($message->status === 'new') {
            DB::table('website_contact_messages')
                ->where('id', $messageId)
                ->update([
                    'status' => 'read',
                    'read_by' => $request->attributes->get('identity')['user_id'],
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['message' => 'Contact message marked as read.']);
    }
}
