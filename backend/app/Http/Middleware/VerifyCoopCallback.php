<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyCoopCallback
{
    public function handle(Request $request, Closure $next)
    {
        $verifySignature = config('coop_bank.verify_callback_signature', false);

        if ($verifySignature) {
            $signature = $request->header(config('coop_bank.callback_signature_header', 'X-Co-op-Signature'));
            $secret = config('coop_bank.callback_signature_secret');

            if (!$signature || !$secret) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $payload = $request->getContent();
            $expected = hash_hmac('sha256', $payload, $secret);

            if (!hash_equals($expected, $signature)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $webhookIps = config('coop_bank.webhook_ips');
        if ($webhookIps) {
            $ips = is_array($webhookIps) ? $webhookIps : explode(',', $webhookIps);
            $clientIp = $request->ip();

            if (!in_array($clientIp, array_map('trim', $ips))) {
                return response()->json(['error' => 'IP not allowed'], 403);
            }
        }

        return $next($request);
    }
}
