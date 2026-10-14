<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BankStatementImportRequest;
use App\Http\Requests\BulkImportRequest;
use App\Services\AuthService;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    public function __construct(private ImportService $imports) {}

    public function importBankStatement(BankStatementImportRequest $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffAccess($identity);

        $data = $request->validated();
        $rows = $data['rows'];
        $sourceName = $data['source_name'] ?? 'bank_statement';
        $fileName = $data['file_name'] ?? '';

        $result = $this->imports->importMemberStatement($rows, $sourceName, $fileName, $identity['user_id']);

        return response()->json([
            'data' => $result,
            'message' => 'Bank statement import completed',
        ]);
    }

    public function bulkImport(BulkImportRequest $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffAccess($identity);

        $data = $request->validated();
        $rows = $data['rows'];
        $sourceName = $data['source_name'] ?? 'bulk_member_import';
        $fileName = $data['file_name'] ?? '';

        $result = $this->imports->importBeneficiaries($rows, $sourceName, $fileName, $identity['user_id']);

        return response()->json([
            'data' => $result,
            'message' => 'Bulk import completed',
        ]);
    }

    private function assertStaffAccess(array $identity): void
    {
        if (! AuthService::isStaff($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Staff access required');
        }
    }
}
