import { api } from './client';
import type {
  WebsiteContent,
  WebsiteSettings,
  LeadershipPerson,
  ContactMessage,
  EmailTemplate,
  MediaAsset,
  Member,
  Paginated,
} from '@/types';

export const websiteApi = {
  dashboard: () => api.get<{
    total_content: number;
    published_content: number;
    draft_content: number;
    total_people: number;
    unread_messages: number;
    media_count: number;
    recent_content: WebsiteContent[];
    recent_messages: ContactMessage[];
  }>('/admin/website/dashboard'),

  getContent: (page = 1) =>
    api.get<Paginated<WebsiteContent>>('/admin/website/content', { page }),

  createContent: (data: Partial<WebsiteContent>) =>
    api.post<WebsiteContent>('/admin/website/content', data),

  updateContent: (id: string, data: Partial<WebsiteContent>) =>
    api.put<WebsiteContent>(`/admin/website/content/${id}`, data),

  archiveContent: (id: string) =>
    api.post<void>(`/admin/website/content/${id}/archive`),

  getPeople: () => api.get<LeadershipPerson[]>('/admin/website/people'),

  createPerson: (data: Partial<LeadershipPerson>) =>
    api.post<LeadershipPerson>('/admin/website/people', data),

  updatePerson: (id: string, data: Partial<LeadershipPerson>) =>
    api.put<LeadershipPerson>(`/admin/website/people/${id}`, data),

  deletePerson: (id: string) => api.delete<void>(`/admin/website/people/${id}`),

  getLeadershipMembers: () =>
    api.get<Member[]>('/admin/website/people/leadership-members'),

  getContactMessages: (page = 1) =>
    api.get<Paginated<ContactMessage>>('/admin/website/contact-messages', { page }),

  markMessageRead: (id: string) =>
    api.post<void>(`/admin/website/contact-messages/${id}/mark-read`),

  getMedia: () => api.get<MediaAsset[]>('/admin/website/media'),

  getSettings: (section: string) =>
    api.get<Record<string, unknown>>(`/admin/website/settings/${section}`),

  updateSettings: (section: string, data: Record<string, unknown>) =>
    api.put<{ message: string }>(`/admin/website/settings/${section}`, data),

  getEmailTemplates: () => api.get<EmailTemplate[]>('/admin/website/email-templates'),

  updateEmailTemplate: (id: string, data: Partial<EmailTemplate>) =>
    api.put<EmailTemplate>(`/admin/website/email-templates/${id}`, data),

  testEmail: (id: string, email: string) =>
    api.post<{ message: string }>(`/admin/website/email-templates/${id}/test`, { email }),
};
