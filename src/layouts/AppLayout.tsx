import { useState, type ReactNode } from 'react';
import { Link, useLocation, useNavigate, Outlet } from '@/lib/router-compat';
import {
  LayoutDashboard, Calendar, FileText, Download, Receipt, Newspaper,
  Users, Bell, AlertCircle, HeartHandshake, User, Coins, Upload,
  UserPlus, CreditCard, AlertTriangle, MessageSquare, Settings,
  Landmark, PenTool, CheckCircle, Wallet, Megaphone, FileCheck,
  ClipboardList, BadgeCheck, Globe, FileEdit, Inbox, Image, Mail,
  SlidersHorizontal, BarChart3, RefreshCw, Scale, Activity, Database,
  Server, Search, Wrench, ScrollText, Shield, Key, Lock, LogIn,
  Home, X, ChevronDown, LogOut, UserCog, Menu, FlaskConical, Cloud, ToggleLeft, ToggleRight,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuth, roleLabels, roleDashboardPath } from '@/auth/AuthContext';
import { navConfig, memberBottomNav, type NavItem } from '@/config/nav';
import { classNames, initials } from '@/lib/format';
import type { UserRole } from '@/types';
import { GroupChatWidget } from '@/components/GroupChatWidget';
import { AppearanceControls } from '@/components/AppearanceProvider';
import { Button } from '@/components/ui';

const iconMap: Record<string, LucideIcon> = {
  LayoutDashboard, Calendar, FileText, Download, Receipt, Newspaper,
  Users, Bell, AlertCircle, HeartHandshake, User, Coins, Upload,
  UserPlus, CreditCard, AlertTriangle, MessageSquare, Settings,
  Landmark, PenTool, CheckCircle, Wallet, Megaphone, FileCheck,
  ClipboardList, BadgeCheck, Globe, FileEdit, Inbox, Image, Mail,
  SlidersHorizontal, BarChart3, RefreshCw, Scale, Activity, Database,
  Server, Search, Wrench, ScrollText, Shield, Key, Lock, LogIn,
  Home, UserCog,
};

function NavIcon({ name, size = 18, className }: { name: string; size?: number; className?: string }) {
  const Icon = iconMap[name] || LayoutDashboard;
  return <Icon size={size} className={className} />;
}

