import { authApi } from './auth';
import { getDemoMode } from './client';
import { publicApi } from './public';
import { memberApi } from './member';
import { treasurerApi, adminApi } from './admin';
import { websiteApi } from './website';
import {
  secretaryApi, viceSecretaryApi, chairpersonApi, officeApi, superAdminApi,
} from './roles';
import {
  demoUser, demoRoleUsers, demoMembers, demoContributions,
  demoBeneficiaries, demoNotifications, demoDocuments, demoEvents,
  demoNews, demoLeadership, demoSettings, demoContactMessages,
  demoWebsiteContent, demoWallets, demoWalletTransactions,
  demoWithdrawals, demoPenalties, demoDonations, demoCampaigns,
  demoDefaulters, demoExpenses, demoBankTransactions, demoMinutes,
  demoMeetings, demoAuditLogs, demoAccessRoles, demoOnlineUsers,
  demoLoginActivity, demoSystemMetrics, demoEmailTemplates,
  demoMediaAssets, demoRegistrationApplicants,
  demoMemos, demoMemoTemplates, demoOrgSettings,
  demoPayouts, demoEligibleMembers,
  demoImportBatches, demoImportBatchRows, demoUnmatchedTransactions,
} from './demo-data';
import type {
  Paginated, AuthUser, LoginResponse,
} from '@/types';

function paginate<T>(items: T[], page = 1, perPage = 10): Paginated<T> {
  const start = (page - 1) * perPage;
  return {
    data: items.slice(start, start + perPage),
    current_page: page,
    last_page: Math.ceil(items.length / perPage),
    per_page: perPage,
    total: items.length,
  };
}

function delay(ms = 200): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function tryApi<T>(
  apiCall: () => Promise<T>,
  fallback: T | (() => T | Promise<T>),
): Promise<T> {
  if (getDemoMode()) {
    await delay();
    return typeof fallback === 'function'
      ? await (fallback as () => T | Promise<T>)()
      : fallback;
  }
  return apiCall();
}

export const mockAuth = {
  async login(email: string, _password: string): Promise<LoginResponse> {
    await delay(300);
    const role = email.includes('254700000002') ? 'admin'
      : email.includes('254700000003') ? 'treasurer'
      : email.includes('254700000004') ? 'secretary'
      : email.includes('254700000005') ? 'chairperson'
      : email.includes('254700000006') ? 'super-admin'
      : 'member';
    const user = { ...demoRoleUsers[role], email };
    return { token: 'demo-jwt-token-' + Date.now(), user };
  },

  async me(): Promise<AuthUser> {
    await delay(100);
    return demoUser;
  },
};

export const mockPublic = {
  getSettings: () => tryApi(() => publicApi.getSettings(), demoSettings),
  getContent: (type: string, page = 1) => tryApi(
    () => publicApi.getContent(type, page),
    () => paginate(demoWebsiteContent.filter(c => c.type === type), page),
  ),
  getContentDetail: (type: string, slug: string) => tryApi(
    () => publicApi.getContentDetail(type, slug),
    demoWebsiteContent.find(c => c.type === type && c.slug === slug) || demoWebsiteContent[0],
  ),
  getLeadership: () => tryApi(() => publicApi.getLeadership(), demoLeadership),
  getPerson: (slug: string) => tryApi(
    () => publicApi.getPerson(slug),
    demoLeadership.find(l => l.slug === slug) || demoLeadership[0],
  ),
  submitContact: (data: { name: string; email: string; subject: string; message: string }) =>
    tryApi(() => publicApi.submitContact(data), { message: 'Message sent successfully' }),
};

