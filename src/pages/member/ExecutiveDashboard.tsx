import { useParams, Link } from '@/lib/router-compat';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Users, TrendingUp } from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, LoadingState, ErrorState, StatCard, PageHeader } from '@/components/ui';

export default function ExecutiveDashboard() {
  const { roleName = '' } = useParams();

  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.memberExecutive(roleName),
    queryFn: () => mockMember.getExecutiveDashboard(roleName),
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load executive dashboard" />;

  return (
    <div>
      <PageHeader
        title={data?.title || roleName}
        subtitle="Executive role dashboard"
        icon={<Users size={20} />}
      />

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
        {data?.stats.map((stat, i) => (
          <StatCard key={i} label={stat.label} value={stat.value} icon={<TrendingUp size={18} />} color={i % 2 === 0 ? 'primary' : 'secondary'} />
        ))}
      </div>

      <Card className="p-5 mt-6">
        <h3 className="text-sm font-bold text-neutral-900 mb-3">Quick Actions</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          {data?.actions.map((action, i) => (
            <Link key={i} to={action.route} className="flex items-center justify-between p-4 rounded-xl border border-neutral-200 hover:border-primary-300 hover:bg-primary-50/50 transition-all group">
              <span className="text-sm font-medium text-neutral-700">{action.label}</span>
              <ArrowRight size={16} className="text-neutral-400 group-hover:text-primary-600 group-hover:translate-x-1 transition-all" />
            </Link>
          ))}
        </div>
      </Card>
    </div>
  );
}
