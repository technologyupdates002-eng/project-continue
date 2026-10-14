import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, Eye, FileText, IdCard, Receipt, Calendar } from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Button, Input, Modal, PageHeader, EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import { formatCurrency, formatDate, downloadCSV } from '@/lib/format';
import type { Contribution } from '@/types';

interface DownloadOption {
  key: string;
  title: string;
  description: string;
  icon: React.ReactNode;
  action: () => void;
}

export default function MemberDownloads() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [viewStatement, setViewStatement] = useState(false);

  // Pull contributions to demo a statement export
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberContributions(1),
    queryFn: () => mockMember.getContributions(1),
  });

  const contributions = (data?.data as Contribution[] | undefined) ?? [];

  const inRange = (c: Contribution) => {
    if (from && new Date(c.date) < new Date(from)) return false;
    if (to && new Date(c.date) > new Date(to)) return false;
    return true;
  };

  const exportStatement = () => {
    const rows = contributions.filter(inRange).map((c) => ({
      Date: formatDate(c.date),
      Type: c.type,
      Method: c.method,
      Reference: c.reference,
      Period: c.period,
      Amount: c.amount,
      Status: c.status,
    }));
    if (rows.length === 0) return;
    downloadCSV(`contribution-statement-${Date.now()}.csv`, rows);
  };

  const exportMembershipCard = () => {
    downloadCSV(`membership-card-${Date.now()}.csv`, [
      { Field: 'Member Number', Value: 'KHWWC-001' },
      { Field: 'Name', Value: 'Dr. Sarah Wanjiku Mwangi' },
      { Field: 'Status', Value: 'Active' },
      { Field: 'Joined', Value: formatDate('2022-03-15') },
    ]);
  };

  const exportReceipt = () => {
    const rows = contributions.filter(inRange).slice(0, 5).map((c) => ({
      Date: formatDate(c.date),
      Reference: c.reference,
      Amount: c.amount,
      Method: c.method,
    }));
    if (rows.length === 0) return;
    downloadCSV(`receipt-${Date.now()}.csv`, rows);
  };

  const options: DownloadOption[] = [
    { key: 'statement', title: 'Contribution Statement', description: 'Export your full contribution history as a CSV file.', icon: <FileText size={22} />, action: exportStatement },
    { key: 'card', title: 'Membership Card', description: 'Download your digital membership card details.', icon: <IdCard size={22} />, action: exportMembershipCard },
    { key: 'receipt', title: 'Payment Receipt', description: 'Export recent payment receipts.', icon: <Receipt size={22} />, action: exportReceipt },
  ];

  return (
    <div className="space-y-5">
      <PageHeader title="Download Center" subtitle="Export statements, receipts and documents" icon={<Download size={20} />} />

      {/* Date range filter */}
      <Card className="p-4">
        <div className="flex items-center gap-2 mb-3">
          <Calendar size={16} className="text-neutral-400" />
          <p className="text-sm font-medium text-neutral-700">Date range filter</p>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Input type="date" label="From" value={from} onChange={(e) => setFrom(e.target.value)} />
          <Input type="date" label="To" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
      </Card>

      {isLoading ? (
        <LoadingState text="Loading..." />
      ) : isError ? (
        <ErrorState message="We couldn't load your data." onRetry={() => refetch()} />
      ) : contributions.length === 0 ? (
        <EmptyState icon={<FileText size={22} />} title="No data to export" message="Make a contribution first to generate a statement." />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {options.map((opt) => (
            <Card key={opt.key} className="p-5 flex flex-col">
              <div className="w-11 h-11 rounded-xl bg-primary-50 text-primary-700 flex items-center justify-center">
                {opt.icon}
              </div>
              <h3 className="mt-3 text-sm font-semibold text-neutral-900">{opt.title}</h3>
              <p className="mt-1 text-xs text-neutral-500 flex-1">{opt.description}</p>
              {opt.key === 'statement' && <Button variant="secondary" className="mt-4 w-full" onClick={() => setViewStatement(true)}><Eye size={15} />View statement</Button>}
              <Button variant="secondary" className="mt-4 w-full inline-flex items-center justify-center gap-2" onClick={opt.action}>
                <Download size={15} /> Download
              </Button>
            </Card>
          ))}
        </div>
      )}
      <Modal open={viewStatement} onClose={() => setViewStatement(false)} title="Contribution Statement" size="xl">
        {contributions.filter(inRange).length === 0 ? <EmptyState icon={<FileText size={22} />} title="No contributions in this date range" /> : <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead><tr>{['Date', 'Period', 'Type', 'Method', 'Reference', 'Amount', 'Status'].map(label => <th key={label} className="p-3 whitespace-nowrap border-b border-border">{label}</th>)}</tr></thead>
              <tbody>{contributions.filter(inRange).map(c => <tr key={c.id}><td className="p-3 whitespace-nowrap border-b border-border">{formatDate(c.date)}</td><td className="p-3 border-b border-border">{c.period}</td><td className="p-3 border-b border-border">{c.type}</td><td className="p-3 border-b border-border">{c.method}</td><td className="p-3 border-b border-border">{c.reference}</td><td className="p-3 whitespace-nowrap border-b border-border">{formatCurrency(c.amount)}</td><td className="p-3 border-b border-border">{c.status}</td></tr>)}</tbody>
            </table>
          </div>
          <Button variant="secondary" className="mt-5" onClick={exportStatement}><Download size={15} />Download statement</Button>
        </>}
      </Modal>
    </div>
  );
}
