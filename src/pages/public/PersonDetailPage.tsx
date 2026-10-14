import { useParams, Link, useNavigate } from '@/lib/router-compat';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Mail, Phone } from 'lucide-react';
import { mockPublic } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, LoadingState, ErrorState, Avatar } from '@/components/ui';

export default function PersonDetailPage() {
  const { slug = '' } = useParams();
  const navigate = useNavigate();

  const { data: person, isLoading, isError } = useQuery({
    queryKey: queryKeys.publicPerson(slug),
    queryFn: () => mockPublic.getPerson(slug),
  });

  if (isLoading) return <LoadingState />;
  if (isError || !person) return <ErrorState message="Failed to load profile" onRetry={() => navigate('/public/website/leadership')} />;

  return (
    <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
      <Link to="/public/website/leadership" className="inline-flex items-center gap-1.5 text-sm text-primary-700 font-medium hover:gap-2.5 transition-all mb-6">
        <ArrowLeft size={16} /> Back to Leadership
      </Link>

      <Card className="overflow-hidden">
        <div className="h-32 bg-gradient-to-br from-primary-700 to-primary-900" />
        <div className="px-5 sm:px-8 pb-8">
          <div className="-mt-16 mb-4">
            <Avatar name={person.name} size="xl" src={person.photo} />
          </div>
          <h1 className="text-2xl font-bold text-neutral-900">{person.name}</h1>
          <p className="text-lg text-primary-700 font-medium">{person.title}</p>
          <p className="mt-5 text-neutral-700 leading-relaxed whitespace-pre-line">{person.bio}</p>

          {(person.email || person.phone) && (
            <div className="mt-6 pt-6 border-t border-neutral-100 flex flex-wrap gap-6">
              {person.email && (
                <a href={`mailto:${person.email}`} className="flex items-center gap-2 text-sm text-neutral-600 hover:text-primary-700">
                  <Mail size={16} className="text-primary-600" /> {person.email}
                </a>
              )}
              {person.phone && (
                <span className="flex items-center gap-2 text-sm text-neutral-600">
                  <Phone size={16} className="text-primary-600" /> {person.phone}
                </span>
              )}
            </div>
          )}
        </div>
      </Card>
    </div>
  );
}
