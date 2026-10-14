import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Wallet, ArrowUpRight, ArrowDownRight, TrendingUp, TrendingDown,
  Download, Search,
} from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, Badge, Button, LoadingState, EmptyState } from '@/components/ui';
import { formatCurrency, formatDate, classNames, downloadCSV } from '@/lib/format';

type WalletType = 'penalty' | 'donation' | 'operational';

const WALLET_CONFIG: Record<WalletType, {
  title: string; subtitle: string; icon: typeof Wallet;
  accent: string; gradient: string; queryKey: (page: number) => readonly unknown[];
  fetchFn: (page: number) => Promise<any>;
  filename: string;
}> = {
  penalty: {
    title: 'Penalty Wallet', subtitle: 'Penalty collection transactions',
    icon: Wallet, accent: 'bg-accent-50 text-accent-600', gradient: 'from-accent-500 to-accent-700',
    queryKey: (p) => queryKeys.treasurerWallet('penalty', p),
    fetchFn: (p) => mockTreasurer.getPenaltyWallet(p),
    filename: 'penalty-wallet.csv',
  },
  donation: {
    title: 'Donation Wallet', subtitle: 'Donation collection transactions',
    icon: Wallet, accent: 'bg-success-50 text-success-600', gradient: 'from-success-500 to-success-700',
    queryKey: (p) => queryKeys.treasurerWallet('donation', p),
    fetchFn: (p) => mockTreasurer.getDonationWallet(p),
    filename: 'donation-wallet.csv',
  },
  operational: {
    title: 'Operational Wallet', subtitle: 'Operational fund transactions',
    icon: Wallet, accent: 'bg-primary-50 text-primary-600', gradient: 'from-primary-500 to-primary-700',
    queryKey: (p) => queryKeys.treasurerWallet('operational', p),
    fetchFn: (p) => mockTreasurer.getOperationalWallet(p),
    filename: 'operational-wallet.csv',
  },
};

