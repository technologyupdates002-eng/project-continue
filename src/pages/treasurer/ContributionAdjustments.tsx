import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { SlidersHorizontal } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { GenericListPage, GenericFormPage } from '@/components/GenericPages';
import { Card, PageHeader, Button, Input, Textarea, Badge, LoadingState, Modal } from '@/components/ui';
import { formatCurrency, formatDate } from '@/lib/format';
import { useState } from 'react';

export default function ContributionAdjustments() {
  const [adjustModal, setAdjustModal] = useState<any>(null);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const qc = useQueryClient();

  const mutation = useMutation({
    mutationFn: (data: { id: string; amount: number; reason: string }) =>
      mockTreasurer.adjustContribution(data.id, { amount: Number(data.amount), reason: data.reason }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['treasurer', 'contributions'] }); setAdjustModal(null); },
  });

  return (
    <>
      <GenericListPage
        config={{
          title: 'Contribution Adjustments',
          subtitle: 'Review and adjust contribution records',
          icon: SlidersHorizontal,
          searchPlaceholder: 'Search contributions...',
          fetchFn: (p) => mockTreasurer.getContributions({ page: p.page, search: p.search, status: p.status }),
          queryKey: (p) => queryKeys.treasurerContributions({ ...p, tab: 'adjust' }),
          rowAction: (row) => { setAdjustModal(row); setAmount(String(row.amount)); setReason(''); },
          rowActionLabel: 'Adjust',
          rowActionIcon: SlidersHorizontal,
          exportFilename: 'contributions-adjustments.csv',
          columns: [
            { key: 'member_name', label: 'Member', render: r => <span className="font-medium">{r.member_name}</span> },
            { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
            { key: 'period', label: 'Period' },
            { key: 'date', label: 'Date', render: r => formatDate(r.date) },
            { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
          ],
        }}
      />
      <Modal open={!!adjustModal} onClose={() => setAdjustModal(null)} title="Adjust Contribution">
        {adjustModal && (
          <div className="space-y-4">
            <div className="p-3 rounded-xl bg-neutral-50 text-sm">
              <p className="font-medium text-neutral-800">{adjustModal.member_name}</p>
              <p className="text-neutral-500">Current: {formatCurrency(adjustModal.amount)} • {adjustModal.period}</p>
            </div>
            <Input label="New Amount (KES)" type="number" value={amount} onChange={e => setAmount(e.target.value)} required />
            <div>
              <label className="label">Reason for Adjustment</label>
              <Textarea rows={3} value={reason} onChange={e => setReason(e.target.value)} placeholder="Explain why this adjustment is needed..." required />
            </div>
            {mutation.isError && <p className="text-sm text-error-600">Failed to adjust. Try again.</p>}
            <Button className="w-full" onClick={() => mutation.mutate({ id: adjustModal.id, amount: Number(amount), reason })} disabled={mutation.isPending || !reason}>
              {mutation.isPending ? 'Processing...' : 'Confirm Adjustment'}
            </Button>
          </div>
        )}
      </Modal>
    </>
  );
}
