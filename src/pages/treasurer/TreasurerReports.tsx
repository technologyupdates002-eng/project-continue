import { useQuery, useMutation } from '@tanstack/react-query';
import { BarChart3, Download, FileText } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, PageHeader, LoadingState, ErrorState, EmptyState, Button } from '@/components/ui';
import { formatDate } from '@/lib/format';

export default function TreasurerReports() {
  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.treasurerReports,
    queryFn: () => mockTreasurer.getReports(),
  });
  const download = useMutation({ mutationFn: (type: string) => mockTreasurer.exportReport(type) });

  return (
    <div>
      <PageHeader title="Reports" subtitle="Generate and download financial reports" icon={<BarChart3 size={20} />} />
      <Card className="p-4">
        {isLoading && <LoadingState />}
        {isError && <ErrorState message="Failed to load reports" />}
        {download.isError && <p className="mb-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-700">Download failed. Please try again.</p>}
        {!isLoading && !isError && (data?.length === 0 ? <EmptyState icon={<BarChart3 size={24} />} title="No reports available" /> : (
          <div className="divide-y divide-neutral-50">
            {data?.map((report: any, i: number) => (
              <div key={i} className="flex items-center justify-between p-4 hover:bg-neutral-50">
                <div className="flex items-center gap-3 min-w-0">
                  <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-primary-50 text-primary-700 flex items-center justify-center"><FileText size={18} /></div>
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-neutral-800 truncate">{report.name}</p>
                    <p className="text-xs text-neutral-400">{report.size} • {formatDate(report.date)}</p>
                  </div>
                </div>
                <Button variant="secondary" size="sm" onClick={() => download.mutate(report.type)} disabled={download.isPending}>
                  <Download size={14} /> {download.isPending ? 'Preparing...' : 'Download'}
                </Button>
              </div>
            ))}
          </div>
        ))}
      </Card>
    </div>
  );
}
