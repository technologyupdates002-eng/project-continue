export const BACKGROUND_INTERVAL_MS = 30_000;
export const MORNING_DURATION_MS = 5_000;
export const MORNING_STORAGE_KEY = 'khwwc.morning.last-day';
export const APPEARANCE_STORAGE_KEY = 'khwwc.appearance.theme';

export function nairobiDay(date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Nairobi', year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

export function shouldShowMorning(lastDay: string | null, date = new Date()): boolean {
  return lastDay !== nairobiDay(date);
}

export function nextBackground(index: number, count: number): number {
  return (index + 1) % count;
}

export function readAppearanceIndex(value: string | null, count: number): number {
  const index = Number(value);
  return Number.isInteger(index) && index >= 0 && index < count ? index : 0;
}