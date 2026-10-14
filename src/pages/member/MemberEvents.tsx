import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Calendar, MapPin, Users } from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, PageHeader, Tabs, EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import { formatDate } from '@/lib/format';
import type { EventItem } from '@/types';

export default function MemberEvents() {
  const [tab, setTab] = useState<'upcoming' | 'past'>('upcoming');
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberEvents,
    queryFn: () => mockMember.getEvents(),
  });

  const events = (data as EventItem[] | undefined) ?? [];
  const now = new Date();
  const upcoming = events
    .filter((e) => new Date(e.date) >= now && e.status !== 'cancelled' && e.status !== 'past')
    .sort((a, b) => new Date(a.date).getTime() - new Date(b.date).getTime());
  const past = events
    .filter((e) => new Date(e.date) < now || e.status === 'past')
    .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime());

  const filtered = tab === 'upcoming' ? upcoming : past;

  return (
    <div className="space-y-5">
      <PageHeader title="Events" subtitle="KHWWC meetings and gatherings" icon={<Calendar size={20} />} />

      <Tabs
        tabs={[
          { key: 'upcoming', label: 'Upcoming', count: upcoming.length },
          { key: 'past', label: 'Past', count: past.length },
        ]}
        active={tab}
        onChange={(k) => setTab(k as 'upcoming' | 'past')}
      />

      {isLoading ? (
        <LoadingState text="Loading events..." />
      ) : isError ? (
        <ErrorState message="We couldn't load events." onRetry={() => refetch()} />
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={<Calendar size={22} />}
          title={tab === 'upcoming' ? 'No upcoming events' : 'No past events'}
          message={tab === 'upcoming' ? 'Check back later for scheduled events.' : 'Past events will appear here.'}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {filtered.map((e) => (
            <Card key={e.id} className="p-5 flex flex-col">
              <div className="flex items-start justify-between gap-2">
                <div className="flex-shrink-0 w-12 h-12 rounded-xl bg-primary-100 text-primary-700 flex flex-col items-center justify-center">
                  <span className="text-[10px] font-medium leading-none">{new Date(e.date).toLocaleDateString('en-KE', { month: 'short' })}</span>
                  <span className="text-lg font-bold leading-none">{new Date(e.date).getDate()}</span>
                </div>
                <Badge status={e.status}>{e.status}</Badge>
              </div>
              <h3 className="mt-3 text-sm font-semibold text-neutral-900">{e.title}</h3>
              <p className="mt-1 text-xs text-neutral-500 line-clamp-2 flex-1">{e.description}</p>
              <div className="mt-3 space-y-1.5 text-xs text-neutral-500">
                <p className="flex items-center gap-1.5"><Calendar size={13} className="text-neutral-400" /> {formatDate(e.date, 'long')}</p>
                <p className="flex items-center gap-1.5"><MapPin size={13} className="text-neutral-400" /> {e.location}</p>
                <p className="flex items-center gap-1.5"><Users size={13} className="text-neutral-400" /> {e.attendees_count} attending</p>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
