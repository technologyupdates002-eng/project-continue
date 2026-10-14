<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiAssistantRequest;
use App\Services\FreeAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AiAssistantController extends Controller
{
    public function __construct(private readonly FreeAiService $aiService)
    {
    }

    public function history(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity', []);
        $userId = (string) ($identity['user_id'] ?? '');
        $contextType = (string) $request->query('context_type', 'admin_assistant');

        $conversation = DB::table('ai_assistant_conversations')
            ->where('user_id', $userId)
            ->where('context_type', $contextType)
            ->orderByDesc('updated_at')
            ->first();

        if (! $conversation) {
            return response()->json(['conversation_id' => null, 'messages' => []]);
        }

        $messages = DB::table('ai_assistant_messages')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['role', 'content'])
            ->reverse()
            ->reject(fn (object $message): bool => $this->isSafetyMetadataOnly((string) $message->content))
            ->values();

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages,
        ]);
    }

    public function query(AiAssistantRequest $request): Response
    {
        $identity = $request->attributes->get('identity', []);
        $data = $request->validated();
        $userId = (string) ($identity['user_id'] ?? '');
        $query = trim((string) ($data['query'] ?? ''));
        if ($query === '' && isset($data['messages'])) {
            $lastUserMessage = collect($data['messages'])
                ->last(fn (array $message): bool => ($message['role'] ?? null) === 'user');
            $query = trim((string) ($lastUserMessage['content'] ?? ''));
        }
        abort_if($query === '', 422, 'A user message is required.');

        $contextType = (string) ($data['context_type'] ?? 'admin_assistant');
        $conversationId = $data['conversation_id'] ?? null;
        $conversationQuery = DB::table('ai_assistant_conversations')
            ->where('user_id', $userId)
            ->where('context_type', $contextType);

        if ($conversationId) {
            $conversationQuery->where('id', $conversationId);
        } else {
            $conversationQuery->orderByDesc('updated_at');
        }

        $conversation = $conversationQuery->first();
        if ($conversationId && ! $conversation) {
            abort(404, 'Conversation not found.');
        }

        $history = $conversation
            ? DB::table('ai_assistant_messages')
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('id')
                ->limit(19)
                ->get(['role', 'content'])
                ->reverse()
                ->values()
                ->reject(fn (object $message): bool => $message->role === 'assistant'
                    && $this->isSafetyMetadataOnly((string) $message->content))
                ->map(fn (object $message): array => ['role' => $message->role, 'content' => $message->content])
                ->all()
            : [];
        $messages = [...$history, ['role' => 'user', 'content' => $query]];

        try {
            $responseText = $this->aiService->generate($messages, $contextType, $identity);
        } catch (\Throwable $e) {
            Log::warning('AI assistant provider error', [
                'user_id' => $identity['user_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'AI provider is not configured or is temporarily unavailable.',
                'message' => 'AI provider is not configured or is temporarily unavailable.',
            ], 503);
        }

        if (blank($responseText)) {
            return response()->json([
                'error' => 'AI provider is not configured or is temporarily unavailable.',
                'message' => 'AI provider is not configured or is temporarily unavailable.',
            ], 503);
        }

        $now = now();
        DB::transaction(function () use (&$conversation, $conversationId, $userId, $contextType, $query, $responseText, $now): void {
            if (! $conversation) {
                $conversation = (object) [
                    'id' => (string) Str::uuid(),
                ];
                DB::table('ai_assistant_conversations')->insert([
                    'id' => $conversation->id,
                    'user_id' => $userId,
                    'context_type' => $contextType,
                    'title' => Str::limit($query, 255),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('ai_assistant_conversations')
                    ->where('id', $conversation->id)
                    ->where('user_id', $userId)
                    ->update(['updated_at' => $now]);
            }

            DB::table('ai_assistant_messages')->insert([
                [
                    'conversation_id' => $conversation->id,
                    'role' => 'user',
                    'content' => $query,
                    'created_at' => $now,
                ],
                [
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $responseText,
                    'created_at' => $now,
                ],
            ]);
        });

        Log::info('AI assistant query', [
            'user_id' => $userId,
            'context_type' => $contextType,
        ]);

        return response()->stream(function () use ($responseText) {
            echo 'data: ' . json_encode([
                'choices' => [[
                    'delta' => ['content' => $responseText],
                ]],
            ]) . "\n\n";
            ob_flush();
            flush();

            echo "data: [DONE]\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'X-AI-Conversation-Id' => $conversation->id,
        ]);
    }

    private function isSafetyMetadataOnly(string $content): bool
    {
        $cleanContent = preg_replace(
            '/^\s*(?:User Safety|Response Safety)\s*:\s*[^\r\n]*\s*$/im',
            '',
            $content
        );

        return blank($cleanContent);
    }
}
