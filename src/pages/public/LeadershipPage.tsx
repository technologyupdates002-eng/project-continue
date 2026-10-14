import { useQuery } from '@tanstack/react-query';
import { Link } from '@/lib/router-compat';
import { ArrowRight, Users } from 'lucide-react';
import { mockPublic } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, LoadingState, ErrorState, Avatar } from '@/components/ui';
import { PageHeader } from '@/components/ui';

export default function LeadershipPage() {
  const { data: leaders, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.publicLeadership,
    queryFn: () => mockPublic.getLeadership(),
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load leadership" onRetry={refetch} />;

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
      <PageHeader
        title="Our Leadership"
        subtitle="Meet the dedicated team guiding KHWWC"
        icon={<Users size={20} />}
      />

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-8">
        {leaders?.map((person) => (
          <Link key={person.id} to={`/public/website/people/${person.slug}`}>
            <Card className="p-5 h-full hover:shadow-card transition-shadow group">
              <div className="flex items-start gap-4">
                <Avatar name={person.name} size="xl" src={person.photo} />
                <div className="min-w-0 flex-1">
                  <h3 className="text-base font-bold text-neutral-900 truncate">{person.name}</h3>
                  <p className="text-sm text-primary-700 font-medium">{person.title}</p>
                </div>
              </div>
              <p className="mt-3 text-sm text-neutral-600 line-clamp-3">{person.bio}</p>
              <div className="mt-4 flex items-center gap-1 text-sm text-primary-700 font-medium group-hover:gap-2 transition-all">
                View profile <ArrowRight size={14} />
              </div>
            </Card>
          </Link>
        ))}
      </div>
    </div>
  );
}