function WalletPage({ type }: { type: WalletType }) {
  const cfg = WALLET_CONFIG[type];
  const [page] = useState(1);
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: cfg.queryKey(page),
    queryFn: () => cfg.fetchFn(page),
  });

  const transactions = ((data as any)?.data ?? (data as any[]) ?? []) as any[];
  const filtered = search.trim()
    ? transactions.filter(t =>
        t.description?.toLowerCase().includes(search.toLowerCase()) ||
        t.reference?.toLowerCase().includes(search.toLowerCase()),
      )
    : transactions;

  // Derive balance summary from transactions
  const totalReceived = transactions.filter(t => t.type === 'credit').reduce((s, t) => s + (t.amount || 0), 0);
  const totalWithdrawn = transactions.filter(t => t.type === 'debit').reduce((s, t) => s + (t.amount || 0), 0);
  const balance = transactions.length > 0 ? (transactions[0].balance_after ?? (totalReceived - totalWithdrawn)) : 0;
  const lastBalance = transactions[0]?.balance_after ?? balance;

  const handleExport = () => {
    downloadCSV(cfg.filename, filtered.map(t => ({
      Date: formatDate(t.date),
      Description: t.description,
      Type: t.type,
      Amount: t.amount,
      Reference: t.reference,
      Balance_After: t.balance_after,
      Status: t.status,
    })));
  };

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{cfg.title}</h1>
          <p className="mt-1 text-sm text-neutral-500">{cfg.subtitle}</p>
        </div>
        <Button variant="secondary" onClick={handleExport}><Download size={15} /> Export CSV</Button>
      </div>

      {/* Balance hero card */}
      <div className={classNames('relative overflow-hidden rounded-n26-card bg-gradient-to-br p-6 text-white', cfg.gradient)}>
        <div className="relative z-10">
          <div className="flex items-center gap-3 mb-4">
            <div className="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center backdrop-blur-sm">
              <cfg.icon size={22} />
            </div>
            <div>
              <p className="text-sm font-medium text-white/80">Current Balance</p>
              <p className="text-3xl font-bold tracking-tight">{formatCurrency(lastBalance)}</p>
            </div>
          </div>
          <div className="flex gap-8 mt-4">
            <div>
              <div className="flex items-center gap-1.5 mb-1">
                <ArrowUpRight size={14} className="text-white/70" />
                <span className="text-xs text-white/70 font-medium">Total Received</span>
              </div>
              <p className="text-lg font-bold">{formatCurrency(totalReceived)}</p>
            </div>
            <div>
              <div className="flex items-center gap-1.5 mb-1">
                <ArrowDownRight size={14} className="text-white/70" />
                <span className="text-xs text-white/70 font-medium">Total Withdrawn</span>
              </div>
              <p className="text-lg font-bold">{formatCurrency(totalWithdrawn)}</p>
            </div>
          </div>
        </div>
        {/* Decorative circles */}
        <div className="absolute -right-12 -top-12 w-48 h-48 rounded-full bg-white/10" />
        <div className="absolute -right-4 top-16 w-28 h-28 rounded-full bg-white/5" />
      </div>

      {/* Transaction list */}
      <Card className="p-6">
        <div className="flex items-center justify-between mb-5">
          <h3 className="text-base font-bold text-neutral-900">Transaction History</h3>
          <div className="relative">
            <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
            <input
              type="text"
              placeholder="Search transactions..."
              value={search}
              onChange={e => setSearch(e.target.value)}
              className="pl-9 pr-3 py-2 text-sm rounded-n26-btn border border-neutral-200 bg-white text-neutral-700 w-56 focus:outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-500/15"
            />
          </div>
        </div>

        {isLoading ? <LoadingState /> : filtered.length === 0 ? (
          <EmptyState icon={<Wallet size={24} />} title="No transactions found" message="Wallet transactions will appear here." />
        ) : (
          <div className="overflow-x-auto -mx-6 px-6">
            <table className="w-full min-w-[680px]">
              <thead>
                <tr className="border-b border-neutral-100">
                  {['Date', 'Description', 'Type', 'Amount', 'Reference', 'Balance After', 'Status'].map((h, i) => (
                    <th key={h} className={classNames(
                      'text-left py-3 px-2 text-xs font-semibold text-neutral-400 uppercase tracking-wider',
                      i >= 3 && i <= 5 && 'text-right',
                    )}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-50">
                {filtered.map((t: any) => (
                  <tr key={t.id} className="hover:bg-neutral-50/60 transition-colors">
                    <td className="py-3.5 px-2 text-sm text-neutral-500 whitespace-nowrap">{formatDate(t.date)}</td>
                    <td className="py-3.5 px-2">
                      <p className="text-sm font-medium text-neutral-800 max-w-[200px] truncate">{t.description}</p>
                    </td>
                    <td className="py-3.5 px-2">
                      <span className={classNames(
                        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
                        t.type === 'credit' ? 'bg-success-50 text-success-700' : 'bg-error-50 text-error-700',
                      )}>
                        {t.type === 'credit' ? <ArrowUpRight size={11} /> : <ArrowDownRight size={11} />}
                        {t.type}
                      </span>
                    </td>
                    <td className={classNames(
                      'py-3.5 px-2 text-right text-sm font-semibold whitespace-nowrap',
                      t.type === 'credit' ? 'text-success-700' : 'text-error-600',
                    )}>
                      {t.type === 'credit' ? '+' : '−'}{formatCurrency(t.amount)}
                    </td>
                    <td className="py-3.5 px-2 text-right text-xs font-mono text-neutral-400 whitespace-nowrap">{t.reference}</td>
                    <td className="py-3.5 px-2 text-right text-sm font-semibold text-neutral-800 whitespace-nowrap">{formatCurrency(t.balance_after)}</td>
                    <td className="py-3.5 px-2">
                      <Badge status={t.status}>{t.status}</Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

export function PenaltyWalletPage() { return <WalletPage type="penalty" />; }
export function DonationWalletPage() { return <WalletPage type="donation" />; }
export function OperationalWalletPage() { return <WalletPage type="operational" />; }
