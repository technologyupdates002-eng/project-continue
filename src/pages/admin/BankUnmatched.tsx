import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle, CheckCircle, XCircle, Clock, Search, UserSearch,
  ArrowRight, AlertCircle, Info,
} from 'lucide-react';
import { mockAdmin } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, LoadingState, ErrorState, EmptyState,
  Modal, Select, Textarea,
} from '@/components/ui';
import { formatCurrency, formatDate, classNames } from '@/lib/format';

const REASON_LABELS: Record<string, string> = {
  unknown_member: 'Unknown Member',
  ambiguous_member: 'Ambiguous Phone Match',
};

const REASON_ICONS: Record<string, typeof AlertTriangle> = {
  unknown_member: UserSearch,
  ambiguous_member: AlertCircle,
};

function KpiCard({ label, value, icon: Icon, accent }: {
  label: string; value: string | number; icon: typeof AlertTriangle; accent: string;
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

export function BankUnmatchedPage() {
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [reconcileItem, setReconcileItem] = useState<any>(null);
  const [matchType, setMatchType] = useState('contribution');
  const [memberId, setMemberId] = useState('');
  const [notes, setNotes] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.adminBankUnmatched,
    queryFn: () => mockAdmin.getBankUnmatched(),
  });

  const reconcileMut = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => mockAdmin.reconcileBankUnmatched(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.adminBankUnmatched });
      setReconcileItem(null);
      setMatchType('contribution');
      setMemberId('');
      setNotes('');
    },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load unmatched transactions" onRetry={refetch} />;

  const items = (data as any[]) || [];
  const pending = items.filter(i => i.status === 'pending');
  const investigating = items.filter(i => i.status === 'investigating');

  const filtered = search.trim()
    ? items.filter(i =>
        i.reference?.toLowerCase().includes(search.toLowerCase()) ||
        i.narration?.toLowerCase().includes(search.toLowerCase()),
      )
    : items;

  const handleReconcile = () => {
    if (!reconcileItem || !memberId) return;
    reconcileMut.mutate({
      id: reconcileItem.id,
      data: { match_type: matchType, member_id: memberId, notes },
    });
  };

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Unmatched Bank Transactions</h1>
        <p className="mt-1 text-sm text-neutral-500">Resolve bank sync transactions that could not be automatically matched to a member</p>
      </div>

      {/* Info banner */}
      <div className="flex items-start gap-3 p-4 rounded-xl bg-primary-50 border border-primary-100">
        <div className="w-9 h-9 rounded-lg bg-primary-100 flex items-center justify-center flex-shrink-0">
          <Info size={18} className="text-primary-600" />
        </div>
        <div className="text-sm text-primary-800">
          <p className="font-semibold">How reconciliation works</p>
          <p className="text-xs mt-1 text-primary-700">
            Each unmatched transaction is matched to an existing member and a match type (contribution, donation, or penalty).
            The backend posts the financial record through the canonical payment engine and marks the bank transaction as matched.
            New members must be onboarded separately before they can be matched here.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-3 gap-4">
        <KpiCard label="Pending" value={pending.length} icon={Clock} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Investigating" value={investigating.length} icon={AlertCircle} accent="bg-error-50 text-error-500" />
        <KpiCard label="Total" value={items.length} icon={AlertTriangle} accent="bg-neutral-100 text-neutral-500" />
      </div>

      <Card className="p-6">
        <div className="flex items-center justify-between mb-5">
          <h3 className="text-base font-bold text-neutral-900">Unmatched Queue</h3>
          <div className="relative">
            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
            <input
              type="text"
              placeholder="Search by reference or narration..."
              value={search}
              onChange={e => setSearch(e.target.value)}
              className="pl-9 pr-3 py-2 text-sm rounded-n26-btn border border-neutral-200 bg-white text-neutral-700 w-56 focus:outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-500/15"
            />
          </div>
        </div>

        {filtered.length === 0 ? (
          <EmptyState icon={<CheckCircle size={24} />} title="No unmatched transactions" message="All bank sync transactions have been matched successfully." />
        ) : (
          <div className="space-y-2">
            {filtered.map((item: any) => {
              const ReasonIcon = REASON_ICONS[item.reason] || AlertTriangle;
              return (
                <div key={item.id} className="group flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-float transition-all">
                  <div className="min-w-0 flex-1 flex items-start gap-3">
                    <div className={classNames(
                      'w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0',
                      item.status === 'pending' ? 'bg-warning-50 text-warning-600' : 'bg-error-50 text-error-500',
                    )}>
                      <ReasonIcon size={20} />
                    </div>
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <p className="text-sm font-semibold text-neutral-800 truncate">{item.narration}</p>
                        <span className="font-mono text-xs text-neutral-300">{item.reference}</span>
                      </div>
                      <p className="text-xs text-neutral-400">
                        {formatCurrency(item.amount)} · {formatDate(item.date)} · {REASON_LABELS[item.reason] || item.reason}
                      </p>
                      {item.phone_extracted && (
                        <p className="text-xs text-neutral-400 mt-0.5">Extracted phone: <span className="font-mono">{item.phone_extracted}</span></p>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center gap-2 flex-shrink-0">
                    <Badge status={item.status === 'pending' ? 'pending' : 'failed'}>{item.status}</Badge>
                    {item.status === 'pending' && (
                      <Button size="sm" onClick={() => setReconcileItem(item)}>
                        <UserSearch size={14} /> Match
                      </Button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      {/* Reconcile Modal */}
      <Modal
        open={!!reconcileItem}
        onClose={() => { setReconcileItem(null); setMatchType('contribution'); setMemberId(''); setNotes(''); }}
        title="Match Unmatched Transaction"
        size="md"
      >
        {reconcileItem && (
          <div className="space-y-4">
            {/* Transaction summary */}
            <div className="p-3 rounded-xl bg-neutral-50 border border-neutral-100">
              <p className="text-sm font-semibold text-neutral-800">{reconcileItem.narration}</p>
              <p className="text-xs text-neutral-400 mt-1">
                {formatCurrency(reconcileItem.amount)} · {formatDate(reconcileItem.date)} · Ref: {reconcileItem.reference}
              </p>
              {reconcileItem.phone_extracted && (
                <p className="text-xs text-neutral-400 mt-0.5">Phone: <span className="font-mono">{reconcileItem.phone_extracted}</span></p>
              )}
            </div>

            <Select label="Match Type" value={matchType} onChange={e => setMatchType(e.target.value)}>
              <option value="contribution">Contribution</option>
              <option value="donation">Donation</option>
              <option value="penalty">Penalty Payment</option>
            </Select>

            <div>
              <label className="label">Select Member</label>
              <p className="text-xs text-neutral-400 mb-2">Only existing members can be matched. Onboard new members separately.</p>
              <select
                value={memberId}
                onChange={e => setMemberId(e.target.value)}
                className="w-full rounded-n26-btn border border-neutral-200 px-3 py-2.5 text-sm text-neutral-700 focus:outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-500/15"
              >
                <option value="">Select an existing member...</option>
                <option value="m1">Dr. Sarah Wanjiku Mwangi — KHW-2024-0142</option>
                <option value="m2">John Kamau Njoroge — KHW-2023-0089</option>
                <option value="m3">Mary Wambui Kariuki — KHW-2023-0056</option>
                <option value="m5">Grace Njeri Wangari — KHW-2024-0155</option>
                <option value="m6">James Otieno Omondi — KHW-2022-0078</option>
              </select>
            </div>

            <Textarea
              label="Resolution Notes (optional)"
              rows={2}
              value={notes}
              onChange={e => setNotes(e.target.value)}
              placeholder="Any notes about this reconciliation..."
            />

            {reconcileMut.isError && <p className="text-sm text-error-600">Failed to reconcile. Try again.</p>}

            <div className="flex justify-end gap-3 pt-2 border-t border-neutral-100">
              <Button variant="secondary" onClick={() => { setReconcileItem(null); setMatchType('contribution'); setMemberId(''); setNotes(''); }}>Cancel</Button>
              <Button onClick={handleReconcile} disabled={!memberId || reconcileMut.isPending}>
                {reconcileMut.isPending ? 'Reconciling...' : 'Confirm Match'}
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
