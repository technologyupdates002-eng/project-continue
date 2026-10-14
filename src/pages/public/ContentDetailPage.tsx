import { useParams, Link } from '@/lib/router-compat';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Calendar, User } from 'lucide-react';
import { mockPublic } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, LoadingState, ErrorState, Badge } from '@/components/ui';
import { formatDate } from '@/lib/format';

export default function ContentDetailPage() {
  const { type = '', slug = '' } = useParams();

  const { data: item, isLoading, isError } = useQuery({
    queryKey: queryKeys.publicContentDetail(type, slug),
    queryFn: () => mockPublic.getContentDetail(type, slug),
  });

  if (isLoading) return <LoadingState />;
  if (isError || !item) return <ErrorState message="Failed to load content" />;

  return (
    <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
      <Link to={`/public/website/content/${type}`} className="inline-flex items-center gap-1.5 text-sm text-primary-700 font-medium hover:gap-2.5 transition-all mb-6">
        <ArrowLeft size={16} /> Back to {type}
      </Link>

      <article>
        <div className="flex items-center gap-3 mb-4">
          {item.category && <Badge status="published">{item.category}</Badge>}
        </div>
        <h1 className="text-2xl sm:text-3xl font-bold text-neutral-900 leading-tight">{item.title}</h1>
        <div className="mt-4 flex flex-wrap items-center gap-4 text-sm text-neutral-400">
          <span className="flex items-center gap-1.5"><User size={14} /> {item.author}</span>
          <span className="flex items-center gap-1.5"><Calendar size={14} /> {formatDate(item.date, 'long')}</span>
        </div>

        {item.image && (
          <img src={item.image} alt={item.title} className="mt-6 w-full rounded-2xl object-cover max-h-96" />
        )}

        <div className="mt-6 prose prose-neutral max-w-none">
          <p className="text-neutral-700 leading-relaxed whitespace-pre-line text-base">{item.content}</p>
        </div>
      </article>
    </div>
  );
}
