<?php

namespace Tests\Feature;

use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_retries_safety_label_only_model_response_and_hides_labels(): void
    {
        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.model' => 'nvidia/nemotron-3-ultra-550b-a55b:free',
        ]);

        Http::fakeSequence('https://openrouter.ai/api/v1/chat/completions')
            ->push([
                'choices' => [[
                    'message' => ['content' => "User Safety: safe\nResponse Safety: safe"],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => ['content' => "User Safety: safe\nResponse Safety: safe\nI'm doing well, thanks for asking!"],
                ]],
            ], 200);

        $answer = app(\App\Services\FreeAiService::class)->generate([
            ['role' => 'user', 'content' => 'How are you?'],
        ]);

        $this->assertSame("I'm doing well, thanks for asking!", $answer);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool =>
            $request['model'] === 'nvidia/nemotron-3-ultra-550b-a55b:free'
        );
        Http::assertSent(fn ($request): bool => $request['model'] === 'openrouter/free');
    }

    public function test_ai_assistant_persists_and_restores_follow_up_conversation(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);

        Http::fakeSequence('https://openrouter.ai/api/v1/chat/completions')
            ->push([
                'choices' => [[
                    'message' => ['content' => 'Here is the answer from the free AI model.'],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => ['content' => 'You can review them in the contributions report.'],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => ['content' => 'I’m here and ready to help.'],
                ]],
            ], 200);

        $token = JwtService::encode(JwtService::buildPayload(
            '11111111-1111-4111-8111-111111111111',
            '254700000000@welfare.local',
            ['treasurer']
        ));

        $firstResponse = $this->withToken($token)
            ->postJson('/api/functions/v1/ai-assistant', [
                'query' => 'How do I check my contributions?',
                'context_type' => 'admin_assistant',
            ]);

        $firstResponse->assertOk();
        $this->assertStringContainsString(
            'Here is the answer from the free AI model.',
            $firstResponse->streamedContent()
        );
        $conversationId = $firstResponse->headers->get('X-AI-Conversation-Id');
        $this->assertNotEmpty($conversationId);

        Http::assertSent(fn ($request): bool =>
            $request['model'] === config('services.openrouter.model')
            && str_contains(
                collect($request->data()['messages'] ?? [])->pluck('content')->implode("\n"),
                'KHWWC Welfare System'
            )
            && str_contains(
                collect($request->data()['messages'] ?? [])->pluck('content')->implode("\n"),
                'You do not have live access to member records'
            )
            && str_contains(
                collect($request->data()['messages'] ?? [])->pluck('content')->implode("\n"),
                'Respond to greetings, thanks, and small talk directly and briefly'
            )
            && str_contains(
                collect($request->data()['messages'] ?? [])->pluck('content')->implode("\n"),
                'Never output safety labels, moderation classifications'
            )
        );

        $followUpResponse = $this->withToken($token)
            ->postJson('/api/functions/v1/ai-assistant', [
                'query' => 'Where do I find that report?',
                'conversation_id' => $conversationId,
                'context_type' => 'admin_assistant',
            ]);

        $followUpResponse->assertOk();
        $this->assertStringContainsString(
            'You can review them in the contributions report.',
            $followUpResponse->streamedContent()
        );

        Http::assertSent(fn ($request): bool =>
            collect($request->data()['messages'] ?? [])->contains(
                fn (array $message): bool => $message['role'] === 'user'
                    && $message['content'] === 'How do I check my contributions?'
            )
            && collect($request->data()['messages'] ?? [])->contains(
                fn (array $message): bool => $message['role'] === 'assistant'
                    && $message['content'] === 'Here is the answer from the free AI model.'
            )
        );

        \Illuminate\Support\Facades\DB::table('ai_assistant_messages')->insert([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => "User Safety: safe\nResponse Safety: safe",
            'created_at' => now(),
        ]);

        $thirdResponse = $this->withToken($token)
            ->postJson('/api/functions/v1/ai-assistant', [
                'query' => 'And how is the app doing?',
                'conversation_id' => $conversationId,
                'context_type' => 'admin_assistant',
            ]);
        $thirdResponse->assertOk();
        $this->assertStringContainsString('I’m here and ready to help.', $thirdResponse->streamedContent());

        Http::assertSent(fn ($request): bool =>
            collect($request->data()['messages'] ?? [])->contains(
                fn (array $message): bool => $message['role'] === 'user'
                    && $message['content'] === 'And how is the app doing?'
            )
            && ! str_contains(
                collect($request->data()['messages'] ?? [])->pluck('content')->implode("\n"),
                'User Safety: safe'
            )
        );

        $history = $this->withToken($token)
            ->getJson('/api/functions/v1/ai-assistant/conversation?context_type=admin_assistant');
        $history->assertOk()
            ->assertJsonPath('conversation_id', $conversationId)
            ->assertJsonCount(6, 'messages')
            ->assertJsonPath('messages.0.content', 'How do I check my contributions?')
            ->assertJsonPath('messages.1.content', 'Here is the answer from the free AI model.')
            ->assertJsonPath('messages.2.content', 'Where do I find that report?')
            ->assertJsonPath('messages.3.content', 'You can review them in the contributions report.')
            ->assertJsonPath('messages.4.content', 'And how is the app doing?')
            ->assertJsonPath('messages.5.content', 'I’m here and ready to help.');

        $otherUserToken = JwtService::encode(JwtService::buildPayload(
            '22222222-2222-4222-8222-222222222222',
            'other-user@welfare.local',
            ['treasurer']
        ));
        $this->withToken($otherUserToken)
            ->postJson('/api/functions/v1/ai-assistant', [
                'query' => 'Continue this conversation.',
                'conversation_id' => $conversationId,
                'context_type' => 'admin_assistant',
            ])
            ->assertNotFound();
    }
}
