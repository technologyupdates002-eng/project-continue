<?php

namespace App\Services;

use App\Exceptions\JwtException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use RuntimeException;

/**
 * HS256 JWT service — exact port of the legacy Jwt.php behaviour.
 *
 * Token claims match the legacy system so tokens issued by the old backend
 * remain valid in Laravel and vice-versa during the migration window.
 */
class JwtService
{
    public const ALGORITHM = 'HS256';

    public static function secret(): string
    {
        $secret = config('jwt.secret');

        if (empty($secret)) {
            throw new RuntimeException('JWT secret is not configured (JWT_SECRET).');
        }

        return $secret;
    }

    /**
     * Encode a payload into an HS256 JWT.
     */
    public static function encode(array $payload): string
    {
        return JWT::encode($payload, self::secret(), self::ALGORITHM);
    }

    /**
     * Decode and verify an HS256 JWT.
     *
     * @return array{iss?:string,sub?:string,email?:string,roles?:array,iat?:int,exp?:int}
     */
    public static function decode(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key(self::secret(), self::ALGORITHM));

            return (array) $decoded;
        } catch (ExpiredException $e) {
            throw new JwtException('Token expired.', 401, $e);
        } catch (SignatureInvalidException $e) {
            throw new JwtException('Invalid token signature.', 401, $e);
        } catch (BeforeValidException $e) {
            throw new JwtException('Token not valid yet.', 401, $e);
        } catch (\Throwable $e) {
            throw new JwtException('Invalid token.', 401, $e);
        }
    }

    /**
     * Verify a token is valid without returning the payload.
     */
    public static function verify(string $token): bool
    {
        try {
            self::decode($token);

            return true;
        } catch (JwtException) {
            return false;
        }
    }

    /**
     * Build the standard JWT payload used across the application.
     */
    public static function buildPayload(string $sub, string $email, array $roles, ?int $ttl = null, bool $mustChangePassword = false): array
    {
        $ttl ??= (int) config('jwt.ttl', 3600);

        $now = time();

        return [
            'iss' => config('jwt.iss', config('app.url')),
            'iat' => $now,
            'exp' => $now + $ttl,
            'sub' => $sub,
            'email' => $email,
            'roles' => $roles,
            'must_change_password' => $mustChangePassword,
        ];
    }
}