export function AppLayout({ children }: { children?: ReactNode }) {
  const { user, logout, switchRole, demoMode, setDemoMode } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [roleMenuOpen, setRoleMenuOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);

  if (!user) return null;

  const role = user.role;
  const navItems = navConfig[role] || [];
  const isMember = role === 'member';

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  const handleSwitchRole = (newRole: UserRole) => {
    switchRole(newRole);
    setRoleMenuOpen(false);
    navigate(roleDashboardPath[newRole]);
  };

  const handleModeToggle = () => {
    setDemoMode(!demoMode);
    navigate('/login');
  };

  const isActive = (path: string) => {
    if (path === `/${role}`) return location.pathname === path;
    return location.pathname.startsWith(path);
  };

  const allRoles: UserRole[] = ['member', 'admin', 'treasurer', 'secretary', 'chairperson', 'office', 'super-admin'];

  return (
    <div className="private-shell min-h-screen bg-neutral-50 flex">
      {/* Desktop Sidebar — N26 dark surface */}
      <aside className={classNames('portal-sidebar hidden lg:flex w-64 flex-col fixed inset-y-0 left-0 z-30', collapsed && 'is-collapsed')}>
        <div className="px-5 py-5 border-b border-white/5">
          <Link to="/" className="flex items-center gap-2.5">
            <img src="/images/image%20copy%203.png" alt="KHWWC logo" className="w-10 h-10 rounded-full object-cover bg-white" />
            <div className="sidebar-brand-copy">
              <p className="text-sm font-bold text-white leading-tight">KHWWC</p>
              <p className="text-2xs text-neutral-500 leading-tight">Welfare Management</p>
            </div>
          </Link>
        </div>

        <div className="sidebar-profile px-3 py-3 border-b border-white/5">
          <div className="flex items-center gap-2.5 px-2 py-2 rounded-lg bg-white/5">
            <div className="w-9 h-9 rounded-full bg-primary-500 text-white font-semibold flex items-center justify-center text-sm flex-shrink-0">
              {initials(user.name)}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold text-white truncate">{user.name}</p>
              <p className="text-xs text-neutral-500 truncate">{roleLabels[role]}</p>
            </div>
          </div>
        </div>

        <nav className="flex-1 overflow-y-auto px-3 py-3 space-y-0.5 scrollbar-hide">
          {navItems.map((item: NavItem) => (
            <Link
              key={item.path}
              to={item.path}
              title={item.label}
              className={classNames(
                'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all',
                isActive(item.path)
                  ? 'bg-primary-500 text-white'
                  : 'text-neutral-400 hover:bg-white/5 hover:text-white',
              )}
            >
              <NavIcon name={item.icon} size={18} className={isActive(item.path) ? 'text-white' : 'text-neutral-500'} />
              <span className="sidebar-label truncate">{item.label}</span>
            </Link>
          ))}
        </nav>

        <div className="sidebar-footer px-3 py-3 border-t border-white/5">
          <div className="relative">
            <button
              onClick={() => setRoleMenuOpen(!roleMenuOpen)}
              className="w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg text-sm text-neutral-400 hover:bg-white/5 hover:text-white transition-colors"
            >
              <span className="flex items-center gap-2">
                <UserCog size={16} className="text-neutral-500" />
                <span className="sidebar-label">Switch Role</span>
              </span>
              <ChevronDown size={14} className={classNames('transition-transform', roleMenuOpen && 'rotate-180')} />
            </button>
            {roleMenuOpen && (
              <div className="absolute bottom-full left-0 right-0 mb-1 bg-neutral-900 rounded-lg border border-white/10 shadow-float-lg py-1 max-h-64 overflow-y-auto scrollbar-hide">
                {allRoles.map(r => (
                  <button
                    key={r}
                    onClick={() => handleSwitchRole(r)}
                    className={classNames(
                      'w-full text-left px-3 py-2 text-sm hover:bg-white/5 transition-colors',
                      r === role ? 'text-primary-400 font-medium' : 'text-neutral-400',
                    )}
                  >
                    {roleLabels[r]}
                  </button>
                ))}
              </div>
            )}
          </div>
          <button onClick={handleModeToggle} className="w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg text-sm text-neutral-400 hover:bg-white/5 hover:text-white transition-colors">
            <span className="flex items-center gap-2">
              {demoMode ? <FlaskConical size={16} /> : <Cloud size={16} />}
              <span className="sidebar-label">{demoMode ? 'Demo mode' : 'Production mode'}</span>
            </span>
            {demoMode ? <ToggleRight size={18} className="text-primary-400" /> : <ToggleLeft size={18} />}
          </button>
          <button onClick={handleLogout} className="w-full flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm text-error-400 hover:bg-error-500/10 transition-colors">
            <LogOut size={16} />
            <span className="sidebar-label">Sign Out</span>
          </button>
        </div>
      </aside>

      {/* Mobile Sidebar Overlay */}
      {sidebarOpen && (
        <div className="lg:hidden fixed inset-0 z-40 animate-fade-in" onClick={() => setSidebarOpen(false)}>
          <div className="absolute inset-0 bg-neutral-950/60 backdrop-blur-sm" />
          <aside className="portal-sidebar absolute left-0 inset-y-0 w-72 shadow-float-lg overflow-y-auto scrollbar-hide animate-slide-up" onClick={e => e.stopPropagation()}>
            <div className="px-5 py-5 border-b border-white/5 flex items-center justify-between">
              <Link to="/" className="flex items-center gap-2.5" onClick={() => setSidebarOpen(false)}>
                <img src="/images/image%20copy%203.png" alt="KHWWC logo" className="w-10 h-10 rounded-full object-cover bg-white" />
                <div>
                  <p className="text-sm font-bold text-white leading-tight">KHWWC</p>
                  <p className="text-2xs text-neutral-500 leading-tight">Welfare Management</p>
                </div>
              </Link>
              <button onClick={() => setSidebarOpen(false)} className="text-neutral-400 hover:text-white p-1.5 rounded-lg hover:bg-white/5">
                <X size={20} />
              </button>
            </div>
            <div className="sidebar-profile px-3 py-3 border-b border-white/5">
              <div className="flex items-center gap-2.5 px-2 py-2 rounded-lg bg-white/5">
                <div className="w-9 h-9 rounded-full bg-primary-500 text-white font-semibold flex items-center justify-center text-sm">
                  {initials(user.name)}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-white truncate">{user.name}</p>
                  <p className="text-xs text-neutral-500 truncate">{roleLabels[role]}</p>
                </div>
              </div>
            </div>
            <nav className="px-3 py-3 space-y-0.5">
              {navItems.map(item => (
                <Link
                  key={item.path}
                  to={item.path}
                  onClick={() => setSidebarOpen(false)}
                  className={classNames(
                    'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all',
                    isActive(item.path) ? 'bg-primary-500 text-white' : 'text-neutral-400 hover:bg-white/5 hover:text-white',
                  )}
                >
                  <NavIcon name={item.icon} size={18} className={isActive(item.path) ? 'text-white' : 'text-neutral-500'} />
                  <span className="sidebar-label truncate">{item.label}</span>
                </Link>
              ))}
            </nav>
            <div className="px-3 py-3 border-t border-white/5 space-y-2">
              <div className="px-3">
                <p className="text-xs font-medium text-neutral-500 mb-2">Switch Role (Demo)</p>
                <div className="flex flex-wrap gap-1.5">
                  {allRoles.map(r => (
                    <button
                      key={r}
                      onClick={() => handleSwitchRole(r)}
                      className={classNames(
                        'px-2.5 py-1 rounded-md text-xs font-medium transition-colors',
                        r === role ? 'bg-primary-500 text-white' : 'bg-white/5 text-neutral-400 hover:bg-white/10',
                      )}
                    >
                      {roleLabels[r]}
                    </button>
                  ))}
                </div>
              </div>
              <button onClick={handleModeToggle} className="w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg text-sm text-neutral-400 hover:bg-white/5 hover:text-white">
                <span className="flex items-center gap-2">
                  {demoMode ? <FlaskConical size={16} /> : <Cloud size={16} />}
                  <span className="sidebar-label">{demoMode ? 'Demo mode' : 'Production mode'}</span>
                </span>
                {demoMode ? <ToggleRight size={18} className="text-primary-400" /> : <ToggleLeft size={18} />}
              </button>
              <button onClick={handleLogout} className="w-full flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm text-error-400 hover:bg-error-500/10">
                <LogOut size={16} />
                <span className="sidebar-label">Sign Out</span>
              </button>
            </div>
          </aside>
        </div>
      )}

      {/* Main Content */}
      <div className={classNames('portal-main flex-1 flex flex-col min-w-0', collapsed && 'is-collapsed')}>
        <div className="flex items-center justify-between px-4 pt-3 sm:px-6 lg:px-8 gap-4">
          <Button variant="ghost" className="lg:hidden p-1.5" onClick={() => setSidebarOpen(true)} aria-label="Open menu" title="Open menu"><Menu size={22} /></Button>
          <Button variant="ghost" className="hidden lg:inline-flex p-1.5" onClick={() => setCollapsed(value => !value)} aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'} title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}><Menu size={20} /></Button>
          <AppearanceControls />
        </div>

        {/* Page Content */}
        <main className={classNames('portal-content flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8', isMember && 'pb-20 lg:pb-5')}>
          {children || <Outlet />}
        </main>

        {/* Mobile Bottom Nav (Member only) */}
        {isMember && location.pathname !== '/member' && (
          <nav className="lg:hidden fixed bottom-0 inset-x-0 z-20 bg-white border-t border-neutral-200 flex items-center justify-around px-2 py-1.5 safe-bottom">
            {memberBottomNav.map(item => (
              <Link
                key={item.path}
                to={item.path}
                className={classNames(
                  'flex flex-col items-center gap-0.5 px-3 py-1.5 rounded-md transition-colors',
                  isActive(item.path) ? 'text-primary-500' : 'text-neutral-400',
                )}
              >
                <NavIcon name={item.icon} size={20} />
                <span className="text-2xs font-medium">{item.label}</span>
              </Link>
            ))}
          </nav>
        )}
      </div>

      {/* Floating group chat */}
      <GroupChatWidget />
    </div>
  );
}
