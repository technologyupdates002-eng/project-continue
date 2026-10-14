import {
  createContext, useContext, useState, useEffect, useCallback,
  type ReactNode,
} from 'react';
import {
  clearToken, setToken, setStoredUser, getStoredUser,
  getDemoMode, setDemoMode as persistDemoMode,
} from '@/api/client';
import { authApi, phoneToSyntheticEmail } from '@/api/auth';
import { mockAuth } from '@/api/mock';
import type { AuthUser, UserRole } from '@/types';

interface AuthContextValue {
  user: AuthUser | null;
  loading: boolean;
  login: (phone: string, password: string) => Promise<AuthUser>;
  logout: () => Promise<void>;
  switchRole: (role: UserRole) => void;
  updatePassword: (current: string, next: string) => Promise<void>;
  demoMode: boolean;
  setDemoMode: (enabled: boolean) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [demoMode, setDemoModeState] = useState(getDemoMode);

  useEffect(() => {
    const stored = getStoredUser<AuthUser>();
    if (stored) {
      setUser(stored);
    }
    setLoading(false);
  }, []);

  const login = useCallback(async (phone: string, password: string): Promise<AuthUser> => {
    const email = phoneToSyntheticEmail(phone);
    const result = demoMode
      ? await mockAuth.login(email, password)
      : await authApi.login({ email, password });
    setToken(result.token);
    setStoredUser(result.user);
    setUser(result.user);
    return result.user;
  }, [demoMode]);

  const logout = useCallback(async () => {
    try {
      if (!demoMode) await authApi.logout();
    } finally {
      clearToken();
      setUser(null);
    }
  }, [demoMode]);

  const switchRole = useCallback((role: UserRole) => {
    if (!demoMode || !user) return;
    const updated: AuthUser = {
      ...user!,
      role,
      name: role === 'member' ? 'Dr. Sarah Wanjiku Mwangi'
        : role === 'admin' ? 'Admin User'
        : role === 'treasurer' ? 'David Mwangi Kuria'
        : role === 'secretary' ? 'Lucy Wangari Kamau'
        : role === 'vice-secretary' ? 'Samuel Njoroge Mbugua'
        : role === 'chairperson' ? 'Dr. Robert Kibochi'
        : role === 'office' ? 'Mary Njeri Kungu'
        : 'Super Admin',
      email: role === 'member' ? 'sarah.mwangi@khwwc.co.ke'
        : role === 'admin' ? 'admin@khwwc.co.ke'
        : role === 'treasurer' ? 'treasurer@khwwc.co.ke'
        : role === 'secretary' ? 'secretary@khwwc.co.ke'
        : role === 'vice-secretary' ? 'vice.secretary@khwwc.co.ke'
        : role === 'chairperson' ? 'chairperson@khwwc.co.ke'
        : role === 'office' ? 'office@khwwc.co.ke'
        : 'superadmin@khwwc.co.ke',
    };
    setStoredUser(updated);
    setUser(updated);
  }, [demoMode, user]);

  const updatePassword = useCallback(async (current: string, next: string) => {
    if (!demoMode) {
      await authApi.changePassword({
        current_password: current,
        new_password: next,
        new_password_confirmation: next,
      });
    }
    if (user) {
      const updated = { ...user, must_change_password: false };
      setStoredUser(updated);
      setUser(updated);
    }
  }, [demoMode, user]);

  const changeDemoMode = useCallback((enabled: boolean) => {
    persistDemoMode(enabled);
    setDemoModeState(enabled);
    clearToken();
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider value={{
      user, loading, login, logout, switchRole, updatePassword,
      demoMode, setDemoMode: changeDemoMode,
    }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}

export const roleLabels: Record<UserRole, string> = {
  'member': 'Member',
  'admin': 'Admin',
  'treasurer': 'Treasurer',
  'secretary': 'Secretary',
  'vice-secretary': 'Vice Secretary',
  'chairperson': 'Chairperson',
  'office': 'Office',
  'super-admin': 'Super Admin',
};

export const roleDashboardPath: Record<UserRole, string> = {
  'member': '/member',
  'admin': '/admin',
  'treasurer': '/treasurer',
  'secretary': '/secretary',
  'vice-secretary': '/vice-secretary',
  'chairperson': '/chairperson',
  'office': '/office',
  'super-admin': '/super-admin',
};