export const mockMember = {
  dashboard: () => tryApi(() => memberApi.dashboard(), {
    contributions_total: 84000, contributions_count: 42,
    penalties_total: 1500, donations_total: 12000,
    wallet_balance: 84000, upcoming_events: demoEvents.filter(e => e.status === 'upcoming').slice(0, 3),
    recent_contributions: demoContributions.filter(c => c.member_id === 'm1').slice(0, 5),
    unread_notifications: demoNotifications.filter(n => !n.read).length, status: 'active',
  }),
  getContributions: (page = 1) => tryApi(
    () => memberApi.getContributions(page),
    () => paginate(demoContributions.filter(c => c.member_id === 'm1'), page),
  ),
  getBeneficiaries: () => tryApi(() => memberApi.getBeneficiaries(), demoBeneficiaries),
  addBeneficiary: (data: any) => tryApi(() => memberApi.addBeneficiary(data), { ...data, id: 'b-new-' + Date.now() } as any),
  updateBeneficiary: (id: string, data: any) => tryApi(() => memberApi.updateBeneficiary(id, data), { ...demoBeneficiaries[0], ...data } as any),
  deleteBeneficiary: (id: string) => tryApi(() => memberApi.deleteBeneficiary(id), undefined),
  getNotifications: () => tryApi(() => memberApi.getNotifications(), demoNotifications),
  markNotificationRead: (id: string) => tryApi(() => memberApi.markNotificationRead(id), undefined),
  getDocuments: () => tryApi(() => memberApi.getDocuments(), demoDocuments),
  getEvents: () => tryApi(() => memberApi.getEvents(), demoEvents),
  getNews: (page = 1) => tryApi(() => memberApi.getNews(page), () => paginate(demoNews, page)),
  getProfile: () => tryApi(() => memberApi.getProfile(), demoMembers[0]),
  updateProfile: (data: any) => tryApi(() => memberApi.updateProfile(data), { ...demoMembers[0], ...data } as any),
  getPenalties: () => tryApi(() => memberApi.getPenalties(), demoPenalties.filter(p => p.member_id === 'm1')),
  payPenalty: (id: string, _data: any) => tryApi(() => memberApi.payPenalty(id, _data), { message: 'Penalty payment initiated', reference: 'PNL' + Date.now() }),
  getWithdrawalReceipts: () => tryApi(() => memberApi.getWithdrawalReceipts(), demoWithdrawals.filter(w => w.member_id === 'm1')),
  getDonationCampaigns: () => tryApi(() => memberApi.getDonationCampaigns(), demoCampaigns.filter(c => c.status === 'active')),
  donate: (_data: any) => tryApi(() => memberApi.donate(_data), { message: 'Donation initiated', reference: 'DON' + Date.now() }),
  getExecutiveDashboard: (roleName: string) => tryApi(() => memberApi.getExecutiveDashboard(roleName), {
    role: roleName, title: roleName.charAt(0).toUpperCase() + roleName.slice(1),
    stats: [{ label: 'Members', value: '512' }, { label: 'Active', value: '489' }, { label: 'Contributions', value: 'KES 4.2M' }],
    actions: [{ label: 'View Reports', route: '/treasurer/reports' }],
  }),
  stkPush: (_data: any) => tryApi(() => memberApi.stkPush(_data), { checkoutRequestId: 'ws-co-' + Date.now(), message: 'STK push sent' } as any),
  paymentStatus: (_checkoutId: string) => tryApi(() => memberApi.paymentStatus(_checkoutId), { status: 'pending' } as any),
  uploadDocument: (_data: any) => tryApi(() => memberApi.uploadDocument(_data), { id: 'doc-new-' + Date.now(), title: _data.file?.name || 'Document', type: _data.document_type_code, status: 'pending', size: '1 KB', uploaded_by: 'You', date: new Date().toISOString() } as any),
  replaceDocument: (id: string, _data: any) => tryApi(() => memberApi.replaceDocument(id, _data), { ...demoDocuments[0], id } as any),
  deleteDocument: (id: string) => tryApi(() => memberApi.deleteDocument(id), undefined),
  getDocumentSummary: () => tryApi(() => memberApi.getDocumentSummary(), { total: 2, uploaded: 1, pending: 1, approved: 0, rejected: 0, expired: 0, completion_pct: 50 }),
  getDocumentTypes: () => tryApi(() => memberApi.getDocumentTypes(), [
    { code: 'national_id', name: 'National ID', allowed_member_upload: true },
    { code: 'passport', name: 'Passport', allowed_member_upload: true },
  ]),
  changePassword: (_data: any) => tryApi(() => memberApi.changePassword(_data), { message: 'Password changed successfully' }),
  uploadProfilePhoto: (_data: any) => tryApi(() => memberApi.uploadProfilePhoto(_data), { url: `/profile-images/${_data.userId}/profile.png` } as any),
};

