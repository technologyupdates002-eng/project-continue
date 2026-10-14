import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { CreditCard, HeartHandshake } from 'lucide-react';
import { Button, PageHeader } from '@/components/ui';
import { PaymentModal } from '@/components/PaymentModal';
import { queryKeys } from '@/lib/query';
import { formatCurrency } from '@/lib/format';

export default function WelfareContribution() {
  const [open, setOpen] = useState(false);
  const [success, setSuccess] = useState(false);
  const queryClient = useQueryClient();
  return <div className="space-y-6">
    <PageHeader title="Welfare contribution" icon={<HeartHandshake size={20} />} />
    <section className="member-balance">
      <p className="text-sm">Monthly contribution</p>
      <p className="balance-number mt-4">{formatCurrency(2000)}</p>
      <Button variant="secondary" className="balance-action mt-6" onClick={() => setOpen(true)}><CreditCard size={16} />Pay contribution</Button>
    </section>
    {success && <p role="status">Payment status updated successfully.</p>}
    <PaymentModal open={open} onClose={() => setOpen(false)} amount={2000} accountRef="CONTRIBUTION-member" paymentType="monthly_contribution" transactionDesc="Monthly contribution payment" bankDetails={{ account: 'KHWWC Welfare', accountNo: '0123 4567 8901', paybill: '522522', reference: 'CONTRIBUTION' }} onSuccess={() => { queryClient.invalidateQueries({ queryKey: queryKeys.memberDashboard }); setOpen(false); setSuccess(true); }} />
  </div>;
}