import { describe, expect, it } from 'vitest';
import { carryOver, monthsCovered } from '@/lib/contribution-math';

describe('monthly contribution allocation', () => {
  it('10,000 KES at a 2,000 KES rate covers 5 months', () => {
    expect(monthsCovered(10000, 2000)).toBe(5);
    expect(carryOver(10000, 2000)).toBe(0);
  });
  it('10,000 KES at a 300 KES rate covers 33 months with 100 KES carried over', () => {
    expect(monthsCovered(10000, 300)).toBe(33);
    expect(carryOver(10000, 300)).toBe(100);
  });
  it('less than one month covers 0 months', () => {
    expect(monthsCovered(150, 300)).toBe(0);
  });
});