export const mockTreasurer = {
  dashboard: () => tryApi(() => treasurerApi.dashboard(), {
    operational_balance: 1850000, contributions_balance: 4200000,
    penalty_balance: 28500, donation_balance: 875000,
    book_balance: 6953500, bank_balance: 6920000, reconciliation_diff: 33500,
    pending_withdrawals: 2, pending_expenses: 1,
    monthly_contributions: [
      { month: 'May', amount: 380000 }, { month: 'Jun', amount: 395000 },
      { month: 'Jul', amount: 410000 }, { month: 'Aug', amount: 420000 },
      { month: 'Sep', amount: 415000 }, { month: 'Oct', amount: 425000 },
    ],
  }),
  getContributions: (params: any) => tryApi(
    () => treasurerApi.getContributions(params),
    () => paginate(demoContributions, params.page || 1),
  ),
  adjustContribution: (id: string, data: any) => tryApi(
    () => treasurerApi.adjustContribution(id, data), { message: 'Contribution adjusted successfully' },
  ),
  getPenaltyWallet: (page = 1) => tryApi(() => treasurerApi.getPenaltyWallet(page),
    () => paginate(demoWalletTransactions.filter(t => t.wallet === 'Penalty'), page)),
  getDonationWallet: (page = 1) => tryApi(() => treasurerApi.getDonationWallet(page),
    () => paginate(demoWalletTransactions.filter(t => t.wallet === 'Donation'), page)),
  getOperationalWallet: (page = 1) => tryApi(() => treasurerApi.getOperationalWallet(page),
    () => paginate(demoWalletTransactions.filter(t => t.wallet === 'Operational'), page)),
  getWallets: () => tryApi(() => treasurerApi.getWallets(), demoWallets),
  getWithdrawalApprovals: () => tryApi(() => treasurerApi.getWithdrawalApprovals(), demoWithdrawals),
  approveWithdrawal: (id: string, action: string) => tryApi(
    () => treasurerApi.approveWithdrawal(id, action as any), { message: `Withdrawal ${action}d` },
  ),
  getDocuments: () => tryApi(() => treasurerApi.getDocuments(), demoDocuments),
  downloadDocument: (id: string) => tryApi(() => treasurerApi.downloadDocument(id), undefined),
  exportReport: (type: string) => tryApi(() => treasurerApi.exportReport(type), undefined),
  getReports: () => tryApi(() => treasurerApi.getReports(), [
    { name: 'Monthly Contributions Report - Oct 2026', type: 'contributions', date: daysAgoStr(2), size: '245 KB' },
    { name: 'Wallet Summary Report - Q3 2026', type: 'wallets', date: daysAgoStr(5), size: '180 KB' },
    { name: 'Bank Reconciliation Report - Oct 2026', type: 'reconciliation', date: daysAgoStr(1), size: '320 KB' },
  ]),
  getBankSync: () => tryApi(() => treasurerApi.getBankSync(), { last_sync: daysAgoStr(1), status: 'connected', new_transactions: 0 }),
  triggerBankSync: () => tryApi(() => treasurerApi.triggerBankSync(), { message: 'Bank sync initiated', imported: 12 }),
  getBankStatement: (page = 1) => tryApi(() => treasurerApi.getBankStatement(page),
    () => paginate(demoBankTransactions, page)),
  importBankStatement: (_file: File) => tryApi(() => treasurerApi.importBankStatement(_file), { message: 'Statement imported', imported: 24 }),
  importBookBalance: (_file: File) => tryApi(() => treasurerApi.importBookBalance(_file), { message: 'Book balance imported', imported: 18 }),
  getBookBalance: () => tryApi(() => treasurerApi.getBookBalance(),
    { balance: 6953500, last_updated: daysAgoStr(1), entries: demoWalletTransactions }),
  getWalletReports: () => tryApi(() => treasurerApi.getWalletReports(),
    demoWallets.map(w => ({ wallet: w.name, transactions: w.transactions_count, balance: w.balance, period: 'Oct 2026' }))),
  getExpenses: (page = 1) => tryApi(() => treasurerApi.getExpenses(page),
    () => paginate(demoExpenses, page)),
  createExpense: (data: any) => tryApi(() => treasurerApi.createExpense(data), { ...data, id: 'ex-new-' + Date.now() } as any),
  approveExpense: (id: string, action: string) => tryApi(
    () => treasurerApi.approveExpense(id, action as any), { message: `Expense ${action}d` },
  ),
  getDonationCampaigns: () => tryApi(() => treasurerApi.getDonationCampaigns(), demoCampaigns),
  createDonationCampaign: (data: any) => tryApi(() => treasurerApi.createDonationCampaign(data), { ...data, id: 'dc-new-' + Date.now() } as any),
  updateDonationCampaign: (id: string, data: any) => tryApi(() => treasurerApi.updateDonationCampaign(id, data), { ...demoCampaigns[0], ...data } as any),
  deleteDonationCampaign: (id: string) => tryApi(() => treasurerApi.deleteDonationCampaign(id), undefined),
  getMemos: () => tryApi(() => treasurerApi.getMemos(), demoMemos),
  createMemo: (data: any) => tryApi(() => treasurerApi.createMemo(data), { ...data, id: 'mem-new-' + Date.now() } as any),
  updateMemo: (id: string, data: any) => tryApi(() => treasurerApi.updateMemo(id, data), { ...demoMemos[0], ...data } as any),
  deleteMemo: (id: string) => tryApi(() => treasurerApi.deleteMemo(id), undefined),
  resendMemo: (id: string) => tryApi(() => treasurerApi.resendMemo(id), { message: 'Memo resent' }),
  getMemoTemplates: () => tryApi(() => treasurerApi.getMemoTemplates(), demoMemoTemplates),
  getOrgSettings: () => tryApi(() => treasurerApi.getOrgSettings(), demoOrgSettings),
  updateOrgSettings: (data: any) => tryApi(() => treasurerApi.updateOrgSettings(data), { message: 'Settings updated' }),
  getPayouts: () => tryApi(() => treasurerApi.getPayouts(), demoPayouts),
  createPayout: (data: any) => tryApi(() => treasurerApi.createPayout(data), { ...data, id: 'po-new-' + Date.now(), reference: 'PAY-' + Date.now(), status: 'pending', created_at: new Date().toISOString() } as any),
  getEligibleMembers: (payoutType: string) => tryApi(() => treasurerApi.getEligibleMembers(payoutType as any), demoEligibleMembers),
  getImportHistory: () => tryApi(() => treasurerApi.getImportHistory(), demoImportBatches),
  getImportBatchDetail: (id: string) => tryApi(() => treasurerApi.getImportBatchDetail(id),
    demoImportBatchRows.filter((r: any) => r.batch_id === id)),
};

