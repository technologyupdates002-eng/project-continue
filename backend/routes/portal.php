<?php

// Portal endpoints used by the dashboards. Included twice from api.php:
// once at /api (current live paths) and once at /api/v1 (versioned).
// Role checks happen inside each controller (requireAnyRole).

use App\Http\Controllers\Api\V1\AdminPortalController as A;
use App\Http\Controllers\Api\V1\MemberPortalController as M;
use App\Http\Controllers\Api\V1\OfficerPortalController as O;
use App\Http\Controllers\Api\V1\SuperAdminPortalController as S;
use App\Http\Controllers\Api\V1\TreasurerPortalController as T;
use App\Http\Controllers\Api\WebsiteContactController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::prefix('member')->group(function () {
        Route::get('dashboard', [M::class, 'dashboard']);
        Route::get('contributions', [M::class, 'contributions']);
        Route::get('beneficiaries', [M::class, 'beneficiaries']);
        Route::post('beneficiaries', [M::class, 'addBeneficiary']);
        Route::match(['put', 'patch'], 'beneficiaries/{id}', [M::class, 'updateBeneficiary']);
        Route::delete('beneficiaries/{id}', [M::class, 'deleteBeneficiary']);
        Route::get('notifications', [M::class, 'notifications']);
        Route::match(['post', 'patch', 'put'], 'notifications/{id}/read', [M::class, 'markNotificationRead']);
        Route::get('documents', [M::class, 'documents']);
        Route::get('events', [M::class, 'events']);
        Route::get('news', [M::class, 'news']);
        Route::get('profile', [M::class, 'profile']);
        Route::match(['put', 'patch'], 'profile', [M::class, 'updateProfile']);
        Route::get('penalties', [M::class, 'penalties']);
        Route::post('penalties/{id}/pay', [M::class, 'payPenalty']);
        Route::get('donation-campaigns', [M::class, 'donationCampaigns']);
        Route::post('donate', [M::class, 'donate']);
        Route::get('withdrawal-receipts', [M::class, 'withdrawalReceipts']);
        Route::get('downloads/statement', [M::class, 'statement']);
        Route::get('downloads/receipt/{id}', [M::class, 'receipt']);
        Route::get('executive/{role}', [M::class, 'executive']);
    });

    Route::prefix('treasurer')->group(function () {
        Route::get('dashboard', [T::class, 'dashboard']);
        Route::get('contributions', [T::class, 'contributions']);
        Route::post('contributions/{id}/adjust', [T::class, 'adjustContribution']);
        Route::get('wallets', [T::class, 'wallets']);
        Route::get('operational-wallet', fn (\Illuminate\Http\Request $r) => app(T::class)->walletLedger($r, 'operational'));
        Route::get('penalty-wallet', fn (\Illuminate\Http\Request $r) => app(T::class)->walletLedger($r, 'penalty'));
        Route::get('donation-wallet', fn (\Illuminate\Http\Request $r) => app(T::class)->walletLedger($r, 'donation'));
        Route::get('withdrawal-approvals', [T::class, 'withdrawalApprovals']);
        Route::post('withdrawal-approvals/{id}/{action}', [T::class, 'decideWithdrawal'])->whereIn('action', ['approve', 'reject']);
        Route::get('documents', [T::class, 'documents']);
        Route::get('documents/{id}/download', [T::class, 'downloadDocument']);
        Route::get('reports', [T::class, 'reports']);
        Route::get('reports/{type}/export', [T::class, 'exportReport']);
        Route::get('import-batches', [T::class, 'importBatches']);
        Route::get('import-history', [T::class, 'importBatches']);
        Route::get('import-batches/{id}', [T::class, 'importBatch']);
        Route::get('bank-sync', [T::class, 'bankSyncStatus']);
        Route::post('bank-sync', [T::class, 'triggerBankSync']);
        Route::get('bank-statement', [T::class, 'bankStatement']);
        Route::post('bank-statement-import', [T::class, 'bankStatementImport']);
        Route::get('book-balance', [T::class, 'bookBalance']);
        Route::post('book-balance-import', [T::class, 'importBookBalance']);
        Route::get('wallet-reports', [T::class, 'walletReports']);
        Route::get('expenses', [T::class, 'expenses']);
        Route::post('expenses', [T::class, 'createExpense']);
        Route::post('expenses/{id}/{action}', [T::class, 'decideExpense'])->whereIn('action', ['approve', 'reject']);
        Route::get('donation-campaigns', [T::class, 'campaigns']);
        Route::post('donation-campaigns', [T::class, 'createCampaign']);
        Route::match(['put', 'patch'], 'donation-campaigns/{id}', [T::class, 'updateCampaign']);
        Route::delete('donation-campaigns/{id}', [T::class, 'deleteCampaign']);
        Route::get('memos', [T::class, 'memos']);
        Route::post('memos', [T::class, 'createMemo']);
        Route::match(['put', 'patch'], 'memos/{id}', [T::class, 'updateMemo']);
        Route::delete('memos/{id}', [T::class, 'deleteMemo']);
        Route::post('memos/{id}/resend', [T::class, 'resendMemo']);
        Route::get('memo-templates', [T::class, 'memoTemplates']);
        Route::get('settings', [T::class, 'settings']);
        Route::match(['put', 'patch', 'post'], 'settings', [T::class, 'updateSettings']);
    });

    // Admin + super-admin share the same actions.
    foreach (['admin', 'super-admin'] as $p) {
        Route::prefix($p)->group(function () {
            Route::get('members', [A::class, 'members']);
            Route::get('members/{id}', [A::class, 'memberDetail']);
            Route::get('members/{id}/contributions', [A::class, 'memberContributions']);
            Route::get('members/{id}/beneficiaries', [A::class, 'memberBeneficiaries']);
            Route::get('contributions', [A::class, 'contributions']);
            Route::get('beneficiaries', [A::class, 'beneficiaries']);
            Route::get('defaulters', [A::class, 'defaulters']);
            Route::get('payments', [A::class, 'payments']);
            Route::get('unmatched', [A::class, 'unmatched']);
            Route::post('unmatched/{id}/match', [A::class, 'matchUnmatched']);
            Route::get('bank-unmatched', [A::class, 'bankUnmatched']);
            Route::post('bank-unmatched/{id}/reconcile', [A::class, 'reconcileBankUnmatched']);
            Route::get('notifications', [A::class, 'notifications']);
            Route::post('sms', [A::class, 'sendSms']);
            Route::get('events', [A::class, 'events']);
            Route::post('events', [A::class, 'createEvent']);
            Route::match(['put', 'patch'], 'events/{id}', [A::class, 'updateEvent']);
            Route::delete('events/{id}', [A::class, 'deleteEvent']);
            Route::get('news', [A::class, 'news']);
            Route::post('news', [A::class, 'createNews']);
            Route::match(['put', 'patch'], 'news/{id}', [A::class, 'updateNews']);
            Route::delete('news/{id}', [A::class, 'deleteNews']);
            Route::get('penalty-payments', [A::class, 'penaltyPayments']);
            Route::post('penalty-payments/{id}/verify', [A::class, 'verifyPenaltyPayment']);
            Route::get('donations', [A::class, 'donations']);
            Route::get('settings', [A::class, 'settings']);
            Route::match(['put', 'patch', 'post'], 'settings', [A::class, 'updateSettings']);
            Route::match(['put', 'post'], 'office/signatures', [A::class, 'updateOfficeSignatures']);
            Route::get('registration-settings', [A::class, 'settings']);
            Route::match(['put', 'post'], 'registration-settings', [A::class, 'updateRegistrationSettings']);
            Route::get('registration-applicants', [A::class, 'registrationApplicants']);
            Route::get('executive-badges', [A::class, 'executiveBadges']);
            Route::post('import', [A::class, 'importMembers']);
            Route::post('beneficiary-import', [A::class, 'importBeneficiaries']);
            Route::get('withdrawal-approval', [T::class, 'withdrawalApprovals']);
            Route::post('withdrawal-approval/{id}/{action}', [T::class, 'decideWithdrawal'])->whereIn('action', ['approve', 'reject']);
        });
    }
    Route::get('admin/dashboard', [A::class, 'dashboard']);

    Route::prefix('super-admin')->group(function () {
        Route::get('dashboard', [S::class, 'dashboard']);
        Route::get('troubleshooting', [S::class, 'troubleshooting']);
        Route::get('audit-logs', [S::class, 'auditLogs']);
        Route::get('security', [S::class, 'security']);
        Route::match(['put', 'post'], 'security', [S::class, 'updateSecurity']);
        Route::get('password-management', [S::class, 'passwordManagement']);
        Route::get('access-control', [S::class, 'accessControl']);
        Route::match(['put', 'patch'], 'access-control/{role}', [S::class, 'updateAccess']);
        Route::get('online-users', [S::class, 'onlineUsers']);
        Route::get('login-activity', [S::class, 'loginActivity']);
        Route::get('system-monitoring', [S::class, 'monitoring']);
        Route::get('bank', [S::class, 'bank']);
        Route::get('database', [S::class, 'database']);
        Route::get('system', [S::class, 'system']);
        Route::get('trace', [S::class, 'trace']);
        Route::get('minutes', [O::class, 'listMinutes']);
    });

    Route::prefix('secretary')->group(function () {
        Route::get('dashboard', [O::class, 'secretaryDashboard']);
        Route::get('minutes-review', [O::class, 'minutesReview']);
        Route::post('minutes/{id}/submit-review', [O::class, 'submitReview']);
        Route::get('meeting-minutes', [O::class, 'listMinutes']);
        Route::post('meeting-minutes', [O::class, 'createMinutes']);
        Route::match(['put', 'patch'], 'meeting-minutes/{id}', [O::class, 'updateMinutes']);
        Route::get('meeting-scheduling', [O::class, 'schedule']);
        Route::post('meeting-scheduling', [O::class, 'createMeeting']);
        Route::post('meeting-scheduling/{id}/cancel', [O::class, 'cancelMeeting']);
        Route::get('events', [O::class, 'schedule']);
    });

    Route::prefix('vice-secretary')->group(function () {
        Route::get('dashboard', [O::class, 'viceSecretaryDashboard']);
        Route::get('minutes', [O::class, 'listMinutes']);
        Route::get('meeting-minutes', [O::class, 'listMinutes']);
        Route::post('meeting-minutes', [O::class, 'createMinutes']);
        Route::match(['put', 'patch'], 'meeting-minutes/{id}', [O::class, 'updateMinutes']);
        Route::post('meeting-minutes/{id}/submit', [O::class, 'submitMinutes']);
    });

    Route::prefix('chairperson')->group(function () {
        Route::get('dashboard', [O::class, 'chairDashboard']);
        Route::get('approve-minutes', [O::class, 'pendingApprovals']);
        Route::post('approve-minutes/{id}/{action}', [O::class, 'decideMinutes'])->whereIn('action', ['approve', 'reject']);
    });

    Route::get('office/dashboard', [O::class, 'officeDashboard']);

    // Frontend calls .../mark-read; keep the original /read path too.
    Route::middleware('admin')->group(function () {
        Route::match(['post', 'patch'], 'admin/website/contact-messages/{messageId}/mark-read', [WebsiteContactController::class, 'markRead'])->whereNumber('messageId');
    });
});
