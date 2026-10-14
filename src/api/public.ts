import { api } from './client';
import type {
  Paginated,
  WebsiteSettings,
  WebsiteContent,
  LeadershipPerson,
  ContactMessage,
} from '@/types';

export const publicApi = {
  getSettings: () => api.get<WebsiteSettings>('/public/website/settings'),

  getContent: (type: string, page = 1) =>
    api.get<Paginated<WebsiteContent>>(`/public/website/content/${type}`, { page }),

  getContentDetail: (type: string, slug: string) =>
    api.get<WebsiteContent>(`/public/website/content/${type}/${slug}`),

  getLeadership: () => api.get<LeadershipPerson[]>('/public/website/leadership'),

  getPerson: (slug: string) =>
    api.get<LeadershipPerson>(`/public/website/people/${slug}`),

  submitContact: (data: { name: string; email: string; subject: string; message: string }) =>
    api.post<{ message: string }>('/public/website/contact', data),
};
