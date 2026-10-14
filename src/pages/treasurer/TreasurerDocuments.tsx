import { useQuery, useMutation } from '@tanstack/react-query';
import { FileText, Download } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Card, PageHeader, LoadingState, ErrorState, EmptyState, Button, Badge } from '@/components/ui';
import { formatDate } from '@/lib/format';

export default function TreasurerDocuments() {
  const { data, isLoading, isError } = useQuery({
    queryKey: queryKeys.treasurerDocuments,
    queryFn: () => mockTreasurer.getDocuments(),
  });
  const download = useMutation({ mutationFn: (id: string) => mockTreasurer.downloadDocument(id) });

  return (
    <div>
      <PageHeader title="Documents" subtitle="Treasurer statements and financial documents" icon={<FileText size={20} />} />
      <Card className="p-4">
        {isLoading && <LoadingState />}
        {isError && <ErrorState message="Failed to load documents" />}
        {download.isError && <p className="mb-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-700">Download failed. Please try again.</p>}
        {!isLoading && !isError && (data?.length === 0 ? <EmptyState icon={<FileText size={24} />} title="No documents" /> : (
          <div className="divide-y divide-neutral-50">
            {data?.map((doc: any) => (
              <div key={doc.id} className="flex items-center justify-between p-4 hover:bg-neutral-50">
                <div className="flex items-center gap-3 min-w-0">
                  <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-secondary-50 text-secondary-700 flex items-center justify-center"><FileText size={18} /></div>
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-neutral-800 truncate">{doc.title}</p>
                    <p className="text-xs text-neutral-400">{doc.type} • {doc.size} • {formatDate(doc.date)}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Badge status={doc.status}>{doc.status}</Badge>
                  <Button variant="ghost" size="sm" onClick={() => download.mutate(doc.id)} disabled={download.isPending} aria-label={`Download ${doc.title}`}>
                    <Download size={14} />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        ))}
      </Card>
    </div>
  );
}
