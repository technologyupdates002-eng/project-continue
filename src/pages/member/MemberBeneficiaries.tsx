import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Users, UserPlus, Pencil, Trash2, Phone, Mail, Heart, AlertTriangle,
} from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, Input, Select, PageHeader, Modal, ConfirmDialog,
  EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import type { Beneficiary } from '@/types';

interface FormData {
  name: string;
  relationship: string;
  phone: string;
  email: string;
  id_number: string;
  date_of_birth: string;
  percentage: string;
}

const emptyForm: FormData = {
  name: '', relationship: '', phone: '', email: '', id_number: '', date_of_birth: '', percentage: '',
};

const relationshipOptions = ['Spouse', 'Child', 'Parent', 'Sibling', 'Other'];

export default function MemberBeneficiaries() {
  const queryClient = useQueryClient();
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Beneficiary | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Beneficiary | null>(null);
  const [form, setForm] = useState<FormData>(emptyForm);
  const [error, setError] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberBeneficiaries,
    queryFn: () => mockMember.getBeneficiaries(),
  });

  const beneficiaries = (data as Beneficiary[] | undefined) ?? [];
  const totalPct = beneficiaries.reduce((sum, b) => sum + (b.percentage || 0), 0);

  const addMutation = useMutation({
    mutationFn: (payload: Partial<Beneficiary>) => mockMember.addBeneficiary(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.memberBeneficiaries });
      setModalOpen(false);
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: Partial<Beneficiary> }) =>
      mockMember.updateBeneficiary(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.memberBeneficiaries });
      setModalOpen(false);
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => mockMember.deleteBeneficiary(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.memberBeneficiaries });
      setDeleteTarget(null);
    },
  });

  const openAdd = () => {
    setEditing(null);
    setForm(emptyForm);
    setError('');
    setModalOpen(true);
  };

  const openEdit = (b: Beneficiary) => {
    setEditing(b);
    setForm({
      name: b.name,
      relationship: b.relationship,
      phone: b.phone,
      email: b.email,
      id_number: b.id_number,
      date_of_birth: b.date_of_birth,
      percentage: String(b.percentage),
    });
    setError('');
    setModalOpen(true);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const pctNum = Number(form.percentage);
    if (!form.name || !form.relationship || !form.percentage) {
      setError('Name, relationship and percentage are required.');
      return;
    }
    if (Number.isNaN(pctNum) || pctNum < 1 || pctNum > 100) {
      setError('Percentage must be between 1 and 100.');
      return;
    }
    // Validate total allocation doesn't exceed 100% (excluding the one being edited)
    const othersTotal = beneficiaries
      .filter((b) => b.id !== editing?.id)
      .reduce((sum, b) => sum + b.percentage, 0);
    if (othersTotal + pctNum > 100) {
      setError(`Total allocation would exceed 100%. Available: ${100 - othersTotal}%.`);
      return;
    }
    const payload: Partial<Beneficiary> = {
      name: form.name,
      relationship: form.relationship,
      phone: form.phone,
      email: form.email,
      id_number: form.id_number,
      date_of_birth: form.date_of_birth,
      percentage: pctNum,
    };
    if (editing) {
      updateMutation.mutate({ id: editing.id, payload });
    } else {
      addMutation.mutate(payload);
    }
  };

  const set = (k: keyof FormData, v: string) => setForm((f) => ({ ...f, [k]: v }));
  const saving = addMutation.isPending || updateMutation.isPending;

  return (
    <div className="space-y-5">
      <PageHeader
        title="Beneficiaries"
        subtitle="Manage your welfare beneficiaries"
        icon={<Users size={20} />}
        action={<Button className="inline-flex items-center gap-2" onClick={openAdd}><UserPlus size={16} /> Add</Button>}
      />

      {/* Total percentage indicator */}
      <Card className="p-4">
        <div className="flex items-center justify-between mb-2">
          <p className="text-sm font-medium text-neutral-700">Total Allocation</p>
          <p className={`text-sm font-bold ${totalPct === 100 ? 'text-success-600' : 'text-warning-600'}`}>
            {totalPct}%
          </p>
        </div>
        <div className="h-2 rounded-full bg-neutral-100 overflow-hidden">
          <div
            className={`h-full rounded-full transition-all ${totalPct === 100 ? 'bg-success-500' : 'bg-warning-500'}`}
            style={{ width: `${Math.min(totalPct, 100)}%` }}
          />
        </div>
        {totalPct !== 100 && (
          <p className="mt-2 text-xs text-warning-600 flex items-center gap-1.5">
            <AlertTriangle size={13} /> Total should add up to 100%.
          </p>
        )}
      </Card>

      {isLoading ? (
        <LoadingState text="Loading beneficiaries..." />
      ) : isError ? (
        <ErrorState message="We couldn't load your beneficiaries." onRetry={() => refetch()} />
      ) : beneficiaries.length === 0 ? (
        <EmptyState
          icon={<Users size={22} />}
          title="No beneficiaries yet"
          message="Add a beneficiary to allocate your welfare benefits."
          action={<Button className="inline-flex items-center gap-2" onClick={openAdd}><UserPlus size={16} /> Add Beneficiary</Button>}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {beneficiaries.map((b) => (
            <Card key={b.id} className="p-5">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                  <div className="flex-shrink-0 w-11 h-11 rounded-xl bg-primary-100 text-primary-700 flex items-center justify-center">
                    <Heart size={18} />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-neutral-900 truncate">{b.name}</p>
                    <p className="text-xs text-neutral-500">{b.relationship}</p>
                  </div>
                </div>
                <Badge status="active">{b.percentage}%</Badge>
              </div>
              <div className="mt-3 space-y-1 text-xs text-neutral-500">
                {b.phone && <p className="flex items-center gap-1.5"><Phone size={12} className="text-neutral-400" /> {b.phone}</p>}
                {b.email && <p className="flex items-center gap-1.5 truncate"><Mail size={12} className="text-neutral-400" /> {b.email}</p>}
              </div>
              <div className="mt-3 flex gap-2">
                <Button variant="secondary" size="sm" className="inline-flex items-center gap-1.5" onClick={() => openEdit(b)}>
                  <Pencil size={13} /> Edit
                </Button>
                <Button variant="ghost" size="sm" className="inline-flex items-center gap-1.5 text-error-600" onClick={() => setDeleteTarget(b)}>
                  <Trash2 size={13} /> Delete
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {/* Add/Edit modal */}
      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit Beneficiary' : 'Add Beneficiary'} size="lg">
        <form onSubmit={handleSubmit} className="space-y-4">
          {error && (
            <div className="flex items-start gap-2 p-3 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
              <AlertTriangle size={16} className="flex-shrink-0 mt-0.5" />
              <span>{error}</span>
            </div>
          )}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Input label="Full Name" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="e.g. John Mwangi" required />
            <Select label="Relationship" value={form.relationship} onChange={(e) => set('relationship', e.target.value)} required>
              <option value="">Select relationship</option>
              {relationshipOptions.map((r) => <option key={r} value={r}>{r}</option>)}
            </Select>
            <Input label="Phone" value={form.phone} onChange={(e) => set('phone', e.target.value)} placeholder="0712 345 678" />
            <Input label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} placeholder="beneficiary@email.com" />
            <Input label="ID Number" value={form.id_number} onChange={(e) => set('id_number', e.target.value)} placeholder="National ID" />
            <Input label="Date of Birth" type="date" value={form.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} />
            <Input label="Allocation (%)" type="number" min="1" max="100" value={form.percentage} onChange={(e) => set('percentage', e.target.value)} placeholder="e.g. 50" required />
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <Button variant="secondary" type="button" onClick={() => setModalOpen(false)}>Cancel</Button>
            <Button type="submit" disabled={saving}>
              {saving ? 'Saving...' : editing ? 'Update Beneficiary' : 'Add Beneficiary'}
            </Button>
          </div>
        </form>
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        title="Delete Beneficiary"
        message={`Are you sure you want to remove ${deleteTarget?.name} from your beneficiaries? This action cannot be undone.`}
        confirmLabel="Delete"
        danger
      />
    </div>
  );
}
