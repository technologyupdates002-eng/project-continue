import { api } from './client';
import type {
  Paginated,
  Member,
  Contribution,
  Beneficiary,
  Document,
  WalletTransaction,
  WalletSummary,
  WithdrawalRequest,
  Penalty,
  Donation,
  DonationCampaign,
  Defaulter,
  Expense,
  BankTransaction,
  Notification,
  EventItem,
  NewsArticle,
  Memo,
  MemoTemplate,
  OrganizationSettings,
  Payout,
  PayoutType,
  EligibleMember,
} from '@/types';

export const treasurerApi = {
  dashboard: () => api.get<{
    operational_balance: number;
    contributions_balance: number;
    penalty_balance: number;
    donation_balance: number;
    book_balance: number;
    bank_balance: number;
    reconciliation_diff: number;
    pending_withdrawals: number;
    pending_expenses: number;
    monthly_contributions: { month: string; amount: number }[];
  }>('/treasurer/dashboard'),

  getContributions: (params: { page?: number; status?: string; from?: string; to?: string }) =>
    api.get<Paginated<Contribution>>('/treasurer/contributions', params),

  adjustContribution: (id: string, data: { amount: number; reason: string }) =>
    api.post<{ message: string }>(`/treasurer/contributions/${id}/adjust`, data),

  getPenaltyWallet: (page = 1) =>
    api.get<Paginated<WalletTransaction>>('/treasurer/penalty-wallet', { page }),

  getDonationWallet: (page = 1) =>
    api.get<Paginated<WalletTransaction>>('/treasurer/donation-wallet', { page }),

  getOperationalWallet: (page = 1) =>
    api.get<Paginated<WalletTransaction>>('/treasurer/operational-wallet', { page }),

  getWallets: () => api.get<WalletSummary[]>('/treasurer/wallets'),

  getWithdrawalApprovals: () => api.get<WithdrawalRequest[]>('/treasurer/withdrawal-approvals'),

  approveWithdrawal: (id: string, action: 'approve' | 'reject', note?: string) =>
    api.post<{ message: string }>(`/treasurer/withdrawal-approvals/${id}/${action}`, { note }),

  getDocuments: () => api.get<Document[]>('/treasurer/documents'),

  downloadDocument: (id: string) => api.download(`/treasurer/documents/${id}/download`, `document-${id}.pdf`),

  getReports: () => api.get<{ name: string; type: string; date: string; size: string }[]>('/treasurer/reports'),

  exportReport: (type: string, params?: { from?: string; to?: string }) => {
    const search = params
      ? '?' + Object.entries(params).map(([k, v]) => `${k}=${encodeURIComponent(v || '')}`).join('&')
      : '';
    return api.download(`/treasurer/reports/${type}/export${search}`, `report-${type}.pdf`);
  },

  getImportHistory: () => api.get<unknown[]>('/treasurer/import-batches'),

  getImportBatchDetail: (id: string) => api.get<unknown[]>(`/treasurer/import-batches/${id}`),

  getBankSync: () => api.get<{ last_sync: string; status: string; new_transactions: number }>('/treasurer/bank-sync'),

  triggerBankSync: () => api.post<{ message: string; imported: number }>('/treasurer/bank-sync'),

  getBankStatement: (page = 1) =>
    api.get<Paginated<BankTransaction>>('/treasurer/bank-statement', { page }),

  importBankStatement: (file: File) => {
    const formData = new FormData();
    formData.append('file', file);
    return api.post<{ message: string; imported: number }>('/treasurer/bank-statement-import', formData);
  },

  importBookBalance: (file: File) => {
    const formData = new FormData();
    formData.append('file', file);
    return api.post<{ message: string; imported: number }>('/treasurer/book-balance-import', formData);
  },

  getBookBalance: () => api.get<{ balance: number; last_updated: string; entries: WalletTransaction[] }>('/treasurer/book-balance'),

  getWalletReports: () => api.get<{ wallet: string; transactions: number; balance: number; period: string }[]>('/treasurer/wallet-reports'),

  getExpenses: (page = 1) => api.get<Paginated<Expense>>('/treasurer/expenses', { page }),

  createExpense: (data: Partial<Expense>) =>
    api.post<Expense>('/treasurer/expenses', data),

  approveExpense: (id: string, action: 'approve' | 'reject') =>
    api.post<{ message: string }>(`/treasurer/expenses/${id}/${action}`),

  getDonationCampaigns: () => api.get<DonationCampaign[]>('/treasurer/donation-campaigns'),

  createDonationCampaign: (data: Partial<DonationCampaign>) =>
    api.post<DonationCampaign>('/treasurer/donation-campaigns', data),

  updateDonationCampaign: (id: string, data: Partial<DonationCampaign>) =>
    api.patch<DonationCampaign>(`/treasurer/donation-campaigns/${id}`, data),

  deleteDonationCampaign: (id: string) =>
    api.delete<{ message: string }>(`/treasurer/donation-campaigns/${id}`),

  getMemos: () => api.get<Memo[]>('/treasurer/memos'),

  createMemo: (data: Partial<Memo>) =>
    api.post<Memo>('/treasurer/memos', data),

  updateMemo: (id: string, data: Partial<Memo>) =>
    api.patch<Memo>(`/treasurer/memos/${id}`, data),

  deleteMemo: (id: string) =>
    api.delete<{ message: string }>(`/treasurer/memos/${id}`),

  resendMemo: (id: string) =>
    api.patch<{ message: string }>(`/treasurer/memos/${id}/resend`, {}),

  getMemoTemplates: () => api.get<MemoTemplate[]>('/treasurer/memo-templates'),

  getOrgSettings: () => api.get<OrganizationSettings>('/treasurer/settings'),

  updateOrgSettings: (data: Partial<OrganizationSettings>) =>
    api.patch<{ message: string }>('/treasurer/settings', data),

  getPayouts: () => api.get<Payout[]>('/treasurer/payouts'),

  createPayout: (data: { member_id: string; payout_type: PayoutType; amount: number; reason: string; phone: string; wallet_type: string }) =>
    api.post<Payout>('/treasurer/payouts', data),

  getEligibleMembers: (payoutType: PayoutType) =>
    api.get<EligibleMember[]>('/treasurer/payouts/eligible', { payout_type: payoutType }),
};