export const mockAdmin = {
  dashboard: () => tryApi(() => adminApi.dashboard(), {
    total_members: 512, active_members: 489, total_contributions: 4200000,
    pending_approvals: 7, defaulters_count: 3,
    recent_signups: demoMembers.slice(0, 4),
    contributions_trend: [
      { month: 'May', amount: 380000 }, { month: 'Jun', amount: 395000 },
      { month: 'Jul', amount: 410000 }, { month: 'Aug', amount: 420000 },
      { month: 'Sep', amount: 415000 }, { month: 'Oct', amount: 425000 },
    ],
  }),
  getMembers: (params: any) => tryApi(
    () => adminApi.getMembers(params),
    () => {
      let items = [...demoMembers];
      if (params.search) {
        const q = params.search.toLowerCase();
        items = items.filter(m => m.name.toLowerCase().includes(q) || m.email.toLowerCase().includes(q) || m.member_number.toLowerCase().includes(q));
      }
      if (params.status) items = items.filter(m => m.status === params.status);
      return paginate(items, params.page || 1);
    },
  ),
  getMemberDetail: (id: string) => tryApi(() => adminApi.getMemberDetail(id),
    demoMembers.find(m => m.id === id) || demoMembers[0]),
  getMemberContributions: (id: string) => tryApi(() => adminApi.getMemberContributions(id),
    demoContributions.filter(c => c.member_id === id)),
  getMemberBeneficiaries: (id: string) => tryApi(() => adminApi.getMemberBeneficiaries(id),
    demoBeneficiaries.filter(b => b.member_id === id)),
  getContributions: (params: any) => tryApi(() => adminApi.getContributions(params),
    () => paginate(demoContributions, params.page || 1)),
  getBeneficiaries: (page = 1) => tryApi(() => adminApi.getBeneficiaries(page),
    () => paginate(demoBeneficiaries, page)),
  getDefaulters: () => tryApi(() => adminApi.getDefaulters(), demoDefaulters),
  getPayments: (page = 1) => tryApi(() => adminApi.getPayments(page),
    () => paginate(demoContributions, page)),
  getUnmatchedPayments: () => tryApi(() => adminApi.getUnmatchedPayments(),
    demoBankTransactions.filter(t => !t.matched).map((t, i) => ({ ...demoContributions[i % demoContributions.length], id: t.id, reference: t.reference, status: 'pending' as const }))),
  matchPayment: (id: string, _memberId: string) => tryApi(() => adminApi.matchPayment(id, _memberId), { message: 'Payment matched successfully' }),
  getBankUnmatched: () => tryApi(() => adminApi.getBankUnmatched(), demoUnmatchedTransactions),
  reconcileBankUnmatched: (id: string, data: any) => tryApi(() => adminApi.reconcileBankUnmatched(id, data),
    { message: 'Transaction reconciled' } as any),
  getNotifications: () => tryApi(() => adminApi.getNotifications(), demoNotifications),
  sendBulkSms: (_data: any) => tryApi(() => adminApi.sendBulkSms(_data), { message: 'SMS sent to 245 recipients' }),
  getEvents: () => tryApi(() => adminApi.getEvents(), demoEvents),
  getNews: (page = 1) => tryApi(() => adminApi.getNews(page), () => paginate(demoNews, page)),
  createNews: (data: any) => tryApi(() => adminApi.createNews(data), { ...data, id: 'news-new-' + Date.now() } as any),
  updateNews: (id: string, data: any) => tryApi(() => adminApi.updateNews(id, data), { ...demoNews[0], ...data, id } as any),
  createEvent: (data: any) => tryApi(() => adminApi.createEvent(data), { ...data, id: 'e-new-' + Date.now() } as any),
  getDocuments: () => tryApi(() => adminApi.getDocuments(), demoDocuments),
  getPenalties: () => tryApi(() => adminApi.getPenalties(), demoPenalties),
  verifyPenaltyPayment: (id: string) => tryApi(() => adminApi.verifyPenaltyPayment(id), { message: 'Penalty payment verified' }),
  getDonations: () => tryApi(() => adminApi.getDonations(), demoDonations),
  getDonationCampaigns: () => tryApi(() => adminApi.getDonationCampaigns(), demoCampaigns),
  createDonationCampaign: (data: any) => tryApi(() => adminApi.createDonationCampaign(data), { ...data, id: 'dc-new-' + Date.now() } as any),
  getWithdrawalApprovals: () => tryApi(() => adminApi.getWithdrawalApprovals(), demoWithdrawals),
  approveWithdrawal: (id: string, action: string) => tryApi(
    () => adminApi.approveWithdrawal(id, action as any), { message: `Withdrawal ${action}d` },
  ),
  importExcel: (_file: File) => tryApi(() => adminApi.importExcel(_file), { message: 'Import successful', imported: 12 }),
  importBeneficiaries: (_file: File) => tryApi(() => adminApi.importBeneficiaries(_file), { message: 'Import successful', imported: 8 }),
  getSettings: () => tryApi(() => adminApi.getSettings(), {
    org_name: demoSettings.organization_name,
    email: demoSettings.email,
    phone: demoSettings.phone,
    address: demoSettings.address,
  }),
  updateSettings: (data: any) => tryApi(() => adminApi.updateSettings(data), { message: 'Settings updated' }),
  updateOfficeSignatures: (data: any) => tryApi(() => adminApi.updateOfficeSignatures(data), { message: 'Signatures updated' }),
  updateRegistrationSettings: (data: any) => tryApi(() => adminApi.updateRegistrationSettings(data), { message: 'Registration settings updated' }),
  getRegistrationApplicants: (page = 1) => tryApi(() => adminApi.getRegistrationApplicants(page), () => paginate(demoRegistrationApplicants as any[], page)),
  getExecutiveBadges: (page = 1) => tryApi(() => adminApi.getExecutiveBadges(page), () => paginate(demoMembers as any[], page)),
};

