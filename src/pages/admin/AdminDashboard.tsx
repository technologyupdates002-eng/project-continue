import { useQuery } from '@tanstack/react-query';
import { Link } from '@/lib/router-compat';
import { LayoutDashboard, Users, Coins, AlertCircle, TrendingUp, ArrowRight } from 'lucide-react';
import { LineChart } from '@/components/Charts';
import { mockAdmin } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, StatCard, LoadingState, ErrorState, PageHeader, Avatar, Badge } from '@/components/ui';
import { formatCurrency, formatCurrencyShort, formatDate, relativeTime } from '@/lib/format';

export default function AdminDashboard() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.adminDashboard,
    queryFn: () => mockAdmin.dashboard(),
  });

  if (isLoading) return <LoadingState />;
  if (isError || !data) return <ErrorState message="Failed to load dashboard" onRetry={refetch} />;

  const d = data;


  return (
    <div>
      <PageHeader title="Admin Dashboard" subtitle="Organization overview and management" icon={<LayoutDashboard size={20} />} />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <StatCard label="Total Members" value={d.total_members} icon={<Users size={18} />} />
        <StatCard label="Active Members" value={d.active_members} icon={<Users size={18} />} color="success" />
        <StatCard label="Total Contributions" value={formatCurrencyShort(d.total_contributions)} icon={<Coins size={18} />} color="secondary" />
        <StatCard label="Pending Approvals" value={d.pending_approvals} icon={<AlertCircle size={18} />} color="warning" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
        <Card className="p-5 lg:col-span-2">
          <h3 className="text-sm font-bold mb-4">Contributions Trend</h3>
          <LineChart series={[{ label: 'Contributions', color: 'var(--brand)', points: d.contributions_trend.map(m => ({ label: m.month, value: m.amount })) }]} height={220} />
        </Card>

        <Card className="p-5">
          <h3 className="text-sm font-bold mb-3">Recent Signups</h3>
          <div className="space-y-3">
            {d.recent_signups.map((m: any) => (
              <div key={m.id} className="flex items-center gap-3">
                <Avatar name={m.name} size="sm" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-neutral-800 truncate">{m.name}</p>
                  <p className="text-xs text-neutral-400">{m.member_number}</p>
                </div>
                <span className="text-xs text-neutral-400">{relativeTime(m.joined_date)}</span>
              </div>
            ))}
          </div>
          <Link to="/admin/members" className="mt-3 flex items-center justify-center gap-1 text-sm text-primary-700 font-medium hover:gap-2 transition-all">
            View all members <ArrowRight size={14} />
          </Link>
        </Card>
      </div>

      <Card className="p-5 mt-4">
        <div className="flex items-center gap-2 mb-3">
          <AlertCircle size={16} className="text-error-500" />
          <h3 className="text-sm font-bold">Defaulters Alert</h3>
          <Badge status="critical">{d.defaulters_count}</Badge>
        </div>
        <Link to="/admin/defaulters" className="text-sm text-primary-700 font-medium hover:underline">View defaulters list →</Link>
      </Card>
    </div>
  );
}
