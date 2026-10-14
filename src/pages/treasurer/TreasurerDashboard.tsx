import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from '@/lib/router-compat';
import {
  Upload, Coins, CreditCard, FileText, Landmark, ArrowRight,
  TrendingUp, TrendingDown, Bell, Calendar, Wallet,
  ArrowUpRight, ArrowDownRight, Receipt, MoreHorizontal,
} from 'lucide-react';
import { mockTreasurer, mockAdmin } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, StatCard, LoadingState, ErrorState } from '@/components/ui';
import { formatCurrency, initials, classNames } from '@/lib/format';
import { LineChart, DonutChart } from '@/components/Charts';
import { useAuth } from '@/auth/AuthContext';
import type { Contribution, BankTransaction } from '@/types';

const AVATAR_COLORS = [
  'bg-primary-500', 'bg-success-500', 'bg-accent-500',
  'bg-secondary-500', 'bg-error-400',
];

function MemberAvatar({ name, index = 0 }: { name: string; index?: number }) {
  return (
    <div className={classNames(
      'w-9 h-9 rounded-full text-white text-xs font-bold flex items-center justify-center flex-shrink-0 ring-2 ring-white',
      AVATAR_COLORS[index % AVATAR_COLORS.length],
    )}>
      {initials(name)}
    </div>
  );
}

const QUICK_ACTIONS = [
  { label: 'Bank Import',     icon: Upload,     to: '/treasurer/bank-statement-import', accent: 'bg-success-50 text-success-600' },
  { label: 'Record Contrib.', icon: Coins,      to: '/treasurer/contributions',          accent: 'bg-primary-50 text-primary-600' },
  { label: 'Approve Payout',  icon: CreditCard, to: '/treasurer/withdrawal-approvals',   accent: 'bg-secondary-50 text-secondary-600' },
  { label: 'New Expense',     icon: Receipt,    to: '/treasurer/expenses',               accent: 'bg-error-50 text-error-500' },
  { label: 'Generate Report', icon: FileText,   to: '/treasurer/reports',                accent: 'bg-accent-50 text-accent-600' },
  { label: 'Bank Statement',  icon: Landmark,   to: '/treasurer/bank-statement',         accent: 'bg-neutral-100 text-neutral-500' },
];

