import { useState } from 'react';
import { Link } from '@/lib/router-compat';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, BarChart3, Bell, CreditCard, FileText, HeartHandshake, PiggyBank, ShieldCheck, WalletCards, Calendar, Coins } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Button, Card, Badge, StatCard, ErrorState, LoadingState } from '@/components/ui';
import { memberPaymentDestinations } from '@/lib/member-payment-navigation';
import { formatCurrency, formatDate } from '@/lib/format';

const shortcuts = [
  { label: 'Contributions', path: '/member/downloads', icon: PiggyBank },
  { label: 'Penalties', path: '/member/pay-penalty', icon: ShieldCheck },
  { label: 'Welfare', path: memberPaymentDestinations.welfare, icon: HeartHandshake },
  { label: 'Fund drive', path: '/member/donate', icon: WalletCards },
  { label: 'Statements', path: '/member/downloads', icon: FileText },
  { label: 'Member stats', path: '/member/downloads', icon: BarChart3 },
];
export default function MemberDashboard() {
  const { user } = useAuth();
  const [paymentMenuOpen, setPaymentMenuOpen] = useState(false);
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: queryKeys.memberDashboard, queryFn: () => mockMember.dashboard() });
  if (isLoading) return <LoadingState text="Loading your member dashboard…" />;
  if (isError || !data) return <ErrorState message="We couldn't load your dashboard." onRetry={() => refetch()} />;
  const firstName = user?.name?.replace(/^(Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s+/i, '').split(' ')[0] ?? 'Member'; const outstanding = Math.max(data.penalties_total, 0);
  const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Africa/Nairobi' }).format(new Date()));
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  return <div className="space-y-7">
    <div className="flex flex-wrap items-center justify-between gap-4"><div><p className="text-sm text-muted-foreground mb-2">Your welfare, at a glance</p><h1 className="font-display text-2xl sm:text-3xl font-bold">{greeting}, {firstName}.</h1><p className="text-sm text-muted-foreground mt-2">{user?.name}</p></div><Badge status={data.status}>{data.status} member</Badge></div>
    <div className="member-overview">
      <section className="member-balance"><div className="flex items-center justify-between"><span className="text-sm opacity-90">Total contributions</span><PiggyBank size={24} /></div><p className="balance-number mt-5">{formatCurrency(data.contributions_total)}</p><p className="mt-2 text-sm opacity-80">{data.contributions_count} contributions</p><div className="mt-6 flex flex-wrap items-center gap-3"><Button variant="secondary" className="balance-action" onClick={() => setPaymentMenuOpen(value => !value)}><CreditCard size={16} />Make a payment<ArrowRight size={16} /></Button><Link to="/member/downloads" className="btn-secondary balance-action"><FileText size={16} />Statement<ArrowRight size={16} /></Link></div></section>
      <div className="grid grid-cols-2 gap-4"><StatCard label="Welfare donations" value={formatCurrency(data.donations_total)} icon={<HeartHandshake size={20} />} color="success" /><StatCard label="Outstanding penalties" value={formatCurrency(outstanding)} icon={<ShieldCheck size={20} />} color="warning" /><StatCard label="Wallet balance" value={formatCurrency(data.wallet_balance)} icon={<WalletCards size={20} />} /><StatCard label="Unread notifications" value={data.unread_notifications} icon={<Bell size={20} />} color="secondary" /></div>
    </div>
    {paymentMenuOpen && <nav aria-label="Payment choices" className="flex flex-wrap gap-3"><Link className="btn-secondary balance-action" to={memberPaymentDestinations.penalty}><ShieldCheck size={16} />Penalty</Link><Link className="btn-secondary balance-action" to={memberPaymentDestinations.welfare}><HeartHandshake size={16} />Welfare contribution</Link><Link className="btn-secondary balance-action" to={memberPaymentDestinations.fundDrive}><WalletCards size={16} />Fund drive</Link></nav>}
    <section><h2 className="text-base font-bold mb-4">Quick actions</h2><div className="member-shortcuts">{shortcuts.map(({ label, path, icon: Icon }) => <Link to={path} key={label} className="member-shortcut card transition-all"><span><Icon size={24} strokeWidth={1.6} /></span><span className="text-sm font-medium">{label}</span></Link>)}</div></section>
    <div className="grid lg:grid-cols-5 gap-6">
      <Card className="lg:col-span-3 p-5 sm:p-6"><div className="flex items-center justify-between gap-3 mb-2"><h2 className="font-bold">Recent contributions</h2><Link to="/member/downloads" className="text-primary text-xs">View all →</Link></div>{data.recent_contributions.length ? data.recent_contributions.map(contribution => <div key={contribution.id} className="member-recent-row"><span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><Coins size={19} /></span><div className="flex-1 min-w-0"><p className="text-sm font-semibold">{contribution.period}</p><p className="text-xs text-muted-foreground mt-1">{formatDate(contribution.date)} · {contribution.method}</p></div><div className="text-right"><p className="text-sm font-bold">{formatCurrency(contribution.amount)}</p><span className="text-xs text-muted-foreground">{contribution.status}</span></div></div>) : <p className="py-8 text-sm text-muted-foreground">No recent contributions.</p>}</Card>
      <Card className="lg:col-span-2 p-5 sm:p-6"><div className="flex items-center justify-between mb-2"><h2 className="font-bold">Upcoming events</h2><Link to="/member/events" className="text-primary text-xs">View all →</Link></div>{data.upcoming_events.length ? data.upcoming_events.map(event => <Link to="/member/events" key={event.id} className="member-recent-row"><span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><Calendar size={19} /></span><div className="min-w-0 flex-1"><p className="text-sm font-semibold">{event.title}</p><p className="text-xs text-muted-foreground mt-1">{formatDate(event.date)}</p></div><ArrowRight size={16} /></Link>) : <p className="py-8 text-sm text-muted-foreground">No upcoming events.</p>}</Card>
    </div>
  </div>;
}
