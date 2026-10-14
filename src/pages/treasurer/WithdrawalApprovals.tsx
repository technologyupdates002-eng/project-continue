import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { FileCheck, CheckCircle, XCircle, Clock, ArrowRight } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, Badge, Button, LoadingState, ErrorState, EmptyState, ConfirmDialog } from '@/components/ui';
import { formatCurrency, formatDate, classNames, initials } from '@/lib/format';

function KpiCard({ label, value, icon: Icon, accent }: {
  label: string; value: string | number; icon: typeof FileCheck; accent: string;
}) {
  return (
    <div className="group relative bg-white rounded-n26-card border border-neutral-100 p-5 transition-all hover:shadow-float hover:border-neutral-200">
      <div className="flex items-start justify-between mb-4">
        <div className={classNames('w-10 h-10 rounded-xl flex items-center justify-center', accent)}>
          <Icon size={20} />
        </div>
      </div>
      <p className="text-2xl font-bold text-neutral-900 tracking-tight">{value}</p>
      <p className="mt-1 text-sm text-neutral-500">{label}</p>
    </div>
  );
}

export default function WithdrawalApprovals() {
  const qc = useQueryClient();
  const [selected, setSelected] = useState<any>(null);
  const [confirmAction, setConfirmAction] = useState<'approve' | 'reject' | null>(null);

  const { data: withdrawals, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.treasurerWithdrawals,
    queryFn: () => mockTreasurer.getWithdrawalApprovals(),
  });

  const mutation = useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'approve' | 'reject' }) => mockTreasurer.approveWithdrawal(id, action),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.treasurerWithdrawals }); setSelected(null); setConfirmAction(null); },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load withdrawal requests" onRetry={refetch} />;

  const pending = (withdrawals || []).filter((w: any) => w.status === 'pending');
  const processed = (withdrawals || []).filter((w: any) => w.status !== 'pending');

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Withdrawal Approvals</h1>
        <p className="mt-1 text-sm text-neutral-500">Review and approve member withdrawal requests</p>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <KpiCard label="Pending" value={pending.length} icon={Clock} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Processed" value={processed.length} icon={CheckCircle} accent="bg-success-50 text-success-600" />
      </div>

      <Card className="p-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-base font-bold text-neutral-900">Pending Requests</h3>
        </div>
        {pending.length === 0 ? (
          <EmptyState icon={<FileCheck size={24} />} title="No pending requests" message="All withdrawal requests have been processed." />
        ) : (
          <div className="space-y-1">
            {pending.map((w: any) => (
              <div key={w.id} className="group flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="min-w-0 flex-1 flex items-center gap-3">
                  <div className="w-9 h-9 rounded-full bg-warning-100 text-warning-700 text-xs font-bold flex items-center justify-center flex-shrink-0">
                    {initials(w.member_name || '?')}
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-neutral-800 truncate">{w.member_name}</p>
                    <p className="text-xs text-neutral-400 mt-0.5">{formatCurrency(w.amount)} · {w.reason}</p>
                    <p className="text-xs text-neutral-300 mt-0.5">Requested {formatDate(w.requested_date)}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <Button size="sm" onClick={() => { setSelected(w); setConfirmAction('approve'); }}>
                    <CheckCircle size={14} /> Approve
                  </Button>
                  <Button size="sm" variant="danger" onClick={() => { setSelected(w); setConfirmAction('reject'); }}>
                    <XCircle size={14} /> Reject
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {processed.length > 0 && (
        <Card className="p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-base font-bold text-neutral-900">Processed</h3>
          </div>
          <div className="space-y-1">
            {processed.map((w: any) => (
              <div key={w.id} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="flex items-center gap-3">
                  <div className={classNames(
                    'w-8 h-8 rounded-full text-xs font-bold flex items-center justify-center flex-shrink-0',
                    w.status === 'approved' ? 'bg-success-100 text-success-700' : 'bg-error-100 text-error-700',
                  )}>
                    {initials(w.member_name || '?')}
                  </div>
                  <div>
                    <span className="text-sm font-medium text-neutral-700">{w.member_name}</span>
                    <span className="text-sm text-neutral-400 ml-2">{formatCurrency(w.amount)}</span>
                  </div>
                </div>
                <Badge status={w.status}>{w.status}</Badge>
              </div>
            ))}
          </div>
        </Card>
      )}

      <ConfirmDialog
        open={!!confirmAction}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => selected && mutation.mutate({ id: selected.id, action: confirmAction! })}
        title={confirmAction === 'approve' ? 'Approve Withdrawal' : 'Reject Withdrawal'}
        message={`Are you sure you want to ${confirmAction} the withdrawal of ${selected ? formatCurrency(selected.amount) : ''} for ${selected?.member_name}?`}
        confirmLabel={confirmAction === 'approve' ? 'Approve' : 'Reject'}
        danger={confirmAction === 'reject'}
      />
    </div>
  );
}
