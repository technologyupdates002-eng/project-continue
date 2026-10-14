<?php

use App\Http\Controllers\Api\AdminRegistrationController;
use App\Http\Controllers\Api\AdminBeneficiaryRequestController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\B2cTransferController;
use App\Http\Controllers\Api\BankBalanceController;
use App\Http\Controllers\Api\BankStatementImportHistoryController;
use App\Http\Controllers\Api\BulkSmsController;
use App\Http\Controllers\Api\ContributionAdjustmentController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentAdminController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MeetingMinutesController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberRegistrationController;
use App\Http\Controllers\Api\MemberStatsController;
use App\Http\Controllers\Api\OperationalTopupController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\SuperAdminStatsController;
use App\Http\Controllers\Api\WebsitePublicProfileController;
use App\Http\Controllers\Api\WebsiteContactController;
use App\Http\Controllers\Api\WebsiteContentController;
use App\Http\Controllers\Api\WebsiteCmsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RestApiController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| KHWWC API Routes
|--------------------------------------------------------------------------
| The Laravel app is mounted under /api in the same deployment model.
*/

Route::get('/health', [HealthController::class, 'health']);
Route::get('/system/info', [HealthController::class, 'systemInfo']);

Route::prefix('/auth/v1')->group(function () {
    Route::post('/token', [AuthController::class, 'token'])->middleware('throttle:login');
    Route::post('/signup', [AuthController::class, 'signup'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/heartbeat', [AuthController::class, 'heartbeat'])->middleware('auth.jwt', 'throttle:api');
    Route::get('/user', [AuthController::class, 'user'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/password', [AuthController::class, 'changePassword'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/dashboard-pin/reset', [AuthController::class, 'resetDashboardPin'])->middleware('auth.jwt', 'throttle:api');
});

// New Laravel-style auth routes (alias for /auth/v1 routes)
Route::prefix('/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'token'])->middleware('throttle:login');
    Route::post('/signup', [AuthController::class, 'signup'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth.jwt', 'throttle:api');
    Route::get('/me', [AuthController::class, 'user'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/refresh', [AuthController::class, 'token'])->middleware('throttle:login');
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth.jwt', 'throttle:api');
});

Route::get('/admin/password-management/users', [AuthController::class, 'passwordManagementUsers'])
    ->middleware('auth.jwt', 'throttle:api');

Route::get('/welfare_settings', [RestApiController::class, 'publicSettings']);

Route::prefix('/public/website')->group(function () {
    Route::get('/settings', [WebsiteCmsController::class, 'publicSettings']);
    Route::get('/content/{contentType}', [WebsiteContentController::class, 'publicIndex']);
    Route::get('/content/{contentType}/{slug}', [WebsiteContentController::class, 'publicShow']);
    Route::get('/leadership', [WebsitePublicProfileController::class, 'index']);
    Route::get('/people/{slug}', [WebsitePublicProfileController::class, 'show']);
    Route::post('/contact', [WebsiteContactController::class, 'store'])->middleware('throttle:5,1');
});

Route::prefix('/admin/website/contact-messages')->middleware(['auth.jwt', 'admin', 'throttle:api'])->group(function () {
    Route::get('/', [WebsiteContactController::class, 'index']);
    Route::post('/{messageId}/read', [WebsiteContactController::class, 'markRead'])->whereNumber('messageId');
});

Route::get('/admin/website/dashboard', [WebsiteContentController::class, 'adminDashboard'])
    ->middleware(['auth.jwt', 'admin', 'throttle:api']);

Route::get('/admin/bank-statement-imports', [BankStatementImportHistoryController::class, 'index'])
    ->middleware(['auth.jwt', 'admin', 'throttle:api']);

Route::prefix('/admin/website')->middleware(['auth.jwt', 'admin', 'throttle:api'])->group(function () {
    Route::get('/settings/{section}', [WebsiteCmsController::class, 'showSettings']);
    Route::put('/settings/{section}', [WebsiteCmsController::class, 'saveSettings']);
    Route::get('/email-templates', [WebsiteCmsController::class, 'listTemplates']);
    Route::post('/email-templates', [WebsiteCmsController::class, 'saveTemplate']);
    Route::put('/email-templates/{templateKey}', [WebsiteCmsController::class, 'saveTemplate']);
    Route::post('/email/test', [WebsiteCmsController::class, 'testEmail']);
    Route::get('/media', [WebsiteCmsController::class, 'mediaIndex']);
});

Route::prefix('/admin/website/content')->middleware(['auth.jwt', 'admin', 'throttle:api'])->group(function () {
    Route::get('/', [WebsiteContentController::class, 'adminIndex']);
    Route::post('/', [WebsiteContentController::class, 'store']);
    Route::put('/{itemId}', [WebsiteContentController::class, 'update'])->whereNumber('itemId');
    Route::post('/{itemId}/archive', [WebsiteContentController::class, 'archive'])->whereNumber('itemId');
});

Route::prefix('/admin/website/people')->middleware(['auth.jwt', 'admin', 'throttle:api'])->group(function () {
    Route::get('/', [WebsitePublicProfileController::class, 'adminIndex']);
    Route::get('/leadership-members', [WebsitePublicProfileController::class, 'leadershipMembers']);
    Route::get('/search-members', [WebsitePublicProfileController::class, 'searchMembers']);
    Route::post('/', [WebsitePublicProfileController::class, 'store']);
    Route::put('/{profileId}', [WebsitePublicProfileController::class, 'update'])->whereNumber('profileId');
    Route::patch('/{profileId}', [WebsitePublicProfileController::class, 'update'])->whereNumber('profileId');
    Route::post('/{profileId}/toggle-publish', [WebsitePublicProfileController::class, 'togglePublish'])->whereNumber('profileId');
    Route::post('/{profileId}/archive', [WebsitePublicProfileController::class, 'archive'])->whereNumber('profileId');
});

Route::prefix('/rest/v1')->group(function () {
    Route::get('/backups', [App\Http\Controllers\Admin\BackupAdminController::class, 'data'])
        ->middleware('admin');
    Route::post('/{table}/update', [RestApiController::class, 'handleCustomUpdate'])
        ->middleware('auth.jwt', 'view.as.member', 'throttle:api')
        ->where('table', 'withdrawal_signatories|donation_withdrawal_signatories|operational_withdrawal_signatories');
    Route::post('/{table}/update-status', [RestApiController::class, 'handleCustomUpdateStatus'])
        ->middleware('auth.jwt', 'view.as.member', 'throttle:api')
        ->where('table', 'penalty_withdrawals|donation_withdrawals|operational_withdrawals');
    Route::match(['get', 'post', 'patch', 'delete', 'put'], '/{table}', [RestApiController::class, 'handle'])
        ->middleware('auth.jwt', 'view.as.member', 'throttle:api');
});

Route::prefix('/storage/v1')->group(function () {
    Route::get('/object/{bucket}/{path}/signed-url', [StorageController::class, 'signedUrl'])->middleware('auth.jwt', 'throttle:api')->where('path', '.+');
    Route::get('/object/{bucket}/{path}', [StorageController::class, 'download'])->name('storage.download')->where('path', '.+');
    Route::post('/object/{bucket}/{path}', [StorageController::class, 'upload'])->middleware('auth.jwt', 'throttle:api')->where('path', '.+');
    Route::delete('/object/{bucket}/{path}', [StorageController::class, 'delete'])->middleware('auth.jwt', 'throttle:api')->where('path', '.+');
});

Route::middleware(['auth.jwt', 'view.as.member', 'throttle:api'])->group(function () {
    Route::get('/treasurer/contribution-adjustments', [ContributionAdjustmentController::class, 'index']);
    Route::post('/treasurer/contribution-adjustments', [ContributionAdjustmentController::class, 'store']);
    
    // Bank Balance API routes for treasurer
    Route::prefix('/treasurer/bank-balance')->group(function () {
        Route::get('/overview', [BankBalanceController::class, 'overview']);
        Route::get('/withdrawals', [BankBalanceController::class, 'withdrawals']);
        Route::get('/deposits', [BankBalanceController::class, 'deposits']);
        Route::get('/transactions', [BankBalanceController::class, 'transactions']);
    });
    
    Route::get('/me', function (Request $request) {
        return response()->json([
            'identity' => $request->attributes->get('identity'),
        ]);
    });
});

Route::prefix('/payments')->group(function () {
    Route::post('/stk-push', [PaymentController::class, 'stkPush'])->middleware('auth.jwt', 'view.as.member', 'throttle:api');
    Route::post('/stk-callback', [PaymentController::class, 'legacyStkCallback']);
    Route::get('/status/{checkoutRequestId}', [PaymentController::class, 'status'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/bank-sync', [PaymentController::class, 'bankSync'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/reconcile-stk', [PaymentController::class, 'reconcileStk'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/b2c', [PaymentController::class, 'b2cTransfer'])->middleware('auth.jwt', 'throttle:api');
});

Route::prefix('/notifications')->middleware(['auth.jwt', 'view.as.member', 'throttle:api'])->group(function () {
    Route::post('/bulk-sms', [NotificationController::class, 'bulkSms']);
    Route::post('/push', [NotificationController::class, 'push']);
    Route::get('/events/unseen-count', [NotificationController::class, 'unseenEventCount']);
    Route::get('/unread-count', [NotificationController::class, 'unreadNotificationCount']);
    Route::get('/news/unread-count', [NotificationController::class, 'unreadNewsCount']);
});

Route::prefix('/admin/notifications')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/inbox', [AdminNotificationController::class, 'index']);
    Route::post('/read-all', [AdminNotificationController::class, 'markAllRead']);
    Route::post('/{id}/read', [AdminNotificationController::class, 'markRead']);
});

Route::prefix('/admin/beneficiary-requests')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/', [AdminBeneficiaryRequestController::class, 'index']);
    Route::post('/{id}/review', [AdminBeneficiaryRequestController::class, 'review']);
});

Route::prefix('/admin/payouts')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/eligible', [\App\Http\Controllers\Api\MemberPayoutController::class, 'eligible']);
    Route::get('/', [\App\Http\Controllers\Api\MemberPayoutController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\MemberPayoutController::class, 'store']);
});

Route::prefix('/wallets/{walletType}')->middleware(['auth.jwt', 'view.as.member', 'throttle:api'])->group(function () {
    Route::post('/credit', [WalletController::class, 'credit']);
    Route::post('/debit', [WalletController::class, 'debit']);
    Route::get('/balance', [WalletController::class, 'balance']);
    Route::get('/transactions', [WalletController::class, 'transactions']);
});
Route::get('/wallets/donation/recent-payments', [WalletController::class, 'recentDonations'])
    ->middleware(['auth.jwt', 'throttle:api']);

Route::middleware(['auth.jwt', 'view.as.member', 'throttle:api'])->group(function () {
    Route::post('/{table}/{id}/approve', [RestApiController::class, 'approveRecord'])
        ->where('table', 'registrations');
    Route::post('/{table}/{id}/reject', [RestApiController::class, 'rejectRecord'])
        ->where('table', 'registrations');
    Route::post('/{table}/update', [RestApiController::class, 'handleCustomUpdate'])
        ->where('table', 'withdrawal_signatories|donation_withdrawal_signatories|operational_withdrawal_signatories');
    Route::post('/{table}/update-status', [RestApiController::class, 'handleCustomUpdateStatus'])
        ->where('table', 'penalty_withdrawals|donation_withdrawals|operational_withdrawals');

    // --- Member Documents (audit 2026-09-04) — register BEFORE the catch-all so it matches first.
    Route::prefix('/documents')->group(function () {
        Route::get('/types', [DocumentController::class, 'types']);
        Route::get('/me', [DocumentController::class, 'myDocuments']);
        Route::get('/me/summary', [DocumentController::class, 'mySummary']);
        Route::get('/me/types', [DocumentController::class, 'myTypes']);
        Route::post('', [DocumentController::class, 'upload']);
        Route::patch('/{id}', [DocumentController::class, 'update']);
        Route::delete('/{id}', [DocumentController::class, 'destroy']);
        Route::get('/{id}', [DocumentController::class, 'show']);
        Route::post('/{id}/approve', [DocumentController::class, 'approve']);
        Route::post('/{id}/reject', [DocumentController::class, 'reject']);
        Route::post('/{id}/replace', [DocumentController::class, 'replace']);
    });
    Route::prefix('/admin/documents')->group(function () {
        Route::get('/', [DocumentAdminController::class, 'index']);
        Route::get('/summary', [DocumentAdminController::class, 'summary']);
        Route::get('/audit', [DocumentAdminController::class, 'audit']);
        Route::get('/expiring', [DocumentAdminController::class, 'expiring']);
        Route::get('/missing', [DocumentAdminController::class, 'missing']);
        Route::get('/member/{memberId}', [DocumentAdminController::class, 'forMember']);
        Route::get('/orphans', [DocumentAdminController::class, 'orphans']);
        Route::post('/types', [DocumentAdminController::class, 'createType']);
        Route::patch('/types/{code}', [DocumentAdminController::class, 'updateType']);
    });

    Route::match(['GET', 'POST', 'PATCH', 'DELETE', 'PUT'], '/{table}', [RestApiController::class, 'handle'])
        ->where('table', 'members|contributions|donation-campaigns|donation_campaigns|donation-withdrawals|donation_withdrawals|member-access-logs|member_access_logs|operational-withdrawals|operational_withdrawals|organization-settings|organization_settings|payments|penalties|penalty-payment-records|penalty_payment_records|penalty_payments|penalty-wallet|penalty_wallet|penalty-withdrawals|penalty_withdrawals|signatory-signatures|signatory_signatures|system-logs|system_logs|user-roles|user_roles|withdrawals|registrations|withdrawal-signatories|withdrawal_signatories|donation-withdrawal-signatories|donation_withdrawal_signatories|operational-withdrawal-signatories|operational_withdrawal_signatories|beneficiaries|beneficiary-requests|beneficiary_requests|book_balance|book_balances|chats|conversation-participants|conversation_participants|conversations|dashboard_security|donation_wallet|events|executive-badges|executive_badges|expenses|financial_reports|meeting_attendance|meeting_minutes|member_executive_roles|memo-recipients|memo_recipients|memo-templates|memo_templates|memos|message-reactions|message_reactions|messages|news|news-read|news_read|notifications|office-bearer-signatures|office_bearer_signatures|operational_wallet|payouts|password_resets|reports|unmatched_payments|user_presence|wallet_transactions|welfare_settings');
});

Route::prefix('/banking/coop')->group(function () {
    Route::prefix('/callback')->middleware('verify.coop.callback')->group(function () {
        Route::post('/transfer', [\App\Http\Controllers\Banking\CoopCallbackController::class, 'handleTransfer']);
        Route::post('/ins', [\App\Http\Controllers\Banking\CoopCallbackController::class, 'handleIns']);
        Route::post('/validation', [\App\Http\Controllers\Banking\CoopCallbackController::class, 'handleTransfer']);
    });

    Route::middleware(['auth.jwt', 'throttle:api'])->group(function () {
        Route::post('/transfers/b2c', [\App\Http\Controllers\Banking\CoopTransferController::class, 'b2cWithdrawal']);
        Route::post('/transfers/pesalink', [\App\Http\Controllers\Banking\CoopTransferController::class, 'pesalinkWithdrawal']);
        Route::get('/transfers/{messageReference}/status', [\App\Http\Controllers\Banking\CoopTransferController::class, 'status']);
        Route::post('/transactions/status', [\App\Http\Controllers\Banking\CoopTransferController::class, 'queryBankStatus']);

        Route::get('/account/balance', [\App\Http\Controllers\Banking\CoopAccountController::class, 'balance']);
        Route::get('/account/ministatement', [\App\Http\Controllers\Banking\CoopAccountController::class, 'miniStatement']);
        Route::get('/account/statement', [\App\Http\Controllers\Banking\CoopAccountController::class, 'statement']);
        Route::post('/account/sync', [\App\Http\Controllers\Banking\CoopAccountController::class, 'syncStatement']);
        Route::post('/account/reconcile', [\App\Http\Controllers\Banking\CoopAccountController::class, 'reconcile']);
        Route::get('/account/sync-history', [\App\Http\Controllers\Banking\CoopAccountController::class, 'syncHistory']);
        Route::get('/reconciliation/status', [\App\Http\Controllers\Banking\CoopAccountController::class, 'reconciliationStatus']);
        Route::get('/dashboard/summary', [\App\Http\Controllers\Banking\CoopAccountController::class, 'dashboardSummary']);
        Route::get('/dashboard/financial', [\App\Http\Controllers\Banking\CoopAccountController::class, 'financialSummary']);
        Route::get('/reconciliation/report', [\App\Http\Controllers\Banking\CoopAccountController::class, 'reconciliationReport']);
        Route::get('/transactions', [\App\Http\Controllers\Banking\CoopAccountController::class, 'bankTransactionsList']);
        Route::get('/transactions/{id}', [\App\Http\Controllers\Banking\CoopAccountController::class, 'bankTransactionDetail']);
        Route::post('/transactions/{id}/reconcile', [\App\Http\Controllers\Banking\CoopAccountController::class, 'bankTransactionReconcile']);
        Route::get('/unmatched-transactions', [\App\Http\Controllers\Banking\CoopAccountController::class, 'unmatchedStatementTransactions']);
        Route::post('/unmatched-transactions/{id}/reconcile', [\App\Http\Controllers\Banking\CoopAccountController::class, 'reconcileUnmatchedStatementTransaction']);
        Route::get('/members/{memberId}/transactions', [\App\Http\Controllers\Banking\CoopAccountController::class, 'memberBankTransactions']);
        Route::post('/diagnostics/connectivity', [\App\Http\Controllers\Banking\CoopBankDiagnosticController::class, 'connectivity']);
        Route::post('/diagnostics/run/{testType}', [\App\Http\Controllers\Banking\CoopBankDiagnosticController::class, 'runTest']);
        Route::get('/diagnostics/history', [\App\Http\Controllers\Banking\CoopBankDiagnosticController::class, 'history']);
        Route::get('/diagnostics/report/{diagnosticId}', [\App\Http\Controllers\Banking\CoopBankDiagnosticController::class, 'report']);
        Route::get('/diagnostics/report/latest', [\App\Http\Controllers\Banking\CoopBankDiagnosticController::class, 'lastReport']);
    });
});

Route::prefix('/functions/v1')->middleware('api')->group(function () {
    Route::prefix('/admin-registration')->middleware('auth.jwt', 'throttle:api')->group(function () {
        Route::get('/config', [AdminRegistrationController::class, 'getConfig']);
        Route::put('/config', [AdminRegistrationController::class, 'updateConfig']);
        Route::get('/registrations', [AdminRegistrationController::class, 'listRegistrations']);
        Route::get('/registrations/{id}', [AdminRegistrationController::class, 'getRegistration']);
        Route::post('/registrations/{id}/approve', [AdminRegistrationController::class, 'approveRegistration']);
        Route::post('/registrations/{id}/reject', [AdminRegistrationController::class, 'rejectRegistration']);
    });

    Route::prefix('/member-registration')->group(function () {
        Route::get('/config', [MemberRegistrationController::class, 'getConfig']);
        Route::post('/register', [MemberRegistrationController::class, 'register']);
        Route::post('/initiate-payment', [MemberRegistrationController::class, 'initiatePayment'])->middleware('throttle:api');
        Route::get('/status/{id}', [MemberRegistrationController::class, 'getStatus']);
    });

    Route::post('/bank-statement-import', [ImportController::class, 'importBankStatement'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/bulk-import', [ImportController::class, 'bulkImport'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/create-member', [MemberController::class, 'createMember'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/operational-topup', [OperationalTopupController::class, 'topup'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/ai-assistant', [AiAssistantController::class, 'query'])->middleware('auth.jwt', 'throttle:api');
    Route::get('/ai-assistant/conversation', [AiAssistantController::class, 'history'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/admin/members/{memberId}/penalty-waivers', [\App\Http\Controllers\Api\PenaltyWaiverController::class, 'store'])->middleware('auth.jwt', 'throttle:api');
    Route::post('/b2c-transfer', [B2cTransferController::class, 'transfer'])->middleware('auth.jwt', 'throttle:api');

    Route::post('/rpc/{fn}', [\App\Http\Controllers\Api\RpcController::class, 'handle'])->middleware('auth.jwt', 'throttle:api');
});

Route::prefix('/members')->middleware(['auth.jwt', 'view.as.member', 'throttle:api'])->group(function () {
    Route::get('/stats', [MemberStatsController::class, 'index']);
    Route::get('/stats/defaulters', [MemberStatsController::class, 'defaulters']);
    Route::get('/stats/summary', [MemberStatsController::class, 'summary']);
    Route::get('/stats/executive-dashboard', [MemberStatsController::class, 'executiveDashboard']);
    Route::get('/stats/statement', [MemberStatsController::class, 'statement']);
    Route::get('/financial', [MemberStatsController::class, 'financial']);
});

Route::prefix('/admin/presence')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/online-users', [\App\Http\Controllers\Api\PresenceController::class, 'onlineUsers']);
    Route::get('/members', [\App\Http\Controllers\Api\PresenceController::class, 'memberStatus']);
    Route::get('/login-activity', [\App\Http\Controllers\Api\PresenceController::class, 'loginActivity']);
});

// --- Signatures (master engineering 2026-09-04) ---
Route::prefix('/signatures')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/', [SignatureController::class, 'index']);
    Route::get('/current', [SignatureController::class, 'current']);
    Route::post('/', [SignatureController::class, 'store']);
    Route::delete('/{id}', [SignatureController::class, 'destroy']);
});

// --- Minutes (master engineering 2026-09-04) ---
Route::prefix('/minutes')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/', [MeetingMinutesController::class, 'index']);
    Route::get('/executive-members', [MeetingMinutesController::class, 'executiveMembers']);
    Route::post('/', [MeetingMinutesController::class, 'store']);
    Route::get('/{id}', [MeetingMinutesController::class, 'show']);
    Route::put('/{id}', [MeetingMinutesController::class, 'update']);
    Route::post('/{id}/submit', [MeetingMinutesController::class, 'submit']);
    Route::post('/{id}/secretary-review', [MeetingMinutesController::class, 'secretaryReview']);
    Route::post('/{id}/approve', [MeetingMinutesController::class, 'approve']);
    Route::post('/{id}/reject', [MeetingMinutesController::class, 'reject']);
    Route::delete('/{id}', [MeetingMinutesController::class, 'destroy']);
});

// --- Bulk SMS (master engineering 2026-09-04) ---
Route::prefix('/bulk-sms')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/recipients', [BulkSmsController::class, 'recipients']);
    Route::post('/dry-run', [BulkSmsController::class, 'dryRun']);
    Route::post('/send', [BulkSmsController::class, 'send']);
    Route::get('/history', [BulkSmsController::class, 'history']);
    Route::get('/history/{jobId}/recipients', [BulkSmsController::class, 'historyRecipients']);
    Route::get('/members/{memberId}/history', [BulkSmsController::class, 'memberHistory']);
});

