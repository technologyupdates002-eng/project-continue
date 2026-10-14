import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useParams, useNavigate, Link } from '@/lib/router-compat';
import {
  LayoutDashboard, Wrench, ScrollText, Shield, Key, Lock,
  LogIn, Activity, Landmark, Database, Server, Search, Users,
  ArrowRight, AlertTriangle, CheckCircle, Eye,
  UserPlus, Upload, Coins, CreditCard, FileText, Archive,
  TrendingUp, TrendingDown, Filter, Download, Crown,
  Bell, MoreHorizontal, ArrowUpRight, Wallet, Receipt,
} from 'lucide-react';
import { mockSuperAdmin, mockAdmin, mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, PageHeader, Badge, LoadingState, ErrorState,
  EmptyState, Avatar, Pagination, StatCard,
} from '@/components/ui';
import { formatCurrency, formatDate, formatDateTime, relativeTime, statusColor, initials, classNames } from '@/lib/format';
import { GenericListPage, GenericDashboardPage, GenericFormPage, GenericDetailPage } from '@/components/GenericPages';
import { useAuth } from '@/auth/AuthContext';
import { LineChart, DonutChart, MiniBars, ProgressRing } from '@/components/Charts';
import type { SystemMetric, Member, Contribution } from '@/types';

// ═══════════════════════════════════════════════════════════════════════════════
//  SHARED DESIGN TOKENS
// ═══════════════════════════════════════════════════════════════════════════════

const AVATAR_COLORS = [
  'bg-primary-500', 'bg-success-500', 'bg-accent-500',
  'bg-secondary-500', 'bg-error-400', 'bg-primary-700',
  'bg-secondary-700', 'bg-success-600',
];

function MemberAvatar({ name, index = 0, size = 'md' }: { name: string; index?: number; size?: 'sm' | 'md' }) {
  const color = AVATAR_COLORS[index % AVATAR_COLORS.length];
  const dim = size === 'sm' ? 'w-8 h-8 text-[10px]' : 'w-10 h-10 text-xs';
  return (
    <div className={classNames(
      'rounded-full text-white font-bold flex items-center justify-center flex-shrink-0 ring-2 ring-white',
      dim, color,
    )}>
      {initials(name)}
    </div>
  );
}

