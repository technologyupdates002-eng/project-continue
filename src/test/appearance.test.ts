import { describe, expect, it } from 'vitest';
import { BACKGROUND_INTERVAL_MS, MORNING_DURATION_MS, nextBackground, shouldShowMorning, nairobiDay } from '@/lib/appearance';

describe('Daily appearance rules', () => {
  it('rotates backgrounds every 30 seconds', () => { expect(BACKGROUND_INTERVAL_MS).toBe(30000); });
  it('holds the morning image for five seconds', () => { expect(MORNING_DURATION_MS).toBe(5000); });
  it('shows the morning image on the first open', () => { expect(shouldShowMorning(null, new Date('2026-10-14T06:00:00Z'))).toBe(true); });
  it('does not repeat the morning image on the same Nairobi day', () => { expect(shouldShowMorning('2026-10-14', new Date('2026-10-14T17:00:00Z'))).toBe(false); });
  it('shows again on the next day in Nairobi', () => { expect(shouldShowMorning('2026-10-14', new Date('2026-10-14T21:01:00Z'))).toBe(true); expect(nairobiDay(new Date('2026-10-14T21:01:00Z'))).toBe('2026-10-15'); });
  it('cycles back to the first background after the last', () => { expect(nextBackground(3, 4)).toBe(0); });
});