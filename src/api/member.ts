import { api } from './client';
import type {
  Paginated,
  Contribution,
  Beneficiary,
  Notification,
  Document,
  EventItem,
  NewsArticle,
  Member,
  WithdrawalRequest,
  Penalty,
  Donation,
  DonationCampaign,
} from '@/types';

export const memberApi = {
  dashboard: () => api.get<{
    contributions_total: number;
    contributions_count: number;
    penalties_total: number;
    donations_total: number;
    wallet_balance: number;
    upcoming_events: EventItem[];
    recent_contributions: Contribution[];
    unread_notifications: number;
    status: string;
  }>('/member/dashboard'),

  getContributions: (page = 1) =>
    api.get<Paginated<Contribution>>('/member/contributions', { page }),

  getBeneficiaries: () => api.get<Beneficiary[]>('/member/beneficiaries'),

  addBeneficiary: (data: Partial<Beneficiary>) =>
    api.post<Beneficiary>('/member/beneficiaries', data),

  updateBeneficiary: (id: string, data: Partial<Beneficiary>) =>
    api.put<Beneficiary>(`/member/beneficiaries/${id}`, data),

  deleteBeneficiary: (id: string) => api.delete<void>(`/member/beneficiaries/${id}`),

  getNotifications: () => api.get<Notification[]>('/member/notifications'),

  markNotificationRead: (id: string) =>
    api.patch<void>(`/member/notifications/${id}/read`),

  getDocuments: () => api.get<Document[]>('/member/documents'),

  getDocumentSummary: () => api.get<{ total: number; uploaded: number; pending: number; approved: number; rejected: number; expired: number; completion_pct: number }>('/documents/me/summary'),

  getDocumentTypes: () => api.get<{ code: string; name: string; allowed_member_upload: boolean }[]>('/documents/types'),

  getEvents: () => api.get<EventItem[]>('/member/events'),

  getNews: (page = 1) =>
    api.get<Paginated<NewsArticle>>('/member/news', { page }),

  getProfile: () => api.get<Member>('/member/profile'),

  updateProfile: (data: Partial<Member>) =>
    api.put<Member>('/member/profile', data),

  getPenalties: () => api.get<Penalty[]>('/member/penalties'),

  payPenalty: (id: string, data: { method: string; phone?: string }) =>
    api.post<{ message: string; reference: string }>(`/member/penalties/${id}/pay`, data),

  uploadDocument: (data: { document_type_code: string; file: File; notes?: string; expires_at?: string }) => {
    const formData = new FormData();
    formData.append('document_type_code', data.document_type_code);
    formData.append('file', data.file);
    if (data.notes) formData.append('notes', data.notes);
    if (data.expires_at) formData.append('expires_at', data.expires_at);
    return api.post<Document>('/documents', formData);
  },

  replaceDocument: (id: string, data: { file: File; notes?: string; expires_at?: string }) => {
    const formData = new FormData();
    formData.append('file', data.file);
    if (data.notes) formData.append('notes', data.notes);
    if (data.expires_at) formData.append('expires_at', data.expires_at);
    return api.post<Document>(`/documents/${id}/replace`, formData);
  },

  deleteDocument: (id: string) => api.delete<void>(`/documents/${id}?hard_delete=0`),

  changePassword: (data: { new_password: string }) =>
    api.post<{ message: string }>('/auth/v1/password', data),

  uploadProfilePhoto: (data: { file: File; userId: string }) => {
    const ext = data.file.name.split('.').pop() || 'png';
    const formData = new FormData();
    formData.append('file', data.file);
    return api.upload<{ url: string }>(`/storage/v1/object/profile-images/${data.userId}/profile.${ext}`, formData);
  },

  getWithdrawalReceipts: () => api.get<WithdrawalRequest[]>('/member/withdrawal-receipts'),

  getDonationCampaigns: () => api.get<DonationCampaign[]>('/member/donation-campaigns'),

  donate: (data: { campaign_id: string; amount: number; method: string; phone?: string }) =>
    api.post<{ message: string; reference: string }>('/member/donate', data),

  downloadStatement: (params?: { from?: string; to?: string }) => {
    const search = params
      ? '?' + Object.entries(params).map(([k, v]) => `${k}=${encodeURIComponent(v || '')}`).join('&')
      : '';
    return api.download(`/member/downloads/statement${search}`, 'statement.pdf');
  },

  downloadReceipt: (id: string) =>
    api.download(`/member/downloads/receipt/${id}`, `receipt-${id}.pdf`),

  getExecutiveDashboard: (roleName: string) =>
    api.get<{ role: string; title: string; stats: { label: string; value: string }[]; actions: { label: string; route: string }[] }>(`/member/executive/${roleName}`),

  stkPush: (data: { phone: string; amount: number; account_ref: string; member_id?: string; paymentType: string; transactionDesc?: string; penaltyIds?: string[]; campaignId?: string }) =>
    api.post<{ checkoutRequestId: string; message: string }>('/payments/stk-push', data),

  paymentStatus: (checkoutRequestId: string) =>
    api.get<{ status: string; message?: string }>(`/payments/status/${checkoutRequestId}`),
};
