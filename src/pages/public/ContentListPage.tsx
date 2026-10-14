import { useParams, Link, useSearchParams } from '@/lib/router-compat';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Calendar, MapPin, FileText, Newspaper, Info, Download } from 'lucide-react';
import { mockPublic } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, LoadingState, ErrorState, EmptyState, Badge, Pagination, PageHeader } from '@/components/ui';
import { formatDate, classNames } from '@/lib/format';
import type { WebsiteContent } from '@/types';

const typeLabels: Record<string, string> = {
  news: 'News & Announcements',
  events: 'Events',
  documents: 'Documents',
  about: 'About Us',
};

const typeIcons: Record<string, React.ComponentType<{ size?: number | string; className?: string }>> = {
  news: Newspaper,
  events: Calendar,
  documents: FileText,
  about: Info,
};

export default function ContentListPage() {
  const { type = 'news' } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const page = parseInt(searchParams.get('page') || '1');
  const label = typeLabels[type] || type;
  const Icon = typeIcons[type] || Info;

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.publicContent(type, page),
    queryFn: () => mockPublic.getContent(type, page),
  });

  if (type === 'about') {
    const { data: about } = useQuery({
      queryKey: queryKeys.publicContent('about', 1),
      queryFn: () => mockPublic.getContent('about', 1),
    });
    const aboutItem = about?.data?.[0];
    return (
      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
        <PageHeader title={label} icon={<Icon size={20} />} />
        <Card className="p-6 sm:p-8">
          {isLoading ? <LoadingState /> : <p className="text-neutral-700 leading-relaxed whitespace-pre-line">{aboutItem?.content}</p>}
        </Card>
      </div>
    );
  }

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
      <PageHeader title={label} subtitle={`Browse ${label.toLowerCase()} from KHWWC`} icon={<Icon size={20} />} />

      {isLoading && <LoadingState />}
      {isError && <ErrorState message="Failed to load content" onRetry={refetch} />}
      {data && data.data.length === 0 && <EmptyState icon={<Icon size={24} />} title={`No ${label.toLowerCase()} yet`} message="Check back soon for updates." />}

      {data && data.data.length > 0 && (
        <>
          {type === 'news' && (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
              {data.data.map((item: WebsiteContent) => (
                <Link key={item.id} to={`/public/website/content/${type}/${item.slug}`}>
                  <Card className="p-5 h-full hover:shadow-card transition-shadow group">
                    <Badge status={item.status}>{item.category || item.status}</Badge>
                    <h3 className="mt-3 text-base font-bold text-neutral-900 line-clamp-2 group-hover:text-primary-700 transition-colors">{item.title}</h3>
                    <p className="mt-2 text-sm text-neutral-600 line-clamp-3">{item.excerpt || item.content}</p>
                    <div className="mt-3 flex items-center justify-between text-xs text-neutral-400">
                      <span>{formatDate(item.date)}</span>
                      <span className="text-primary-700 font-medium group-hover:gap-2 inline-flex items-center gap-1 transition-all">Read more <ArrowRight size={12} /></span>
                    </div>
                  </Card>
                </Link>
              ))}
            </div>
          )}

          {type === 'events' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
              {data.data.map((item: WebsiteContent) => {
                const eventDate = new Date(item.date);
                return (
                  <Card key={item.id} className="p-5">
                    <div className="flex items-start gap-4">
                      <div className="flex-shrink-0 w-14 h-14 rounded-xl bg-primary-50 text-primary-700 flex flex-col items-center justify-center">
                        <span className="text-lg font-bold leading-none">{eventDate.getDate()}</span>
                        <span className="text-xs">{eventDate.toLocaleString('en', { month: 'short' })}</span>
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="text-base font-bold text-neutral-900">{item.title}</h3>
                        <p className="mt-1 text-sm text-neutral-600 line-clamp-2">{item.content}</p>
                        <div className="mt-2 flex items-center gap-1 text-xs text-neutral-400">
                          <MapPin size={12} /> {item.author || 'Location TBA'}
                        </div>
                      </div>
                    </div>
                  </Card>
                );
              })}
            </div>
          )}

          {type === 'documents' && (
            <Card className="divide-y divide-neutral-50">
              {data.data.map((item: WebsiteContent) => (
                <div key={item.id} className="flex items-center justify-between p-4 hover:bg-neutral-50 transition-colors">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-secondary-50 text-secondary-700 flex items-center justify-center">
                      <FileText size={18} />
                    </div>
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-neutral-800 truncate">{item.title}</p>
                      <p className="text-xs text-neutral-400">{formatDate(item.date)}</p>
                    </div>
                  </div>
                  <button className="btn-ghost p-2" aria-label="Download">
                    <Download size={16} />
                  </button>
                </div>
              ))}
            </Card>
          )}

          <Pagination
            currentPage={data.current_page}
            lastPage={data.last_page}
            onPage={(p) => setSearchParams({ page: String(p) })}
          />
        </>
      )}
    </div>
  );
}