export default function TreasurerDashboard() {
  const { user } = useAuth();

  const { data: stats, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.treasurerDashboard,
    queryFn: () => mockTreasurer.dashboard(),
  });

  const { data: contribData } = useQuery({
    queryKey: queryKeys.adminContributions({ page: 1, status: '' }),
    queryFn: () => mockAdmin.getContributions({ page: 1 }),
  });

  const { data: bankData } = useQuery({
    queryKey: queryKeys.treasurerBankStatement(1),
    queryFn: () => mockTreasurer.getBankStatement(1),
  });

  if (isLoading) return <LoadingState />;
  if (isError || !stats) return <ErrorState message="Failed to load dashboard" onRetry={refetch} />;

  const s = stats;
  const recentContribs: Contribution[] = contribData?.data ?? [];
  const bankTxns: BankTransaction[] = bankData?.data ?? [];
  const totalContribs = s.contributions_balance;
  const bankBalance = s.bank_balance;
  const now = new Date();
  const dateStr = now.toLocaleDateString('en-KE', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
  const timeStr = now.toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit', hour12: false });
  const monthStart = new Date(now.getFullYear(), now.getMonth(), 1).toLocaleDateString('en-KE', { day: '2-digit', month: 'short' });
  const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0).toLocaleDateString('en-KE', { day: '2-digit', month: 'short' });

  const trend = s.monthly_contributions ?? [];
  const activityItems = recentContribs.slice(0, 5).map(c => ({ icon: Coins, text: c.member_name ?? 'Contribution', detail: `${formatCurrency(c.amount)} · ${c.method} · ${c.status}`, time: new Date(c.date).toLocaleDateString('en-KE'), color: 'bg-success-50 text-success-600' }));
  const kpis = [
    { label: 'Contribution balance', value: formatCurrency(totalContribs), icon: Coins },
    { label: 'Bank balance', value: formatCurrency(bankBalance), icon: Landmark },
    { label: 'Operational balance', value: formatCurrency(s.operational_balance), icon: Wallet },
    { label: 'Pending withdrawals', value: s.pending_withdrawals, icon: CreditCard },
  ];

  return (
    <div className="space-y-6 animate-fade-in">
      {/* ═══ HEADER ═══ */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">
            Treasurer Dashboard
          </h1>
          <p className="mt-1 text-sm text-neutral-500">
            {dateStr} · {timeStr} · {monthStart} – {monthEnd}
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button className="relative p-2.5 rounded-xl border border-neutral-200 bg-white hover:bg-neutral-50 transition-colors">
            <Bell size={18} className="text-neutral-500" />
            <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-error-500 rounded-full ring-2 ring-white" />
          </button>
          <div className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2">
            <div className="w-9 h-9 rounded-full bg-primary-500 text-white text-sm font-bold flex items-center justify-center">
              {initials(user?.name ?? 'TR')}
            </div>
            <div className="hidden sm:block">
              <p className="text-sm font-semibold text-neutral-800">{user?.name ?? 'Treasurer'}</p>
              <p className="text-xs text-neutral-400">Treasurer · KHWWC</p>
            </div>
          </div>
        </div>
      </div>

      {/* ═══ KPI ROW ═══ */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {kpis.map(c => <StatCard key={c.label} label={c.label} value={c.value} icon={<c.icon size={20} />} />)}
      </div>

      {/* ═══ CHARTS ═══ */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Line chart — 2 cols */}
        <Card className="p-6 lg:col-span-2">
          <div className="flex items-center justify-between mb-5">
            <div>
              <h3 className="text-base font-bold text-neutral-900">Contribution trend</h3>
              <p className="text-xs text-neutral-400 mt-0.5">Monthly contributions</p>
            </div>
            <div className="flex items-center gap-4">
              <span className="flex items-center gap-1.5 text-xs text-neutral-500">
                <span className="w-2.5 h-2.5 rounded-full bg-success-500" /> Contributions
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

        <section className="space-y-4">
          <StatCard label="Welfare donation balance" value={formatCurrency(s.donation_balance)} icon={<Wallet size={20} />} color="success" />
          <StatCard label="Penalty balance" value={formatCurrency(s.penalty_balance)} icon={<Receipt size={20} />} color="warning" />
          <StatCard label="Pending expenses" value={s.pending_expenses} icon={<FileText size={20} />} />
        </section>
      </div>

      {/* ═══ QUICK ACTIONS + ACTIVITY ═══ */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
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

        <Card className="p-6 lg:col-span-2">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-base font-bold text-neutral-900">Recent Activity</h3>
            <Link to="/treasurer/contributions" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          </div>
          <div className="space-y-1">
            {activityItems.map((a, i) => (
              <div key={i} className="flex items-start gap-3 p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className={classNames('w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0', a.color)}>
                  <a.icon size={16} />
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-neutral-800">{a.text}</p>
                  <p className="text-xs text-neutral-400 mt-0.5 truncate">{a.detail}</p>
                </div>
                <span className="text-xs text-neutral-400 whitespace-nowrap flex-shrink-0">{a.time}</span>
              </div>
            ))}
          </div>
        </Card>
      </div>

      {/* ═══ RECENT CONTRIBUTIONS ═══ */}
      <Card className="p-6">
        <div className="flex items-center justify-between mb-5">
          <h3 className="text-base font-bold text-neutral-900">Recent Contributions</h3>
          <Link to="/treasurer/contributions" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
            View all <ArrowRight size={12} />
          </Link>
        </div>
        <div className="space-y-1">
          {recentContribs.slice(0, 5).map((c: Contribution, i: number) => (
            <div key={c.id} className="flex items-center gap-3 p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
              <MemberAvatar name={c.member_name ?? ''} index={i} />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-neutral-800 truncate">{c.member_name}</p>
                <p className="text-xs text-neutral-400">{c.method} · {new Date(c.date).toLocaleDateString('en-KE', { day: '2-digit', month: 'short' })}</p>
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

      {/* ═══ BANK ACCOUNTS + TRANSACTIONS ═══ */}
      <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
        <section className="lg:col-span-2 space-y-4">
          <StatCard label="Bank balance" value={formatCurrency(bankBalance)} icon={<Landmark size={20} />} />
          <StatCard label="Book balance" value={formatCurrency(s.book_balance)} icon={<FileText size={20} />} />
          <StatCard label="Reconciliation difference" value={formatCurrency(s.reconciliation_diff)} icon={<Receipt size={20} />} color="warning" />
        </section>

        {/* Bank transactions — 3 cols */}
        <Card className="p-6 lg:col-span-3">
          <div className="flex items-center justify-between mb-5">
            <h3 className="text-base font-bold text-neutral-900">Recent Bank Transactions</h3>
            <Link to="/treasurer/bank-statement" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          </div>
          <div className="overflow-x-auto -mx-6 px-6">
            <table className="w-full min-w-[520px]">
              <thead>
                <tr className="border-b border-neutral-100">
                  {['Date', 'Description', 'Type', 'Amount'].map((h, i) => (
                    <th key={h} className={classNames(
                      'text-left py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider',
                      i >= 3 && 'text-right',
                    )}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-50">
                {bankTxns.slice(0, 5).map((t: BankTransaction) => {
                  return (
                    <tr key={t.id} className="hover:bg-neutral-50/60 transition-colors">
                      <td className="py-3.5 px-2 text-sm text-neutral-500 whitespace-nowrap">
                        {new Date(t.date).toLocaleDateString('en-KE', { day: '2-digit', month: 'short' })}
                      </td>
                      <td className="py-3.5 px-2">
                        <p className="text-sm font-medium text-neutral-800 max-w-[160px] truncate">{t.description}</p>
                        <p className="text-xs text-neutral-400 font-mono">{t.reference}</p>
                      </td>
                      <td className="py-3.5 px-2">
                        <span className={classNames(
                          'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
                          t.type === 'credit' ? 'bg-success-50 text-success-700' : 'bg-error-50 text-error-700',
                        )}>
                          {t.type === 'credit' ? <ArrowUpRight size={11} /> : <ArrowDownRight size={11} />}
                          {t.type === 'credit' ? 'Credit' : 'Debit'}
                        </span>
                      </td>
                      <td className={classNames(
                        'py-3.5 px-2 text-right text-sm font-semibold whitespace-nowrap',
                        t.type === 'credit' ? 'text-success-700' : 'text-error-600',
                      )}>
                        {t.type === 'debit' ? '−' : '+'}{formatCurrency(t.amount)}
                      </td>

                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      </div>


    </div>
  );
}
