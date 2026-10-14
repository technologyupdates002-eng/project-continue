import { BrowserRouter, Routes, Route, Navigate, Outlet } from '@/lib/router-compat';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '@/lib/query';
import { AuthProvider, useAuth } from '@/auth/AuthContext';
import { RequireAuth, getHomePath } from '@/auth/RouteGuard';
import { AppLayout } from '@/layouts/AppLayout';
import { PublicLayout } from '@/layouts/PublicLayout';

// Auth pages
import LoginPage from '@/pages/auth/LoginPage';
import ForcePasswordChangePage from '@/pages/auth/ForcePasswordChangePage';

// Chat page
import ChatPage from '@/pages/member/ChatPage';

// Public pages
import HomePage from '@/pages/public/HomePage';
import LeadershipPage from '@/pages/public/LeadershipPage';
import PersonDetailPage from '@/pages/public/PersonDetailPage';
import ContentListPage from '@/pages/public/ContentListPage';
import ContentDetailPage from '@/pages/public/ContentDetailPage';
import ContactPage from '@/pages/public/ContactPage';

// Member pages
import MemberDashboard from '@/pages/member/MemberDashboard';
import MemberEvents from '@/pages/member/MemberEvents';
import MemberDocuments from '@/pages/member/MemberDocuments';
import MemberDownloads from '@/pages/member/MemberDownloads';
import WithdrawalReceipts from '@/pages/member/WithdrawalReceipts';
import MemberNews from '@/pages/member/MemberNews';
import MemberBeneficiaries from '@/pages/member/MemberBeneficiaries';
import MemberNotifications from '@/pages/member/MemberNotifications';
import MemberProfile from '@/pages/member/MemberProfile';
import PayPenalty from '@/pages/member/PayPenalty';
import Donate from '@/pages/member/Donate';
import WelfareContribution from '@/pages/member/WelfareContribution';
import ExecutiveDashboard from '@/pages/member/ExecutiveDashboard';

// Treasurer pages
import TreasurerDashboard from '@/pages/treasurer/TreasurerDashboard';
import TreasurerContributions from '@/pages/treasurer/TreasurerContributions';
import ContributionAdjustments from '@/pages/treasurer/ContributionAdjustments';
import WithdrawalApprovals from '@/pages/treasurer/WithdrawalApprovals';
import TreasurerDocuments from '@/pages/treasurer/TreasurerDocuments';
import TreasurerReports from '@/pages/treasurer/TreasurerReports';
import BankSync from '@/pages/treasurer/BankSync';
import DonationCampaigns from '@/pages/treasurer/DonationCampaigns';
import MemoHistory from '@/pages/treasurer/MemoHistory';
import CreateMemo from '@/pages/treasurer/CreateMemo';
import TreasurerSettings from '@/pages/treasurer/TreasurerSettings';
import { PenaltyWalletPage, DonationWalletPage, OperationalWalletPage } from '@/pages/treasurer/WalletPages';
import { BankStatementPage, BookBalanceImportPage, BookBalancePage, WalletReportsPage, ExpensesPage } from '@/pages/treasurer/TreasurerPages';
import { BankStatementImportPage } from '@/pages/treasurer/BankStatementImport';
import { ImportHistoryPage } from '@/pages/treasurer/ImportHistory';
import { BankUnmatchedPage } from '@/pages/admin/BankUnmatched';

// Admin pages
import AdminDashboard from '@/pages/admin/AdminDashboard';
import { AdminMembers, AdminMemberDetail } from '@/pages/admin/AdminMembers';
import {
  AdminContributions, AdminImport, AdminBeneficiaryImport, AdminBeneficiaries,
  AdminPayments, AdminUnmatchedPayments, AdminBulkSms, AdminEvents,
  AdminDocuments, AdminNews, AdminBeneficiaryRequests, AdminDefaulters,
  AdminNotifications, AdminSettings, AdminBankDiagnostics, AdminOfficeSignatures,
  AdminVerifyPenaltyPayments, AdminPenaltyWallet, AdminDonations,
  AdminDonationCampaigns, AdminWithdrawalApproval, AdminRegistrationSettings,
  AdminRegistrationManagement, AdminRegistrationApiDocs, AdminExecutiveBadges,
} from '@/pages/admin/AdminPages';

// Website CMS pages
import {
  WebsiteDashboard, WebsiteContentManagement, WebsitePeople,
  WebsiteLeadershipMembers, WebsiteContactInbox, WebsiteMedia,
  WebsiteSettings, WebsiteEmailTemplates,
} from '@/pages/website/WebsitePages';

