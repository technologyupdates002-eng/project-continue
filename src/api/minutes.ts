import { api } from './client';

export type MinuteStatus =
  | 'draft'
  | 'submitted_to_secretary'
  | 'pending_chairperson_approval'
  | 'rejected_by_secretary'
  | 'rejected'
  | 'approved';

export interface MinuteAttendance {
  member_id: string;
  status: 'PRESENT' | 'ABSENT_WITH_APOLOGY' | 'ABSENT';
}

export interface MinuteMember {
  id: string;
  user_id: string;
  name: string;
  phone: string;
}

export interface HydratedMinute {
  id: string;
  title: string;
  meeting_date: string;
  meeting_type: 'general' | 'executive';
  agenda: string;
  discussions: string;
  decisions: string;
  action_items: string;
  next_meeting_date: string | null;
  status: MinuteStatus;
  prepared_by: string;
  reviewed_by: string | null;
  approved_by: string | null;
  rejection_notes: string | null;
  attendees: { id: string; name: string }[];
  absent_with_apology: { id: string; name: string }[];
  absent_without_apology: { id: string; name: string }[];
  secretary_name: string | null;
  secretary_signature_url: string | null;
  chairperson_name: string | null;
  chairperson_signature_url: string | null;
  created_at: string;
  updated_at: string;
}

export interface CreateMinutePayload {
  title: string;
  meeting_date: string;
  meeting_type: 'general' | 'executive';
  agenda?: string;
  discussions?: string;
  decisions?: string;
  action_items?: string;
  next_meeting_date?: string | null;
  attendance: MinuteAttendance[];
  visible_to_members?: string[] | null;
}

export interface SignatureInfo {
  url: string;
  uploaded_at: string;
  name: string;
}

export const minutesApi = {
  list: (filter: 'mine' | 'secretary_pending' | 'pending') =>
    api.get<HydratedMinute[]>(`/minutes?filter=${filter}`),

  get: (id: string) => api.get<HydratedMinute>(`/minutes/${id}`),

  create: (data: CreateMinutePayload) =>
    api.post<{ data: HydratedMinute }>('/minutes', data),

  update: (id: string, data: Partial<CreateMinutePayload>) =>
    api.put<{ data: HydratedMinute }>(`/minutes/${id}`, data),

  delete: (id: string) =>
    api.delete<{ data: { deleted: boolean } }>(`/minutes/${id}`),

  submit: (id: string) =>
    api.post<{ message: string }>(`/minutes/${id}/submit`),

  secretaryReview: (id: string, decision: 'approve' | 'reject', notes?: string) =>
    api.post<{ message: string }>(`/minutes/${id}/secretary-review`, { decision, notes }),

  approve: (id: string) =>
    api.post<{ message: string }>(`/minutes/${id}/approve`),

  reject: (id: string, notes: string) =>
    api.post<{ message: string }>(`/minutes/${id}/reject`, { notes }),

  getExecutiveMembers: () =>
    api.get<MinuteMember[]>('/minutes/executive-members'),

  getMembers: (limit = 1000) =>
    api.get<MinuteMember[]>(`/rest/v1/members?limit=${limit}`),

  getSignature: (role: 'secretary' | 'chairperson') =>
    api.get<SignatureInfo | null>(`/signatures/current?role=${role}`),

  uploadSignature: (role: string, file: File) => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('role', role);
    return api.upload<{ message: string; url: string }>('/signatures', formData);
  },
};
