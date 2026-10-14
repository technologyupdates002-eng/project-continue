import { GenericListPage } from '@/components/GenericPages';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Badge } from '@/components/ui';
import { formatCurrency, formatDate } from '@/lib/format';
import { Coins, Search } from 'lucide-react';

export default function TreasurerContributions() {
  return (
    <GenericListPage
      config={{
        title: 'Contributions',
        subtitle: 'Review and manage all member contributions',
        icon: Coins,
        searchPlaceholder: 'Search by member or reference...',
        fetchFn: (p) => mockTreasurer.getContributions({ page: p.page, search: p.search, status: p.status }),
        queryKey: (p) => queryKeys.treasurerContributions(p),
        statusFilters: [
          { value: 'confirmed', label: 'Confirmed' },
          { value: 'pending', label: 'Pending' },
          { value: 'failed', label: 'Failed' },
        ],
        exportFilename: 'contributions.csv',
        columns: [
          { key: 'member_name', label: 'Member', render: r => <span className="font-medium text-neutral-800">{r.member_name}</span> },
          { key: 'amount', label: 'Amount', render: r => <span className="font-semibold">{formatCurrency(r.amount)}</span> },
          { key: 'type', label: 'Type' },
          { key: 'method', label: 'Method' },
          { key: 'reference', label: 'Reference', render: r => <span className="font-mono text-xs text-neutral-500">{r.reference}</span> },
          { key: 'period', label: 'Period' },
          { key: 'date', label: 'Date', render: r => formatDate(r.date) },
          { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
        ],
      }}
    />
  );
}