export const mockWebsite = {
  dashboard: () => tryApi(() => websiteApi.dashboard(), {
    total_content: demoWebsiteContent.length, published_content: demoWebsiteContent.filter(c => c.status === 'published').length,
    draft_content: demoWebsiteContent.filter(c => c.status === 'draft').length,
    total_people: demoLeadership.length, unread_messages: demoContactMessages.filter(m => !m.read).length,
    media_count: demoMediaAssets.length,
    recent_content: demoWebsiteContent.slice(0, 5), recent_messages: demoContactMessages.slice(0, 3),
  }),
  getContent: (page = 1) => tryApi(() => websiteApi.getContent(page), () => paginate(demoWebsiteContent, page)),
  createContent: (data: any) => tryApi(() => websiteApi.createContent(data), { ...data, id: 'wc-new-' + Date.now() } as any),
  updateContent: (id: string, data: any) => tryApi(() => websiteApi.updateContent(id, data), { ...demoWebsiteContent[0], ...data } as any),
  archiveContent: (id: string) => tryApi(() => websiteApi.archiveContent(id), undefined),
  getPeople: () => tryApi(() => websiteApi.getPeople(), demoLeadership),
  createPerson: (data: any) => tryApi(() => websiteApi.createPerson(data), { ...data, id: 'l-new-' + Date.now() } as any),
  updatePerson: (id: string, data: any) => tryApi(() => websiteApi.updatePerson(id, data), { ...demoLeadership[0], ...data } as any),
  deletePerson: (id: string) => tryApi(() => websiteApi.deletePerson(id), undefined),
  getLeadershipMembers: () => tryApi(() => websiteApi.getLeadershipMembers(), demoMembers),
  getContactMessages: (page = 1) => tryApi(() => websiteApi.getContactMessages(page),
    () => paginate(demoContactMessages, page)),
  markMessageRead: (id: string) => tryApi(() => websiteApi.markMessageRead(id), undefined),
  getMedia: () => tryApi(() => websiteApi.getMedia(), demoMediaAssets),
  getSettings: (section: string) => tryApi(() => websiteApi.getSettings(section),
    section === 'contact' ? { email: demoSettings.email, phone: demoSettings.phone, address: demoSettings.address } :
    section === 'general' ? { organization_name: demoSettings.organization_name, tagline: demoSettings.tagline, about: demoSettings.about } :
    section === 'social' ? demoSettings.social : {}),
  updateSettings: (_section: string, _data: any) => tryApi(() => websiteApi.updateSettings(_section, _data), { message: 'Settings updated' }),
  getEmailTemplates: () => tryApi(() => websiteApi.getEmailTemplates(), demoEmailTemplates),
  updateEmailTemplate: (id: string, data: any) => tryApi(() => websiteApi.updateEmailTemplate(id, data), { ...demoEmailTemplates[0], ...data } as any),
  testEmail: (id: string, _email: string) => tryApi(() => websiteApi.testEmail(id, _email), { message: 'Test email sent successfully' }),
};

