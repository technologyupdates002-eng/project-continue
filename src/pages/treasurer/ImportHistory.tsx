import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  FileSpreadsheet, CheckCircle, XCircle, Clock, Users, Copy,
  TrendingUp, AlertTriangle, ChevronRight, ChevronDown, Search,
} from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, Badge, LoadingState, EmptyState } from '@/components/ui';
import { formatCurrency, formatDate, classNames } from '@/lib/format';

function KpiCard({ label, value, icon: Icon, accent }: {
  label: string; value: string | number; icon: typeof FileSpreadsheet; accent: string;
}) {
  return (
    <div className="bg-white rounded-n26-card border border-neutral-100 p-5 transition-all hover:shadow-float hover:border-neutral-200">
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

const ROW_STATUS_COLORS: Record<string, string> = {
  matched: 'bg-success-50 text-success-700',
  new_member: 'bg-primary-50 text-primary-700',
  skipped: 'bg-neutral-100 text-neutral-500',
  unmatched: 'bg-warning-50 text-warning-700',
  failed: 'bg-error-50 text-error-700',
};

const BATCH_STATUS_COLORS: Record<string, string> = {
  completed: 'confirmed',
  failed: 'failed',
  processing: 'pending',
};

export function ImportHistoryPage() {
  const [expandedBatch, setExpandedBatch] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  const { data: batches, isLoading } = useQuery({
    queryKey: queryKeys.treasurerImportHistory,
    queryFn: () => mockTreasurer.getImportHistory(),
  });

  const { data: batchRows, isLoading: rowsLoading } = useQuery({
    queryKey: queryKeys.treasurerImportBatch(expandedBatch || ''),
    queryFn: () => mockTreasurer.getImportBatchDetail(expandedBatch!),
    enabled: !!expandedBatch,
  });

  if (isLoading) return <LoadingState />;

  const allBatches = (batches as any[]) || [];
  const filtered = search.trim()
    ? allBatches.filter(b =>
        b.file_name.toLowerCase().includes(search.toLowerCase()) ||
        b.source_name.toLowerCase().includes(search.toLowerCase()) ||
        b.id.toLowerCase().includes(search.toLowerCase()),
      )
    : allBatches;

  const totalImported = allBatches.reduce((s, b) => s + b.new_members + b.matched_members, 0);
  const totalNewMembers = allBatches.reduce((s, b) => s + b.new_members, 0);
  const totalAmount = allBatches.reduce((s, b) => s + b.total_amount, 0);
  const failedBatches = allBatches.filter(b => b.status === 'failed').length;

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Import History</h1>
        <p className="mt-1 text-sm text-neutral-500">Review past bank statement import batches and row-level outcomes</p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard label="Total Batches" value={allBatches.length} icon={FileSpreadsheet} accent="bg-primary-50 text-primary-600" />
        <KpiCard label="Members Onboarded" value={totalNewMembers} icon={Users} accent="bg-success-50 text-success-600" />
        <KpiCard label="Total Imported" value={totalImported} icon={TrendingUp} accent="bg-secondary-50 text-secondary-600" />
        <KpiCard label="Failed Batches" value={failedBatches} icon={XCircle} accent="bg-error-50 text-error-500" />
      </div>

      <Card className="p-6">
        <div className="flex items-center justify-between mb-5">
          <h3 className="text-base font-bold text-neutral-900">Import Batches</h3>
          <div className="relative">
            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
            <input
              type="text"
              placeholder="Search by file, source, or batch ID..."
              value={search}
              onChange={e => setSearch(e.target.value)}
              className="pl-9 pr-3 py-2 text-sm rounded-n26-btn border border-neutral-200 bg-white text-neutral-700 w-64 focus:outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-500/15"
            />
          </div>
        </div>

        {filtered.length === 0 ? (
          <EmptyState icon={<FileSpreadsheet size={24} />} title="No import batches found" message="Bank statement imports will appear here with full audit details." />
        ) : (
          <div className="space-y-2">
            {filtered.map((batch: any) => {
              const isExpanded = expandedBatch === batch.id;
              return (
                <div key={batch.id} className="rounded-xl border border-neutral-100 overflow-hidden transition-all hover:border-neutral-200">
                  <button
                    onClick={() => setExpandedBatch(isExpanded ? null : batch.id)}
                    className="w-full flex items-center justify-between gap-3 p-4 hover:bg-neutral-50 transition-colors text-left"
                  >
                    <div className="flex items-center gap-3 min-w-0 flex-1">
                      <div className={classNames(
                        'w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0',
                        batch.status === 'completed' ? 'bg-success-50 text-success-600' : 'bg-error-50 text-error-500',
                      )}>
                        {batch.status === 'completed' ? <CheckCircle size={20} /> : <XCircle size={20} />}
                      </div>
                      <div className="min-w-0">
                        <p className="text-sm font-semibold text-neutral-800 truncate">{batch.file_name}</p>
                        <p className="text-xs text-neutral-400 mt-0.5">
                          {batch.source_name} · {formatDate(batch.imported_at)} · <span className="font-mono">{batch.id}</span>
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-4 flex-shrink-0">
                      <div className="hidden sm:flex items-center gap-4 text-xs">
                        <div className="text-center">
                          <p className="font-bold text-neutral-800">{batch.total_rows}</p>
                          <p className="text-neutral-400">Rows</p>
                        </div>
                        <div className="text-center">
                          <p className="font-bold text-success-600">{batch.new_members}</p>
                          <p className="text-neutral-400">New</p>
                        </div>
                        <div className="text-center">
                          <p className="font-bold text-primary-600">{batch.matched_members}</p>
                          <p className="text-neutral-400">Matched</p>
                        </div>
                        <div className="text-center">
                          <p className="font-bold text-neutral-800">{formatCurrency(batch.total_amount)}</p>
                          <p className="text-neutral-400">Amount</p>
                        </div>
                      </div>
                      <Badge status={BATCH_STATUS_COLORS[batch.status] || 'pending'}>{batch.status}</Badge>
                      {isExpanded ? <ChevronDown size={16} className="text-neutral-400" /> : <ChevronRight size={16} className="text-neutral-400" />}
                    </div>
                  </button>

                  {isExpanded && (
                    <div className="border-t border-neutral-100 bg-neutral-50/50 p-4">
                      {rowsLoading ? (
                        <LoadingState text="Loading batch rows..." />
                      ) : (
                        <>
                          {/* Batch summary stats */}
                          <div className="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-4">
                            {[
                              { label: 'New Members', value: batch.new_members, color: 'text-success-600' },
                              { label: 'Matched', value: batch.matched_members, color: 'text-primary-600' },
                              { label: 'Duplicates', value: batch.duplicates, color: 'text-neutral-600' },
                              { label: 'Unmatched', value: batch.unmatched, color: 'text-warning-600' },
                              { label: 'Invalid Phone', value: batch.invalid_phone, color: 'text-neutral-600' },
                              { label: 'Failures', value: batch.failures, color: 'text-error-600' },
                            ].map(s => (
                              <div key={s.label} className="bg-white rounded-lg p-2.5 text-center border border-neutral-100">
                                <p className={classNames('text-lg font-bold', s.color)}>{s.value}</p>
                                <p className="text-2xs text-neutral-400 mt-0.5">{s.label}</p>
                              </div>
                            ))}
                          </div>

                          {/* Row-level details */}
                          {(batchRows as any[])?.length > 0 ? (
                            <div className="overflow-x-auto">
                              <table className="w-full min-w-[600px]">
                                <thead>
                                  <tr className="border-b border-neutral-100">
                                    <th className="text-left py-2 px-2 text-xs font-semibold text-neutral-400 uppercase">Row</th>
                                    <th className="text-left py-2 px-2 text-xs font-semibold text-neutral-400 uppercase">Name</th>
                                    <th className="text-left py-2 px-2 text-xs font-semibold text-neutral-400 uppercase">Phone</th>
                                    <th className="text-left py-2 px-2 text-xs font-semibold text-neutral-400 uppercase">Reference</th>
                                    <th className="text-right py-2 px-2 text-xs font-semibold text-neutral-400 uppercase">Amount</th>
                                    <th className="text-left py-2 px-2 text-xs font-semibold text-neutral-400 uppercase">Status</th>
                                    <th className="text-left py-2 px-2 text-xs font-semibold text-neutral-400 uppercase">Member</th>
                                  </tr>
                                </thead>
                                <tbody className="divide-y divide-neutral-50">
                                  {(batchRows as any[]).map((row: any) => (
                                    <tr key={row.id} className="hover:bg-white transition-colors">
                                      <td className="py-2.5 px-2 text-sm text-neutral-400">{row.row_number}</td>
                                      <td className="py-2.5 px-2 text-sm font-medium text-neutral-700">{row.name || '—'}</td>
                                      <td className="py-2.5 px-2 text-xs font-mono text-neutral-500">{row.phone || '—'}</td>
                                      <td className="py-2.5 px-2 text-xs font-mono text-neutral-400">{row.reference}</td>
                                      <td className="py-2.5 px-2 text-right text-sm font-semibold text-neutral-700">{row.amount ? formatCurrency(row.amount) : '—'}</td>
                                      <td className="py-2.5 px-2">
                                        <span className={classNames(
                                          'inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-medium',
                                          ROW_STATUS_COLORS[row.status] || 'bg-neutral-100 text-neutral-500',
                                        )}>{row.match_status}</span>
                                      </td>
                                      <td className="py-2.5 px-2 text-sm text-neutral-600">{row.member_name || '—'}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          ) : (
                            <p className="text-sm text-neutral-400 text-center py-4">No row details available for this batch.</p>
                          )}
                        </>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </Card>
    </div>
  );
}
