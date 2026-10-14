import { useEffect, useRef, useState } from 'react';
import { Link } from '@/lib/router-compat';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Bell, CalendarCheck, ChevronDown, CreditCard, FileText, HeartHandshake, PiggyBank, ShieldCheck, WalletCards, Calendar, Coins, Users, Download } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Button, Card, Badge, StatCard, ErrorState, LoadingState } from '@/components/ui';
import { memberPaymentDestinations } from '@/lib/member-payment-navigation';
import { formatCurrency, formatDate } from '@/lib/format';

const paymentChoices = [
  { label: 'Monthly contribution', hint: 'Pay any amount — split into months at the set rate', path: memberPaymentDestinations.welfare, icon: PiggyBank },
  { label: 'Penalty', hint: 'Clear outstanding penalties', path: memberPaymentDestinations.penalty, icon: ShieldCheck },
  { label: 'Fund drive', hint: 'Donate to an active campaign', path: memberPaymentDestinations.fundDrive, icon: HeartHandshake },
];

const shortcuts = [
  { label: 'Pay contribution', path: memberPaymentDestinations.welfare, icon: PiggyBank },
  { label: 'Pay penalty', path: memberPaymentDestinations.penalty, icon: ShieldCheck },
  { label: 'Fund drive', path: memberPaymentDestinations.fundDrive, icon: HeartHandshake },
  { label: 'Statement', path: '/member/downloads', icon: Download },
  { label: 'Beneficiaries', path: '/member/beneficiaries', icon: Users },
  { label: 'Notifications', path: '/member/notifications', icon: Bell },
];

function PaymentMenu() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);
  return <div className="relative" ref={ref}>
    <Button variant="secondary" className="balance-action" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen(v => !v)}><CreditCard size={16} />Make a payment<ChevronDown size={16} className={open ? 'rotate-180 transition-transform' : 'transition-transform'} /></Button>
    {open && <div role="menu" className="payment-menu card animate-fade-in">
      {paymentChoices.map(({ label, hint, path, icon: Icon }) => <Link role="menuitem" key={label} to={path} className="payment-menu-item" onClick={() => setOpen(false)}>
        <span className="payment-menu-icon"><Icon size={18} /></span>
        <span className="min-w-0 flex-1"><span className="block text-sm font-semibold">{label}</span><span className="block text-xs text-muted-foreground">{hint}</span></span>
        <ArrowRight size={15} />
      </Link>)}
    </div>}
  </div>;
}

export default function MemberDashboard() {
  const { user } = useAuth();
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: queryKeys.memberDashboard, queryFn: () => mockMember.dashboard() });
  if (isLoading) return <LoadingState text="Loading your member dashboard…" />;
  if (isError || !data) return <ErrorState message="We couldn't load your dashboard." onRetry={() => refetch()} />;
  const firstName = user?.name?.replace(/^(Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s+/i, '').split(' ')[0] ?? 'Member';
  const outstanding = Math.max(data.penalties_total, 0);
  const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Africa/Nairobi' }).format(new Date()));
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

  return <div className="space-y-7">
    <div className="flex flex-wrap items-center justify-between gap-4">
      <div><p className="text-sm text-muted-foreground mb-2">Your welfare, at a glance</p><h1 className="font-display text-2xl sm:text-3xl font-bold">{greeting}, {firstName}.</h1></div>
      <Badge status={data.status}>{data.status} member</Badge>
    </div>

    <div className="member-overview">
      <section className="member-balance">
        <div className="flex items-center justify-between"><span className="text-sm">Total contributions</span><PiggyBank size={24} /></div>
        <p className="balance-number mt-5">{formatCurrency(data.contributions_total)}</p>
        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
          <span>{data.contributions_count} payments</span>
          {data.months_covered != null && <span className="inline-flex items-center gap-1"><CalendarCheck size={14} />{data.months_covered} months covered</span>}
          {data.monthly_rate != null && <span>Rate {formatCurrency(data.monthly_rate)}/month</span>}
        </div>
        <div className="mt-6 flex flex-wrap items-center gap-3">
          <PaymentMenu />
          <Link to="/member/downloads" className="btn-secondary balance-action"><FileText size={16} />Statement<ArrowRight size={16} /></Link>
        </div>
      </section>
      <div className="grid grid-cols-2 gap-4">
        <StatCard label="Fund drive donations" value={formatCurrency(data.donations_total)} icon={<HeartHandshake size={20} />} color="success" />
        <StatCard label="Outstanding penalties" value={formatCurrency(outstanding)} icon={<ShieldCheck size={20} />} color="warning" />
        <StatCard label="Wallet balance" value={formatCurrency(data.wallet_balance)} icon={<WalletCards size={20} />} />
        <StatCard label="Unread notifications" value={data.unread_notifications} icon={<Bell size={20} />} color="secondary" />
      </div>
    </div>

    {outstanding > 0 && <Link to={memberPaymentDestinations.penalty} className="member-alert card"><ShieldCheck size={20} /><span className="flex-1 text-sm"><strong>{formatCurrency(outstanding)}</strong> in penalties is outstanding{data.grace_days != null ? ` — penalties apply ${data.grace_days} days after a missed month` : ''}.</span><span className="text-sm font-semibold">Pay now</span><ArrowRight size={16} /></Link>}

    <section><h2 className="text-base font-bold mb-4">Quick actions</h2><div className="member-shortcuts">{shortcuts.map(({ label, path, icon: Icon }) => <Link to={path} key={label} className="member-shortcut card transition-all"><span><Icon size={24} strokeWidth={1.6} /></span><span className="text-sm font-medium">{label}</span></Link>)}</div></section>

    <div className="grid lg:grid-cols-5 gap-6">
      <Card className="lg:col-span-3 p-5 sm:p-6"><div className="flex items-center justify-between gap-3 mb-2"><h2 className="font-bold">Recent contributions</h2><Link to="/member/downloads" className="text-primary text-xs">View all →</Link></div>{data.recent_contributions.length ? data.recent_contributions.map(contribution => <div key={contribution.id} className="member-recent-row"><span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><Coins size={19} /></span><div className="flex-1 min-w-0"><p className="text-sm font-semibold">{contribution.period}</p><p className="text-xs text-muted-foreground mt-1">{formatDate(contribution.date)} · {contribution.method}</p></div><div className="text-right"><p className="text-sm font-bold">{formatCurrency(contribution.amount)}</p><span className="text-xs text-muted-foreground">{contribution.status}</span></div></div>) : <p className="py-8 text-sm text-muted-foreground">No recent contributions.</p>}</Card>
      <Card className="lg:col-span-2 p-5 sm:p-6"><div className="flex items-center justify-between mb-2"><h2 className="font-bold">Upcoming events</h2><Link to="/member/events" className="text-primary text-xs">View all →</Link></div>{data.upcoming_events.length ? data.upcoming_events.map(event => <Link to="/member/events" key={event.id} className="member-recent-row"><span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><Calendar size={19} /></span><div className="min-w-0 flex-1"><p className="text-sm font-semibold">{event.title}</p><p className="text-xs text-muted-foreground mt-1">{formatDate(event.date)}</p></div><ArrowRight size={16} /></Link>) : <p className="py-8 text-sm text-muted-foreground">No upcoming events.</p>}</Card>
    </div>
  </div>;
}
