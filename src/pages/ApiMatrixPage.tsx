import { useState, useMemo } from 'react';
import { Search, Lock, Globe, Download, FileText, Database, Cloud, Zap, Filter } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

interface Endpoint {
  id: string;
  method: Method;
  path: string;
  module: string;
  role: string;
  auth: 'public' | 'authenticated';
  body?: string;
  response: string;
  multipart?: boolean;
  download?: boolean;
  sse?: boolean;
}

const MODULES = [
  'auth', 'public', 'member', 'documents', 'payments', 'treasurer',
  'admin', 'website', 'secretary', 'vice-secretary', 'chairperson',
  'office', 'minutes', 'signatures', 'super-admin', 'ai-assistant', 'group-chat',
];

const ROLE_COLORS: Record<string, string> = {
  '🔓': 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-900',
  '🔐': 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
  '👤': 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-900',
  '🏛️': 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900',
  '💰': 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-950 dark:text-teal-300 dark:border-teal-900',
  '📝': 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950 dark:text-indigo-300 dark:border-indigo-900',
  '📋': 'bg-cyan-50 text-cyan-700 border-cyan-200 dark:bg-cyan-950 dark:text-cyan-300 dark:border-cyan-900',
  '🪑': 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950 dark:text-rose-300 dark:border-rose-900',
  '🏢': 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950 dark:text-orange-300 dark:border-orange-900',
  '🛡️': 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950 dark:text-red-300 dark:border-red-900',
};

const METHOD_COLORS: Record<Method, string> = {
  GET: 'bg-blue-600 text-white',
  POST: 'bg-emerald-600 text-white',
  PUT: 'bg-amber-600 text-white',
  PATCH: 'bg-violet-600 text-white',
  DELETE: 'bg-red-600 text-white',
};

const MODULE_ICONS: Record<string, React.ReactNode> = {
  'auth': <Lock className="h-4 w-4" />,
  'public': <Globe className="h-4 w-4" />,
  'member': <Globe className="h-4 w-4" />,
  'documents': <FileText className="h-4 w-4" />,
  'payments': <FileText className="h-4 w-4" />,
  'treasurer': <FileText className="h-4 w-4" />,
  'admin': <FileText className="h-4 w-4" />,
  'website': <Globe className="h-4 w-4" />,
  'secretary': <FileText className="h-4 w-4" />,
  'vice-secretary': <FileText className="h-4 w-4" />,
  'chairperson': <FileText className="h-4 w-4" />,
  'office': <FileText className="h-4 w-4" />,
  'minutes': <FileText className="h-4 w-4" />,
  'signatures': <FileText className="h-4 w-4" />,
  'super-admin': <Shield className="h-4 w-4" />,
  'ai-assistant': <Zap className="h-4 w-4" />,
  'group-chat': <Cloud className="h-4 w-4" />,
};

import { Shield } from 'lucide-react';