export const mockSecretary = {
  dashboard: () => tryApi(() => secretaryApi.dashboard(), {
    pending_minutes: demoMinutes.filter(m => m.status === 'pending_review').length,
    approved_minutes: demoMinutes.filter(m => m.status === 'approved').length,
    upcoming_meetings: demoMeetings.length, total_minutes: demoMinutes.length,
    recent_minutes: demoMinutes, upcoming: demoMeetings,
  }),
  getMinutesReview: () => tryApi(() => secretaryApi.getMinutesReview(),
    demoMinutes.filter(m => m.status === 'pending_review')),
  submitForReview: (id: string) => tryApi(() => secretaryApi.submitForReview(id), { message: 'Minutes submitted for review' }),
  secretaryReview: (id: string, decision: 'approve' | 'reject', notes?: string) =>
    tryApi(() => secretaryApi.submitForReview(id), { message: decision === 'approve' ? 'Minutes forwarded to Chairperson' : 'Minutes returned to author' }),
  getMeetingMinutes: () => tryApi(() => secretaryApi.getMeetingMinutes(), demoMinutes),
  createMinutes: (data: any) => tryApi(() => secretaryApi.createMinutes(data), { ...data, id: 'min-new-' + Date.now() } as any),
  updateMinutes: (id: string, data: any) => tryApi(() => secretaryApi.updateMinutes(id, data), { ...demoMinutes[0], ...data } as any),
  deleteMinutes: (id: string) => tryApi(() => secretaryApi.cancelMeeting(id), { message: 'Minutes deleted' }),
  getSchedule: () => tryApi(() => secretaryApi.getSchedule(), demoMeetings),
  createMeeting: (data: any) => tryApi(() => secretaryApi.createMeeting(data), { ...data, id: 'mt-new-' + Date.now() } as any),
  cancelMeeting: (id: string) => tryApi(() => secretaryApi.cancelMeeting(id), { message: 'Meeting cancelled' }),
  getEvents: () => tryApi(() => adminApi.getEvents(), demoEvents),
  createEvent: (data: any) => tryApi(() => adminApi.createEvent(data), { ...data, id: 'e-new-' + Date.now() } as any),
  getWithdrawalApprovals: () => tryApi(() => adminApi.getWithdrawalApprovals(), demoWithdrawals),
  approveWithdrawal: (id: string, action: string) => tryApi(() => adminApi.approveWithdrawal(id, action as any), { message: `Withdrawal ${action}d` }),
};

