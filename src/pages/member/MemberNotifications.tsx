import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Bell, Info, CheckCircle, AlertTriangle, XCircle, Clock,
} from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, PageHeader, EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import { relativeTime, formatDateTime } from '@/lib/format';
import type { Notification } from '@/types';

function notifIcon(type: string) {
  switch (type) {
    case 'success': return <CheckCircle size={18} className="text-success-600" />;
    case 'warning': return <AlertTriangle size={18} className="text-warning-600" />;
    case 'error': return <XCircle size={18} className="text-error-600" />;
    default: return <Info size={18} className="text-primary-600" />;
  }
}

export default function MemberNotifications() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberNotifications,
    queryFn: () => mockMember.getNotifications(),
  });

  const markRead = useMutation({
    mutationFn: (id: string) => mockMember.markNotificationRead(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.memberNotifications });
      queryClient.invalidateQueries({ queryKey: queryKeys.memberDashboard });
    },
  });

  const notifications = (data as Notification[] | undefined) ?? [];
  const unreadCount = notifications.filter((n) => !n.read).length;

  return (
    <div className="space-y-5">
      <PageHeader
        title="Notifications"
        subtitle={unreadCount > 0 ? `${unreadCount} unread notification${unreadCount > 1 ? 's' : ''}` : 'You are all caught up'}
        icon={<Bell size={20} />}
      />

      {isLoading ? (
        <LoadingState text="Loading notifications..." />
      ) : isError ? (
        <ErrorState message="We couldn't load your notifications." onRetry={() => refetch()} />
      ) : notifications.length === 0 ? (
        <EmptyState icon={<Bell size={22} />} title="No notifications" message="You will be notified about important updates here." />
      ) : (
        <Card className="divide-y divide-neutral-50">
          {notifications.map((n) => (
            <div key={n.id} className={`flex items-start gap-3 p-4 ${!n.read ? 'bg-primary-50/40' : ''}`}>
              <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-neutral-50 flex items-center justify-center">
                {notifIcon(n.type)}
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className={`text-sm ${n.read ? 'font-medium text-neutral-700' : 'font-semibold text-neutral-900'}`}>{n.title}</p>
                  {!n.read && <Badge status="ongoing">New</Badge>}
                </div>
                <p className="mt-0.5 text-xs text-neutral-600">{n.message}</p>
                <p className="mt-1 text-xs text-neutral-400 flex items-center gap-1">
                  <Clock size={11} /> {relativeTime(n.date)} · {formatDateTime(n.date)}
                </p>
              </div>
              {!n.read && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="flex-shrink-0"
                  disabled={markRead.isPending}
                  onClick={() => markRead.mutate(n.id)}
                >
                  Mark read
                </Button>
              )}
            </div>
          ))}
        </Card>
      )}
    </div>
  );
}
