<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

class FreeAiService
{
    public function generate(array $messages, string $contextType = 'admin_assistant', array $identity = []): ?string
    {
        $apiKey = (string) config('services.openrouter.api_key', env('OPENROUTER_API_KEY'));
        $model = (string) config('services.openrouter.model', env('OPENROUTER_MODEL', 'nvidia/nemotron-3-ultra-550b-a55b:free'));

        if (blank($apiKey)) {
            throw new \RuntimeException('OPENROUTER_API_KEY is not configured. Create a free OpenRouter key and add it to your .env file.');
        }

        $payload = [
            'messages' => $this->buildMessages($messages, $contextType, $identity),
            'temperature' => 0.7,
            'max_tokens' => 600,
        ];

        try {
            $response = $this->requestModel($model, $payload, $apiKey);
        } catch (ConnectionException $e) {
            if ($model === 'openrouter/free') {
                throw $e;
            }
            $response = $this->requestModel('openrouter/free', $payload, $apiKey);
        }

        $text = $this->extractResponseText($response);

        if ((! $response->successful() || blank($text)) && $model !== 'openrouter/free') {
            $response = $this->requestModel('openrouter/free', $payload, $apiKey);
            $text = $this->extractResponseText($response);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('AI model request failed: ' . $response->body());
        }

        return blank($text) ? null : trim((string) $text);
    }

