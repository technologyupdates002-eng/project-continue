<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRegistrationConfigRequest;
use App\Http\Requests\ApproveRegistrationRequest;
use App\Http\Requests\RejectRegistrationRequest;
use App\Models\MemberRegistration;
use App\Models\RegistrationConfig;
use App\Services\AuthService;
use App\Services\PolicyService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRegistrationController extends Controller
{
    public function getConfig(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdminAccess($identity);

        $config = RegistrationConfig::first();

        if (!$config) {
            $config = RegistrationConfig::create([
                'id' => Uuid::v4(),
                'retiring_date' => null,
                'registration_fee' => 0,
                'active' => true,
                'show_on_login' => true,
                'auto_approve' => false,
                'updated_by' => $identity['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => $config,
        ]);
    }

    public function updateConfig(AdminRegistrationConfigRequest $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdminAccess($identity);

        $config = RegistrationConfig::first();

        $data = $request->validated();
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        if ($config) {
            $config->update($data);
        } else {
            $data['id'] = Uuid::v4();
            $data['created_at'] = now();
            $config = RegistrationConfig::create($data);
        }

        return response()->json([
            'data' => $config,
            'message' => 'Registration configuration updated successfully',
        ]);
    }

    public function listRegistrations(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffAccess($identity);

        $query = MemberRegistration::query();

        $status = $request->query('status');
        if ($status) {
            $query->where('status', $status);
        }

        $paymentStatus = $request->query('payment_status');
        if ($paymentStatus) {
            $query->where('payment_status', $paymentStatus);
        }

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $registrations = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $registrations->items(),
            'meta' => [
                'current_page' => $registrations->currentPage(),
                'last_page' => $registrations->lastPage(),
                'per_page' => $registrations->perPage(),
                'total' => $registrations->total(),
            ],
        ]);
    }

    public function getRegistration(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffAccess($identity);

        $registration = MemberRegistration::find($id);

        if (!$registration) {
            throw new HttpException(404, 'not_found', 'Registration not found');
        }

        return response()->json([
            'data' => $registration,
        ]);
    }

    public function approveRegistration(ApproveRegistrationRequest $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdminAccess($identity);

        $registration = MemberRegistration::find($id);

        if (!$registration) {
            throw new HttpException(404, 'not_found', 'Registration not found');
        }

        if ($registration->status === 'approved') {
            throw new HttpException(422, 'already_approved', 'Registration is already approved');
        }

        $registration->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $identity['user_id'],
            'approval_notes' => $request->input('notes'),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => $registration->fresh(),
            'message' => 'Registration approved successfully',
        ]);
    }

    public function rejectRegistration(RejectRegistrationRequest $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertAdminAccess($identity);

        $registration = MemberRegistration::find($id);

        if (!$registration) {
            throw new HttpException(404, 'not_found', 'Registration not found');
        }

        if ($registration->status === 'rejected') {
            throw new HttpException(422, 'already_rejected', 'Registration is already rejected');
        }

        $registration->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason'),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => $registration->fresh(),
            'message' => 'Registration rejected',
        ]);
    }

    private function assertAdminAccess(array $identity): void
    {
        if (!AuthService::isAdmin($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Admin access required');
        }
    }

    private function assertStaffAccess(array $identity): void
    {
        if (!AuthService::isStaff($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Staff access required');
        }
    }
}