export const mockChairperson = {
  dashboard: () => tryApi(() => chairpersonApi.dashboard(), {
    pending_approvals: demoMinutes.filter(m => m.status === 'pending_review').length,
    approved_count: demoMinutes.filter(m => m.status === 'approved').length,
    rejected_count: 0,
    pending_items: demoMinutes.filter(m => m.status === 'pending_review'),
    recent_approvals: [
      { title: 'Q3 Members Meeting Minutes', date: daysAgoStr(10), action: 'approved' },
    ],
  }),
  getPendingApprovals: () => tryApi(() => chairpersonApi.getPendingApprovals(),
    demoMinutes.filter(m => m.status === 'pending_review')),
  approveMinutes: (id: string, action: string) => tryApi(
    () => chairpersonApi.approveMinutes(id, action as any), { message: `Minutes ${action}d` },
  ),
  getWithdrawalApprovals: () => tryApi(() => adminApi.getWithdrawalApprovals(), demoWithdrawals),
  approveWithdrawal: (id: string, action: string) => tryApi(() => adminApi.approveWithdrawal(id, action as any), { message: `Withdrawal ${action}d` }),
};

export const mockViceSecretary = {
  dashboard: () => tryApi(() => viceSecretaryApi.dashboard(), {
    total_events: demoEvents.length,
    active_events: demoEvents.filter(e => e.status === 'upcoming').length,
    total_documents: demoDocuments.length,
    pending_documents: demoDocuments.filter(d => d.status === 'pending').length,
    active_members: demoMembers.filter(m => m.status === 'active').length,
    recent_messages: demoContactMessages.length,
    unread_notifications: demoNotifications.filter(n => !n.read).length,
    recent_documents: demoDocuments.slice(0, 5),
    event_summary: [
      { type: 'AGM', count: 1 },
      { type: 'Fundraiser', count: 1 },
      { type: 'Wellness', count: 1 },
      { type: 'Meeting', count: 1 },
    ],
    recent_activity: [
      { description: 'Event created: Members Wellness Day', date: daysAgoStr(3) },
      { description: 'Document uploaded: ID Document', date: daysAgoStr(5) },
      { description: 'Meeting minutes created: Executive Committee', date: daysAgoStr(7) },
    ],
  }),
  getMeetingMinutes: () => tryApi(() => viceSecretaryApi.getMeetingMinutes(), demoMinutes),
  createMinutes: (data: any) => tryApi(() => viceSecretaryApi.createMinutes(data), { ...data, id: 'min-new-' + Date.now() } as any),
  updateMinutes: (id: string, data: any) => tryApi(() => viceSecretaryApi.updateMinutes(id, data), { ...demoMinutes[0], ...data } as any),
  submitMinutes: (id: string) => tryApi(() => viceSecretaryApi.submitMinutes(id), { message: 'Minutes submitted' }),
};

export const mockOffice = {
  dashboard: () => tryApi(() => officeApi.dashboard(), {
    total_members: 512, pending_applications: demoRegistrationApplicants.filter(a => a.status === 'pending').length,
    pending_documents: demoDocuments.filter(d => d.status === 'pending').length,
    recent_activities: [
      { description: 'New member registration: Patrick Maina', date: daysAgoStr(2) },
      { description: 'Document approved: ID Document', date: daysAgoStr(5) },
      { description: 'Member status updated: Peter Mbugua', date: daysAgoStr(7) },
    ],
  }),
};

