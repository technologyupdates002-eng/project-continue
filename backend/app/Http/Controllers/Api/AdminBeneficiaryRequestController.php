<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminBeneficiaryRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->adminUserId($request);
        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'reviewed'], true)) {
            throw new HttpException(422, 'invalid_status', 'Status must be pending or reviewed.');
        }

        $query = DB::table('beneficiary_requests')
            ->join('members', 'members.id', '=', 'beneficiary_requests.member_id')
            ->select([
                'beneficiary_requests.*',
                'members.name as member_name',
                'members.phone as member_phone',
                'members.member_id as member_number',
            ])
            ->orderByDesc('beneficiary_requests.created_at');

        if ($status === 'pending') {
            $query->where('beneficiary_requests.status', 'pending');
        } else {
            $query->whereIn('beneficiary_requests.status', ['approved', 'rejected'])->limit(50);
        }

        $requests = $query->get()->map(function (object $row): array {
            $request = (array) $row;
            $request['member'] = [
                'name' => $row->member_name,
                'phone' => $row->member_phone,
                'member_id' => $row->member_number,
            ];
            unset($request['member_name'], $request['member_phone'], $request['member_number']);

            return $request;
        });

        return response()->json($requests);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $reviewerId = $this->adminUserId($request);
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $reviewed = DB::transaction(function () use ($id, $reviewerId, $data): array {
            $beneficiaryRequest = DB::table('beneficiary_requests')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (! $beneficiaryRequest) {
                throw new HttpException(404, 'not_found', 'Beneficiary request not found.');
            }
            if ($beneficiaryRequest->status !== 'pending') {
                throw new HttpException(409, 'request_already_reviewed', 'This beneficiary request has already been reviewed.');
            }

            if ($data['status'] === 'approved') {
                if ($beneficiaryRequest->request_type === 'add') {
                    if (trim((string) $beneficiaryRequest->beneficiary_name) === '') {
                        throw new HttpException(409, 'invalid_request', 'The beneficiary request does not include a beneficiary name.');
                    }
                    DB::table('beneficiaries')->insert([
                        'id' => Uuid::v4(),
                        'member_id' => $beneficiaryRequest->member_id,
                        'name' => $beneficiaryRequest->beneficiary_name,
                        'relationship' => $beneficiaryRequest->beneficiary_relationship ?: 'spouse',
                        'phone' => $beneficiaryRequest->beneficiary_phone,
                        'id_number' => $beneficiaryRequest->beneficiary_id_number,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($beneficiaryRequest->request_type === 'remove' && $beneficiaryRequest->beneficiary_id) {
                    $deleted = DB::table('beneficiaries')
                        ->where('id', $beneficiaryRequest->beneficiary_id)
                        ->where('member_id', $beneficiaryRequest->member_id)
                        ->delete();
                    if ($deleted !== 1) {
                        throw new HttpException(409, 'beneficiary_conflict', 'The beneficiary no longer belongs to this member or has already been removed.');
                    }
                } else {
                    throw new HttpException(409, 'invalid_request', 'The beneficiary request cannot be approved.');
                }
            }

            DB::table('beneficiary_requests')
                ->where('id', $id)
                ->update([
                    'status' => $data['status'],
                    'admin_notes' => $data['admin_notes'] ?? null,
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'id' => $id,
                'status' => $data['status'],
            ];
        });

        return response()->json(['success' => true, 'data' => $reviewed]);
    }

    private function adminUserId(Request $request): string
    {
        $identity = $request->attributes->get('identity', []);
        $roles = $identity['roles'] ?? [];
        if (! in_array('admin', $roles, true) && ! in_array('super_admin', $roles, true)) {
            throw new HttpException(403, 'forbidden', 'Admin only.');
        }

        $userId = (string) ($identity['user_id'] ?? '');
        if ($userId === '') {
            throw new HttpException(401, 'unauthenticated', 'Authentication required.');
        }

        return $userId;
    }
}
