import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, CheckCircle, AlertCircle } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, PageHeader, Button, LoadingState } from '@/components/ui';
import { formatDateTime } from '@/lib/format';

export default function BankSync() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: queryKeys.treasurerBankSync,
    queryFn: () => mockTreasurer.getBankSync(),
  });

  const mutation = useMutation({
    mutationFn: () => mockTreasurer.triggerBankSync(),
    onSuccess: () => qc.invalidateQueries({ queryKey: queryKeys.treasurerBankSync }),
  });

  if (isLoading) return <LoadingState />;

  return (
    <div>
      <PageHeader title="Bank Sync" subtitle="Synchronize bank transactions with KHWWC records" icon={<RefreshCw size={20} />}
        action={<Button onClick={() => mutation.mutate()} disabled={mutation.isPending}><RefreshCw size={16} className={mutation.isPending ? 'animate-spin' : ''} /> Sync Now</Button>}
      />

      <Card className="p-6">
        <div className="flex items-center gap-3 mb-4">
          <div className={`w-12 h-12 rounded-xl flex items-center justify-center ${data?.status === 'connected' ? 'bg-success-50 text-success-600' : 'bg-error-50 text-error-600'}`}>
            {data?.status === 'connected' ? <CheckCircle size={24} /> : <AlertCircle size={24} />}
          </div>
          <div>
            <p className="text-sm font-bold text-neutral-900">Bank Connection {data?.status === 'connected' ? 'Active' : 'Disconnected'}</p>
            <p className="text-xs text-neutral-400">Last sync: {data ? formatDateTime(data.last_sync) : 'Never'}</p>
          </div>
        </div>

        {data?.new_transactions !== undefined && (
          <div className="p-4 rounded-xl bg-neutral-50">
            <p className="text-sm text-neutral-600">New transactions since last sync</p>
            <p className="text-2xl font-bold text-neutral-900 mt-1">{data.new_transactions}</p>
          </div>
        )}

        {mutation.isSuccess && (
          <div className="mt-4 p-4 rounded-xl bg-success-50 border border-success-200 text-sm text-success-700">
            Sync completed — {mutation.data?.imported} new transactions imported.
          </div>
        )}
        {mutation.isError && (
          <div className="mt-4 p-4 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
            Sync failed. Please try again.
          </div>
        )}
      </Card>
    </div>
  );
}
