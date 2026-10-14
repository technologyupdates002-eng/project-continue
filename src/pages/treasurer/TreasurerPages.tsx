import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from '@/lib/router-compat';
import {
  Receipt, Plus, UserCheck, CheckCircle, XCircle, Landmark, Upload,
  Scale, BarChart3, Wallet, ArrowRight, TrendingUp, TrendingDown,
  Clock, FileText, AlertTriangle, ArrowUpRight, ArrowDownRight,
} from 'lucide-react';
import { GenericListPage, ImportPage } from '@/components/GenericPages';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, Input, Textarea, Select, Tabs,
  LoadingState, EmptyState, Modal, ConfirmDialog,
} from '@/components/ui';
import { formatCurrency, formatDate, classNames, initials } from '@/lib/format';
import { useAuth } from '@/auth/AuthContext';
import type { PayoutType } from '@/types';

// ─── Premium KPI card ─────────────────────────────────────────────────────────
function KpiCard({ label, value, icon: Icon, accent, delta, positive }: {
  label: string; value: string | number; icon: typeof Receipt;
  accent: string; delta?: string; positive?: boolean;
}) {
  return (
    <div className="group relative bg-white rounded-n26-card border border-neutral-100 p-5 transition-all hover:shadow-float hover:border-neutral-200">
      <div className="flex items-start justify-between mb-4">
        <div className={classNames('w-10 h-10 rounded-xl flex items-center justify-center', accent)}>
          <Icon size={20} />
        </div>
        {delta && (
          <span className={classNames(
            'flex items-center gap-0.5 text-xs font-semibold rounded-full px-2 py-0.5',
            positive ? 'bg-success-50 text-success-700' : 'bg-error-50 text-error-700',
          )}>
            {positive ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
            {delta}
          </span>
        )}
      </div>
      <p className="text-2xl font-bold text-neutral-900 tracking-tight">{value}</p>
      <p className="mt-1 text-sm text-neutral-500">{label}</p>
    </div>
  );
}

function SectionHeader({ title, action }: { title: string; action?: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between mb-4">
      <h3 className="text-base font-bold text-neutral-900">{title}</h3>
      {action}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  BANK STATEMENT / BOOK BALANCE / WALLET REPORTS — unchanged list pages
// ═══════════════════════════════════════════════════════════════════════════════
export function BankStatementPage() {
  return <GenericListPage config={{
    title: 'Bank Statement', subtitle: 'View imported bank transactions', icon: Landmark,
    fetchFn: (p) => mockTreasurer.getBankStatement(p.page), queryKey: (p) => queryKeys.treasurerBankStatement(p.page),
    exportFilename: 'bank-statement.csv',
    statusFilters: [{ value: 'matched', label: 'Matched' }, { value: 'unmatched', label: 'Unmatched' }],
    columns: [
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'description', label: 'Description' },
      { key: 'reference', label: 'Reference', render: r => <span className="font-mono text-xs text-neutral-500">{r.reference}</span> },
      { key: 'amount', label: 'Amount', render: r => <span className={r.type === 'credit' ? 'text-success-600 font-semibold' : 'text-error-600 font-semibold'}>{r.type === 'credit' ? '+' : '-'}{formatCurrency(r.amount)}</span> },
      { key: 'matched', label: 'Status', render: r => <Badge status={r.matched ? 'confirmed' : 'pending'}>{r.matched ? 'Matched' : 'Unmatched'}</Badge> },
    ],
  }} />;
}

export function BookBalanceImportPage() {
  return <ImportPage title="Import Book Balance" icon={Upload} onImport={(file) => mockTreasurer.importBookBalance(file)} />;
}

export function BookBalancePage() {
  return <GenericListPage config={{
    title: 'Book Balance', subtitle: 'Internal ledger balance and entries', icon: Scale,
    fetchFn: (p) => { void p; return mockTreasurer.getBookBalance().then(d => ({ data: d.entries, current_page: 1, last_page: 1, total: d.entries.length })); },
    queryKey: (p) => queryKeys.treasurerBookBalance,
    exportFilename: 'book-balance.csv',
    columns: [
      { key: 'description', label: 'Description' },
      { key: 'type', label: 'Type', render: r => <Badge status={r.type === 'credit' ? 'confirmed' : 'failed'}>{r.type}</Badge> },
      { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
    ],
  }} />;
}

export function WalletReportsPage() {
  return <GenericListPage config={{
    title: 'Wallet Reports', subtitle: 'Summary reports for all wallets', icon: BarChart3,
    fetchFn: () => mockTreasurer.getWalletReports().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.treasurerWalletReports,
    exportFilename: 'wallet-reports.csv',
    columns: [
      { key: 'wallet', label: 'Wallet' },
      { key: 'transactions', label: 'Transactions' },
      { key: 'balance', label: 'Balance', render: r => formatCurrency(r.balance) },
      { key: 'period', label: 'Period' },
    ],
  }} />;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  PAYOUT TYPES — now includes 'medical' per spec
// ═══════════════════════════════════════════════════════════════════════════════
const PAYOUT_TYPES: { value: PayoutType; label: string; relation: string }[] = [
  { value: 'wedding', label: 'Wedding', relation: 'Self' },
  { value: 'death_member', label: 'Death - Member', relation: 'Member' },
  { value: 'death_spouse', label: 'Death - Spouse', relation: 'Spouse' },
  { value: 'death_child', label: 'Death - Child', relation: 'Child' },
  { value: 'death_parent', label: 'Death - Parent', relation: 'Parent' },
  { value: 'retirement', label: 'Retirement', relation: 'Self' },
  { value: 'emergency', label: 'Emergency', relation: 'Self' },
  { value: 'medical', label: 'Medical', relation: 'Self' },
];

const WALLET_TYPES = [
  { value: 'operational', label: 'Operational Wallet', accent: 'bg-primary-50 text-primary-600' },
  { value: 'donation', label: 'Donation Wallet', accent: 'bg-success-50 text-success-600' },
  { value: 'penalty', label: 'Penalty Wallet', accent: 'bg-accent-50 text-accent-600' },
];

const PAYOUT_TYPE_ICONS: Record<string, string> = {
  wedding: 'bg-accent-50 text-accent-600',
  death_member: 'bg-error-50 text-error-500',
  death_spouse: 'bg-error-50 text-error-500',
  death_child: 'bg-error-50 text-error-500',
  death_parent: 'bg-error-50 text-error-500',
  retirement: 'bg-secondary-50 text-secondary-600',
  emergency: 'bg-warning-50 text-warning-600',
  medical: 'bg-primary-50 text-primary-600',
};

// ═══════════════════════════════════════════════════════════════════════════════
//  EXPENSES & PAYOUTS PAGE
// ═══════════════════════════════════════════════════════════════════════════════
export function ExpensesPage() {
  const { user } = useAuth();
  const qc = useQueryClient();
  const [tab, setTab] = useState('expenses');
  const [expenseModal, setExpenseModal] = useState(false);
  const [payoutModal, setPayoutModal] = useState(false);
  const [approveId, setApproveId] = useState<string | null>(null);
  const [rejectId, setRejectId] = useState<string | null>(null);

  const [expenseForm, setExpenseForm] = useState({ description: '', amount: '', category: 'Operations' });
  const [payoutForm, setPayoutForm] = useState<{
    payoutType: PayoutType | '';
    memberId: string;
    amount: string;
    reason: string;
    phone: string;
    walletType: string;
  }>({ payoutType: '', memberId: '', amount: '', reason: '', phone: '', walletType: 'operational' });

  const { data: expensesData, isLoading: expLoading } = useQuery({
    queryKey: queryKeys.treasurerExpenses(1),
    queryFn: () => mockTreasurer.getExpenses(1),
  });
  const { data: payouts, isLoading: payLoading } = useQuery({
    queryKey: queryKeys.treasurerPayouts,
    queryFn: () => mockTreasurer.getPayouts(),
  });
  const { data: eligibleMembers, isLoading: eligLoading } = useQuery({
    queryKey: queryKeys.treasurerEligibleMembers(payoutForm.payoutType || 'wedding'),
    queryFn: () => mockTreasurer.getEligibleMembers(payoutForm.payoutType || 'wedding'),
    enabled: !!payoutForm.payoutType,
  });

  const createExpenseMut = useMutation({
    mutationFn: (data: any) => mockTreasurer.createExpense(data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['treasurer', 'expenses'] }); setExpenseModal(false); setExpenseForm({ description: '', amount: '', category: 'Operations' }); },
  });
  const createPayoutMut = useMutation({
    mutationFn: (data: any) => mockTreasurer.createPayout(data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.treasurerPayouts }); setPayoutModal(false); resetPayoutForm(); },
  });
  const approveMut = useMutation({
    mutationFn: ({ id, action }: { id: string; action: string }) => mockTreasurer.approveExpense(id, action),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['treasurer', 'expenses'] }); setApproveId(null); setRejectId(null); },
  });

  function resetPayoutForm() {
    setPayoutForm({ payoutType: '', memberId: '', amount: '', reason: '', phone: '', walletType: 'operational' });
  }
  function selectMember(m: any) {
    setPayoutForm(prev => ({ ...prev, memberId: m.id, phone: m.phone, amount: String(m.eligible_amount) }));
  }
  function submitExpense(e: React.FormEvent) {
    e.preventDefault();
    createExpenseMut.mutate({ description: expenseForm.description, amount: Number(expenseForm.amount), category: expenseForm.category, status: 'pending' });
  }
  function submitPayout(e: React.FormEvent) {
    e.preventDefault();
    const pt = PAYOUT_TYPES.find(t => t.value === payoutForm.payoutType);
    createPayoutMut.mutate({
      member_id: payoutForm.memberId,
      payout_type: payoutForm.payoutType,
      relation: pt?.relation || 'Self',
      amount: Number(payoutForm.amount),
      reason: payoutForm.reason,
      phone: payoutForm.phone,
      wallet_type: payoutForm.walletType,
    });
  }

  const expenses = (expensesData as any)?.data || (expensesData as any[] | undefined) || [];
  const payoutList = (payouts as any[]) || [];

  const pendingExpenses = expenses.filter((e: any) => e.status === 'pending');
  const pendingPayouts = payoutList.filter((p: any) => p.status === 'pending' || p.status === 'approved');

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Expenses & Payouts</h1>
          <p className="mt-1 text-sm text-neutral-500">Manage operational expenses and member benefit payouts</p>
        </div>
        {tab === 'expenses' ? (
          <Button onClick={() => setExpenseModal(true)}><Plus size={16} /> New Expense</Button>
        ) : (
          <Button onClick={() => setPayoutModal(true)}><Plus size={16} /> New Payout</Button>
        )}
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard label="Total Expenses" value={expenses.length} icon={Receipt} accent="bg-secondary-50 text-secondary-600" />
        <KpiCard label="Pending Expenses" value={pendingExpenses.length} icon={Clock} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Total Payouts" value={payoutList.length} icon={UserCheck} accent="bg-primary-50 text-primary-600" />
        <KpiCard label="Pending Payouts" value={pendingPayouts.length} icon={Clock} accent="bg-warning-50 text-warning-600" />
      </div>

      <Tabs
        tabs={[
          { key: 'expenses', label: 'Expenses', count: expenses.length },
          { key: 'payouts', label: 'Payouts', count: payoutList.length },
        ]}
        active={tab}
        onChange={setTab}
      />

      {/* Expenses tab */}
      {tab === 'expenses' && (
        <Card className="p-6">
          <SectionHeader title="All Expenses" />
          {expLoading ? <LoadingState /> : expenses.length === 0 ? (
            <EmptyState icon={<Receipt size={24} />} title="No expenses recorded" />
          ) : (
            <div className="space-y-1">
              {expenses.map((ex: any) => (
                <div key={ex.id} className="group flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-neutral-800">{ex.description}</p>
                    <p className="text-xs text-neutral-400 mt-0.5">{ex.category} · {formatCurrency(ex.amount)} · {formatDate(ex.date)}</p>
                    {ex.approved_by && <p className="text-xs text-neutral-400 mt-0.5">Approved by {ex.approved_by}</p>}
                  </div>
                  <div className="flex items-center gap-2 flex-shrink-0">
                    <Badge status={ex.status}>{ex.status}</Badge>
                    {ex.status === 'pending' && (
                      <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button onClick={() => setApproveId(ex.id)} className="p-1.5 rounded-lg text-neutral-400 hover:text-success-600 hover:bg-success-50 transition-colors">
                          <CheckCircle size={15} />
                        </button>
                        <button onClick={() => setRejectId(ex.id)} className="p-1.5 rounded-lg text-neutral-400 hover:text-error-500 hover:bg-error-50 transition-colors">
                          <XCircle size={15} />
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {/* Payouts tab */}
      {tab === 'payouts' && (
        <Card className="p-6">
          <SectionHeader title="All Payouts" />
          {payLoading ? <LoadingState /> : payoutList.length === 0 ? (
            <EmptyState icon={<UserCheck size={24} />} title="No payout requests" message="Create a member benefit payout to get started." />
          ) : (
            <div className="space-y-1">
              {payoutList.map((po: any) => {
                const pt = PAYOUT_TYPES.find(t => t.value === po.payout_type);
                const typeIcon = PAYOUT_TYPE_ICONS[po.payout_type] || 'bg-neutral-100 text-neutral-500';
                return (
                  <div key={po.id} className="group flex flex-col sm:flex-row sm:items-start justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                    <div className="min-w-0 flex-1 flex items-start gap-3">
                      <div className={classNames('w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0', typeIcon)}>
                        <UserCheck size={17} />
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                          <p className="text-sm font-semibold text-neutral-800 truncate">{po.member_name}</p>
                          <span className="font-mono text-xs text-neutral-300">{po.reference}</span>
                        </div>
                        <p className="text-xs text-neutral-400">{pt?.label || po.payout_type} · {formatCurrency(po.amount)} · {po.wallet_type} wallet</p>
                        {po.reason && <p className="text-xs text-neutral-400 mt-0.5">{po.reason}</p>}
                        {po.signatories && po.signatories.length > 0 && (
                          <div className="flex gap-3 mt-1.5">
                            {po.signatories.map((sig: any, i: number) => (
                              <span key={i} className="text-xs flex items-center gap-1">
                                <span className="text-neutral-400 capitalize">{sig.role}:</span>
                                <span className={classNames(
                                  'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-2xs font-medium',
                                  sig.status === 'approved' ? 'bg-success-50 text-success-700' :
                                  sig.status === 'rejected' ? 'bg-error-50 text-error-700' :
                                  'bg-warning-50 text-warning-700',
                                )}>{sig.status}</span>
                              </span>
                            ))}
                          </div>
                        )}
                        <p className="text-xs text-neutral-300 mt-0.5">Requested {formatDate(po.created_at)}</p>
                      </div>
                    </div>
                    <Badge status={po.status}>{po.status}</Badge>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      )}

      {/* Create Expense Modal */}
      <Modal open={expenseModal} onClose={() => setExpenseModal(false)} title="New Expense">
        <form onSubmit={submitExpense} className="space-y-4">
          <Input label="Description" value={expenseForm.description} onChange={e => setExpenseForm({ ...expenseForm, description: e.target.value })} required />
          <Input label="Amount (KES)" type="number" value={expenseForm.amount} onChange={e => setExpenseForm({ ...expenseForm, amount: e.target.value })} required />
          <Select label="Category" value={expenseForm.category} onChange={e => setExpenseForm({ ...expenseForm, category: e.target.value })}>
            <option value="Operations">Operations</option>
            <option value="Events">Events</option>
            <option value="Administrative">Administrative</option>
            <option value="Logistics">Logistics</option>
          </Select>
          {createExpenseMut.isError && <p className="text-sm text-error-600">Failed to create expense. Try again.</p>}
          <div className="flex justify-end gap-3 pt-2">
            <Button variant="secondary" onClick={() => setExpenseModal(false)}>Cancel</Button>
            <Button type="submit" disabled={createExpenseMut.isPending}>{createExpenseMut.isPending ? 'Saving...' : 'Create Expense'}</Button>
          </div>
        </form>
      </Modal>

      {/* Create Payout Modal */}
      <Modal open={payoutModal} onClose={() => setPayoutModal(false)} title="New Member Payout" size="lg">
        <form onSubmit={submitPayout} className="space-y-4">
          <Select
            label="Payout Type"
            value={payoutForm.payoutType}
            onChange={e => { const v = e.target.value as PayoutType; setPayoutForm(prev => ({ ...prev, payoutType: v, memberId: '', amount: '', phone: '' })); }}
            required
          >
            <option value="">Select payout type...</option>
            {PAYOUT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>

          {payoutForm.payoutType && (
            <>
              <div>
                <label className="label">Eligible Members</label>
                {eligLoading ? <LoadingState text="Loading eligible members..." /> : (
                  <div className="max-h-52 overflow-y-auto border border-neutral-100 rounded-xl divide-y divide-neutral-50">
                    {(eligibleMembers as any[])?.map((m: any) => (
                      <button
                        key={m.id}
                        type="button"
                        onClick={() => selectMember(m)}
                        className={classNames(
                          'w-full text-left flex items-center justify-between p-3 transition-colors',
                          payoutForm.memberId === m.id ? 'bg-primary-50' : 'hover:bg-neutral-50',
                        )}
                      >
                        <div className="flex items-center gap-3">
                          <div className={classNames(
                            'w-8 h-8 rounded-full text-white text-xs font-bold flex items-center justify-center flex-shrink-0',
                            payoutForm.memberId === m.id ? 'bg-primary-500' : 'bg-neutral-300',
                          )}>
                            {initials(m.name)}
                          </div>
                          <div>
                            <p className="text-sm font-medium text-neutral-800">{m.name}</p>
                            <p className="text-xs text-neutral-400">{m.member_number} · {m.phone}</p>
                          </div>
                        </div>
                        <span className="text-sm font-semibold text-neutral-700">{formatCurrency(m.eligible_amount)}</span>
                      </button>
                    ))}
                    {(eligibleMembers as any[])?.length === 0 && <p className="p-3 text-sm text-neutral-400">No eligible members for this payout type.</p>}
                  </div>
                )}
              </div>

              {payoutForm.memberId && (
                <>
                  <div className="grid grid-cols-2 gap-4">
                    <Input label="Amount (KES)" type="number" value={payoutForm.amount} onChange={e => setPayoutForm({ ...payoutForm, amount: e.target.value })} required />
                    <Input label="Member Phone" value={payoutForm.phone} onChange={e => setPayoutForm({ ...payoutForm, phone: e.target.value })} required />
                  </div>
                  <div>
                    <label className="label">Fund Source Wallet</label>
                    <div className="grid grid-cols-3 gap-2">
                      {WALLET_TYPES.map(w => (
                        <button
                          key={w.value}
                          type="button"
                          onClick={() => setPayoutForm(prev => ({ ...prev, walletType: w.value }))}
                          className={classNames(
                            'flex flex-col items-center gap-2 p-3 rounded-xl border transition-all',
                            payoutForm.walletType === w.value
                              ? 'border-primary-300 bg-primary-50 ring-2 ring-primary-500/15'
                              : 'border-neutral-100 hover:border-neutral-200',
                          )}
                        >
                          <div className={classNames('w-8 h-8 rounded-lg flex items-center justify-center', w.accent)}>
                            <Wallet size={16} />
                          </div>
                          <span className="text-xs font-medium text-neutral-700">{w.label.replace(' Wallet', '')}</span>
                        </button>
                      ))}
                    </div>
                  </div>
                  <Textarea label="Reason" rows={3} value={payoutForm.reason} onChange={e => setPayoutForm({ ...payoutForm, reason: e.target.value })} placeholder="Reason for payout..." required />
                </>
              )}
            </>
          )}

          {createPayoutMut.isError && <p className="text-sm text-error-600">Failed to create payout. Try again.</p>}
          <div className="flex justify-end gap-3 pt-2">
            <Button variant="secondary" onClick={() => setPayoutModal(false)}>Cancel</Button>
            <Button type="submit" disabled={createPayoutMut.isPending || !payoutForm.memberId}>
              {createPayoutMut.isPending ? 'Submitting...' : 'Submit Payout Request'}
            </Button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        open={!!approveId}
        onClose={() => setApproveId(null)}
        onConfirm={() => approveId && approveMut.mutate({ id: approveId, action: 'approve' })}
        title="Approve Expense"
        message="Approve this expense for payment?"
        confirmLabel="Approve"
      />
      <ConfirmDialog
        open={!!rejectId}
        onClose={() => setRejectId(null)}
        onConfirm={() => rejectId && approveMut.mutate({ id: rejectId, action: 'reject' })}
        title="Reject Expense"
        message="Are you sure you want to reject this expense?"
        confirmLabel="Reject"
        danger
      />
    </div>
  );
}
