<?php

namespace App\Services;

use App\Services\Statement\ProcessOutcome;
use App\Services\Statement\StatementContext;
use App\Services\Statement\StatementMode;
use App\Services\Statement\StatementProcessor;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

class ImportService
{
    public function __construct(
        ?StatementProcessor $processor = null,
    ) {
        $this->processor = $processor ?? app(StatementProcessor::class);
    }

    public function importMemberStatement(array $rows, string $sourceName, string $fileName, ?string $createdBy): array
    {
        $batchId = Uuid::v4();
        $totalRows = count($rows);

        $credits = 0;
        $debits = 0;
        $matchedExisting = 0;
        $newMembers = 0;
        $contributionsCreated = 0;
        $contributionsUpdated = 0;
        $duplicatesSkipped = 0;
        $unmatched = 0;
        $invalidPhones = 0;
        $totalAmount = 0.0;
        $failures = [];

        $processedMemberIds = [];

        DB::table('member_import_batches')->insert([
            'id' => $batchId,
            'source_name' => $sourceName,
            'file_name' => $fileName,
            'statement_period' => null,
            'total_rows' => $totalRows,
            'success_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'status' => 'processing',
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);

        $ctx = new StatementContext(
            batchId: $batchId,
            accountNumber: null,
            syncUserId: $createdBy,
        );

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $rowId = Uuid::v4();
            try {
                $outcome = $this->processor->process(
                    is_array($row) ? $row : (array) $row,
                    StatementMode::TREASURER_STATEMENT_IMPORT,
                    $ctx,
                );

                $credits += $outcome->credits;
                $debits += $outcome->debits;
                $matchedExisting += $outcome->matchedExisting;
                $newMembers += $outcome->newMembers;
                $contributionsCreated += $outcome->contributionsCreated;
                $contributionsUpdated += $outcome->contributionsUpdated;
                $duplicatesSkipped += $outcome->duplicatesSkipped;
                $unmatched += $outcome->unmatched;
                $invalidPhones += $outcome->invalidPhones;
                $totalAmount += $outcome->totalAmount;

                if ($outcome->memberId) {
                    $processedMemberIds[] = $outcome->memberId;
                }

                $this->recordImportRow($batchId, $rowId, $rowNumber, $row, $outcome);
            } catch (\Throwable $e) {
                $failures[] = ['row' => $rowNumber, 'reason' => $e->getMessage()];
                \Log::error('Import row failed', [
                    'batch_id' => $batchId,
                    'row_index' => $index,
                    'error' => $e->getMessage(),
                ]);
                $this->recordImportRow($batchId, $rowId, $rowNumber, $row, null, $e->getMessage());
            }
        }

        $transactionsImported = max(0, $credits - $duplicatesSkipped);

        DB::table('member_import_batches')
            ->where('id', $batchId)
            ->update([
                'success_count' => $transactionsImported,
                'skipped_count' => $duplicatesSkipped + $invalidPhones + $unmatched,
                'failed_count' => count($failures),
                'status' => 'completed',
                'summary' => json_encode([
                    'credits' => $credits,
                    'debits' => $debits,
                    'matched_existing' => $matchedExisting,
                    'new_members' => $newMembers,
                    'contributions_created' => $contributionsCreated,
                    'contributions_updated' => $contributionsUpdated,
                    'duplicates_skipped' => $duplicatesSkipped,
                    'unmatched' => $unmatched,
                    'invalid_phones' => $invalidPhones,
                    'total_amount' => $totalAmount,
                ]),
            ]);

        DB::table('statement_processing_audit')->insert([
            'id' => Uuid::v4(),
            'mode' => StatementMode::TREASURER_STATEMENT_IMPORT->value,
            'batch_id' => $batchId,
            'source_name' => $sourceName,
            'file_name' => $fileName,
            'total_transactions' => $totalRows,
            'new_members' => $newMembers,
            'members_matched' => $matchedExisting,
            'contributions_created' => $contributionsCreated,
            'contributions_updated' => $contributionsUpdated,
            'duplicates_skipped' => $duplicatesSkipped,
            'unmatched' => $unmatched,
            'invalid_phones' => $invalidPhones,
            'total_amount' => $totalAmount,
            'created_by' => $createdBy,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'batch_id' => $batchId,
            'total' => $totalRows,
            'credits' => $credits,
            'debits' => $debits,
            'matched_existing' => $matchedExisting,
            'new_members' => $newMembers,
            'members_created' => $newMembers,
            'members_matched' => $matchedExisting,
            'members_updated' => $matchedExisting,
            'contributions_created' => $contributionsCreated,
            'contributions_updated' => $contributionsUpdated,
            'duplicates_skipped' => $duplicatesSkipped,
            'duplicates_prevented' => $duplicatesSkipped,
            'unmatched' => $unmatched,
            'invalid_transactions' => $invalidPhones,
            'invalid_phones' => $invalidPhones,
            'total_amount' => $totalAmount,
            'transactions_imported' => $transactionsImported,
            'valid_transactions' => $transactionsImported,
            'failures' => $failures,
            'status' => 'completed',
            'mode' => StatementMode::TREASURER_STATEMENT_IMPORT->value,
        ];
    }

    private function recordImportRow(string $batchId, string $rowId, int $rowNumber, $raw, ?ProcessOutcome $outcome, ?string $failureReason = null): void
    {
        $transDate = $raw['trans_date'] ?? $raw['date'] ?? null;
        $valueDate = $raw['value_date'] ?? $raw['valueDate'] ?? $transDate;
        $transactionDetails = $raw['transaction_details'] ?? $raw['rawDetails'] ?? null;
        $referenceNo = $raw['reference_no'] ?? $raw['reference'] ?? null;
        $credit = (float) ($raw['credit'] ?? $raw['amount'] ?? 0);
        $debit = (float) ($raw['debit'] ?? 0);
        $bookBalance = (float) ($raw['book_balance'] ?? 0);

        $isNew = $outcome && $outcome->newMembers > 0;
        $isMatched = $outcome && $outcome->wasMatched;
        $isDuplicate = $outcome && $outcome->reason === 'duplicate';
        $noPhone = $outcome && $outcome->reason === 'no_phone_extracted';
        $debitOnly = $outcome && $outcome->reason === 'debit_only';
        $noCredit = $outcome && $outcome->reason === 'no_credit';

        $status = 'pending';
        $matchStatus = 'pending';
        $message = $failureReason ?? ($outcome->reason ?? 'processed');

        if ($failureReason) {
            $status = 'failed';
            $matchStatus = 'failed';
        } elseif ($noPhone) {
            $status = 'skipped';
            $matchStatus = 'no_phone';
            $message = 'No phone number extracted';
        } elseif ($debitOnly) {
            $status = 'unmatched';
            $matchStatus = 'unmatched';
            $message = 'Debit transaction — no contribution';
        } elseif ($noCredit) {
            $status = 'unmatched';
            $matchStatus = 'unmatched';
            $message = 'No credit amount';
        } elseif ($isDuplicate) {
            $status = 'skipped';
            $matchStatus = 'duplicate';
            $message = 'Duplicate bank transaction reference';
        } elseif ($isNew) {
            $status = 'new_member';
            $matchStatus = 'matched';
            $message = 'New member created and contribution recorded';
        } elseif ($isMatched) {
            $status = 'matched';
            $matchStatus = 'matched';
            $message = 'Matched existing member, contribution recorded';
        }

        DB::table('member_import_rows')->insert([
            'id' => $rowId,
            'batch_id' => $batchId,
            'row_number' => $rowNumber,
            'trans_date' => $transDate,
            'value_date' => $valueDate,
            'transaction_details' => $transactionDetails,
            'reference_no' => $referenceNo,
            'credit' => $credit,
            'debit' => $debit,
            'book_balance' => $bookBalance,
            'extracted_name' => $outcome?->extractedName,
            'extracted_phone' => $outcome?->extractedPhone,
            'normalized_phone' => $outcome?->normalizedPhone,
            'member_name' => $outcome?->extractedName,
            'phone' => $outcome?->normalizedPhone,
            'member_id_ref' => $outcome?->memberId,
            'status' => $status,
            'match_status' => $matchStatus,
            'message' => $message,
            'raw_payload' => json_encode($raw),
            'contribution_amount' => $credit,
            'contribution_month' => $transDate && !$failureReason ? (int) date('n', strtotime((string) $transDate)) : null,
            'contribution_year' => $transDate && !$failureReason ? (int) date('Y', strtotime((string) $transDate)) : null,
            'contribution_status' => $isMatched ? ($outcome->contributionsCreated ? 'paid' : 'existing') : null,
            'contribution_paid_date' => $isMatched ? $transDate : null,
            'default_password' => $isNew ? 'Member2026' : null,
            'auth_user_created' => $isNew ? 1 : 0,
            'member_dashboard_created' => $isNew ? 1 : 0,
            'created_at' => now(),
        ]);
    }

    public function importBeneficiaries(array $rows, string $sourceName, string $fileName, ?string $createdBy): array
    {
        $batchId = Uuid::v4();
        $successCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        DB::table('beneficiary_import_batches')->insert([
            'id' => $batchId,
            'source_name' => $sourceName,
            'file_name' => $fileName,
            'total_rows' => count($rows),
            'success_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'status' => 'processing',
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);

        foreach ($rows as $index => $row) {
            try {
                $memberId = $row['member_id'] ?? $row['MemberId'] ?? null;
                $name = $row['name'] ?? $row['Name'] ?? null;
                $relationship = $row['relationship'] ?? $row['Relationship'] ?? null;
                $allocation = $this->parseAmount($row['allocation'] ?? $row['Allocation'] ?? 0);
                $phone = $row['phone'] ?? $row['Phone'] ?? null;

                if (! $memberId || ! $name) {
                    $skippedCount++;
                    DB::table('beneficiary_import_rows')->insert([
                        'id' => Uuid::v4(),
                        'batch_id' => $batchId,
                        'row_number' => $index + 1,
                        'member_id' => $memberId,
                        'name' => $name,
                        'relationship' => $relationship,
                        'allocation' => $allocation,
                        'phone' => $phone,
                        'status' => 'skipped',
                        'error_message' => 'Missing required fields',
                        'raw_row' => json_encode($row),
                        'created_at' => now(),
                    ]);

                    continue;
                }

                $member = DB::table('members')->where('id', $memberId)->first();
                if (! $member) {
                    $skippedCount++;
                    DB::table('beneficiary_import_rows')->insert([
                        'id' => Uuid::v4(),
                        'batch_id' => $batchId,
                        'row_number' => $index + 1,
                        'member_id' => $memberId,
                        'name' => $name,
                        'relationship' => $relationship,
                        'allocation' => $allocation,
                        'phone' => $phone,
                        'status' => 'skipped',
                        'error_message' => 'Member not found',
                        'raw_row' => json_encode($row),
                        'created_at' => now(),
                    ]);

                    continue;
                }

                DB::table('beneficiaries')->insert([
                    'id' => Uuid::v4(),
                    'member_id' => $memberId,
                    'name' => $name,
                    'relationship' => $relationship,
                    'phone' => $phone,
                    'id_number' => $row['id_number'] ?? $row['IDNumber'] ?? $row['IdNumber'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('beneficiary_import_rows')->insert([
                    'id' => Uuid::v4(),
                    'batch_id' => $batchId,
                    'row_number' => $index + 1,
                    'member_id' => $memberId,
                    'name' => $name,
                    'relationship' => $relationship,
                    'allocation' => $allocation,
                    'phone' => $phone,
                    'status' => 'success',
                    'error_message' => null,
                    'raw_row' => json_encode($row),
                    'created_at' => now(),
                ]);

                $successCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                \Log::error('Beneficiary import row failed', [
                    'batch_id' => $batchId,
                    'row_index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::table('beneficiary_import_batches')
            ->where('id', $batchId)
            ->update([
                'success_count' => $successCount,
                'skipped_count' => $skippedCount,
                'failed_count' => $failedCount,
                'status' => 'completed',
                'updated_at' => now(),
            ]);

        return [
            'batch_id' => $batchId,
            'total_rows' => count($rows),
            'success_count' => $successCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'status' => 'completed',
        ];
    }

    private function parseAmount($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        $cleaned = preg_replace('/[^0-9.-]/', '', (string) $value);

        return (float) ($cleaned ?: 0);
    }
}