const ENDPOINTS: Endpoint[] = [
  // Auth
  { id: '1.1', method: 'POST', path: '/auth/login', module: 'auth', role: '🔓', auth: 'public', body: '{ phone, password }', response: '{ token, user, requires_password_change? }' },
  { id: '1.2', method: 'POST', path: '/auth/logout', module: 'auth', role: '🔐', auth: 'authenticated', response: '{ message }' },
  { id: '1.3', method: 'GET', path: '/auth/me', module: 'auth', role: '🔐', auth: 'authenticated', response: 'AuthUser' },
  { id: '1.4', method: 'POST', path: '/auth/password', module: 'auth', role: '🔐', auth: 'authenticated', body: '{ current_password, new_password, new_password_confirmation }', response: '{ message }' },
  { id: '1.5', method: 'POST', path: '/auth/force-password-change', module: 'auth', role: '🔐', auth: 'authenticated', body: '{ new_password, new_password_confirmation }', response: '{ message }' },
  { id: '1.6', method: 'POST', path: '/auth/v1/password', module: 'auth', role: '👤', auth: 'authenticated', body: '{ new_password }', response: '{ message }' },

  // Public Website
  { id: '2.1', method: 'GET', path: '/public/website/settings', module: 'public', role: '🔓', auth: 'public', response: 'WebsiteSettings' },
  { id: '2.2', method: 'GET', path: '/public/website/content/{type}', module: 'public', role: '🔓', auth: 'public', body: '?page', response: 'Paginated<WebsiteContent>' },
  { id: '2.3', method: 'GET', path: '/public/website/content/{type}/{slug}', module: 'public', role: '🔓', auth: 'public', response: 'WebsiteContent' },
  { id: '2.4', method: 'GET', path: '/public/website/leadership', module: 'public', role: '🔓', auth: 'public', response: 'LeadershipPerson[]' },
  { id: '2.5', method: 'GET', path: '/public/website/people/{slug}', module: 'public', role: '🔓', auth: 'public', response: 'LeadershipPerson' },
  { id: '2.6', method: 'POST', path: '/public/website/contact', module: 'public', role: '🔓', auth: 'public', body: '{ name, email, subject, message }', response: '{ message }' },

  // Member
  { id: '3.1', method: 'GET', path: '/member/dashboard', module: 'member', role: '👤', auth: 'authenticated', response: 'DashboardSummary' },
  { id: '3.2', method: 'GET', path: '/member/contributions', module: 'member', role: '👤', auth: 'authenticated', body: '?page', response: 'Paginated<Contribution>' },
  { id: '3.3', method: 'GET', path: '/member/beneficiaries', module: 'member', role: '👤', auth: 'authenticated', response: 'Beneficiary[]' },
  { id: '3.4', method: 'POST', path: '/member/beneficiaries', module: 'member', role: '👤', auth: 'authenticated', body: 'Partial<Beneficiary>', response: 'Beneficiary' },
  { id: '3.5', method: 'PUT', path: '/member/beneficiaries/{id}', module: 'member', role: '👤', auth: 'authenticated', body: 'Partial<Beneficiary>', response: 'Beneficiary' },
  { id: '3.6', method: 'DELETE', path: '/member/beneficiaries/{id}', module: 'member', role: '👤', auth: 'authenticated', response: 'void' },
  { id: '3.7', method: 'GET', path: '/member/notifications', module: 'member', role: '👤', auth: 'authenticated', response: 'Notification[]' },
  { id: '3.8', method: 'PATCH', path: '/member/notifications/{id}/read', module: 'member', role: '👤', auth: 'authenticated', response: 'void' },
  { id: '3.9', method: 'GET', path: '/member/documents', module: 'member', role: '👤', auth: 'authenticated', response: 'Document[]' },
  { id: '3.10', method: 'GET', path: '/documents/me/summary', module: 'documents', role: '👤', auth: 'authenticated', response: 'DocumentSummary' },
  { id: '3.11', method: 'GET', path: '/documents/types', module: 'documents', role: '👤', auth: 'authenticated', response: 'DocumentType[]' },
  { id: '3.12', method: 'GET', path: '/member/events', module: 'member', role: '👤', auth: 'authenticated', response: 'EventItem[]' },
  { id: '3.13', method: 'GET', path: '/member/news', module: 'member', role: '👤', auth: 'authenticated', body: '?page', response: 'Paginated<NewsArticle>' },
  { id: '3.14', method: 'GET', path: '/member/profile', module: 'member', role: '👤', auth: 'authenticated', response: 'Member' },
  { id: '3.15', method: 'PUT', path: '/member/profile', module: 'member', role: '👤', auth: 'authenticated', body: 'Partial<Member>', response: 'Member' },
  { id: '3.16', method: 'GET', path: '/member/penalties', module: 'member', role: '👤', auth: 'authenticated', response: 'Penalty[]' },
  { id: '3.17', method: 'POST', path: '/member/penalties/{id}/pay', module: 'member', role: '👤', auth: 'authenticated', body: '{ method, phone? }', response: '{ message, reference }' },
  { id: '3.18', method: 'GET', path: '/member/withdrawal-receipts', module: 'member', role: '👤', auth: 'authenticated', response: 'WithdrawalRequest[]' },
  { id: '3.19', method: 'GET', path: '/member/donation-campaigns', module: 'member', role: '👤', auth: 'authenticated', response: 'DonationCampaign[]' },
  { id: '3.20', method: 'POST', path: '/member/donate', module: 'member', role: '👤', auth: 'authenticated', body: '{ campaign_id, amount, method, phone? }', response: '{ message, reference }' },
  { id: '3.21', method: 'GET', path: '/member/executive/{roleName}', module: 'member', role: '👤', auth: 'authenticated', response: 'ExecutiveDashboard' },
  { id: '3.22', method: 'GET', path: '/member/downloads/statement', module: 'member', role: '👤', auth: 'authenticated', body: '?from&to', response: 'PDF', download: true },
  { id: '3.23', method: 'GET', path: '/member/downloads/receipt/{id}', module: 'member', role: '👤', auth: 'authenticated', response: 'PDF', download: true },
  { id: '3.24', method: 'POST', path: '/documents', module: 'documents', role: '👤', auth: 'authenticated', body: 'multipart: document_type_code, file, notes?, expires_at?', response: 'Document', multipart: true },
  { id: '3.25', method: 'POST', path: '/documents/{id}/replace', module: 'documents', role: '👤', auth: 'authenticated', body: 'multipart: file, notes?, expires_at?', response: 'Document', multipart: true },
  { id: '3.26', method: 'DELETE', path: '/documents/{id}', module: 'documents', role: '👤', auth: 'authenticated', body: '?hard_delete=0', response: 'void' },
  { id: '3.27', method: 'POST', path: '/storage/v1/object/profile-images/{userId}/profile.{ext}', module: 'member', role: '👤', auth: 'authenticated', body: 'multipart: file', response: '{ url }', multipart: true },
  { id: '3.28', method: 'POST', path: '/payments/stk-push', module: 'payments', role: '👤', auth: 'authenticated', body: '{ phone, amount, account_ref, member_id?, paymentType, transactionDesc?, penaltyIds?, campaignId? }', response: '{ checkoutRequestId, message }' },
  { id: '3.29', method: 'GET', path: '/payments/status/{checkoutRequestId}', module: 'payments', role: '👤', auth: 'authenticated', response: '{ status, message? }' },

  // Treasurer
  { id: '4.1', method: 'GET', path: '/treasurer/dashboard', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'TreasurerDashboard' },
  { id: '4.2', method: 'GET', path: '/treasurer/contributions', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?page&status&from&to', response: 'Paginated<Contribution>' },
  { id: '4.3', method: 'POST', path: '/treasurer/contributions/{id}/adjust', module: 'treasurer', role: '💰', auth: 'authenticated', body: '{ amount, reason }', response: '{ message }' },
  { id: '4.4', method: 'GET', path: '/treasurer/penalty-wallet', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?page', response: 'Paginated<WalletTransaction>' },
  { id: '4.5', method: 'GET', path: '/treasurer/donation-wallet', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?page', response: 'Paginated<WalletTransaction>' },
  { id: '4.6', method: 'GET', path: '/treasurer/operational-wallet', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?page', response: 'Paginated<WalletTransaction>' },
  { id: '4.7', method: 'GET', path: '/treasurer/wallets', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'WalletSummary[]' },
  { id: '4.8', method: 'GET', path: '/treasurer/withdrawal-approvals', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'WithdrawalRequest[]' },
  { id: '4.9', method: 'POST', path: '/treasurer/withdrawal-approvals/{id}/{approve|reject}', module: 'treasurer', role: '💰', auth: 'authenticated', body: '{ note? }', response: '{ message }' },
  { id: '4.10', method: 'GET', path: '/treasurer/documents', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'Document[]' },
  { id: '4.11', method: 'GET', path: '/treasurer/reports', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'Report[]' },
  { id: '4.12', method: 'GET', path: '/treasurer/reports/{type}/export', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?from&to', response: 'PDF', download: true },
  { id: '4.13', method: 'GET', path: '/treasurer/bank-sync', module: 'treasurer', role: '💰', auth: 'authenticated', response: '{ last_sync, status, new_transactions }' },
  { id: '4.14', method: 'POST', path: '/treasurer/bank-sync', module: 'treasurer', role: '💰', auth: 'authenticated', response: '{ message, imported }' },
  { id: '4.15', method: 'GET', path: '/treasurer/bank-statement', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?page', response: 'Paginated<BankTransaction>' },
  { id: '4.16', method: 'GET', path: '/treasurer/book-balance', module: 'treasurer', role: '💰', auth: 'authenticated', response: '{ balance, last_updated, entries[] }' },
  { id: '4.17', method: 'GET', path: '/treasurer/wallet-reports', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'WalletReport[]' },
  { id: '4.18', method: 'GET', path: '/treasurer/expenses', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?page', response: 'Paginated<Expense>' },
  { id: '4.19', method: 'POST', path: '/treasurer/expenses', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'Partial<Expense>', response: 'Expense' },
  { id: '4.20', method: 'POST', path: '/treasurer/expenses/{id}/{approve|reject}', module: 'treasurer', role: '💰', auth: 'authenticated', response: '{ message }' },
  { id: '4.21', method: 'GET', path: '/treasurer/donation-campaigns', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'DonationCampaign[]' },
  { id: '4.22', method: 'POST', path: '/treasurer/donation-campaigns', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'Partial<DonationCampaign>', response: 'DonationCampaign' },
  { id: '4.23', method: 'PATCH', path: '/treasurer/donation-campaigns/{id}', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'Partial<DonationCampaign>', response: 'DonationCampaign' },
  { id: '4.24', method: 'DELETE', path: '/treasurer/donation-campaigns/{id}', module: 'treasurer', role: '💰', auth: 'authenticated', response: '{ message }' },
  { id: '4.25', method: 'GET', path: '/treasurer/memos', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'Memo[]' },
  { id: '4.26', method: 'POST', path: '/treasurer/memos', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'Partial<Memo>', response: 'Memo' },
  { id: '4.27', method: 'PATCH', path: '/treasurer/memos/{id}', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'Partial<Memo>', response: 'Memo' },
  { id: '4.28', method: 'DELETE', path: '/treasurer/memos/{id}', module: 'treasurer', role: '💰', auth: 'authenticated', response: '{ message }' },
  { id: '4.29', method: 'PATCH', path: '/treasurer/memos/{id}/resend', module: 'treasurer', role: '💰', auth: 'authenticated', body: '{}', response: '{ message }' },
  { id: '4.30', method: 'GET', path: '/treasurer/memo-templates', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'MemoTemplate[]' },
  { id: '4.31', method: 'GET', path: '/treasurer/settings', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'OrganizationSettings' },
  { id: '4.32', method: 'PATCH', path: '/treasurer/settings', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'Partial<OrganizationSettings>', response: '{ message }' },
  { id: '4.33', method: 'GET', path: '/treasurer/payouts', module: 'treasurer', role: '💰', auth: 'authenticated', response: 'Payout[]' },
  { id: '4.34', method: 'POST', path: '/treasurer/payouts', module: 'treasurer', role: '💰', auth: 'authenticated', body: '{ member_id, payout_type, amount, reason, phone, wallet_type }', response: 'Payout' },
  { id: '4.35', method: 'GET', path: '/treasurer/payouts/eligible', module: 'treasurer', role: '💰', auth: 'authenticated', body: '?payout_type', response: 'EligibleMember[]' },
  { id: '4.36', method: 'POST', path: '/treasurer/bank-statement-import', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'multipart: file', response: '{ message, imported }', multipart: true },
  { id: '4.37', method: 'POST', path: '/treasurer/bank-statement-import', module: 'treasurer', role: '💰', auth: 'authenticated', body: '{ rows: [[]], source_name?, file_name? }', response: 'ImportBatchResponse' },
  { id: '4.38', method: 'POST', path: '/treasurer/book-balance-import', module: 'treasurer', role: '💰', auth: 'authenticated', body: 'multipart: file', response: '{ message, imported }', multipart: true },

  // Admin
  { id: '5.1', method: 'GET', path: '/admin/dashboard', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'AdminDashboard' },
  { id: '5.2', method: 'GET', path: '/admin/members', module: 'admin', role: '🏛️', auth: 'authenticated', body: '?page&search&status', response: 'Paginated<Member>' },
  { id: '5.3', method: 'GET', path: '/admin/members/{id}', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Member' },
  { id: '5.4', method: 'GET', path: '/admin/members/{id}/contributions', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Contribution[]' },
  { id: '5.5', method: 'GET', path: '/admin/members/{id}/beneficiaries', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Beneficiary[]' },
  { id: '5.6', method: 'GET', path: '/admin/contributions', module: 'admin', role: '🏛️', auth: 'authenticated', body: '?page&status', response: 'Paginated<Contribution>' },
  { id: '5.7', method: 'GET', path: '/admin/beneficiaries', module: 'admin', role: '🏛️', auth: 'authenticated', body: '?page', response: 'Paginated<Beneficiary>' },
  { id: '5.8', method: 'GET', path: '/admin/defaulters', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Defaulter[]' },
  { id: '5.9', method: 'GET', path: '/admin/payments', module: 'admin', role: '🏛️', auth: 'authenticated', body: '?page', response: 'Paginated<Contribution>' },
  { id: '5.10', method: 'GET', path: '/admin/unmatched', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Contribution[]' },
  { id: '5.11', method: 'POST', path: '/admin/unmatched/{id}/match', module: 'admin', role: '🏛️', auth: 'authenticated', body: '{ member_id }', response: '{ message }' },
  { id: '5.12', method: 'GET', path: '/admin/notifications', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Notification[]' },
  { id: '5.13', method: 'POST', path: '/admin/sms', module: 'admin', role: '🏛️', auth: 'authenticated', body: '{ message, recipients[] }', response: '{ message }' },
  { id: '5.14', method: 'GET', path: '/admin/events', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'EventItem[]' },
  { id: '5.15', method: 'POST', path: '/admin/events', module: 'admin', role: '🏛️', auth: 'authenticated', body: 'Partial<EventItem>', response: 'EventItem' },
  { id: '5.16', method: 'GET', path: '/admin/documents', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Document[]' },
  { id: '5.17', method: 'GET', path: '/admin/penalty-payments', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Penalty[]' },
  { id: '5.18', method: 'POST', path: '/admin/penalty-payments/{id}/verify', module: 'admin', role: '🏛️', auth: 'authenticated', response: '{ message }' },
  { id: '5.19', method: 'GET', path: '/admin/donations', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'Donation[]' },
  { id: '5.20', method: 'GET', path: '/admin/donation-campaigns', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'DonationCampaign[]' },
  { id: '5.21', method: 'POST', path: '/admin/donation-campaigns', module: 'admin', role: '🏛️', auth: 'authenticated', body: 'Partial<DonationCampaign>', response: 'DonationCampaign' },
  { id: '5.22', method: 'GET', path: '/admin/withdrawal-approval', module: 'admin', role: '🏛️', auth: 'authenticated', response: 'WithdrawalRequest[]' },
  { id: '5.23', method: 'POST', path: '/admin/withdrawal-approval/{id}/{approve|reject}', module: 'admin', role: '🏛️', auth: 'authenticated', response: '{ message }' },
  { id: '5.24', method: 'POST', path: '/admin/import', module: 'admin', role: '🏛️', auth: 'authenticated', body: 'multipart: file', response: '{ message, imported }', multipart: true },
  { id: '5.25', method: 'POST', path: '/admin/beneficiary-import', module: 'admin', role: '🏛️', auth: 'authenticated', body: 'multipart: file', response: '{ message, imported }', multipart: true },

  // Website Management
  { id: '6.1', method: 'GET', path: '/admin/website/dashboard', module: 'website', role: '🏛️', auth: 'authenticated', response: 'WebsiteDashboard' },
  { id: '6.2', method: 'GET', path: '/admin/website/content', module: 'website', role: '🏛️', auth: 'authenticated', body: '?page', response: 'Paginated<WebsiteContent>' },
  { id: '6.3', method: 'POST', path: '/admin/website/content', module: 'website', role: '🏛️', auth: 'authenticated', body: 'Partial<WebsiteContent>', response: 'WebsiteContent' },
  { id: '6.4', method: 'PUT', path: '/admin/website/content/{id}', module: 'website', role: '🏛️', auth: 'authenticated', body: 'Partial<WebsiteContent>', response: 'WebsiteContent' },
  { id: '6.5', method: 'POST', path: '/admin/website/content/{id}/archive', module: 'website', role: '🏛️', auth: 'authenticated', response: 'void' },
  { id: '6.6', method: 'GET', path: '/admin/website/people', module: 'website', role: '🏛️', auth: 'authenticated', response: 'LeadershipPerson[]' },
  { id: '6.7', method: 'POST', path: '/admin/website/people', module: 'website', role: '🏛️', auth: 'authenticated', body: 'Partial<LeadershipPerson>', response: 'LeadershipPerson' },
  { id: '6.8', method: 'PUT', path: '/admin/website/people/{id}', module: 'website', role: '🏛️', auth: 'authenticated', body: 'Partial<LeadershipPerson>', response: 'LeadershipPerson' },
  { id: '6.9', method: 'DELETE', path: '/admin/website/people/{id}', module: 'website', role: '🏛️', auth: 'authenticated', response: 'void' },
  { id: '6.10', method: 'GET', path: '/admin/website/people/leadership-members', module: 'website', role: '🏛️', auth: 'authenticated', response: 'Member[]' },
  { id: '6.11', method: 'GET', path: '/admin/website/contact-messages', module: 'website', role: '🏛️', auth: 'authenticated', body: '?page', response: 'Paginated<ContactMessage>' },
  { id: '6.12', method: 'POST', path: '/admin/website/contact-messages/{id}/mark-read', module: 'website', role: '🏛️', auth: 'authenticated', response: 'void' },
  { id: '6.13', method: 'GET', path: '/admin/website/media', module: 'website', role: '🏛️', auth: 'authenticated', response: 'MediaAsset[]' },
  { id: '6.14', method: 'GET', path: '/admin/website/settings/{section}', module: 'website', role: '🏛️', auth: 'authenticated', response: 'Record<string, unknown>' },
  { id: '6.15', method: 'PUT', path: '/admin/website/settings/{section}', module: 'website', role: '🏛️', auth: 'authenticated', body: 'Record<string, unknown>', response: '{ message }' },
  { id: '6.16', method: 'GET', path: '/admin/website/email-templates', module: 'website', role: '🏛️', auth: 'authenticated', response: 'EmailTemplate[]' },
  { id: '6.17', method: 'PUT', path: '/admin/website/email-templates/{id}', module: 'website', role: '🏛️', auth: 'authenticated', body: 'Partial<EmailTemplate>', response: 'EmailTemplate' },
  { id: '6.18', method: 'POST', path: '/admin/website/email-templates/{id}/test', module: 'website', role: '🏛️', auth: 'authenticated', body: '{ email }', response: '{ message }' },

  // Secretary
  { id: '7.1', method: 'GET', path: '/secretary/dashboard', module: 'secretary', role: '📝', auth: 'authenticated', response: 'SecretaryDashboard' },
  { id: '7.2', method: 'GET', path: '/secretary/minutes-review', module: 'secretary', role: '📝', auth: 'authenticated', response: 'MeetingMinutes[]' },
  { id: '7.3', method: 'POST', path: '/secretary/minutes/{id}/submit-review', module: 'secretary', role: '📝', auth: 'authenticated', response: '{ message }' },
  { id: '7.4', method: 'GET', path: '/secretary/meeting-minutes', module: 'secretary', role: '📝', auth: 'authenticated', response: 'MeetingMinutes[]' },
  { id: '7.5', method: 'POST', path: '/secretary/meeting-minutes', module: 'secretary', role: '📝', auth: 'authenticated', body: 'Partial<MeetingMinutes>', response: 'MeetingMinutes' },
  { id: '7.6', method: 'PUT', path: '/secretary/meeting-minutes/{id}', module: 'secretary', role: '📝', auth: 'authenticated', body: 'Partial<MeetingMinutes>', response: 'MeetingMinutes' },
  { id: '7.7', method: 'GET', path: '/secretary/meeting-scheduling', module: 'secretary', role: '📝', auth: 'authenticated', response: 'ScheduledMeeting[]' },
  { id: '7.8', method: 'POST', path: '/secretary/meeting-scheduling', module: 'secretary', role: '📝', auth: 'authenticated', body: 'Partial<ScheduledMeeting>', response: 'ScheduledMeeting' },
  { id: '7.9', method: 'POST', path: '/secretary/meeting-scheduling/{id}/cancel', module: 'secretary', role: '📝', auth: 'authenticated', response: '{ message }' },

  // Vice-Secretary
  { id: '8.1', method: 'GET', path: '/vice-secretary/dashboard', module: 'vice-secretary', role: '📋', auth: 'authenticated', response: 'ViceSecretaryDashboard' },
  { id: '8.2', method: 'GET', path: '/vice-secretary/meeting-minutes', module: 'vice-secretary', role: '📋', auth: 'authenticated', response: 'MeetingMinutes[]' },
  { id: '8.3', method: 'POST', path: '/vice-secretary/meeting-minutes', module: 'vice-secretary', role: '📋', auth: 'authenticated', body: 'Partial<MeetingMinutes>', response: 'MeetingMinutes' },
  { id: '8.4', method: 'PUT', path: '/vice-secretary/meeting-minutes/{id}', module: 'vice-secretary', role: '📋', auth: 'authenticated', body: 'Partial<MeetingMinutes>', response: 'MeetingMinutes' },
  { id: '8.5', method: 'POST', path: '/vice-secretary/meeting-minutes/{id}/submit', module: 'vice-secretary', role: '📋', auth: 'authenticated', response: '{ message }' },

  // Chairperson
  { id: '9.1', method: 'GET', path: '/chairperson/dashboard', module: 'chairperson', role: '🪑', auth: 'authenticated', response: 'ChairpersonDashboard' },
  { id: '9.2', method: 'GET', path: '/chairperson/approve-minutes', module: 'chairperson', role: '🪑', auth: 'authenticated', response: 'MeetingMinutes[]' },
  { id: '9.3', method: 'POST', path: '/chairperson/approve-minutes/{id}/{approve|reject}', module: 'chairperson', role: '🪑', auth: 'authenticated', body: '{ note? }', response: '{ message }' },

  // Office
  { id: '10.1', method: 'GET', path: '/office/dashboard', module: 'office', role: '🏢', auth: 'authenticated', response: 'OfficeDashboard' },

  // Minutes (shared)
  { id: '11.1', method: 'GET', path: '/minutes', module: 'minutes', role: '🔐', auth: 'authenticated', body: '?filter=mine|secretary_pending|pending', response: 'HydratedMinute[]' },
  { id: '11.2', method: 'GET', path: '/minutes/{id}', module: 'minutes', role: '🔐', auth: 'authenticated', response: 'HydratedMinute' },
  { id: '11.3', method: 'POST', path: '/minutes', module: 'minutes', role: '🔐', auth: 'authenticated', body: 'CreateMinutePayload', response: '{ data: HydratedMinute }' },
  { id: '11.4', method: 'PUT', path: '/minutes/{id}', module: 'minutes', role: '🔐', auth: 'authenticated', body: 'Partial<CreateMinutePayload>', response: '{ data: HydratedMinute }' },
  { id: '11.5', method: 'DELETE', path: '/minutes/{id}', module: 'minutes', role: '🔐', auth: 'authenticated', response: '{ data: { deleted: boolean } }' },
  { id: '11.6', method: 'POST', path: '/minutes/{id}/submit', module: 'minutes', role: '🔐', auth: 'authenticated', response: '{ message }' },
  { id: '11.7', method: 'POST', path: '/minutes/{id}/secretary-review', module: 'minutes', role: '📝', auth: 'authenticated', body: '{ decision, notes? }', response: '{ message }' },
  { id: '11.8', method: 'POST', path: '/minutes/{id}/approve', module: 'minutes', role: '🪑', auth: 'authenticated', response: '{ message }' },
  { id: '11.9', method: 'POST', path: '/minutes/{id}/reject', module: 'minutes', role: '🪑', auth: 'authenticated', body: '{ notes }', response: '{ message }' },
  { id: '11.10', method: 'GET', path: '/minutes/executive-members', module: 'minutes', role: '🔐', auth: 'authenticated', response: 'MinuteMember[]' },
  { id: '11.11', method: 'GET', path: '/rest/v1/members', module: 'minutes', role: '🔐', auth: 'authenticated', body: '?limit', response: 'MinuteMember[]' },

  // Signatures (shared)
  { id: '12.1', method: 'GET', path: '/signatures/current', module: 'signatures', role: '🔐', auth: 'authenticated', body: '?role', response: '{ url, uploaded_at } | null' },
  { id: '12.2', method: 'POST', path: '/signatures', module: 'signatures', role: '🔐', auth: 'authenticated', body: '{ role, file_name }', response: '{ message, url }' },
  { id: '12.3', method: 'POST', path: '/signatures', module: 'signatures', role: '🔐', auth: 'authenticated', body: 'multipart: file, role', response: '{ message, url }', multipart: true },

  // Super-Admin
  { id: '13.1', method: 'GET', path: '/super-admin/dashboard', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'SuperAdminDashboard' },
  { id: '13.2', method: 'GET', path: '/super-admin/members/{id}', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'Member' },
  { id: '13.3', method: 'GET', path: '/super-admin/troubleshooting', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'TroubleshootingData' },
  { id: '13.4', method: 'GET', path: '/super-admin/audit-logs', module: 'super-admin', role: '🛡️', auth: 'authenticated', body: '?page', response: 'Paginated<AuditLog>' },
  { id: '13.5', method: 'GET', path: '/super-admin/security', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'SecuritySettings' },
  { id: '13.6', method: 'PUT', path: '/super-admin/security', module: 'super-admin', role: '🛡️', auth: 'authenticated', body: 'Record<string, unknown>', response: '{ message }' },
  { id: '13.7', method: 'GET', path: '/super-admin/password-management', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'PasswordManagement' },
  { id: '13.8', method: 'GET', path: '/super-admin/access-control', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'AccessRole[]' },
  { id: '13.9', method: 'PUT', path: '/super-admin/access-control/{id}', module: 'super-admin', role: '🛡️', auth: 'authenticated', body: '{ permissions[] }', response: '{ message }' },
  { id: '13.10', method: 'GET', path: '/super-admin/online-users', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'OnlineUser[]' },
  { id: '13.11', method: 'GET', path: '/super-admin/login-activity', module: 'super-admin', role: '🛡️', auth: 'authenticated', body: '?page', response: 'Paginated<LoginActivity>' },
  { id: '13.12', method: 'GET', path: '/super-admin/system-monitoring', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'SystemMonitoring' },
  { id: '13.13', method: 'GET', path: '/super-admin/bank', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'BankStatus' },
  { id: '13.14', method: 'GET', path: '/super-admin/database', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'DatabaseStatus' },
  { id: '13.15', method: 'GET', path: '/super-admin/system', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: 'SystemInfo' },
  { id: '13.16', method: 'GET', path: '/super-admin/trace', module: 'super-admin', role: '🛡️', auth: 'authenticated', response: '{ traces[] }' },

  // AI Assistant
  { id: '14.1', method: 'POST', path: '/functions/v1/ai-assistant', module: 'ai-assistant', role: '🔐', auth: 'authenticated', body: '{ message, conversationId?, userId, userRole, contextType?, history? }', response: 'SSE stream', sse: true },
  { id: '14.2', method: 'GET', path: '/functions/v1/ai-assistant/conversation', module: 'ai-assistant', role: '🔐', auth: 'authenticated', body: '?context_type&conversation_id', response: '{ conversation_id, messages[] }' },

  // Group Chat (Supabase direct)
  { id: '15.1', method: 'GET', path: 'chat_participants + chat_conversations', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'GroupConversation[]' },
  { id: '15.2', method: 'GET', path: 'chat_messages + chat_reactions', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'GroupMessage[]' },
  { id: '15.3', method: 'GET', path: 'chat_participants', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'GroupParticipant[]' },
  { id: '15.4', method: 'POST', path: 'chat_messages (INSERT)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'GroupMessage' },
  { id: '15.5', method: 'DELETE', path: 'chat_messages (DELETE)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'void' },
  { id: '15.6', method: 'PATCH', path: 'chat_participants (UPDATE last_read_at)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'void' },
  { id: '15.7', method: 'POST', path: 'chat_typing (UPSERT/DELETE)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'void' },
  { id: '15.8', method: 'POST', path: 'chat_reactions (INSERT/DELETE)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'void' },
  { id: '15.9', method: 'POST', path: 'chat_conversations + chat_participants (INSERT)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'GroupConversation' },
  { id: '15.10', method: 'POST', path: 'chat_participants (INSERT)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'void' },
  { id: '15.11', method: 'DELETE', path: 'chat_participants (DELETE)', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'void' },
  { id: '15.12', method: 'GET', path: 'Realtime: chat_messages channel', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'realtime subscription' },
  { id: '15.13', method: 'GET', path: 'Realtime: chat_reactions channel', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'realtime subscription' },
  { id: '15.14', method: 'GET', path: 'Realtime: chat_typing channel', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'realtime subscription' },
  { id: '15.15', method: 'GET', path: 'Realtime: conversations channel', module: 'group-chat', role: '🔐', auth: 'authenticated', response: 'realtime subscription' },
];

export default function ApiMatrixPage() {
  const { demoMode } = useAuth();
  const [search, setSearch] = useState('');
  const [moduleFilter, setModuleFilter] = useState<string>('all');
  const [methodFilter, setMethodFilter] = useState<string>('all');
  const [expanded, setExpanded] = useState<string | null>(null);

  const filtered = useMemo(() => {
    return ENDPOINTS.filter((e) => {
      if (moduleFilter !== 'all' && e.module !== moduleFilter) return false;
      if (methodFilter !== 'all' && e.method !== methodFilter) return false;
      if (search) {
        const q = search.toLowerCase();
        return (
          e.path.toLowerCase().includes(q) ||
          e.module.toLowerCase().includes(q) ||
          e.response.toLowerCase().includes(q) ||
          (e.body?.toLowerCase().includes(q) ?? false)
        );
      }
      return true;
    });
  }, [search, moduleFilter, methodFilter]);

  const moduleCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    for (const e of ENDPOINTS) {
      counts[e.module] = (counts[e.module] || 0) + 1;
    }
    return counts;
  }, []);

  const methodCounts = useMemo(() => {
    const counts: Record<string, number> = {};
    for (const e of ENDPOINTS) {
      counts[e.method] = (counts[e.method] || 0) + 1;
    }
    return counts;
  }, []);

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
      {/* Header */}
      <div className="sticky top-0 z-10 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
        <div className="mx-auto max-w-7xl px-4 py-4 sm:px-6">
          <div className="flex items-center justify-between gap-4">
            <div>
              <h1 className="text-xl font-bold text-slate-900 dark:text-white sm:text-2xl">
                Laravel API Matrix
              </h1>
              <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                Complete endpoint reference — public website to portal
              </p>
            </div>
            <div className="flex items-center gap-2">
              <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${
                demoMode
                  ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                  : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
              }`}>
                <span className={`h-2 w-2 rounded-full ${demoMode ? 'bg-amber-500' : 'bg-emerald-500'}`} />
                {demoMode ? 'Demo Mode' : 'Production Mode'}
              </span>
            </div>
          </div>

          {/* Stats */}
          <div className="mt-4 flex flex-wrap gap-2">
            <div className="rounded-lg bg-slate-100 px-3 py-1.5 dark:bg-slate-800">
              <span className="text-xs font-medium text-slate-500 dark:text-slate-400">Total: </span>
              <span className="text-sm font-bold text-slate-900 dark:text-white">{ENDPOINTS.length}</span>
            </div>
            {(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as Method[]).map((m) => (
              <div key={m} className="rounded-lg bg-slate-100 px-3 py-1.5 dark:bg-slate-800">
                <span className={`text-xs font-bold ${METHOD_COLORS[m]} rounded px-1.5 py-0.5`}>{m}</span>
                <span className="ml-1.5 text-sm font-bold text-slate-900 dark:text-white">{methodCounts[m] || 0}</span>
              </div>
            ))}
          </div>

          {/* Search + Filters */}
          <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                placeholder="Search endpoints by path, module, response..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full rounded-lg border border-slate-200 bg-white py-2 pl-10 pr-4 text-sm text-slate-900 placeholder:text-slate-400 focus:border-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-white dark:placeholder:text-slate-500 dark:focus:ring-slate-700"
              />
            </div>
            <div className="flex gap-2">
              <select
                value={moduleFilter}
                onChange={(e) => setModuleFilter(e.target.value)}
                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-400 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                <option value="all">All Modules</option>
                {MODULES.map((m) => (
                  <option key={m} value={m}>
                    {m} ({moduleCounts[m] || 0})
                  </option>
                ))}
              </select>
              <select
                value={methodFilter}
                onChange={(e) => setMethodFilter(e.target.value)}
                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-400 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white"
              >
                <option value="all">All Methods</option>
                {(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as Method[]).map((m) => (
                  <option key={m} value={m}>{m}</option>
                ))}
              </select>
            </div>
          </div>
        </div>
      </div>

      {/* Endpoint List */}
      <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        {filtered.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 text-slate-400">
            <Filter className="h-12 w-12 mb-3 opacity-40" />
            <p className="text-sm">No endpoints match your filters</p>
          </div>
        ) : (
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="divide-y divide-slate-100 dark:divide-slate-800">
              {filtered.map((ep) => (
                <div
                  key={ep.id}
                  className="group hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                >
                  <button
                    onClick={() => setExpanded(expanded === ep.id ? null : ep.id)}
                    className="flex w-full items-center gap-3 px-4 py-3 text-left"
                  >
                    <span className={`shrink-0 rounded-md px-2 py-1 text-xs font-bold ${METHOD_COLORS[ep.method]} min-w-[52px] text-center`}>
                      {ep.method}
                    </span>
                    <code className="flex-1 truncate font-mono text-sm text-slate-700 dark:text-slate-300">
                      {ep.path}
                    </code>
                    <div className="flex shrink-0 items-center gap-1.5">
                      {ep.multipart && (
                        <span className="rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-950 dark:text-orange-300">
                          MULTIPART
                        </span>
                      )}
                      {ep.download && (
                        <span className="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                          <Download className="inline h-3 w-3" />
                        </span>
                      )}
                      {ep.sse && (
                        <span className="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-950 dark:text-violet-300">
                          SSE
                        </span>
                      )}
                      <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold ${ROLE_COLORS[ep.role] || 'bg-slate-100 text-slate-600 border-slate-200'}`}>
                        {ep.role}
                      </span>
                      <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                        {ep.module}
                      </span>
                    </div>
                  </button>
                  {expanded === ep.id && (
                    <div className="border-t border-slate-100 bg-slate-50/50 px-4 py-3 dark:border-slate-800 dark:bg-slate-800/30">
                      <div className="grid gap-3 sm:grid-cols-2">
                        {ep.body && (
                          <div>
                            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Request Body / Params</p>
                            <code className="block rounded-lg bg-white p-2 font-mono text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-400">
                              {ep.body}
                            </code>
                          </div>
                        )}
                        <div>
                          <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Response</p>
                          <code className="block rounded-lg bg-white p-2 font-mono text-xs text-emerald-600 dark:bg-slate-900 dark:text-emerald-400">
                            {ep.response}
                          </code>
                        </div>
                      </div>
                      <div className="mt-2 flex items-center gap-3 text-xs text-slate-400">
                        <span>#{ep.id}</span>
                        <span>Auth: {ep.auth}</span>
                        <span>Module: {ep.module}</span>
                      </div>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Footer Summary */}
        <div className="mt-6 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center gap-2 mb-3">
            <Database className="h-4 w-4 text-slate-400" />
            <h3 className="text-sm font-semibold text-slate-900 dark:text-white">Laravel Route File Mapping</h3>
          </div>
          <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
            {MODULES.filter(m => m !== 'group-chat').map((m) => (
              <div key={m} className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 dark:bg-slate-800/50">
                {MODULE_ICONS[m]}
                <code className="text-xs font-mono text-slate-600 dark:text-slate-400">
                  /{m}/*
                </code>
                <span className="ml-auto text-xs font-bold text-slate-400">{moduleCounts[m] || 0}</span>
              </div>
            ))}
          </div>
          <div className="mt-3 flex items-center gap-2 rounded-lg bg-violet-50 px-3 py-2 dark:bg-violet-950/30">
            <Cloud className="h-4 w-4 text-violet-500" />
            <span className="text-xs text-violet-700 dark:text-violet-300">
              Group chat (15 ops) goes directly to Supabase — not through Laravel
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}
