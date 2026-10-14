import { describe, expect, it } from 'vitest';
import { memberPaymentDestinations } from '@/lib/member-payment-navigation';

describe('Member payment navigation', () => {
  it('routes penalty payments to penalty selection', () => {
    expect(memberPaymentDestinations.penalty).toBe('/member/pay-penalty');
  });
  it('routes welfare contributions to monthly contribution payment', () => {
    expect(memberPaymentDestinations.welfare).toBe('/member/welfare-contribution');
  });
  it('routes fund drives to campaign selection', () => {
    expect(memberPaymentDestinations.fundDrive).toBe('/member/donate');
  });
});