// --- Super Admin stats (master engineering 2026-09-04) ---
Route::prefix('/super-admin')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/stats', [SuperAdminStatsController::class, 'dashboard']);
    Route::get('/health', [SuperAdminStatsController::class, 'health']);
});

// --- Admin API (mirrors web.php admin/api routes for SPA consumption) ---
Route::prefix('/admin/api')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/dashboard/stats', [App\Http\Controllers\Admin\AdminDashboardController::class, 'stats']);

    Route::middleware('admin')->prefix('backups')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\BackupAdminController::class, 'data']);
        Route::post('/', [App\Http\Controllers\Admin\BackupAdminController::class, 'store']);
        Route::post('/prune', [App\Http\Controllers\Admin\BackupAdminController::class, 'prune']);
        Route::get('/settings', [App\Http\Controllers\Admin\BackupAdminController::class, 'settings']);
        Route::put('/settings', [App\Http\Controllers\Admin\BackupAdminController::class, 'settings']);
        Route::get('/{id}', [App\Http\Controllers\Admin\BackupAdminController::class, 'show']);
        Route::post('/{id}/verify', [App\Http\Controllers\Admin\BackupAdminController::class, 'verify']);
        Route::get('/{id}/download', [App\Http\Controllers\Admin\BackupAdminController::class, 'download']);
        Route::delete('/{id}', [App\Http\Controllers\Admin\BackupAdminController::class, 'destroy']);
        Route::post('/{id}/restore', [App\Http\Controllers\Admin\BackupAdminController::class, 'restore']);
    });

    Route::get('/users', [App\Http\Controllers\Admin\AdminDataController::class, 'usersData']);
    Route::get('/groups', [App\Http\Controllers\Admin\AdminDataController::class, 'groupsData']);
    Route::get('/transactions', [App\Http\Controllers\Admin\AdminDataController::class, 'transactionsData']);
    Route::get('/reports', [App\Http\Controllers\Admin\AdminDataController::class, 'reportsData']);
    Route::get('/audit-logs', [App\Http\Controllers\Admin\AdminDataController::class, 'auditData']);

    Route::prefix('system')->group(function () {
        Route::get('/health', [App\Http\Controllers\Admin\SuperAdminController::class, 'systemHealth']);
        Route::get('/queue', [App\Http\Controllers\Admin\SuperAdminController::class, 'queueOverview']);
        Route::get('/queue/failed', [App\Http\Controllers\Admin\SuperAdminController::class, 'queueFailed']);
        Route::post('/queue/failed/{jobId}/retry', [App\Http\Controllers\Admin\SuperAdminController::class, 'retryFailedJob']);
        Route::delete('/queue/failed/{jobId}', [App\Http\Controllers\Admin\SuperAdminController::class, 'deleteFailedJob']);
        Route::post('/queue/failed/retry-all', [App\Http\Controllers\Admin\SuperAdminController::class, 'retryAllFailedJobs']);
        Route::delete('/queue/failed/flush', [App\Http\Controllers\Admin\SuperAdminController::class, 'flushFailedJobs']);
        Route::get('/cache', [App\Http\Controllers\Admin\SuperAdminController::class, 'cacheOverview']);
        Route::post('/cache/clear', [App\Http\Controllers\Admin\SuperAdminController::class, 'clearCache']);
        Route::post('/cache/redis/flush', [App\Http\Controllers\Admin\SuperAdminController::class, 'clearRedis']);
        Route::get('/cache/stats', [App\Http\Controllers\Admin\SuperAdminController::class, 'cacheStats']);
        Route::get('/financial', [App\Http\Controllers\Admin\SuperAdminController::class, 'financialOverview']);
        Route::get('/database', [App\Http\Controllers\Admin\SuperAdminController::class, 'databaseHealth']);
        Route::get('/bank', [App\Http\Controllers\Admin\SuperAdminController::class, 'bankMonitoring']);
        Route::get('/trace', [App\Http\Controllers\Admin\SuperAdminController::class, 'transactionTrace']);
    });
});

// --- Treasurer dashboard (performance optimization) ---
Route::prefix('/treasurer')->middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::get('/chart-data', [\App\Http\Controllers\Api\TreasurerDashboardController::class, 'chartData']);
    Route::get('/recent-activity', [\App\Http\Controllers\Api\TreasurerDashboardController::class, 'recentActivity']);
});

// Dashboard portal endpoints: current paths (/api/...) and versioned (/api/v1/...).
require __DIR__.'/portal.php';
Route::prefix('v1')->group(__DIR__.'/portal.php');
