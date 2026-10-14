import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  HeartHandshake, Target, Users, Calendar, TrendingUp, CheckCircle, AlertCircle,
} from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, Input, PageHeader,
  EmptyState, ErrorState, LoadingState,
} from '@/components/ui';
import { PaymentModal } from '@/components/PaymentModal';
import { formatCurrency, formatCurrencyShort, formatDate } from '@/lib/format';
import type { DonationCampaign } from '@/types';

const presetAmounts = [500, 1000, 2000, 5000];

export default function Donate() {
  const queryClient = useQueryClient();
  const [payModal, setPayModal] = useState(false);
  const [selectedCampaign, setSelectedCampaign] = useState<DonationCampaign | null>(null);
  const [amount, setAmount] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberDonationCampaigns,
    queryFn: () => mockMember.getDonationCampaigns(),
  });

  const campaigns = (data as DonationCampaign[] | undefined) ?? [];

  const openDonate = (c: DonationCampaign) => {
    setSelectedCampaign(c);
    setAmount('');
    setError('');
    setPayModal(true);
  };

  const handlePayClick = () => {
    const amt = Number(amount);
    if (!selectedCampaign) {
      setError('Please select a campaign.');
      return;
    }
    if (!amount || Number.isNaN(amt) || amt < 100) {
      setError('Please enter an amount of at least KES 100.');
      return;
    }
    setError('');
    setPayModal(true);
  };

  const handleSuccess = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.memberDonationCampaigns });
    queryClient.invalidateQueries({ queryKey: queryKeys.memberDashboard });
    setPayModal(false);
    setAmount('');
    setSuccess('Donation confirmed successfully. Thank you for your generosity!');
    setTimeout(() => setSuccess(''), 4000);
  };

  return (
    <div className="space-y-5">
      <PageHeader title="Donate" subtitle="Support KHWWC welfare causes" icon={<HeartHandshake size={20} />} />

      {success && (
        <div className="flex items-start gap-2 p-3 rounded-xl bg-success-50 border border-success-200 text-sm text-success-700">
          <CheckCircle size={16} className="flex-shrink-0 mt-0.5" />
          <span>{success}</span>
        </div>
      )}

      {isLoading ? (
        <LoadingState text="Loading campaigns..." />
      ) : isError ? (
        <ErrorState message="We couldn't load donation campaigns." onRetry={() => refetch()} />
      ) : campaigns.length === 0 ? (
        <EmptyState icon={<HeartHandshake size={22} />} title="No active campaigns" message="There are no active donation campaigns right now." />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {campaigns.map((c) => {
            const pct = c.target > 0 ? Math.min(Math.round((c.raised / c.target) * 100), 100) : 0;
            return (
              <Card key={c.id} className="p-5 flex flex-col">
                {c.image ? (
                  <img src={c.image} alt={c.title} className="w-full h-32 object-cover rounded-xl mb-3" />
                ) : (
                  <div className="w-full h-32 rounded-xl bg-primary-50 flex items-center justify-center mb-3">
                    <Target size={28} className="text-primary-300" />
                  </div>
                )}
                <div className="flex items-center justify-between mb-2">
                  <Badge status={c.status}>{c.status}</Badge>
                  <span className="text-xs text-neutral-400 flex items-center gap-1"><Calendar size={11} /> Ends {formatDate(c.end_date)}</span>
                </div>
                <h3 className="text-sm font-semibold text-neutral-900">{c.title}</h3>
                <p className="mt-1 text-xs text-neutral-500 line-clamp-2 flex-1">{c.description}</p>

                <div className="mt-3">
                  <div className="flex items-center justify-between text-xs mb-1">
                    <span className="font-medium text-neutral-700">{formatCurrencyShort(c.raised)}</span>
                    <span className="text-neutral-400">of {formatCurrencyShort(c.target)}</span>
                  </div>
                  <div className="h-2 rounded-full bg-neutral-100 overflow-hidden">
                    <div className="h-full rounded-full bg-primary-600 transition-all" style={{ width: `${pct}%` }} />
                  </div>
                  <div className="mt-1.5 flex items-center justify-between text-xs text-neutral-500">
                    <span className="flex items-center gap-1"><TrendingUp size={12} /> {pct}% funded</span>
                    <span className="flex items-center gap-1"><Users size={12} /> {c.contributors} donors</span>
                  </div>
                </div>

                <Button variant="primary" className="mt-4 w-full inline-flex items-center justify-center gap-2" onClick={() => openDonate(c)}>
                  <HeartHandshake size={15} /> Donate
                </Button>
              </Card>
            );
          })}
        </div>
      )}

      {/* Amount selection modal */}
      {payModal && selectedCampaign && !amount && (
        <Card className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" >
          <div className="w-full max-w-md bg-white rounded-2xl shadow-xl p-6 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold text-neutral-900">Donate to {selectedCampaign.title}</h3>
              <button onClick={() => setPayModal(false)} className="text-neutral-400 hover:text-neutral-600">Close</button>
            </div>

            {error && (
              <div className="flex items-start gap-2 p-3 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
                <AlertCircle size={16} className="flex-shrink-0 mt-0.5" />
                <span>{error}</span>
              </div>
            )}

            <div>
              <label className="label">Amount (KES)</label>
              <Input type="number" min="100" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="e.g. 1000" autoFocus />
              <div className="mt-2 flex flex-wrap gap-2">
                {presetAmounts.map((amt) => (
                  <button
                    key={amt}
                    type="button"
                    onClick={() => setAmount(String(amt))}
                    className={`px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors ${amount === String(amt) ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'}`}
                  >
                    {formatCurrency(amt)}
                  </button>
                ))}
              </div>
            </div>

            <div className="flex justify-end gap-3 pt-2">
              <Button variant="secondary" onClick={() => setPayModal(false)}>Cancel</Button>
              <Button onClick={handlePayClick}>Continue</Button>
            </div>
          </div>
        </Card>
      )}

      <PaymentModal
        open={payModal && !!selectedCampaign && !!amount}
        onClose={() => { setPayModal(false); setAmount(''); }}
        amount={Number(amount) || 0}
        accountRef={`DONATION-${selectedCampaign?.id || ''}`}
        paymentType="donation"
        transactionDesc={`Donation to ${selectedCampaign?.title || ''}`}
        campaignId={selectedCampaign?.id}
        onSuccess={handleSuccess}
      />
    </div>
  );
}
