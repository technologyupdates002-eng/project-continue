/** How many full months a payment covers at the admin-set monthly rate. */
export function monthsCovered(amount: number, monthlyRate: number): number {
  if (!monthlyRate || monthlyRate <= 0 || !amount || amount <= 0) return 0;
  return Math.floor(amount / monthlyRate);
}

/** Leftover after full months, carried into the next month. */
export function carryOver(amount: number, monthlyRate: number): number {
  if (!monthlyRate || monthlyRate <= 0 || !amount || amount <= 0) return 0;
  return Math.round((amount - monthsCovered(amount, monthlyRate) * monthlyRate) * 100) / 100;
}
