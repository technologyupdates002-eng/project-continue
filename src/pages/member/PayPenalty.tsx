import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertCircle, CheckCircle, Calendar, Wallet, CreditCard,
} from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, PageHeader, EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import { PaymentModal } from '@/components/PaymentModal';
import { formatCurrency, formatDate } from '@/lib/format';
import type { Penalty } from '@/types';

export default function PayPenalty() {
  const queryClient = useQueryClient();
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [payOpen, setPayOpen] = useState(false);
  const [success, setSuccess] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberPenalties,
    queryFn: () => mockMember.getPenalties(),
  });

  const penalties = (data as Penalty[] | undefined) ?? [];
  const unpaid = penalties.filter((p) => p.status === 'unpaid');
  const totalUnpaid = unpaid.reduce((sum, p) => sum + p.amount, 0);
  const selectedPenalties = unpaid.filter((p) => selectedIds.has(p.id));
  const selectedTotal = selectedPenalties.reduce((sum, p) => sum + p.amount, 0);

  const togglePenalty = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleAll = () => {
    if (selectedIds.size === unpaid.length) setSelectedIds(new Set());
    else setSelectedIds(new Set(unpaid.map((p) => p.id)));
  };

  const handlePaySuccess = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.memberPenalties });
    queryClient.invalidateQueries({ queryKey: queryKeys.memberDashboard });
    setSelectedIds(new Set());
    setPayOpen(false);
    setSuccess('Penalty payment confirmed successfully.');
    setTimeout(() => setSuccess(''), 4000);
  };

  const openPay = () => {
    if (selectedIds.size === 0) return;
    setPayOpen(true);
  };

  return (
    <div className="space-y-5">
      <PageHeader title="Pay Penalties" subtitle="Select and clear your outstanding penalties" icon={<AlertCircle size={20} />} />

      <Card className="p-5">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-neutral-500">Total Outstanding</p>
            <p className={`mt-1 text-2xl font-bold ${totalUnpaid > 0 ? 'text-error-600' : 'text-success-600'}`}>
              {formatCurrency(totalUnpaid)}
            </p>
          </div>
          <div className={`w-12 h-12 rounded-xl flex items-center justify-center ${totalUnpaid > 0 ? 'bg-error-50 text-error-600' : 'bg-success-50 text-success-600'}`}>
            <Wallet size={22} />
          </div>
        </div>
      </Card>

      {success && (
        <div className="flex items-start gap-2 p-3 rounded-xl bg-success-50 border border-success-200 text-sm text-success-700">
          <CheckCircle size={16} className="flex-shrink-0 mt-0.5" />
          <span>{success}</span>
        </div>
      )}

      {isLoading ? (
        <LoadingState text="Loading penalties..." />
      ) : isError ? (
        <ErrorState message="We couldn't load your penalties." onRetry={() => refetch()} />
      ) : unpaid.length === 0 ? (
        <EmptyState
          icon={<CheckCircle size={22} />}
          title="No outstanding penalties"
          message="You have cleared all your penalties. Great job!"
        />
      ) : (
        <>
          <Card className="p-4">
            <div className="flex items-center justify-between mb-3">
              <label className="flex items-center gap-2 text-sm font-medium text-neutral-700 cursor-pointer">
                <input
                  type="checkbox"
                  checked={selectedIds.size === unpaid.length}
                  onChange={toggleAll}
                  className="w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
                />
                Select All
              </label>
              {selectedIds.size > 0 && (
                <span className="text-sm text-neutral-600">
                  {selectedIds.size} selected · <span className="font-semibold text-neutral-900">{formatCurrency(selectedTotal)}</span>
                </span>
              )}
            </div>
          </Card>

          <Card className="divide-y divide-neutral-50">
            {unpaid.map((p) => (
              <div key={p.id} className="flex items-start gap-3 p-4">
                <input
                  type="checkbox"
                  checked={selectedIds.has(p.id)}
                  onChange={() => togglePenalty(p.id)}
                  className="mt-1 w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 flex-shrink-0"
                />
                <div className="flex-shrink-0 w-11 h-11 rounded-xl bg-error-50 text-error-600 flex items-center justify-center">
                  <AlertCircle size={18} />
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-neutral-900">{formatCurrency(p.amount)}</p>
                  <p className="mt-0.5 text-xs text-neutral-600 line-clamp-2">{p.reason}</p>
                  <p className="mt-1 text-xs text-neutral-400 flex items-center gap-1">
                    <Calendar size={11} /> Incurred {formatDate(p.date)}
                  </p>
                </div>
                <Badge status={p.status}>{p.status}</Badge>
              </div>
            ))}
          </Card>

          {selectedIds.size > 0 && (
            <div className="sticky bottom-4 z-10">
              <Card className="p-4 shadow-lg border-primary-200">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-xs text-neutral-500">Selected Total</p>
                    <p className="text-lg font-bold text-neutral-900">{formatCurrency(selectedTotal)}</p>
                  </div>
                  <Button onClick={openPay} className="inline-flex items-center gap-2">
                    <CreditCard size={16} /> Pay Selected
                  </Button>
                </div>
              </Card>
            </div>
          )}
        </>
      )}

      <PaymentModal
        open={payOpen}
        onClose={() => setPayOpen(false)}
        amount={selectedTotal}
        accountRef={`PENALTY-member`}
        paymentType="penalty"
        transactionDesc={`Penalty payment for ${selectedIds.size} penalty(es)`}
        penaltyIds={Array.from(selectedIds)}
        onSuccess={handlePaySuccess}
      />
    </div>
  );
}
