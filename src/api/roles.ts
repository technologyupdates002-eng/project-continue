import { api } from './client';
import type {
  MeetingMinutes,
  ScheduledMeeting,
  AuditLog,
  AccessRole,
  OnlineUser,
  LoginActivity,
  SystemMetric,
  Member,
  Paginated,
} from '@/types';

export const secretaryApi = {
  dashboard: () => api.get<{
    pending_minutes: number;
    approved_minutes: number;
    upcoming_meetings: number;
    total_minutes: number;
    recent_minutes: MeetingMinutes[];
    upcoming: ScheduledMeeting[];
  }>('/secretary/dashboard'),

  getMinutesReview: () => api.get<MeetingMinutes[]>('/secretary/minutes-review'),

  submitForReview: (id: string) =>
    api.post<{ message: string }>(`/secretary/minutes/${id}/submit-review`),

  getMeetingMinutes: () => api.get<MeetingMinutes[]>('/secretary/meeting-minutes'),

  createMinutes: (data: Partial<MeetingMinutes>) =>
    api.post<MeetingMinutes>('/secretary/meeting-minutes', data),

  updateMinutes: (id: string, data: Partial<MeetingMinutes>) =>
    api.put<MeetingMinutes>(`/secretary/meeting-minutes/${id}`, data),

  getSchedule: () => api.get<ScheduledMeeting[]>('/secretary/meeting-scheduling'),

  createMeeting: (data: Partial<ScheduledMeeting>) =>
    api.post<ScheduledMeeting>('/secretary/meeting-scheduling', data),

  cancelMeeting: (id: string) =>
    api.post<{ message: string }>(`/secretary/meeting-scheduling/${id}/cancel`),
};

export const viceSecretaryApi = {
  dashboard: () => api.get<{
    total_events: number;
    active_events: number;
    total_documents: number;
    pending_documents: number;
    active_members: number;
    recent_messages: number;
    unread_notifications: number;
    recent_documents: any[];
    event_summary: any[];
    recent_activity: any[];
  }>('/vice-secretary/dashboard'),

  getMeetingMinutes: () => api.get<MeetingMinutes[]>('/vice-secretary/meeting-minutes'),

  createMinutes: (data: Partial<MeetingMinutes>) =>
    api.post<MeetingMinutes>('/vice-secretary/meeting-minutes', data),

  updateMinutes: (id: string, data: Partial<MeetingMinutes>) =>
    api.put<MeetingMinutes>(`/vice-secretary/meeting-minutes/${id}`, data),

  submitMinutes: (id: string) =>
    api.post<{ message: string }>(`/vice-secretary/meeting-minutes/${id}/submit`),
};

export const signatureApi = {
  getCurrent: (role: string) => api.get<{ url: string; uploaded_at: string } | null>(`/signatures/current?role=${role}`),
  upload: (data: { role: string; file_name: string }) => api.post<{ message: string; url: string }>('/signatures', data),
};

export const chairpersonApi = {
  dashboard: () => api.get<{
    pending_approvals: number;
    approved_count: number;
    rejected_count: number;
    pending_items: MeetingMinutes[];
    recent_approvals: { title: string; date: string; action: string }[];
  }>('/chairperson/dashboard'),

  getPendingApprovals: () => api.get<MeetingMinutes[]>('/chairperson/approve-minutes'),

  approveMinutes: (id: string, action: 'approve' | 'reject', note?: string) =>
    api.post<{ message: string }>(`/chairperson/approve-minutes/${id}/${action}`, { note }),
};

export const officeApi = {
  dashboard: () => api.get<{
    total_members: number;
    pending_applications: number;
    pending_documents: number;
    recent_activities: { description: string; date: string }[];
  }>('/office/dashboard'),
};

export const superAdminApi = {
  dashboard: () => api.get<{
    total_users: number;
    online_users: number;
    total_members: number;
    system_health: SystemMetric[];
    recent_logins: LoginActivity[];
    pending_alerts: number;
  }>('/super-admin/dashboard'),

  getMemberDetail: (id: string) => api.get<Member>(`/super-admin/members/${id}`),

  getTroubleshooting: () => api.get<{
    issues: { id: string; severity: string; module: string; description: string; date: string }[];
    diagnostics: SystemMetric[];
  }>('/super-admin/troubleshooting'),

  getAuditLogs: (page = 1) =>
    api.get<Paginated<AuditLog>>('/super-admin/audit-logs', { page }),

  getSecuritySettings: () => api.get<{
    two_factor: boolean;
    password_policy: { min_length: number; require_special: boolean; require_numbers: boolean };
    session_timeout: number;
    ip_whitelist: string[];
  }>('/super-admin/security'),

  updateSecuritySettings: (data: Record<string, unknown>) =>
    api.put<{ message: string }>('/super-admin/security', data),

  getPasswordManagement: () => api.get<{
    policies: SystemMetric[];
    expired_passwords: number;
    forced_resets: number;
  }>('/super-admin/password-management'),

  getAccessControl: () => api.get<AccessRole[]>('/super-admin/access-control'),

  updateRolePermissions: (id: string, permissions: string[]) =>
    api.put<{ message: string }>(`/super-admin/access-control/${id}`, { permissions }),

  getOnlineUsers: () => api.get<OnlineUser[]>('/super-admin/online-users'),

  getLoginActivity: (page = 1) =>
    api.get<Paginated<LoginActivity>>('/super-admin/login-activity', { page }),

  getSystemMonitoring: () => api.get<{
    metrics: SystemMetric[];
    services: { name: string; status: string; uptime: string }[];
  }>('/super-admin/system-monitoring'),

  getBank: () => api.get<{
    connected: boolean;
    last_sync: string;
    accounts: { name: string; number: string; balance: number; status: string }[];
  }>('/super-admin/bank'),

  getDatabase: () => api.get<{
    size: string;
    tables: number;
    connections: number;
    slow_queries: number;
    backup_last: string;
  }>('/super-admin/database'),

  getSystem: () => api.get<{
    version: string;
    uptime: string;
    environment: string;
    features: { name: string; enabled: boolean }[];
  }>('/super-admin/system'),

  getTrace: () => api.get<{
    traces: { id: string; request: string; duration: string; status: number; date: string }[];
  }>('/super-admin/trace'),
};
