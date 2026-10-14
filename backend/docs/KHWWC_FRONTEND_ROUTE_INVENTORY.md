# KHWWC Frontend Route Inventory

## Evidence source
`/home/laban/Documents/kirinyaga App/frontend/src/App.tsx`

## Public / unauthenticated routes
- `/login` -> `Login`
- `/force-password-change` -> `ForcePasswordChange`
- `/` -> app entry / redirect logic handled by auth flow

## Super Admin routes
- `/super-admin` -> `SuperAdminDashboard`
- `/super-admin/members/:memberId` -> `SuperAdminMemberDetail`
- `/super-admin/troubleshooting` -> `SystemTroubleshooting`
- `/super-admin/audit-logs` -> `AuditLogs`
- `/super-admin/security` -> `SecuritySettings`
- `/super-admin/password-management` -> `PasswordManagement`
- `/super-admin/access-control` -> `AccessControl`
- `/super-admin/online-users` -> `OnlineUsers`
- `/super-admin/login-activity` -> `LoginActivity`
- `/super-admin/system-monitoring` -> `SystemMonitoring`
- `/super-admin/bank` -> `SuperAdminBank`
- `/super-admin/database` -> `SuperAdminDatabase`
- `/super-admin/system` -> `SuperAdminSystem`
- `/super-admin/trace` -> `SuperAdminTrace`

## Admin routes
- `/admin` -> `AdminDashboard`
- `/admin/members` -> `Members`
- `/admin/members/:memberId` -> `MemberDetail`
- `/admin/members/:memberId/view-as-member` -> `MemberDetailAsImpersonate`
- `/admin/members/:memberId/view-as-member/dashboard` -> `ImpersonateRouter` + `MemberDashboard`
- `/admin/members/:memberId/view-as-member/events` -> `ImpersonateRouter` + `MemberEvents`
- `/admin/members/:memberId/view-as-member/documents` -> `ImpersonateRouter` + `MemberDocuments`
- `/admin/members/:memberId/view-as-member/downloads` -> `ImpersonateRouter` + `MemberDownloads`
- `/admin/members/:memberId/view-as-member/withdrawal-receipts` -> `ImpersonateRouter` + `WithdrawalReceipts`
- `/admin/members/:memberId/view-as-member/news` -> `ImpersonateRouter` + `MemberNews`
- `/admin/members/:memberId/view-as-member/beneficiaries` -> `ImpersonateRouter` + `MemberBeneficiaries`
- `/admin/members/:memberId/view-as-member/notifications` -> `ImpersonateRouter` + `MemberNotifications`
- `/admin/members/:memberId/view-as-member/profile` -> `ImpersonateRouter` + `MemberProfile`
- `/admin/members/:memberId/view-as-member/pay-penalty` -> `ImpersonateRouter` + `PayPenalty`
- `/admin/members/:memberId/view-as-member/donate` -> `ImpersonateRouter` + `Donate`
- `/admin/members/:memberId/view-as-member/executive/:roleName` -> `ImpersonateRouter` + `ExecutiveDashboard`
- `/admin/contributions` -> `Contributions`
- `/admin/import` -> `ExcelImport`
- `/admin/beneficiary-import` -> `BeneficiaryImport`
- `/admin/beneficiaries` -> `Beneficiaries`
- `/admin/payments` -> `Payments`
- `/admin/unmatched` -> `UnmatchedPayments`
- `/admin/sms` -> `BulkSms`
- `/admin/events` -> `AdminEvents`
- `/admin/documents` -> `AdminDocuments`
- `/admin/news` -> `AdminNews`
- `/admin/beneficiary-requests` -> `BeneficiaryRequests`
- `/admin/defaulters` -> `Defaulters`
- `/admin/notifications` -> `AdminNotifications`
- `/admin/settings` -> `AdminSettings`
- `/admin/banking/diagnostics` -> `BankDiagnostics`
- `/admin/signatures` -> `OfficeSignatures`
- `/admin/penalty-payments` -> `VerifyPenaltyPayments`
- `/admin/penalty-wallet` -> `PenaltyWallet`
- `/admin/donations` -> `DonationWallet`
- `/admin/donation-campaigns` -> `DonationCampaigns`
- `/admin/withdrawal-approval` -> `WithdrawalApproval`
- `/admin/withdrawal-receipts` -> `WithdrawalReceipts`
- `/admin/registration-settings` -> `RegistrationSettings`
- `/admin/registration-management` -> `RegistrationManagement`
- `/admin/registration-api-docs` -> `RegistrationApiDocs`
- `/admin/executive-badges` -> `ExecutiveBadges`

## Treasurer routes
- `/treasurer` -> `TreasurerDashboard`
- `/treasurer/contributions` -> `TreasurerContributions`
- `/treasurer/contribution-adjustments` -> `ContributionAdjustments`
- `/treasurer/penalty-wallet` -> `TreasurerPenaltyWallet`
- `/treasurer/donation-wallet` -> `TreasurerDonationWallet`
- `/treasurer/withdrawal-approvals` -> `TreasurerWithdrawalApprovals`
- `/treasurer/donation-campaigns` -> `DonationCampaigns`
- `/treasurer/documents` -> `TreasurerDocuments`
- `/treasurer/reports` -> `TreasurerReports`
- `/treasurer/settings` -> `TreasurerSettings`
- `/treasurer/bank-sync` -> `BankSync`
- `/treasurer/bank-statement` -> `TreasurerBankStatement`
- `/treasurer/bank-statement-import` -> `BankStatementImport`
- `/treasurer/book-balance-import` -> `BookBalanceImport`
- `/treasurer/book-balance` -> `BookBalance`
- `/treasurer/wallet-reports` -> `WalletReports`
- `/treasurer/operational-wallet` -> `OperationalWallet`
- `/treasurer/expenses` -> `ExpensesPayouts`

## Member routes
- `/member` -> `MemberDashboard`
- `/member/events` -> `MemberEvents`
- `/member/documents` -> `MemberDocuments`
- `/member/downloads` -> `MemberDownloads`
- `/member/withdrawal-receipts` -> `WithdrawalReceipts`
- `/member/news` -> `MemberNews`
- `/member/beneficiaries` -> `MemberBeneficiaries`
- `/member/notifications` -> `MemberNotifications`
- `/member/profile` -> `MemberProfile`
- `/member/pay-penalty` -> `PayPenalty`
- `/member/donate` -> `Donate`
- `/member/executive/:roleName` -> `ExecutiveDashboard`

## Secretary / chairperson / office routes
- `/secretary` -> `SecretaryDashboard`
- `/secretary/minutes-review` -> `MinutesReview`
- `/secretary/meeting-minutes` -> `MinutesManagement`
- `/secretary/meeting-scheduling` -> `Schedule`
- `/chairperson` -> `ChairpersonDashboard`
- `/chairperson/approve-minutes` -> `ApproveMinutes`
- `/office` -> `OfficeDashboard` (in route tree, layout-based)

## Summary
There are many app-level route groups, with the largest clusters in admin, treasurer, member, and super-admin access. This inventory is a route baseline; the next step is route-to-backend authentication and authorization verification.
