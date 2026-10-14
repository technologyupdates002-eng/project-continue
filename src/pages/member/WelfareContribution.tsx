import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarCheck, CreditCard, PiggyBank } from 'lucide-react';
import { Button, Card, Input, PageHeader } from '@/components/ui';
import { PaymentModal } from '@/components/PaymentModal';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { formatCurrency } from '@/lib/format';
import { carryOver, monthsCovered } from '@/lib/contribution-math';

export default function WelfareContribution() {
  const [open, setOpen] = useState(false);
  const [success, setSuccess] = useState(false);
  const queryClient = useQueryClient();
  const { data } = useQuery({ queryKey: queryKeys.memberDashboard, queryFn: () => mockMember.dashboard() });
  const rate = data?.monthly_rate ?? 0;
  const [amount, setAmount] = useState<number>(0);
  useEffect(() => { if (rate && !amount) setAmount(rate); }, [rate, amount]);
  const months = monthsCovered(amount, rate);
  const extra = carryOver(amount, rate);

  return <div className="space-y-6">
    <PageHeader title="Monthly contribution" icon={<PiggyBank size={20} />} />
    <section className="member-balance">
      <p className="text-sm">Monthly rate set by admin</p>
      <p className="balance-number mt-4">{rate ? formatCurrency(rate) : '—'}</p>
      {data?.grace_days != null && <p className="mt-2 text-sm text-muted-foreground">A penalty applies {data.grace_days} days after a month is due and unpaid. You'll get an SMS reminder.</p>}
    </section>
    <Card className="p-5 sm:p-6 space-y-4">
      <Input label="Amount to pay (KES)" type="number" min={1} inputMode="numeric" value={amount || ''} onChange={e => setAmount(Number(e.target.value))} />
      {rate > 0 && amount > 0 && <div className="flex items-start gap-3 rounded-lg bg-primary-50 p-4 text-sm">
        <CalendarCheck size={18} className="text-primary-700 mt-0.5" />
        <p>{formatCurrency(amount)} covers <strong>{months} {months === 1 ? 'month' : 'months'}</strong>{extra > 0 && <> and {formatCurrency(extra)} is carried into the next month</>}.</p>
      </div>}
      <Button disabled={!amount || amount < 1} onClick={() => setOpen(true)}><CreditCard size={16} />Pay {amount ? formatCurrency(amount) : ''}</Button>
    </Card>
    {success && <p role="status">Payment received. Your contributions will update shortly.</p>}
    <PaymentModal open={open} onClose={() => setOpen(false)} amount={amount} accountRef="CONTRIBUTION-member" paymentType="monthly_contribution" transactionDesc={`Monthly contribution${months ? ` (${months} ${months === 1 ? 'month' : 'months'})` : ''}`} bankDetails={{ account: 'KHWWC Welfare', accountNo: '0123 4567 8901', paybill: '522522', reference: 'CONTRIBUTION' }} onSuccess={() => { queryClient.invalidateQueries({ queryKey: queryKeys.memberDashboard }); setOpen(false); setSuccess(true); }} />
  </div>;
}
