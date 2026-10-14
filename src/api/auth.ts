import { api } from './client';
import type {
  LoginCredentials,
  LoginResponse,
  AuthUser,
} from '@/types';

export function phoneToSyntheticEmail(phone: string): string {
  const digits = phone.replace(/\D/g, '');
  const normalized = digits.startsWith('0') ? `254${digits.slice(1)}` : digits.startsWith('7') || digits.startsWith('1') ? `254${digits}` : digits;
  return `${normalized}@khwwc.local`;
}

export const authApi = {
  login: (creds: LoginCredentials) =>
    api.post<LoginResponse>('/auth/login', creds),

  logout: () => api.post<void>('/auth/logout'),

  me: () => api.get<AuthUser>('/auth/me'),

  changePassword: (data: { current_password: string; new_password: string; new_password_confirmation: string }) =>
    api.post<{ message: string }>('/auth/password', data),

  forcePasswordChange: (data: { new_password: string; new_password_confirmation: string }) =>
    api.post<{ message: string }>('/auth/force-password-change', data),
};