export const adminApi = {
  dashboard: () => api.get<{
    total_members: number;
    active_members: number;
    total_contributions: number;
    pending_approvals: number;
    defaulters_count: number;
    recent_signups: Member[];
    contributions_trend: { month: string; amount: number }[];
  }>('/admin/dashboard'),

  getMembers: (params: { page?: number; search?: string; status?: string }) =>
    api.get<Paginated<Member>>('/admin/members', params),

  getMemberDetail: (id: string) => api.get<Member>(`/admin/members/${id}`),

  getMemberContributions: (id: string) =>
    api.get<Contribution[]>(`/admin/members/${id}/contributions`),

  getMemberBeneficiaries: (id: string) =>
    api.get<Beneficiary[]>(`/admin/members/${id}/beneficiaries`),

  getContributions: (params: { page?: number; status?: string }) =>
    api.get<Paginated<Contribution>>('/admin/contributions', params),

  getBeneficiaries: (page = 1) => api.get<Paginated<Beneficiary>>('/admin/beneficiaries', { page }),

  getDefaulters: () => api.get<Defaulter[]>('/admin/defaulters'),

  getPayments: (page = 1) =>
    api.get<Paginated<Contribution>>('/admin/payments', { page }),

  getUnmatchedPayments: () => api.get<Contribution[]>('/admin/unmatched'),

  getBankUnmatched: () => api.get<unknown[]>('/admin/bank-unmatched'),

  reconcileBankUnmatched: (id: string, data: { match_type: string; member_id: string; notes?: string }) =>
    api.post<{ message: string }>(`/admin/bank-unmatched/${id}/reconcile`, data),

  matchPayment: (id: string, memberId: string) =>
    api.post<{ message: string }>(`/admin/unmatched/${id}/match`, { member_id: memberId }),

  getNotifications: () => api.get<Notification[]>('/admin/notifications'),

  sendBulkSms: (data: { message: string; recipients: string[] }) =>
    api.post<{ message: string }>('/admin/sms', data),

  getEvents: () => api.get<EventItem[]>('/admin/events'),

  getNews: (page = 1) => api.get<Paginated<NewsArticle>>('/admin/news', { page }),

  createNews: (data: Partial<NewsArticle>) => api.post<NewsArticle>('/admin/news', data),

  updateNews: (id: string, data: Partial<NewsArticle>) => api.put<NewsArticle>(`/admin/news/${id}`, data),

  createEvent: (data: Partial<EventItem>) => api.post<EventItem>('/admin/events', data),

  getDocuments: () => api.get<Document[]>('/admin/documents'),

  getPenalties: () => api.get<Penalty[]>('/admin/penalty-payments'),

  verifyPenaltyPayment: (id: string) =>
    api.post<{ message: string }>(`/admin/penalty-payments/${id}/verify`),

  getDonations: () => api.get<Donation[]>('/admin/donations'),

  getDonationCampaigns: () => api.get<DonationCampaign[]>('/admin/donation-campaigns'),

  createDonationCampaign: (data: Partial<DonationCampaign>) =>
    api.post<DonationCampaign>('/admin/donation-campaigns', data),

  getWithdrawalApprovals: () => api.get<WithdrawalRequest[]>('/admin/withdrawal-approval'),

  approveWithdrawal: (id: string, action: 'approve' | 'reject') =>
    api.post<{ message: string }>(`/admin/withdrawal-approval/${id}/${action}`),

  importExcel: (file: File) => {
    const formData = new FormData();
    formData.append('file', file);
    return api.post<{ message: string; imported: number }>('/admin/import', formData);
  },

  importBeneficiaries: (file: File) => {
    const formData = new FormData();
    formData.append('file', file);
    return api.post<{ message: string; imported: number }>('/admin/beneficiary-import', formData);
  },

  getSettings: () => api.get<Record<string, string>>('/admin/settings'),

  updateSettings: (data: Record<string, string>) => api.put<{ message: string }>('/admin/settings', data),

  updateOfficeSignatures: (data: Record<string, string>) => api.put<{ message: string }>('/admin/office/signatures', data),

  updateRegistrationSettings: (data: Record<string, string>) => api.put<{ message: string }>('/admin/registration-settings', data),

  getRegistrationApplicants: (page = 1) => api.get<Paginated<Member>>('/admin/registration-applicants', { page }),

  getExecutiveBadges: (page = 1) => api.get<Paginated<Member>>('/admin/executive-badges', { page }),
};
