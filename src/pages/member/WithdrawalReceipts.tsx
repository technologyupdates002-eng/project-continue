import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Receipt, Download, Calendar, Printer, Eye } from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, PageHeader, Modal, EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import { formatCurrency, formatDate, formatDateTime, downloadCSV } from '@/lib/format';
import type { WithdrawalRequest } from '@/types';

export default function WithdrawalReceipts() {
  const [previewW, setPreviewW] = useState<WithdrawalRequest | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberWithdrawalReceipts,
    queryFn: () => mockMember.getWithdrawalReceipts(),
  });

  const withdrawals = (data as WithdrawalRequest[] | undefined) ?? [];

  const downloadReceipt = (w: WithdrawalRequest) => {
    downloadCSV(`withdrawal-receipt-${w.id}.csv`, [
      { Field: 'Receipt No', Value: w.id },
      { Field: 'Amount', Value: w.amount },
      { Field: 'Reason', Value: w.reason },
      { Field: 'Status', Value: w.status },
      { Field: 'Requested Date', Value: formatDateTime(w.requested_date) },
      { Field: 'Approved Date', Value: w.approved_date ? formatDateTime(w.approved_date) : '—' },
      { Field: 'Approved By', Value: w.approved_by ?? '—' },
    ]);
  };

  const printReceipt = (w: WithdrawalRequest) => {
    const html = `
      <!DOCTYPE html>
      <html><head><title>Withdrawal Receipt - ${w.id}</title>
      <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; color: #1a1a1a; }
        h1 { font-size: 20px; color: #1a5e48; border-bottom: 2px solid #1a5e48; padding-bottom: 8px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e5e5; }
        .label { color: #666; font-size: 13px; }
        .value { font-weight: 600; font-size: 14px; }
        .status { padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #999; }
      </style></head><body>
        <h1>KHWWC Withdrawal Receipt</h1>
        <div class="row"><span class="label">Receipt No</span><span class="value">${w.id}</span></div>
        <div class="row"><span class="label">Member</span><span class="value">${w.member_name}</span></div>
        <div class="row"><span class="label">Amount</span><span class="value">${formatCurrency(w.amount)}</span></div>
        <div class="row"><span class="label">Reason</span><span class="value">${w.reason}</span></div>
        <div class="row"><span class="label">Status</span><span class="value">${w.status}</span></div>
        <div class="row"><span class="label">Requested Date</span><span class="value">${formatDateTime(w.requested_date)}</span></div>
        <div class="row"><span class="label">Approved Date</span><span class="value">${w.approved_date ? formatDateTime(w.approved_date) : '—'}</span></div>
        <div class="row"><span class="label">Approved By</span><span class="value">${w.approved_by ?? '—'}</span></div>
        <div class="footer">Kirinyaga Healthcare Workers Welfare · This is a system-generated receipt</div>
      </body></html>
    `;
    const win = window.open('', '_blank');
    if (win) {
      win.document.write(html);
      win.document.close();
      win.focus();
      setTimeout(() => win.print(), 300);
    }
  };

  return (
    <div className="space-y-5">
      <PageHeader title="Withdrawal Receipts" subtitle="View and print your withdrawal receipts" icon={<Receipt size={20} />} />

      {isLoading ? (
        <LoadingState text="Loading receipts..." />
      ) : isError ? (
        <ErrorState message="We couldn't load your withdrawal receipts." onRetry={() => refetch()} />
      ) : withdrawals.length === 0 ? (
        <EmptyState icon={<Receipt size={22} />} title="No withdrawal requests" message="Your withdrawal history will appear here." />
      ) : (
        <Card className="divide-y divide-neutral-50">
          {withdrawals.map((w) => (
            <div key={w.id} className="flex items-start gap-3 p-4">
              <div className="flex-shrink-0 w-11 h-11 rounded-xl bg-primary-50 text-primary-700 flex items-center justify-center">
                <Receipt size={18} />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-sm font-semibold text-neutral-900">{formatCurrency(w.amount)}</p>
                  <Badge status={w.status}>{w.status}</Badge>
                </div>
                <p className="mt-0.5 text-xs text-neutral-600 line-clamp-2">{w.reason}</p>
                <div className="mt-1.5 flex items-center gap-1 text-xs text-neutral-500">
                  <Calendar size={12} className="text-neutral-400" />
                  Requested {formatDateTime(w.requested_date)}
                  {w.approved_date && <span className="ml-1">· Approved {formatDate(w.approved_date)}</span>}
                </div>
              </div>
              <div className="flex items-center gap-1 flex-shrink-0">
                <Button variant="ghost" size="sm" onClick={() => setPreviewW(w)} title="Preview"><Eye size={14} /></Button>
                <Button variant="ghost" size="sm" onClick={() => printReceipt(w)} title="Print"><Printer size={14} /></Button>
                <Button variant="ghost" size="sm" onClick={() => downloadReceipt(w)} title="Download CSV"><Download size={14} /></Button>
              </div>
            </div>
          ))}
        </Card>
      )}

      {/* Receipt preview modal */}
      <Modal open={!!previewW} onClose={() => setPreviewW(null)} title="Receipt Preview" size="md">
        {previewW && (
          <div className="space-y-3">
            <div className="p-4 rounded-xl border border-neutral-100 space-y-3">
              <div className="text-center pb-3 border-b border-neutral-100">
                <p className="text-sm font-bold text-primary-700">Kirinyaga Healthcare Workers Welfare</p>
                <p className="text-xs text-neutral-400">Withdrawal Receipt</p>
              </div>
              <div className="space-y-2">
                <div className="flex justify-between text-sm"><span className="text-neutral-500">Receipt No</span><span className="font-medium text-neutral-900">{previewW.id}</span></div>
                <div className="flex justify-between text-sm"><span className="text-neutral-500">Member</span><span className="font-medium text-neutral-900">{previewW.member_name}</span></div>
                <div className="flex justify-between text-sm"><span className="text-neutral-500">Amount</span><span className="font-bold text-neutral-900">{formatCurrency(previewW.amount)}</span></div>
                <div className="flex justify-between text-sm"><span className="text-neutral-500">Reason</span><span className="font-medium text-neutral-900 text-right">{previewW.reason}</span></div>
                <div className="flex justify-between text-sm"><span className="text-neutral-500">Status</span><Badge status={previewW.status}>{previewW.status}</Badge></div>
                <div className="flex justify-between text-sm"><span className="text-neutral-500">Requested</span><span className="font-medium text-neutral-900">{formatDateTime(previewW.requested_date)}</span></div>
                {previewW.approved_date && <div className="flex justify-between text-sm"><span className="text-neutral-500">Approved</span><span className="font-medium text-neutral-900">{formatDateTime(previewW.approved_date)}</span></div>}
                {previewW.approved_by && <div className="flex justify-between text-sm"><span className="text-neutral-500">Approved By</span><span className="font-medium text-neutral-900">{previewW.approved_by}</span></div>}
              </div>
            </div>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={() => setPreviewW(null)}>Close</Button>
              <Button onClick={() => printReceipt(previewW)} className="inline-flex items-center gap-2"><Printer size={15} /> Print</Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
