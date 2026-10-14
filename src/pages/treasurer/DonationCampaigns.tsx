import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Megaphone, Plus, Edit, Trash2, Users } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, PageHeader, Badge, Button, Input, Textarea, Select,
  LoadingState, ErrorState, EmptyState, Modal, ConfirmDialog,
} from '@/components/ui';
import { formatCurrency, formatDate } from '@/lib/format';

interface CampaignForm {
  title: string;
  description: string;
  target: string;
  raised: string;
  contributors: string;
  status: 'active' | 'completed' | 'paused';
  start_date: string;
  end_date: string;
}

const emptyForm: CampaignForm = {
  title: '', description: '', target: '', raised: '0', contributors: '0',
  status: 'active', start_date: new Date().toISOString().slice(0, 10),
  end_date: '',
};

export default function DonationCampaigns() {
  const qc = useQueryClient();
  const [modalOpen, setModalOpen] = useState(false);
  const [editId, setEditId] = useState<string | null>(null);
  const [deleteId, setDeleteId] = useState<string | null>(null);
  const [form, setForm] = useState<CampaignForm>(emptyForm);

  const { data: campaigns, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.treasurerCampaigns,
    queryFn: () => mockTreasurer.getDonationCampaigns(),
  });

  const createMut = useMutation({
    mutationFn: (data: Record<string, unknown>) => mockTreasurer.createDonationCampaign(data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.treasurerCampaigns }); setModalOpen(false); },
  });

  const updateMut = useMutation({
    mutationFn: ({ id, data }: { id: string; data: Record<string, unknown> }) =>
      mockTreasurer.updateDonationCampaign(id, data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.treasurerCampaigns }); setModalOpen(false); },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => mockTreasurer.deleteDonationCampaign(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.treasurerCampaigns }); setDeleteId(null); },
  });

  function openCreate() {
    setEditId(null);
    setForm(emptyForm);
    setModalOpen(true);
  }

  function openEdit(c: any) {
    setEditId(c.id);
    setForm({
      title: c.title, description: c.description, target: String(c.target),
      raised: String(c.raised), contributors: String(c.contributors),
      status: c.status, start_date: c.start_date?.slice(0, 10) || '',
      end_date: c.end_date?.slice(0, 10) || '',
    });
    setModalOpen(true);
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      title: form.title, description: form.description,
      target: Number(form.target), raised: Number(form.raised),
      contributors: Number(form.contributors), status: form.status,
      start_date: form.start_date, end_date: form.end_date || undefined,
    };
    if (editId) {
      updateMut.mutate({ id: editId, data: payload });
    } else {
      createMut.mutate(payload);
    }
  }

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load donation campaigns" onRetry={refetch} />;

  const items = (campaigns as any[]) || [];

  return (
    <div>
      <PageHeader
        title="Donation Campaigns"
        subtitle="Create and manage fundraising campaigns"
        icon={<Megaphone size={20} />}
        action={<Button onClick={openCreate}><Plus size={16} /> New Campaign</Button>}
      />

      {items.length === 0 ? (
        <Card className="p-4">
          <EmptyState icon={<Megaphone size={24} />} title="No campaigns yet" message="Create a donation campaign to start fundraising." />
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {items.map((c: any) => {
            const pct = c.target > 0 ? Math.min(100, Math.round((c.raised / c.target) * 100)) : 0;
            return (
              <Card key={c.id} className="p-5">
                <div className="flex items-start justify-between gap-3 mb-3">
                  <div className="min-w-0">
                    <h3 className="text-sm font-bold text-neutral-900 truncate">{c.title}</h3>
                    <p className="text-xs text-neutral-500 mt-1 line-clamp-2">{c.description}</p>
                  </div>
                  <Badge status={c.status}>{c.status}</Badge>
                </div>

                <div className="mb-3">
                  <div className="flex justify-between text-xs text-neutral-500 mb-1">
                    <span>{formatCurrency(c.raised)} raised</span>
                    <span>{pct}% of {formatCurrency(c.target)}</span>
                  </div>
                  <div className="h-2 bg-neutral-100 rounded-full overflow-hidden">
                    <div className="h-full bg-primary-600 rounded-full transition-all" style={{ width: `${pct}%` }} />
                  </div>
                </div>

                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-1 text-xs text-neutral-500">
                    <Users size={14} /> {c.contributors} contributors
                  </div>
                  <div className="flex gap-2">
                    <Button size="sm" variant="ghost" onClick={() => openEdit(c)}><Edit size={14} /></Button>
                    <Button size="sm" variant="ghost" onClick={() => setDeleteId(c.id)}><Trash2 size={14} className="text-error-600" /></Button>
                  </div>
                </div>

                <div className="mt-3 pt-3 border-t border-neutral-50 text-xs text-neutral-400">
                  {c.start_date ? formatDate(c.start_date) : ''} — {c.end_date ? formatDate(c.end_date) : 'Ongoing'}
                </div>
              </Card>
            );
          })}
        </div>
      )}

      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editId ? 'Edit Campaign' : 'New Campaign'} size="lg">
        <form onSubmit={submit} className="space-y-4">
          <Input label="Title" value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} required />
          <Textarea label="Description" rows={3} value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} required />
          <div className="grid grid-cols-2 gap-4">
            <Input label="Target Amount (KES)" type="number" value={form.target} onChange={e => setForm({ ...form, target: e.target.value })} required />
            <Input label="Raised Amount (KES)" type="number" value={form.raised} onChange={e => setForm({ ...form, raised: e.target.value })} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <Input label="Start Date" type="date" value={form.start_date} onChange={e => setForm({ ...form, start_date: e.target.value })} required />
            <Input label="End Date" type="date" value={form.end_date} onChange={e => setForm({ ...form, end_date: e.target.value })} />
          </div>
          <Select label="Status" value={form.status} onChange={e => setForm({ ...form, status: e.target.value as CampaignForm['status'] })}>
            <option value="active">Active</option>
            <option value="paused">Paused</option>
            <option value="completed">Completed</option>
          </Select>
          <div className="flex justify-end gap-3 pt-2">
            <Button variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
            <Button type="submit" disabled={createMut.isPending || updateMut.isPending}>
              {(createMut.isPending || updateMut.isPending) ? 'Saving...' : editId ? 'Update' : 'Create'}
            </Button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        open={!!deleteId}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMut.mutate(deleteId)}
        title="Delete Campaign"
        message="Are you sure you want to delete this donation campaign? This action cannot be undone."
        confirmLabel="Delete"
        danger
      />
    </div>
  );
}