function StatusDot({ status }: { status: 'online' | 'offline' | 'warning' }) {
  const colors = { online: 'bg-success-500', offline: 'bg-neutral-300', warning: 'bg-warning-500' };
  return (
    <span className="relative flex h-2 w-2">
      {status === 'online' && <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-success-400 opacity-75" />}
      <span className={classNames('relative inline-flex rounded-full h-2 w-2', colors[status])} />
    </span>
  );
}

function Sparkline({ data, color }: { data: number[]; color: string }) {
  const max = Math.max(...data, 1);
  const w = 80; const h = 24;
  const pts = data.map((v, i) => `${(i / (data.length - 1)) * w},${h - (v / max) * h}`).join(' ');
  return (
    <svg width={w} height={h} className="opacity-60">
      <polyline points={pts} fill="none" stroke={color} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  KPI CARD — premium, clean, with sparkline
// ═══════════════════════════════════════════════════════════════════════════════

interface KpiProps {
  label: string; value: string | number; delta: string; positive: boolean;
  icon: typeof Users; accent: string; spark?: number[];
}

function KpiCard({ label, value, delta, positive, icon: Icon, accent, spark }: KpiProps) {
  return (
    <div className="group relative bg-white rounded-n26-card border border-neutral-100 p-5 transition-all hover:shadow-float hover:border-neutral-200">
      <div className="flex items-start justify-between mb-4">
        <div className={classNames('w-10 h-10 rounded-xl flex items-center justify-center', accent)}>
          <Icon size={20} />
        </div>
        <div className="flex items-center gap-1.5">
          {spark && <Sparkline data={spark} color={positive ? '#22c55e' : '#ef4444'} />}
          <span className={classNames(
            'flex items-center gap-0.5 text-xs font-semibold rounded-full px-2 py-0.5',
            positive ? 'bg-success-50 text-success-700' : 'bg-error-50 text-error-700',
          )}>
            {positive ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
            {delta}
          </span>
        </div>
      </div>
      <p className="text-2xl font-bold text-neutral-900 tracking-tight">{value}</p>
      <p className="mt-1 text-sm text-neutral-500">{label}</p>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ACTIVITY FEED
// ═══════════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════════
//  QUICK ACTION GRID
// ═══════════════════════════════════════════════════════════════════════════════

const QUICK_ACTIONS = [
  { label: 'Add Member',         icon: UserPlus,   to: '/admin/members',                    accent: 'bg-primary-50 text-primary-600' },
  { label: 'Bank Import',        icon: Upload,     to: '/treasurer/bank-statement-import',   accent: 'bg-success-50 text-success-600' },
  { label: 'Record Contribution',icon: Coins,      to: '/admin/contributions',               accent: 'bg-accent-50 text-accent-600' },
  { label: 'Create Payment',     icon: CreditCard, to: '/admin/payments',                    accent: 'bg-secondary-50 text-secondary-600' },
  { label: 'Generate Report',    icon: FileText,   to: '/member/downloads',                  accent: 'bg-error-50 text-error-500' },
  { label: 'System Backup',      icon: Archive,    to: '/super-admin/database',              accent: 'bg-neutral-100 text-neutral-500' },
];

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════

export function SuperAdminDashboard() {
  const { user } = useAuth();
  const [memberSearch, setMemberSearch] = useState('');
  const [memberPage, setMemberPage] = useState(1);

  const greeting = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  })();

  const { data: sysData, isLoading: sysLoading } = useQuery({
    queryKey: queryKeys.superAdminDashboard,
    queryFn: () => mockSuperAdmin.dashboard(),
  });

  const { data: adminData, isLoading: adminLoading } = useQuery({
    queryKey: queryKeys.adminDashboard,
    queryFn: () => mockAdmin.dashboard(),
  });

  const { data: membersData, isLoading: membersLoading } = useQuery({
    queryKey: [...queryKeys.adminMembers({ page: memberPage, search: memberSearch, status: '' }), memberSearch],
    queryFn: () => mockAdmin.getMembers({ page: memberPage, search: memberSearch }),
  });

  const { data: contribData } = useQuery({
    queryKey: queryKeys.adminContributions({ page: 1, status: '' }),
    queryFn: () => mockAdmin.getContributions({ page: 1 }),
  });

  const { data: bankData } = useQuery({
    queryKey: queryKeys.treasurerBankStatement(1),
    queryFn: () => mockTreasurer.getBankStatement(1),
  });

  if (sysLoading || adminLoading) return <LoadingState />;

  if (!sysData || !adminData) return <ErrorState message="Unable to load the overview." />;
  const sys = sysData;
  const adm = adminData;

  const trend = adm.contributions_trend ?? [];

  const sparkData = trend.map(t => t.amount);

  const activeCount = adm.active_members ?? 0;
  const totalMembers = adm.total_members ?? 0;
  const pendingCount = adm.pending_approvals ?? 0;
  const inactiveCount = Math.max(totalMembers - activeCount - pendingCount, 0);

  const recentContribs: Contribution[] = contribData?.data ?? [];
  const bankTxns = bankData?.data ?? [];
  const pendingBank = bankTxns.filter((t: any) => !t.matched).length;

  const topMembers = (adm.recent_signups ?? [])
    .map((m: Member) => ({ ...m, contribs: m.contributions_total ?? 0 }))
    .sort((a: any, b: any) => b.contribs - a.contribs)
    .slice(0, 5);

  const activePct = totalMembers ? Math.round((activeCount / totalMembers) * 100) : 0;

  return (
    <div className="space-y-6 animate-fade-in">
      {/* ═══ HEADER ═══ */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">
            {greeting}, {user?.name?.split(' ')[0] ?? 'Admin'}
          </h1>
          <p className="mt-1 text-sm text-neutral-500">
            Here's what's happening across KHWWC Welfare today.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button className="relative p-2.5 rounded-xl border border-neutral-200 bg-white hover:bg-neutral-50 transition-colors">
            <Bell size={18} className="text-neutral-500" />
            <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-error-500 rounded-full ring-2 ring-white" />
          </button>
          <div className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2">
            <div className="w-9 h-9 rounded-full bg-primary-500 text-white text-sm font-bold flex items-center justify-center">
              {initials(user?.name ?? 'SA')}
            </div>
            <div className="hidden sm:block">
              <p className="text-sm font-semibold text-neutral-800">{user?.name ?? 'Super Admin'}</p>
              <p className="text-xs text-neutral-400">Super Administrator</p>
            </div>
          </div>
        </div>
      </div>

      {/* ═══ KPI ROW ═══ */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Total members" value={totalMembers} icon={<Users size={20} />} />
        <StatCard label="Contributions" value={formatCurrency(adm.total_contributions)} icon={<Coins size={20} />} color="success" />
        <StatCard label="Online users" value={sys.online_users} icon={<Activity size={20} />} color="secondary" />
        <StatCard label="Pending approvals" value={pendingCount} icon={<Shield size={20} />} color="warning" />
      </div>

      {/* ═══ CHARTS ROW ═══ */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Trend chart — 2 cols */}
        <Card className="p-6 lg:col-span-2">
          <div className="flex items-center justify-between mb-5">
            <div>
              <h3 className="text-base font-bold text-neutral-900">Financial Overview</h3>
              <p className="text-xs text-neutral-400 mt-0.5">Monthly contributions</p>
            </div>
            <div className="flex items-center gap-4">
              <span className="flex items-center gap-1.5 text-xs text-neutral-500">
                <span className="w-2.5 h-2.5 rounded-full bg-primary-500" /> Contributions
              </span>

            </div>
          </div>
          <LineChart
            series={[
              { label: 'Contributions', color: 'var(--brand)', points: trend.map(t => ({ label: t.month, value: t.amount })) },
            ]}
            height={220}
          />
        </Card>

        {/* Donut — member status */}
        <Card className="p-6">
          <h3 className="text-base font-bold text-neutral-900 mb-1">Member Status</h3>
          <p className="text-xs text-neutral-400 mb-4">Distribution by account state</p>
          <div className="flex flex-col items-center">
            <DonutChart
              segments={[
                { label: 'Active', value: activeCount, color: 'var(--brand)' },
                { label: 'Pending', value: pendingCount, color: 'var(--warning)' },
                { label: 'Inactive', value: inactiveCount, color: 'var(--danger)' },
              ]}
              centerLabel={String(totalMembers)}
              centerSub="Total"
            />
            <div className="w-full mt-5 space-y-2.5">
              {[
                { label: 'Active', value: activeCount, pct: activePct, color: 'bg-primary-500' },
                { label: 'Pending', value: pendingCount, pct: Math.round((pendingCount / (totalMembers || 1)) * 100), color: 'bg-warning-500' },
                { label: 'Inactive', value: inactiveCount, pct: Math.round((inactiveCount / (totalMembers || 1)) * 100), color: 'bg-error-400' },
              ].map(s => (
                <div key={s.label} className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <span className={classNames('w-2.5 h-2.5 rounded-full', s.color)} />
                    <span className="text-sm text-neutral-600">{s.label}</span>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-sm font-semibold text-neutral-800">{s.value.toLocaleString()}</span>
                    <span className="text-xs text-neutral-400 w-10 text-right">{s.pct}%</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </Card>
      </div>

      {/* ═══ QUICK ACTIONS + ACTIVITY ═══ */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Quick actions */}
        <Card className="p-6">
          <h3 className="text-base font-bold text-neutral-900 mb-4">Quick Actions</h3>
          <div className="grid grid-cols-2 gap-3">
            {QUICK_ACTIONS.map(a => (
              <Link
                key={a.label}
                to={a.to}
                className="group flex flex-col items-start gap-2 p-3.5 rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-float transition-all"
              >
                <div className={classNames('w-9 h-9 rounded-lg flex items-center justify-center', a.accent)}>
                  <a.icon size={17} />
                </div>
                <span className="text-xs font-medium text-neutral-700 group-hover:text-neutral-900">{a.label}</span>
              </Link>
            ))}
          </div>
        </Card>

        {/* Activity feed — 2 cols */}
        <Card className="p-6 lg:col-span-2">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-base font-bold text-neutral-900">Recent logins</h3>
            <Link to="/super-admin/audit-logs" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          </div>
          <div className="space-y-3">{sys.recent_logins.map((entry: any, i: number) => <div key={entry.id ?? i} className="member-recent-row"><LogIn size={18} /><div><p className="text-sm font-semibold">{entry.user_name ?? entry.name ?? 'User login'}</p><p className="text-xs text-muted-foreground">{entry.date ? formatDateTime(entry.date) : entry.login_at ? formatDateTime(entry.login_at) : entry.created_at ? formatDateTime(entry.created_at) : ''}</p></div></div>)}</div>
        </Card>
      </div>

      {/* ═══ RECENT CONTRIBUTIONS + TOP MEMBERS ═══ */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Recent contributions */}
        <Card className="p-6">
          <div className="flex items-center justify-between mb-5">
            <h3 className="text-base font-bold text-neutral-900">Recent Contributions</h3>
            <Link to="/admin/contributions" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          </div>
          <div className="space-y-1">
            {recentContribs.slice(0, 5).map((c: Contribution, i: number) => (
              <div key={c.id} className="flex items-center gap-3 p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <MemberAvatar name={c.member_name ?? ''} index={i} size="sm" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-neutral-800 truncate">{c.member_name}</p>
                  <p className="text-xs text-neutral-400">{c.method} · {formatDate(c.date)}</p>
                </div>
                <div className="text-right flex-shrink-0">
                  <p className="text-sm font-bold text-neutral-900">{formatCurrency(c.amount)}</p>
                  <span className={classNames(
                    'inline-block text-xs font-medium mt-0.5',
                    c.status === 'confirmed' ? 'text-success-600' : c.status === 'pending' ? 'text-warning-600' : 'text-error-600',
                  )}>
                    {c.status === 'confirmed' ? 'Completed' : c.status.charAt(0).toUpperCase() + c.status.slice(1)}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </Card>

        {/* Top contributors */}
        <Card className="p-6">
          <div className="flex items-center justify-between mb-5">
            <h3 className="text-base font-bold text-neutral-900">Recent member contributions</h3>
            <Link to="/admin/members" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          </div>
          <div className="space-y-1">
            {topMembers.map((m: any, i: number) => (
              <div key={m.id} className="flex items-center gap-3 p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="flex-shrink-0 w-7 text-center">
                  {i < 3 ? (
                    <Crown size={16} className={i === 0 ? 'text-accent-500 mx-auto' : i === 1 ? 'text-neutral-400 mx-auto' : 'text-accent-700 mx-auto'} />
                  ) : (
                    <span className="text-xs text-neutral-400 font-bold">{i + 1}</span>
                  )}
                </div>
                <MemberAvatar name={m.name} index={i} size="sm" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-neutral-800 truncate">{m.name.split(' ').slice(0, 2).join(' ')}</p>
                  <p className="text-xs text-neutral-400">{m.member_number}</p>
                </div>
                <span className="text-sm font-bold text-neutral-900 flex-shrink-0">{formatCurrency(m.contribs)}</span>
              </div>
            ))}
          </div>
        </Card>
      </div>

      {/* ═══ MEMBER DIRECTORY ═══ */}
      <Card className="p-6">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
          <div>
            <h3 className="text-base font-bold text-neutral-900">Member Directory</h3>
            <p className="text-xs text-neutral-400 mt-0.5">{totalMembers} total members</p>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            <div className="relative">
              <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
              <input
                type="text"
                placeholder="Search members..."
                value={memberSearch}
                onChange={e => { setMemberSearch(e.target.value); setMemberPage(1); }}
                className="pl-9 pr-3 py-2 text-sm rounded-n26-btn border border-neutral-200 bg-white text-neutral-700 w-56 focus:outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-500/15 transition-colors"
              />
            </div>
            <button className="flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-neutral-600 border border-neutral-200 rounded-n26-btn hover:bg-neutral-50 transition-colors">
              <Filter size={14} /> Filter
            </button>
            <button className="flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-primary-500 rounded-n26-btn hover:bg-primary-600 transition-colors">
              <Download size={14} /> Export
            </button>
          </div>
        </div>

        {membersLoading ? (
          <div className="py-12 text-center text-sm text-neutral-400">Loading members…</div>
        ) : (
          <div className="overflow-x-auto -mx-6 px-6">
            <table className="w-full min-w-[720px]">
              <thead>
                <tr className="border-b border-neutral-100">
                  {['Member', 'Phone', 'Member ID', 'Joined', 'Status', 'Contributions', ''].map((h, i) => (
                    <th key={i} className={classNames(
                      'text-left py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider',
                      i === 5 && 'text-right',
                    )}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-50">
                {(membersData?.data ?? []).map((m: Member, i: number) => (
                  <tr key={m.id} className="hover:bg-neutral-50/60 transition-colors group">
                    <td className="py-3.5 px-2">
                      <div className="flex items-center gap-3">
                        <MemberAvatar name={m.name} index={i} size="sm" />
                        <div>
                          <p className="text-sm font-medium text-neutral-800">{m.name.split(' ').slice(0, 2).join(' ')}</p>
                          <p className="text-xs text-neutral-400">{m.county}</p>
                        </div>
                      </div>
                    </td>
                    <td className="py-3.5 px-2 text-sm text-neutral-500 font-mono">{m.phone?.replace(/\s/g, '')}</td>
                    <td className="py-3.5 px-2 text-sm font-mono text-neutral-600">{m.member_number}</td>
                    <td className="py-3.5 px-2 text-sm text-neutral-500">{formatDate(m.joined_date)}</td>
                    <td className="py-3.5 px-2">
                      <span className={classNames(
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                        m.status === 'active' ? 'bg-success-50 text-success-700' :
                        m.status === 'suspended' ? 'bg-error-50 text-error-700' :
                        'bg-warning-50 text-warning-700',
                      )}>
                        <span className={classNames(
                          'w-1.5 h-1.5 rounded-full',
                          m.status === 'active' ? 'bg-success-500' :
                          m.status === 'suspended' ? 'bg-error-500' : 'bg-warning-500',
                        )} />
                        {m.status.charAt(0).toUpperCase() + m.status.slice(1)}
                      </span>
                    </td>
                    <td className="py-3.5 px-2 text-right">
                      <span className="text-sm font-semibold text-neutral-800">
                        {m.contributions_total ? formatCurrency(m.contributions_total) : 'KES 0'}
                      </span>
                    </td>
                    <td className="py-3.5 px-2 text-right">
                      <Link
                        to={`/super-admin/members/${m.id}`}
                        className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-primary-600 border border-primary-100 rounded-n26-btn hover:bg-primary-50 transition-colors opacity-0 group-hover:opacity-100"
                      >
                        <Eye size={12} /> View
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {membersData && membersData.last_page > 1 && (
          <div className="mt-4 flex justify-end">
            <Pagination currentPage={memberPage} lastPage={membersData.last_page} onPage={setMemberPage} />
          </div>
        )}
      </Card>

      <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {sys.system_health.map((metric: SystemMetric) => <StatCard key={metric.label} label={metric.label} value={metric.value} icon={<Server size={18} />} />)}
      </section>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ALL OTHER PAGES — preserved unchanged
// ═══════════════════════════════════════════════════════════════════════════════

export function SuperAdminMemberDetail() {
  const { memberId = '' } = useParams();
  const navigate = useNavigate();
  const { data: member, isLoading } = useQuery({
    queryKey: queryKeys.superAdminMember(memberId),
    queryFn: () => mockSuperAdmin.getMemberDetail(memberId),
  });
  if (isLoading) return <LoadingState />;
  if (!member) return <ErrorState message="Member not found" />;
  return (
    <GenericDetailPage
      title={member.name} subtitle={member.member_number} icon={Users}
      onBack={() => navigate('/super-admin')}
      sections={[
        { label: 'Email', value: member.email }, { label: 'Phone', value: member.phone },
        { label: 'Role', value: member.role }, { label: 'Status', value: <Badge status={member.status}>{member.status}</Badge> },
        { label: 'County', value: member.county }, { label: 'Workplace', value: member.workplace },
        { label: 'Contributions', value: formatCurrency(member.contributions_total) },
        { label: 'Joined', value: formatDate(member.joined_date) },
      ]}
    />
  );
}

export function SystemTroubleshooting() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminTroubleshooting, queryFn: () => mockSuperAdmin.getTroubleshooting() });
  if (isLoading) return <LoadingState />;
  const d = data!;
  return (
    <div>
      <PageHeader title="System Troubleshooting" subtitle="Diagnose and resolve system issues" icon={<Wrench size={20} />} />
      <Card className="p-5 mb-4">
        <h3 className="text-sm font-bold mb-3">Active Issues</h3>
        <div className="space-y-3">
          {d.issues.map((issue: any) => (
            <div key={issue.id} className="flex items-start gap-3 p-4 rounded-xl border border-neutral-100">
              <div className={classNames('w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0', issue.severity === 'critical' ? 'bg-error-50 text-error-600' : issue.severity === 'warning' ? 'bg-warning-50 text-warning-600' : 'bg-neutral-100 text-neutral-500')}>
                <AlertTriangle size={16} />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-semibold text-neutral-800">{issue.module}: {issue.description}</p>
                <p className="text-xs text-neutral-400 mt-1">{formatDate(issue.date)}</p>
              </div>
            </div>
          ))}
        </div>
      </Card>
      <Card className="p-5">
        <h3 className="text-sm font-bold mb-3">Diagnostics</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {d.diagnostics.map((m: SystemMetric, i: number) => (
            <div key={i} className="flex items-center justify-between p-3 rounded-xl bg-neutral-50">
              <span className="text-sm text-neutral-600">{m.label}</span>
              <div className="flex items-center gap-2">
                <span className="text-sm font-semibold">{m.value}</span>
                <span className={classNames('w-2 h-2 rounded-full', m.status === 'healthy' ? 'bg-success-500' : m.status === 'warning' ? 'bg-warning-500' : 'bg-error-500')} />
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}

export function AuditLogs() {
  return <GenericListPage config={{
    title: 'Audit Logs', subtitle: 'Security and audit trail', icon: ScrollText,
    fetchFn: (p) => mockSuperAdmin.getAuditLogs(p.page), queryKey: (p) => queryKeys.superAdminAuditLogs(p.page),
    exportFilename: 'audit-logs.csv',
    columns: [
      { key: 'date', label: 'Date', render: r => formatDateTime(r.date) },
      { key: 'user', label: 'User' },
      { key: 'action', label: 'Action', render: r => <span className="font-mono text-xs">{r.action}</span> },
      { key: 'module', label: 'Module' },
      { key: 'ip', label: 'IP', render: r => <span className="font-mono text-xs text-neutral-500">{r.ip}</span> },
      { key: 'details', label: 'Details' },
    ],
  }} />;
}

export function SecuritySettings() {
  return <GenericFormPage
    title="Security Settings" subtitle="Configure system security" icon={Shield}
    fields={[
      { key: 'two_factor', label: 'Two-Factor Authentication', type: 'text', value: 'Disabled' },
      { key: 'min_length', label: 'Min Password Length', type: 'number', value: '8' },
      { key: 'session_timeout', label: 'Session Timeout (min)', type: 'number', value: '60' },
      { key: 'ip_whitelist', label: 'IP Whitelist (comma-separated)', value: '' },
    ]}
    onSubmit={async (data) => { await mockSuperAdmin.updateSecuritySettings(data); }}
  />;
}

export function PasswordManagement() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminPasswords, queryFn: () => mockSuperAdmin.getPasswordManagement() });
  if (isLoading) return <LoadingState />;
  return (
    <GenericDashboardPage
      title="Password Management" subtitle="Password policies and resets" icon={Key}
      stats={[
        { label: 'Expired Passwords', value: data!.expired_passwords, icon: Key, color: 'warning' as const },
        { label: 'Forced Resets', value: data!.forced_resets, icon: Key, color: 'error' as const },
      ]}
      sections={[{ title: 'Password Policies', content: (
        <div className="space-y-2">
          {data!.policies.map((p: SystemMetric, i: number) => (
            <div key={i} className="flex justify-between text-sm py-2 border-b border-neutral-50 last:border-0">
              <span className="text-neutral-600">{p.label}</span>
              <span className="font-semibold">{p.value}</span>
            </div>
          ))}
        </div>
      )}]}
    />
  );
}

export function AccessControl() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminAccessControl, queryFn: () => mockSuperAdmin.getAccessControl() });
  if (isLoading) return <LoadingState />;
  return (
    <div>
      <PageHeader title="Access Control" subtitle="Manage roles and permissions" icon={<Lock size={20} />} />
      <div className="space-y-3">
        {(data || []).map((role: any) => (
          <Card key={role.id} className="p-5">
            <div className="flex items-start justify-between gap-3 mb-3">
              <div><h3 className="text-sm font-bold text-neutral-900">{role.name}</h3><p className="text-xs text-neutral-400">{role.description}</p></div>
              <Badge>{role.users_count} users</Badge>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {role.permissions.map((p: string, i: number) => (
                <span key={i} className="px-2 py-0.5 rounded-md bg-neutral-100 text-xs text-neutral-600 font-mono">{p}</span>
              ))}
            </div>
          </Card>
        ))}
      </div>
    </div>
  );
}

export function OnlineUsers() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminOnlineUsers, queryFn: () => mockSuperAdmin.getOnlineUsers() });
  if (isLoading) return <LoadingState />;
  return (
    <div>
      <PageHeader title="Online Users" subtitle={`${(data || []).length} users currently active`} icon={<Users size={20} />} />
      <Card className="p-4">
        <div className="space-y-2">
          {(data || []).map((u: any) => (
            <div key={u.id} className="flex items-center justify-between py-3 border-b border-neutral-50 last:border-0">
              <div className="flex items-center gap-3">
                <div className="relative">
                  <Avatar name={u.name} size="md" />
                  <span className="absolute bottom-0 right-0 w-3 h-3 bg-success-500 rounded-full border-2 border-white" />
                </div>
                <div><p className="text-sm font-semibold text-neutral-800">{u.name}</p><p className="text-xs text-neutral-400">{u.device}</p></div>
              </div>
              <div className="text-right"><p className="text-xs text-neutral-500">{u.last_activity}</p><p className="text-xs text-neutral-400 font-mono">{u.ip}</p></div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}

export function LoginActivity() {
  return <GenericListPage config={{
    title: 'Login Activity', subtitle: 'User login history and failures', icon: LogIn,
    fetchFn: (p) => mockSuperAdmin.getLoginActivity(p.page), queryKey: (p) => queryKeys.superAdminLoginActivity(p.page),
    exportFilename: 'login-activity.csv',
    statusFilters: [{ value: 'success', label: 'Success' }, { value: 'failed', label: 'Failed' }],
    columns: [
      { key: 'date', label: 'Date', render: r => formatDateTime(r.date) },
      { key: 'user', label: 'User' },
      { key: 'email', label: 'Email' },
      { key: 'ip', label: 'IP', render: r => <span className="font-mono text-xs">{r.ip}</span> },
      { key: 'device', label: 'Device' },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function SystemMonitoring() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminMonitoring, queryFn: () => mockSuperAdmin.getSystemMonitoring() });
  if (isLoading) return <LoadingState />;
  const d = data!;
  return (
    <div>
      <PageHeader title="System Monitoring" subtitle="Real-time system health" icon={<Activity size={20} />} />
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-4">
        {d.metrics.map((m: SystemMetric, i: number) => (
          <Card key={i} className="p-4">
            <div className="flex items-center justify-between mb-1">
              <p className="text-xs text-neutral-400">{m.label}</p>
              <span className={classNames('w-2.5 h-2.5 rounded-full', m.status === 'healthy' ? 'bg-success-500' : m.status === 'warning' ? 'bg-warning-500' : 'bg-error-500')} />
            </div>
            <p className="text-lg font-bold text-neutral-900">{m.value}</p>
            {m.detail && <p className="text-xs text-neutral-400 mt-0.5">{m.detail}</p>}
          </Card>
        ))}
      </div>
      <Card className="p-5">
        <h3 className="text-sm font-bold mb-3">Services</h3>
        <div className="space-y-2">
          {d.services.map((s: any, i: number) => (
            <div key={i} className="flex items-center justify-between py-2 border-b border-neutral-50 last:border-0">
              <span className="text-sm font-medium text-neutral-700">{s.name}</span>
              <div className="flex items-center gap-3">
                <span className="text-xs text-neutral-400">{s.uptime}</span>
                <span className={classNames('w-2.5 h-2.5 rounded-full', s.status === 'healthy' ? 'bg-success-500' : 'bg-warning-500')} />
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}

export function SuperAdminBank() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminBank, queryFn: () => mockSuperAdmin.getBank() });
  if (isLoading) return <LoadingState />;
  const d = data!;
  return (
    <GenericDashboardPage
      title="Bank Configuration" subtitle="Bank integration health and settings" icon={Landmark}
      stats={[
        { label: 'Connection', value: d.connected ? 'Active' : 'Disconnected', icon: d.connected ? CheckCircle : AlertTriangle, color: (d.connected ? 'success' : 'error') as 'success' | 'error' },
        { label: 'Last Sync', value: relativeTime(d.last_sync), icon: Landmark },
        { label: 'Accounts', value: d.accounts.length, icon: Landmark },
        { label: 'Total Balance', value: formatCurrency(d.accounts.reduce((s: number, a: any) => s + a.balance, 0)), icon: Landmark, color: 'secondary' as const },
      ]}
      sections={[{ title: 'Bank Accounts', content: (
        <div className="space-y-3">
          {d.accounts.map((a: any, i: number) => (
            <div key={i} className="flex items-center justify-between p-3 rounded-xl border border-neutral-100">
              <div><p className="text-sm font-semibold text-neutral-800">{a.name}</p><p className="text-xs text-neutral-400 font-mono">{a.number}</p></div>
              <div className="text-right"><p className="text-sm font-bold text-neutral-900">{formatCurrency(a.balance)}</p><Badge status={a.status}>{a.status}</Badge></div>
            </div>
          ))}
        </div>
      )}]}
    />
  );
}

export function SuperAdminDatabase() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminDatabase, queryFn: () => mockSuperAdmin.getDatabase() });
  if (isLoading) return <LoadingState />;
  const d = data!;
  return (
    <GenericDashboardPage
      title="Database" subtitle="Database health and statistics" icon={Database}
      stats={[
        { label: 'Size', value: d.size, icon: Database },
        { label: 'Tables', value: d.tables, icon: Database },
        { label: 'Connections', value: d.connections, icon: Database },
        { label: 'Slow Queries', value: d.slow_queries, icon: AlertTriangle, color: (d.slow_queries > 0 ? 'warning' : 'success') as 'warning' | 'success' },
      ]}
      sections={[{ title: 'Backup Status', content: (
        <p className="text-sm text-neutral-600">Last backup: {relativeTime(d.backup_last)}</p>
      )}]}
    />
  );
}

export function SuperAdminSystem() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminSystem, queryFn: () => mockSuperAdmin.getSystem() });
  if (isLoading) return <LoadingState />;
  const d = data!;
  return (
    <GenericDashboardPage
      title="System Information" subtitle="Application version and features" icon={Server}
      stats={[
        { label: 'Version', value: d.version, icon: Server },
        { label: 'Uptime', value: d.uptime, icon: Activity },
        { label: 'Environment', value: d.environment, icon: Server },
        { label: 'Features', value: d.features.filter((f: any) => f.enabled).length + ' enabled', icon: CheckCircle, color: 'success' as const },
      ]}
      sections={[{ title: 'Feature Flags', content: (
        <div className="grid grid-cols-2 gap-2">
          {d.features.map((f: any, i: number) => (
            <div key={i} className="flex items-center gap-2 p-2 rounded-lg bg-neutral-50">
              <span className={classNames('w-2 h-2 rounded-full', f.enabled ? 'bg-success-500' : 'bg-neutral-300')} />
              <span className="text-xs text-neutral-600">{f.name}</span>
            </div>
          ))}
        </div>
      )}]}
    />
  );
}

export function SuperAdminTrace() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.superAdminTrace, queryFn: () => mockSuperAdmin.getTrace() });
  if (isLoading) return <LoadingState />;
  return (
    <div>
      <PageHeader title="Request Traces" subtitle="API request performance" icon={<Search size={20} />} />
      <Card className="p-4">
        <div className="space-y-2">
          {(data?.traces ?? []).map((t: any) => (
            <div key={t.id} className="flex items-center justify-between py-2.5 border-b border-neutral-50 last:border-0">
              <div className="flex items-center gap-3 min-w-0">
                <span className={classNames('px-2 py-0.5 rounded text-xs font-bold font-mono', t.status < 300 ? 'bg-success-100 text-success-700' : t.status < 400 ? 'bg-warning-100 text-warning-700' : 'bg-error-100 text-error-700')}>{t.status}</span>
                <span className="text-sm font-mono text-neutral-700 truncate">{t.request}</span>
              </div>
              <div className="flex items-center gap-3 flex-shrink-0 text-xs text-neutral-400">
                <span className="font-mono">{t.duration}</span>
                <span>{relativeTime(t.date)}</span>
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