// Role pages (Secretary, Vice Secretary, Chairperson, Office)
import {
  SecretaryDashboard, MinutesReview, MinutesManagement, MeetingScheduling,
  SecretaryEvents, SecretarySignatureUpload, SecretaryWithdrawalApprovals,
  ViceSecretaryDashboard, ViceSecretaryMinutes,
  ChairpersonDashboard, ApproveMinutes, ChairpersonSignatureUpload, ChairpersonWithdrawalApprovals,
  OfficeDashboard,
} from '@/pages/roles/RolePages';

// Super Admin pages
import {
  SuperAdminDashboard, SuperAdminMemberDetail, SystemTroubleshooting,
  AuditLogs, SecuritySettings, PasswordManagement, AccessControl,
  OnlineUsers, LoginActivity, SystemMonitoring, SuperAdminBank,
  SuperAdminDatabase, SuperAdminSystem, SuperAdminTrace,
} from '@/pages/super-admin/SuperAdminPages';

// API Matrix page
import ApiMatrixPage from '@/pages/ApiMatrixPage';

function RootRedirect() {
  const { user, loading } = useAuth();
  if (loading) return null;
  if (user) return <Navigate to={getHomePath(user.role)} replace />;
  return <Navigate to="/login" replace />;
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <Routes>
            {/* Public website */}
            <Route element={<PublicLayout />}>
              <Route path="/" element={<HomePage />} />
              <Route path="/public/website/leadership" element={<LeadershipPage />} />
              <Route path="/public/website/people/:slug" element={<PersonDetailPage />} />
              <Route path="/public/website/content/:type" element={<ContentListPage />} />
              <Route path="/public/website/content/:type/:slug" element={<ContentDetailPage />} />
              <Route path="/public/website/contact" element={<ContactPage />} />
            </Route>

            {/* Auth */}
            <Route path="/login" element={<LoginPage />} />
            <Route path="/force-password-change" element={<ForcePasswordChangePage />} />

            {/* Chat - available to all authenticated users */}
            <Route element={<RequireAuth><AppLayout /></RequireAuth>}>
              <Route path="/chat" element={<ChatPage />} />
              <Route path="/api-matrix" element={<ApiMatrixPage />} />
            </Route>

            {/* Member portal */}
            <Route element={<RequireAuth><AppLayout /></RequireAuth>}>
              <Route path="/member" element={<MemberDashboard />} />
              <Route path="/member/events" element={<MemberEvents />} />
              <Route path="/member/documents" element={<MemberDocuments />} />
              <Route path="/member/downloads" element={<MemberDownloads />} />
              <Route path="/member/withdrawal-receipts" element={<WithdrawalReceipts />} />
              <Route path="/member/news" element={<MemberNews />} />
              <Route path="/member/beneficiaries" element={<MemberBeneficiaries />} />
              <Route path="/member/notifications" element={<MemberNotifications />} />
              <Route path="/member/profile" element={<MemberProfile />} />
              <Route path="/member/pay-penalty" element={<PayPenalty />} />
              <Route path="/member/donate" element={<Donate />} />
              <Route path="/member/welfare-contribution" element={<WelfareContribution />} />
              <Route path="/member/executive/:roleName" element={<ExecutiveDashboard />} />
            </Route>

            {/* Treasurer */}
            <Route element={<RequireAuth roles={['treasurer', 'admin']}><AppLayout /></RequireAuth>}>
              <Route path="/treasurer" element={<TreasurerDashboard />} />
              <Route path="/treasurer/contributions" element={<TreasurerContributions />} />
              <Route path="/treasurer/contribution-adjustments" element={<ContributionAdjustments />} />
              <Route path="/treasurer/penalty-wallet" element={<PenaltyWalletPage />} />
              <Route path="/treasurer/donation-wallet" element={<DonationWalletPage />} />
              <Route path="/treasurer/operational-wallet" element={<OperationalWalletPage />} />
              <Route path="/treasurer/withdrawal-approvals" element={<WithdrawalApprovals />} />
              <Route path="/treasurer/documents" element={<TreasurerDocuments />} />
              <Route path="/treasurer/reports" element={<TreasurerReports />} />
              <Route path="/treasurer/bank-sync" element={<BankSync />} />
              <Route path="/treasurer/bank-statement" element={<BankStatementPage />} />
              <Route path="/treasurer/bank-statement-import" element={<BankStatementImportPage />} />
              <Route path="/treasurer/import-history" element={<ImportHistoryPage />} />
              <Route path="/treasurer/book-balance-import" element={<BookBalanceImportPage />} />
              <Route path="/treasurer/book-balance" element={<BookBalancePage />} />
              <Route path="/treasurer/wallet-reports" element={<WalletReportsPage />} />
              <Route path="/treasurer/expenses" element={<ExpensesPage />} />
              <Route path="/treasurer/donation-campaigns" element={<DonationCampaigns />} />
              <Route path="/treasurer/memos" element={<MemoHistory />} />
              <Route path="/treasurer/memos/create" element={<CreateMemo />} />
              <Route path="/treasurer/memos/:id/edit" element={<CreateMemo />} />
              <Route path="/treasurer/settings" element={<TreasurerSettings />} />
            </Route>

            {/* Admin */}
            <Route element={<RequireAuth roles={['admin']}><AppLayout /></RequireAuth>}>
              <Route path="/admin" element={<AdminDashboard />} />
              <Route path="/admin/members" element={<AdminMembers />} />
              <Route path="/admin/members/:memberId" element={<AdminMemberDetail />} />
              <Route path="/admin/contributions" element={<AdminContributions />} />
              <Route path="/admin/import" element={<AdminImport />} />
              <Route path="/admin/beneficiary-import" element={<AdminBeneficiaryImport />} />
              <Route path="/admin/beneficiaries" element={<AdminBeneficiaries />} />
              <Route path="/admin/payments" element={<AdminPayments />} />
              <Route path="/admin/unmatched" element={<AdminUnmatchedPayments />} />
              <Route path="/admin/bank-unmatched" element={<BankUnmatchedPage />} />
              <Route path="/admin/sms" element={<AdminBulkSms />} />
              <Route path="/admin/events" element={<AdminEvents />} />
              <Route path="/admin/documents" element={<AdminDocuments />} />
              <Route path="/admin/news" element={<AdminNews />} />
              <Route path="/admin/beneficiary-requests" element={<AdminBeneficiaryRequests />} />
              <Route path="/admin/defaulters" element={<AdminDefaulters />} />
              <Route path="/admin/notifications" element={<AdminNotifications />} />
              <Route path="/admin/settings" element={<AdminSettings />} />
              <Route path="/admin/banking/diagnostics" element={<AdminBankDiagnostics />} />
              <Route path="/admin/signatures" element={<AdminOfficeSignatures />} />
              <Route path="/admin/penalty-payments" element={<AdminVerifyPenaltyPayments />} />
              <Route path="/admin/penalty-wallet" element={<AdminPenaltyWallet />} />
              <Route path="/admin/donations" element={<AdminDonations />} />
              <Route path="/admin/donation-campaigns" element={<AdminDonationCampaigns />} />
              <Route path="/admin/withdrawal-approval" element={<AdminWithdrawalApproval />} />
              <Route path="/admin/registration-settings" element={<AdminRegistrationSettings />} />
              <Route path="/admin/registration-management" element={<AdminRegistrationManagement />} />
              <Route path="/admin/registration-api-docs" element={<AdminRegistrationApiDocs />} />
              <Route path="/admin/executive-badges" element={<AdminExecutiveBadges />} />

              {/* Website Management CMS */}
              <Route path="/admin/website/dashboard" element={<WebsiteDashboard />} />
              <Route path="/admin/website/content" element={<WebsiteContentManagement />} />
              <Route path="/admin/website/people" element={<WebsitePeople />} />
              <Route path="/admin/website/people/leadership-members" element={<WebsiteLeadershipMembers />} />
              <Route path="/admin/website/contact-messages" element={<WebsiteContactInbox />} />
              <Route path="/admin/website/media" element={<WebsiteMedia />} />
              <Route path="/admin/website/settings/:section" element={<WebsiteSettingsRoute />} />
              <Route path="/admin/website/email-templates" element={<WebsiteEmailTemplates />} />
            </Route>

            {/* Secretary */}
            <Route element={<RequireAuth roles={['secretary']}><AppLayout /></RequireAuth>}>
              <Route path="/secretary" element={<SecretaryDashboard />} />
              <Route path="/secretary/events" element={<SecretaryEvents />} />
              <Route path="/secretary/minutes-review" element={<MinutesReview />} />
              <Route path="/secretary/meeting-minutes" element={<MinutesManagement />} />
              <Route path="/secretary/meeting-scheduling" element={<MeetingScheduling />} />
              <Route path="/secretary/signature" element={<SecretarySignatureUpload />} />
              <Route path="/secretary/withdrawal-approvals" element={<SecretaryWithdrawalApprovals />} />
            </Route>

            {/* Vice Secretary */}
            <Route element={<RequireAuth roles={['vice-secretary']}><AppLayout /></RequireAuth>}>
              <Route path="/vice-secretary" element={<ViceSecretaryDashboard />} />
              <Route path="/vice-secretary/minutes" element={<ViceSecretaryMinutes />} />
            </Route>

            {/* Chairperson */}
            <Route element={<RequireAuth roles={['chairperson']}><AppLayout /></RequireAuth>}>
              <Route path="/chairperson" element={<ChairpersonDashboard />} />
              <Route path="/chairperson/approve-minutes" element={<ApproveMinutes />} />
              <Route path="/chairperson/signature" element={<ChairpersonSignatureUpload />} />
              <Route path="/chairperson/withdrawal-approvals" element={<ChairpersonWithdrawalApprovals />} />
            </Route>

            {/* Office */}
            <Route element={<RequireAuth roles={['office']}><AppLayout /></RequireAuth>}>
              <Route path="/office" element={<OfficeDashboard />} />
            </Route>

            {/* Super Admin */}
            <Route element={<RequireAuth roles={['super-admin']}><AppLayout /></RequireAuth>}>
              <Route path="/super-admin" element={<SuperAdminDashboard />} />
              <Route path="/super-admin/members/:memberId" element={<SuperAdminMemberDetail />} />
              <Route path="/super-admin/troubleshooting" element={<SystemTroubleshooting />} />
              <Route path="/super-admin/audit-logs" element={<AuditLogs />} />
              <Route path="/super-admin/security" element={<SecuritySettings />} />
              <Route path="/super-admin/password-management" element={<PasswordManagement />} />
              <Route path="/super-admin/access-control" element={<AccessControl />} />
              <Route path="/super-admin/online-users" element={<OnlineUsers />} />
              <Route path="/super-admin/login-activity" element={<LoginActivity />} />
              <Route path="/super-admin/system-monitoring" element={<SystemMonitoring />} />
              <Route path="/super-admin/bank" element={<SuperAdminBank />} />
              <Route path="/super-admin/database" element={<SuperAdminDatabase />} />
              <Route path="/super-admin/system" element={<SuperAdminSystem />} />
              <Route path="/super-admin/trace" element={<SuperAdminTrace />} />

              {/* Admin module reuse */}
              <Route path="/super-admin/members" element={<AdminMembers />} />
              <Route path="/super-admin/contributions" element={<AdminContributions />} />
              <Route path="/super-admin/import" element={<AdminImport />} />
              <Route path="/super-admin/beneficiaries" element={<AdminBeneficiaries />} />
              <Route path="/super-admin/payments" element={<AdminPayments />} />
              <Route path="/super-admin/unmatched" element={<AdminUnmatchedPayments />} />
              <Route path="/super-admin/bank-unmatched" element={<BankUnmatchedPage />} />
              <Route path="/super-admin/sms" element={<AdminBulkSms />} />
              <Route path="/super-admin/events" element={<AdminEvents />} />
              <Route path="/super-admin/documents" element={<AdminDocuments />} />
              <Route path="/super-admin/news" element={<AdminNews />} />
              <Route path="/super-admin/beneficiary-requests" element={<AdminBeneficiaryRequests />} />
              <Route path="/super-admin/defaulters" element={<AdminDefaulters />} />
              <Route path="/super-admin/notifications" element={<AdminNotifications />} />
              <Route path="/super-admin/settings" element={<AdminSettings />} />
              <Route path="/super-admin/banking/diagnostics" element={<AdminBankDiagnostics />} />
              <Route path="/super-admin/signatures" element={<AdminOfficeSignatures />} />
              <Route path="/super-admin/penalty-payments" element={<AdminVerifyPenaltyPayments />} />
              <Route path="/super-admin/penalty-wallet" element={<AdminPenaltyWallet />} />
              <Route path="/super-admin/donations" element={<AdminDonations />} />
              <Route path="/super-admin/donation-campaigns" element={<AdminDonationCampaigns />} />
              <Route path="/super-admin/withdrawal-approval" element={<AdminWithdrawalApproval />} />
              <Route path="/super-admin/registration-settings" element={<AdminRegistrationSettings />} />
              <Route path="/super-admin/registration-management" element={<AdminRegistrationManagement />} />
              <Route path="/super-admin/registration-api-docs" element={<AdminRegistrationApiDocs />} />
              <Route path="/super-admin/executive-badges" element={<AdminExecutiveBadges />} />
              <Route path="/super-admin/minutes" element={<MinutesManagement />} />

              {/* Website CMS reuse */}
              <Route path="/super-admin/website/dashboard" element={<WebsiteDashboard />} />
              <Route path="/super-admin/website/content" element={<WebsiteContentManagement />} />
              <Route path="/super-admin/website/people" element={<WebsitePeople />} />
              <Route path="/super-admin/website/people/leadership-members" element={<WebsiteLeadershipMembers />} />
              <Route path="/super-admin/website/contact-messages" element={<WebsiteContactInbox />} />
              <Route path="/super-admin/website/media" element={<WebsiteMedia />} />
              <Route path="/super-admin/website/settings/:section" element={<WebsiteSettingsRoute />} />
              <Route path="/super-admin/website/email-templates" element={<WebsiteEmailTemplates />} />
            </Route>

            <Route path="*" element={<RootRedirect />} />
          </Routes>
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  );
}

function WebsiteSettingsRoute() {
  const section = window.location.pathname.split('/').pop() || 'general';
  return <WebsiteSettings section={section} />;
}
