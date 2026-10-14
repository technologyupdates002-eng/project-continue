import type { ReactNode } from 'react';
import { Navigate, useLocation } from '@/lib/router-compat';
import { useAuth } from '@/auth/AuthContext';
import type { UserRole } from '@/types';
import { LoadingState } from '@/components/ui';

export function RequireAuth({ children, roles }: { children: ReactNode; roles?: UserRole[] }) {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return <LoadingState text="Loading..." />;
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  if (roles && !roles.includes(user.role)) {
    return <Navigate to={getHomePath(user.role)} replace />;
  }

  return <>{children}</>;
}

export function getHomePath(role: UserRole): string {
  const paths: Record<UserRole, string> = {
    'member': '/member',
    'admin': '/admin',
    'treasurer': '/treasurer',
    'secretary': '/secretary',
    'vice-secretary': '/vice-secretary',
    'chairperson': '/chairperson',
    'office': '/office',
    'super-admin': '/super-admin',
  };
  return paths[role];
}
