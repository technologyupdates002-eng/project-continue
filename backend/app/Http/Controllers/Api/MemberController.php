<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMemberRequest;
use App\Models\AuthUser;
use App\Models\Member;
use App\Models\MemberRegistration;
use App\Models\UserRole;
use App\Services\AuthService;
use App\Services\SmsService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberController extends Controller
{
    public function __construct(private SmsService $sms) {}

    public function createMember(CreateMemberRequest $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdminAccess($identity);

        $data = $request->validated();
        $normalizedPhone = AuthService::normalizePhone($data['phone_number']);

        $existingMember = Member::where('phone', $normalizedPhone)->first();
        if ($existingMember) {
            throw new HttpException(422, 'duplicate_phone', 'A member with this phone number already exists');
        }

        $email = $data['email'] ?? AuthService::emailForPhone($normalizedPhone);
        $existingUser = AuthUser::where('email', strtolower($email))->first();
        if ($existingUser) {
            throw new HttpException(422, 'duplicate_email', 'A user with this email already exists');
        }

        $tempPassword = $this->generateTempPassword();

        $userId = Uuid::v4();

        // Generate a human-friendly member code (initials) and ensure uniqueness.
        $memberCode = $this->generateMemberCode($data['full_name']);

        DB::transaction(function () use ($userId, $memberCode, $data, $normalizedPhone, $email, $tempPassword) {
            AuthUser::create([
                'id' => $userId,
                'email' => strtolower($email),
                'encrypted_password' => AuthService::hashPassword($tempPassword),
                'is_banned' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Member::create([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'name' => $data['full_name'],
                'phone' => $normalizedPhone,
                // Persist the display member code (e.g. JD, JD-002) in `member_id`.
                'member_id' => $memberCode,
                'status' => 'active',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            UserRole::create([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'role' => 'member',
                'is_active' => 1,
            ]);
        });

        $registration = MemberRegistration::where('phone_number', $normalizedPhone)->first();
        if ($registration) {
            $registration->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $identity['user_id'],
                'payment_status' => 'paid',
                'updated_at' => now(),
            ]);
        }

        try {
            $message = "Welcome to KHWWC! Your account has been created. Login with your phone number. Temporary password: {$tempPassword}. Please change it after login.";
            $this->sms->send($normalizedPhone, $message, 'member_created', $userId);
        } catch (\Throwable $e) {
            Log::warning('Failed to send member creation SMS', [
                'member_id' => $memberCode,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'member_id' => $memberCode,
                'user_id' => $userId,
                'phone' => $normalizedPhone,
                'email' => strtolower($email),
                'temporary_password' => $tempPassword,
            ],
            'message' => 'Member created successfully',
        ], 201);
    }

    /**
     * Generate a persistent, display-friendly member code from a full name.
     * Uses first and last name initials, uppercased, removes non-alphanumerics.
     * On collision appends a suffix like `-002`, `-003`.
     */
    private function generateMemberCode(string $fullName): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $fullName));
        $parts = explode(' ', $name);
        $first = $parts[0] ?? '';
        $last = end($parts) ?: '';

        $initials = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
        $initials = preg_replace('/[^A-Z0-9]/', '', $initials);
        if ($initials === '') {
            $initials = 'M';
        }

        $base = $initials;
        $candidate = $base;
        $i = 1;
        // Start suffix from 002 when collision occurs
        while (DB::table('members')->where('member_id', $candidate)->exists()) {
            $i++;
            $candidate = sprintf('%s-%03d', $base, $i);
            if ($i > 9999) break; // safety
        }

        return $candidate;
    }

    private function assertAdminAccess(array $identity): void
    {
        if (!AuthService::isAdmin($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Admin access required');
        }
    }

    private function generateTempPassword(): string
    {
        return substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
    }
}
