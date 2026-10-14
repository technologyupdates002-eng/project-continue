import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Settings, Upload, Save, Palette } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, PageHeader, Button, Input, Textarea,
  LoadingState, ErrorState,
} from '@/components/ui';
import { formatCurrency } from '@/lib/format';
import { AppearanceSettings } from '@/components/AppearanceProvider';

export default function TreasurerSettings() {
  const qc = useQueryClient();
  const [savedMsg, setSavedMsg] = useState('');

  const { data: settings, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.treasurerSettings,
    queryFn: () => mockTreasurer.getOrgSettings(),
  });

  const s = settings as any;

  const [form, setForm] = useState({
    organization_name: '',
    tagline: '',
    email: '',
    phone: '',
    address: '',
    payout_bereavement: '',
    payout_medical: '',
    payout_education: '',
  });

  useState(() => {
    if (s) {
      setForm({
        organization_name: s.organization_name || '',
        tagline: s.tagline || '',
        email: s.email || '',
        phone: s.phone || '',
        address: s.address || '',
        payout_bereavement: String(s.payout_bereavement || ''),
        payout_medical: String(s.payout_medical || ''),
        payout_education: String(s.payout_education || ''),
      });
    }
  });

  const saveMut = useMutation({
    mutationFn: (data: Record<string, unknown>) => mockTreasurer.updateOrgSettings(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.treasurerSettings });
      setSavedMsg('Settings saved successfully.');
      setTimeout(() => setSavedMsg(''), 3000);
    },
  });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    saveMut.mutate({
      organization_name: form.organization_name,
      tagline: form.tagline,
      email: form.email,
      phone: form.phone,
      address: form.address,
      payout_bereavement: Number(form.payout_bereavement),
      payout_medical: Number(form.payout_medical),
      payout_education: Number(form.payout_education),
    });
  }

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load settings" onRetry={refetch} />;

  if (s && !form.organization_name) {
    setForm({
      organization_name: s.organization_name || '',
      tagline: s.tagline || '',
      email: s.email || '',
      phone: s.phone || '',
      address: s.address || '',
      payout_bereavement: String(s.payout_bereavement || ''),
      payout_medical: String(s.payout_medical || ''),
      payout_education: String(s.payout_education || ''),
    });
  }

  return (
    <div>
      <PageHeader
        title="Treasurer Settings"
        subtitle="Organization letterhead, contact, and payout rules"
        icon={<Settings size={20} />}
      />

      {savedMsg && (
        <div className="mb-4 p-3 rounded-xl bg-success-50 border border-success-200 text-sm text-success-700">{savedMsg}</div>
      )}

      <form onSubmit={submit} className="space-y-4">
        <Card className="p-5">
          <div className="flex items-start justify-between gap-4 mb-4">
            <div>
              <h3 className="text-sm font-bold text-neutral-900 flex items-center gap-2"><Palette size={16} className="text-primary-500" /> Portal Appearance</h3>
              <p className="text-sm text-neutral-500 mt-1">Choose the background style for the private member and staff portal. The public website keeps its standard appearance.</p>
            </div>
          </div>
          <AppearanceSettings />
        </Card>
        <Card className="p-5">
          <h3 className="text-sm font-bold text-neutral-900 mb-4">Organization Letterhead</h3>
          <div className="space-y-4">
            <Input label="Organization Name" value={form.organization_name} onChange={e => setForm({ ...form, organization_name: e.target.value })} required />
            <Textarea label="Tagline / About" rows={2} value={form.tagline} onChange={e => setForm({ ...form, tagline: e.target.value })} />
          </div>

          <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label className="label">Logo</label>
              <div className="border-2 border-dashed border-neutral-200 rounded-xl p-4 text-center hover:border-primary-300 transition-colors cursor-pointer">
                <Upload size={20} className="mx-auto text-neutral-300 mb-1" />
                <p className="text-xs text-neutral-400">Click to upload</p>
              </div>
            </div>
            <div>
              <label className="label">Signature</label>
              <div className="border-2 border-dashed border-neutral-200 rounded-xl p-4 text-center hover:border-primary-300 transition-colors cursor-pointer">
                <Upload size={20} className="mx-auto text-neutral-300 mb-1" />
                <p className="text-xs text-neutral-400">Click to upload</p>
              </div>
            </div>
            <div>
              <label className="label">Stamp</label>
              <div className="border-2 border-dashed border-neutral-200 rounded-xl p-4 text-center hover:border-primary-300 transition-colors cursor-pointer">
                <Upload size={20} className="mx-auto text-neutral-300 mb-1" />
                <p className="text-xs text-neutral-400">Click to upload</p>
              </div>
            </div>
          </div>
        </Card>

        <Card className="p-5">
          <h3 className="text-sm font-bold text-neutral-900 mb-4">Contact Information</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Input label="Email" type="email" value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} required />
            <Input label="Phone" value={form.phone} onChange={e => setForm({ ...form, phone: e.target.value })} required />
          </div>
          <div className="mt-4">
            <Input label="Address" value={form.address} onChange={e => setForm({ ...form, address: e.target.value })} required />
          </div>
        </Card>

        <Card className="p-5">
          <h3 className="text-sm font-bold text-neutral-900 mb-4">Payout Rules</h3>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <Input
                label="Bereavement Payout (KES)"
                type="number"
                value={form.payout_bereavement}
                onChange={e => setForm({ ...form, payout_bereavement: e.target.value })}
                required
              />
              {s && <p className="mt-1 text-xs text-neutral-400">Current: {formatCurrency(s.payout_bereavement)}</p>}
            </div>
            <div>
              <Input
                label="Medical Payout (KES)"
                type="number"
                value={form.payout_medical}
                onChange={e => setForm({ ...form, payout_medical: e.target.value })}
                required
              />
              {s && <p className="mt-1 text-xs text-neutral-400">Current: {formatCurrency(s.payout_medical)}</p>}
            </div>
            <div>
              <Input
                label="Education Payout (KES)"
                type="number"
                value={form.payout_education}
                onChange={e => setForm({ ...form, payout_education: e.target.value })}
                required
              />
              {s && <p className="mt-1 text-xs text-neutral-400">Current: {formatCurrency(s.payout_education)}</p>}
            </div>
          </div>
        </Card>

        <div className="flex justify-end">
          <Button type="submit" size="lg" disabled={saveMut.isPending}>
            <Save size={16} /> {saveMut.isPending ? 'Saving...' : 'Save Settings'}
          </Button>
        </div>
      </form>
    </div>
  );
}