export const mockSuperAdmin = {
  dashboard: () => tryApi(() => superAdminApi.dashboard(), {
    total_users: 526, online_users: demoOnlineUsers.length, total_members: 512,
    system_health: demoSystemMetrics, recent_logins: demoLoginActivity.slice(0, 5),
    pending_alerts: 2,
  }),
  getMemberDetail: (id: string) => tryApi(() => superAdminApi.getMemberDetail(id),
    demoMembers.find(m => m.id === id) || demoMembers[0]),
  getTroubleshooting: () => tryApi(() => superAdminApi.getTroubleshooting(), {
    issues: [
      { id: 'is1', severity: 'warning', module: 'Database', description: 'Slow query detected on contributions table', date: daysAgoStr(1) },
      { id: 'is2', severity: 'info', module: 'Cache', description: 'Cache rebuild scheduled', date: daysAgoStr(2) },
    ],
    diagnostics: demoSystemMetrics,
  }),
  getAuditLogs: (page = 1) => tryApi(() => superAdminApi.getAuditLogs(page),
    () => paginate(demoAuditLogs, page)),
  getSecuritySettings: () => tryApi(() => superAdminApi.getSecuritySettings(), {
    two_factor: false,
    password_policy: { min_length: 8, require_special: true, require_numbers: true },
    session_timeout: 60, ip_whitelist: [],
  }),
  updateSecuritySettings: (data: any) => tryApi(() => superAdminApi.updateSecuritySettings(data), { message: 'Security settings updated' }),
  getPasswordManagement: () => tryApi(() => superAdminApi.getPasswordManagement(), {
    policies: demoSystemMetrics.slice(0, 3), expired_passwords: 5, forced_resets: 2,
  }),
  getAccessControl: () => tryApi(() => superAdminApi.getAccessControl(), demoAccessRoles),
  updateRolePermissions: (id: string, _permissions: string[]) => tryApi(
    () => superAdminApi.updateRolePermissions(id, _permissions), { message: 'Permissions updated' }),
  getOnlineUsers: () => tryApi(() => superAdminApi.getOnlineUsers(), demoOnlineUsers),
  getLoginActivity: (page = 1) => tryApi(() => superAdminApi.getLoginActivity(page),
    () => paginate(demoLoginActivity, page)),
  getSystemMonitoring: () => tryApi(() => superAdminApi.getSystemMonitoring(), {
    metrics: demoSystemMetrics,
    services: [
      { name: 'API Server', status: 'healthy', uptime: '99.98%' },
      { name: 'Database', status: 'healthy', uptime: '99.99%' },
      { name: 'Redis Cache', status: 'healthy', uptime: '100%' },
      { name: 'Queue Worker', status: 'healthy', uptime: '99.95%' },
      { name: 'Mail Service', status: 'warning', uptime: '98.20%' },
    ],
  }),
  getBank: () => tryApi(() => superAdminApi.getBank(), {
    connected: true, last_sync: daysAgoStr(1),
    accounts: [{ name: 'KHWWC Main Account', number: '****1234', balance: 6920000, status: 'active' }],
  }),
  getDatabase: () => tryApi(() => superAdminApi.getDatabase(), {
    size: '2.4 GB', tables: 28, connections: 8, slow_queries: 2, backup_last: daysAgoStr(1),
  }),
  getSystem: () => tryApi(() => superAdminApi.getSystem(), {
    version: '2.4.1', uptime: '45 days', environment: 'production',
    features: [
      { name: 'JWT Auth', enabled: true }, { name: 'M-Pesa Integration', enabled: true },
      { name: 'Bank Sync', enabled: true }, { name: 'SMS Notifications', enabled: true },
      { name: 'Email Notifications', enabled: true }, { name: 'File Uploads', enabled: true },
    ],
  }),
  getTrace: () => tryApi(() => superAdminApi.getTrace(), {
    traces: [
      { id: 'tr1', request: 'GET /api/treasurer/dashboard', duration: '142ms', status: 200, date: daysAgoStr(0) },
      { id: 'tr2', request: 'POST /api/auth/login', duration: '89ms', status: 200, date: daysAgoStr(0) },
      { id: 'tr3', request: 'GET /api/admin/members?page=1', duration: '215ms', status: 200, date: daysAgoStr(0) },
    ],
  }),
};

function daysAgoStr(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString();
}
