<?php

namespace Tests\Unit;

use App\Exceptions\JwtException;
use App\Services\JwtService;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    public function test_encode_and_decode_round_trip_preserves_claims(): void
    {
        $payload = JwtService::buildPayload(
            'user-uuid',
            '254700000000@welfare.local',
            ['member', 'admin']
        );

        $token = JwtService::encode($payload);
        $decoded = JwtService::decode($token);

        $this->assertSame('user-uuid', $decoded['sub']);
        $this->assertSame('254700000000@welfare.local', $decoded['email']);
        $this->assertSame(['member', 'admin'], $decoded['roles']);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
        $this->assertSame(config('jwt.iss'), $decoded['iss']);
    }

    public function test_token_expiry_is_respected(): void
    {
        $payload = JwtService::buildPayload('u', 'e', ['member'], -10);
        $token = JwtService::encode($payload);

        $this->expectException(JwtException::class);
        JwtService::decode($token);
    }

    public function test_verify_returns_false_for_garbage(): void
    {
        $this->assertFalse(JwtService::verify('not-a-real-token'));
    }

    public function test_verify_returns_true_for_valid_token(): void
    {
        $token = JwtService::encode(JwtService::buildPayload('u', 'e', ['member']));
        $this->assertTrue(JwtService::verify($token));
    }
}
