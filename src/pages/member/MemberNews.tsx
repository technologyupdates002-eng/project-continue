import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Newspaper, Calendar, Tag } from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, PageHeader, Pagination, EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import { formatDate } from '@/lib/format';
import type { NewsArticle } from '@/types';

export default function MemberNews() {
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, refetch, isFetching } = useQuery({
    queryKey: queryKeys.memberNews(page),
    queryFn: () => mockMember.getNews(page),
  });

  const articles = (data?.data as NewsArticle[] | undefined) ?? [];

  return (
    <div className="space-y-5">
      <PageHeader title="News & Updates" subtitle="Latest announcements from KHWWC" icon={<Newspaper size={20} />} />

      {isLoading ? (
        <LoadingState text="Loading news..." />
      ) : isError ? (
        <ErrorState message="We couldn't load the news feed." onRetry={() => refetch()} />
      ) : articles.length === 0 ? (
        <EmptyState icon={<Newspaper size={22} />} title="No news articles" message="Check back later for updates." />
      ) : (
        <>
          {isFetching && !isLoading && <LoadingState text="Updating..." />}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {articles.map((a) => (
              <Card key={a.id} className="p-5 flex flex-col">
                {a.image ? (
                  <img src={a.image} alt={a.title} className="w-full h-32 object-cover rounded-xl mb-3" />
                ) : (
                  <div className="w-full h-32 rounded-xl bg-primary-50 flex items-center justify-center mb-3">
                    <Newspaper size={28} className="text-primary-300" />
                  </div>
                )}
                <div className="flex items-center gap-2 mb-2">
                  <Badge status="published"><span className="flex items-center gap-1"><Tag size={10} /> {a.category}</span></Badge>
                  <span className="text-xs text-neutral-400 flex items-center gap-1"><Calendar size={11} /> {formatDate(a.date)}</span>
                </div>
                <h3 className="text-sm font-semibold text-neutral-900 line-clamp-2">{a.title}</h3>
                <p className="mt-1 text-xs text-neutral-500 line-clamp-3 flex-1">{a.excerpt}</p>
                <p className="mt-3 text-xs text-neutral-400">By {a.author}</p>
              </Card>
            ))}
          </div>
          {data && (
            <Pagination
              currentPage={data.current_page}
              lastPage={data.last_page}
              onPage={setPage}
            />
          )}
        </>
      )}
    </div>
  );
}