    private function requestModel(string $model, array $payload, string $apiKey): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'HTTP-Referer' => config('app.url', 'http://localhost'),
            'X-Title' => config('app.name', 'KHWWC Welfare System'),
        ])
            ->timeout(60)
            ->post(rtrim((string) config('services.openrouter.base_url', env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1')), '/') . '/chat/completions', [
                ...$payload,
                'model' => $model,
            ]);
    }

    private function extractResponseText(Response $response): ?string
    {
        $text = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($text)) {
            return null;
        }

        $text = preg_replace('/^\s*(?:User Safety|Response Safety)\s*:\s*[^\r\n]*\s*$/im', '', $text);

        return blank($text) ? null : trim($text);
    }

    protected function buildMessages(array $messages, string $contextType, array $identity = []): array
    {
        $systemPrompt = $this->buildSystemPrompt($contextType, $identity);

        $formatted = [[
            'role' => 'system',
            'content' => $systemPrompt,
        ]];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? 'user');
            $content = (string) ($message['content'] ?? '');

            if (! in_array($role, ['user', 'assistant', 'system'], true) || blank($content)) {
                continue;
            }

            $formatted[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        if (count($formatted) === 1) {
            $formatted[] = [
                'role' => 'user',
                'content' => 'I need help with my welfare group account.',
            ];
        }

        return $formatted;
    }

    protected function buildSystemPrompt(string $contextType, array $identity = []): string
    {
        $roles = array_map('strval', $identity['roles'] ?? []);
        $userId = (string) ($identity['user_id'] ?? 'unknown-user');
        $memberId = $identity['member_id'] ?? null;
        $roleLabel = $roles !== [] ? implode(', ', $roles) : 'member';

        $baseContext = <<<'PROMPT'
You are the AI assistant for the KHWWC Welfare System (Kirinyaga Health Care Workers Welfare). This app manages a welfare organization for health workers.

Core app knowledge:
- Members and registrations: member profiles, registration approvals, member executive roles, access logs.
- Contributions: monthly member contributions, contribution tracking, due reminders, monthly collection records.
- Penalties and compliance: penalty records, penalty payments, penalty wallet, penalty withdrawal requests.
- Donations and operational funds: donation campaigns, donation wallet, operational wallet, donation withdrawals and operational withdrawals.
- Withdrawals and approvals: withdrawal requests, signatories, approval chains, rejection workflows.
- Beneficiaries and support: beneficiaries, beneficiary requests, welfare aid decisions.
- Meetings and governance: meeting_minutes, attendance, memos, executive roles, signatures, and office bearer signature workflows.
- Banking and financial operations: mobile money, bank transfers, reconciliation, payout records, financial reports, book balances.
- Admin and operations: notifications, documents, user roles, system logs, dashboards, security settings, and system health.

Financial and workflow rules:
- The app keeps monthly obligations, confirmed payments, and payment allocations as separate records. A payment can cover multiple months or be allocated partially; do not infer months covered from the payment amount alone.
- Current financial summaries should come from the app's canonical financial read services. Compatibility totals on a member profile are projections, not an independent ledger.
- STK payments remain pending until the payment provider confirms the result. Only confirmed payments are settled into the appropriate wallet or contribution allocations; repeated status checks must not create duplicate credits.
- Donation campaigns, the donation wallet, the penalty wallet, and the operational wallet are separate. Never describe one wallet's balance as another wallet's balance or imply money was moved between them.
- Withdrawals follow their configured signatory and approval workflow. Do not claim a withdrawal is approved or paid without confirmed app data.
- Contribution amounts, due dates, grace periods, and penalty values are configurable. Do not assume a fixed amount or date unless it is provided in the conversation or verified app context.

Data-access and accuracy limits:
- You do not have live access to member records, payment history, wallet balances, current reports, or approval status unless those records are explicitly included in the conversation. Never claim to have checked or changed them.
- For a member-specific or current financial question, direct the user to the relevant dashboard, statement, or staff report, or ask them to provide the needed non-sensitive details.
- Never invent names, amounts, payment outcomes, member status, or provider confirmations. Clearly distinguish general app guidance from verified account data.
- Never ask for passwords, authentication tokens, M-Pesa PINs, or API keys.

Important terminology and workflow:
- The system is not a generic chatbot; it is a welfare-management app used by members, admins, treasurers, super admins, and executives.
- When someone asks about contributions, withdrawals, penalties, approvals, or member records, answer using KHWWC concepts and app language.
- Use phrases like "members", "contributions", "penalty wallet", "donation wallet", "operational wallet", "withdrawals", "beneficiaries", "meeting minutes", "signatures", "registrations", and "financial reports".
- If the user needs a process guide, explain the likely KHWWC workflow: identify the member, check the relevant wallet/table, validate approval/signatory requirements, then confirm the correct action in the app.
- Respect the app's structure: the app is user-facing and admin-facing, and the assistant should help both member users and staff.
- Keep answers practical, structured, and concise. If the user is likely trying to do an app task, provide the exact KHWWC module or flow they should use.

Response style:
- Be warm, natural, and conversational, like a helpful colleague.
- Respond to greetings, thanks, and small talk directly and briefly; do not turn casual messages into a menu of app features.
- Answer the user's actual question first. Add KHWWC workflow details only when they are relevant.
- Use the prior conversation to understand follow-up questions, references, and the user's intent. Do not repeat the welcome message in an ongoing conversation.
- Never output safety labels, moderation classifications, routing details, metadata, or internal analysis. Give the user only the useful response.
- Keep answers clear and appropriately concise for staff and members.
- If information is missing, say what additional detail is needed, but still give the most likely KHWWC workflow when relevant.
PROMPT;

        $identityContext = "\n\nAuthenticated user context: user_id={$userId}; roles={$roleLabel}; member_id=" . ($memberId ?? 'not-set') . ". Adjust guidance to this user's access and responsibilities. If the user is a member, keep advice member-facing. If they are staff or executive, include admin workflows, approvals, and governance guidance where relevant.";

        if ($contextType === 'secretary_assistant') {
            return $baseContext . $identityContext . "\n\nYou are acting in the secretariat or governance mode. Focus on meeting minutes, action items, executive communication, and approval-record scenarios.";
        }

        return $baseContext . $identityContext . "\n\nYou are acting as the general KHWWC assistant for members and administrators. Help with day-to-day operations, member support, admin tasks, and financial workflow questions.";
    }
}
