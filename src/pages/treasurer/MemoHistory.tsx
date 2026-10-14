import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@/lib/router-compat';
import { Mail, Plus, Search, Trash2, Send, Eye } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, PageHeader, Badge, Button, Input, Select, Tabs,
  LoadingState, ErrorState, EmptyState, Modal, ConfirmDialog,
} from '@/components/ui';
import { formatDate } from '@/lib/format';

export default function MemoHistory() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [tab, setTab] = useState('all');
  const [search, setSearch] = useState('');
  const [deleteId, setDeleteId] = useState<string | null>(null);
  const [previewMemo, setPreviewMemo] = useState<any>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.treasurerMemos,
    queryFn: () => mockTreasurer.getMemos(),
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => mockTreasurer.deleteMemo(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.treasurerMemos }); setDeleteId(null); },
  });

  const resendMut = useMutation({
    mutationFn: (id: string) => mockTreasurer.resendMemo(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: queryKeys.treasurerMemos }),
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load memos" onRetry={refetch} />;

  let memos = (data as any[]) || [];
  if (tab === 'sent') memos = memos.filter((m: any) => m.status === 'sent');
  if (tab === 'draft') memos = memos.filter((m: any) => m.status === 'draft');
  if (search) {
    const q = search.toLowerCase();
    memos = memos.filter((m: any) =>
      m.title?.toLowerCase().includes(q) || m.subject?.toLowerCase().includes(q),
    );
  }

  const sentCount = ((data as any[]) || []).filter((m: any) => m.status === 'sent').length;
  const draftCount = ((data as any[]) || []).filter((m: any) => m.status === 'draft').length;

  return (
    <div>
      <PageHeader
        title="Memo History"
        subtitle="Search, preview, resend, and delete memos"
        icon={<Mail size={20} />}
        action={<Button onClick={() => navigate('/treasurer/memos/create')}><Plus size={16} /> New Memo</Button>}
      />

      <Tabs
        tabs={[
          { key: 'all', label: 'All', count: ((data as any[]) || []).length },
          { key: 'sent', label: 'Sent', count: sentCount },
          { key: 'draft', label: 'Drafts', count: draftCount },
        ]}
        active={tab}
        onChange={setTab}
      />

      <Card className="p-4 mt-4">
        <div className="flex flex-col sm:flex-row gap-3 mb-4">
          <div className="flex-1">
            <Input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search by title or subject..." />
          </div>
        </div>

        {memos.length === 0 ? (
          <EmptyState icon={<Mail size={24} />} title="No memos found" message="Create a memo to get started." />
        ) : (
          <div className="divide-y divide-neutral-50">
            {memos.map((m: any) => (
              <div key={m.id} className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 py-4">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <p className="text-sm font-semibold text-neutral-800 truncate">{m.title}</p>
                    <Badge status={m.status}>{m.status}</Badge>
                  </div>
                  <p className="text-xs text-neutral-500 truncate">{m.subject}</p>
                  <p className="text-xs text-neutral-400 mt-1">
                    {m.status === 'sent' ? `Sent ${formatDate(m.sent_at || m.created_at)}` : `Created ${formatDate(m.created_at)}`}
                    {' • '}{m.recipients_count} recipients
                  </p>
                </div>
                <div className="flex gap-2 flex-shrink-0">
                  <Button size="sm" variant="ghost" onClick={() => setPreviewMemo(m)}><Eye size={14} /></Button>
                  <Button size="sm" variant="ghost" onClick={() => navigate(`/treasurer/memos/${m.id}/edit`)}><Plus size={14} /> Edit</Button>
                  {m.status === 'sent' && (
                    <Button size="sm" variant="ghost" onClick={() => resendMut.mutate(m.id)}><Send size={14} /></Button>
                  )}
                  <Button size="sm" variant="ghost" onClick={() => setDeleteId(m.id)}><Trash2 size={14} className="text-error-600" /></Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal open={!!previewMemo} onClose={() => setPreviewMemo(null)} title={previewMemo?.title || 'Memo'} size="lg">
        <div className="space-y-4">
          <div>
            <p className="text-xs font-medium text-neutral-400">Subject</p>
            <p className="text-sm font-semibold text-neutral-800">{previewMemo?.subject}</p>
          </div>
          <div>
            <p className="text-xs font-medium text-neutral-400">From</p>
            <p className="text-sm text-neutral-700">{previewMemo?.prepared_by}</p>
          </div>
          <div>
            <p className="text-xs font-medium text-neutral-400">Body</p>
            <p className="text-sm text-neutral-700 whitespace-pre-wrap">{previewMemo?.body}</p>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleteId}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMut.mutate(deleteId)}
        title="Delete Memo"
        message="Are you sure you want to delete this memo? This action cannot be undone."
        confirmLabel="Delete"
        danger
      />
    </div>
  );
}
