import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { APPEARANCE_STORAGE_KEY, BACKGROUND_INTERVAL_MS, MORNING_DURATION_MS, MORNING_STORAGE_KEY, nairobiDay, nextBackground, readAppearanceIndex, shouldShowMorning } from '@/lib/appearance';
import { Button } from '@/components/ui';
import { Pause, Play } from 'lucide-react';

export type AppearanceTheme = { name: string; theme: string; url: string; swatch: string };

export const backgrounds: AppearanceTheme[] = [
  { name: 'Sky', theme: 'sky', url: '/images/themes/sky.webp', swatch: '#42aabc' },
  { name: 'Golden', theme: 'gold', url: '/images/themes/gold.webp', swatch: '#c78c27' },
  { name: 'Crimson', theme: 'crimson', url: '/images/themes/crimson.png', swatch: '#ac193c' },
  { name: 'Pearl', theme: 'pearl', url: '/images/themes/pearl.webp', swatch: '#b59b55' },
];

type AppearanceContextValue = {
  index: number;
  paused: boolean;
  select: (index: number) => void;
  toggle: () => void;
};

const AppearanceContext = createContext<AppearanceContextValue>({ index: 0, paused: false, select: () => {}, toggle: () => {} });

export function useAppearance() {
  return useContext(AppearanceContext);
}

export function AppearanceProvider({ children }: { children: ReactNode }) {
  const [index, setIndex] = useState(() => {
    try {
      return readAppearanceIndex(localStorage.getItem(APPEARANCE_STORAGE_KEY), backgrounds.length);
    } catch {
      return 0;
    }
  });
  const [paused, setPaused] = useState(false);
  const [showMorning, setShowMorning] = useState(false);
  const [morningReady, setMorningReady] = useState(false);

  useEffect(() => {
    try {
      localStorage.setItem(APPEARANCE_STORAGE_KEY, String(index));
    } catch { /* Device storage may be unavailable. */ }
  }, [index]);

  useEffect(() => {
    const checkDay = () => {
      try {
        if (!shouldShowMorning(localStorage.getItem(MORNING_STORAGE_KEY))) return;
        localStorage.setItem(MORNING_STORAGE_KEY, nairobiDay());
      } catch { /* Device storage may be unavailable. */ }
      setMorningReady(false);
      setShowMorning(true);
    };
    checkDay();
    const onVisible = () => { if (document.visibilityState === 'visible') checkDay(); };
    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, []);

  useEffect(() => {
    if (!showMorning || !morningReady) return;
    const timer = window.setTimeout(() => setShowMorning(false), MORNING_DURATION_MS);
    return () => window.clearTimeout(timer);
  }, [showMorning, morningReady]);

  useEffect(() => {
    if (paused || showMorning) return;
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') setIndex(value => nextBackground(value, backgrounds.length));
    }, BACKGROUND_INTERVAL_MS);
    return () => window.clearInterval(timer);
  }, [paused, showMorning]);

  useEffect(() => {
    document.documentElement.dataset.appearance = backgrounds[index].theme;
    const meta = document.querySelector('meta[name="theme-color"]');
    meta?.setAttribute('content', getComputedStyle(document.documentElement).getPropertyValue('--brand').trim());
  }, [index]);

  const select = (nextIndex: number) => setIndex(Math.max(0, Math.min(nextIndex, backgrounds.length - 1)));

  return (
    <AppearanceContext.Provider value={{ index, paused, select, toggle: () => setPaused(value => !value) }}>
      <div className="floral-backdrop" aria-hidden="true">
        {backgrounds.map((background, i) => <img key={background.theme} src={background.url} alt="" className={i === index ? 'floral-frame is-visible' : 'floral-frame'} />)}
      </div>
      <div className="app-experience" inert={showMorning}>{children}</div>
      {showMorning && <div className="morning-screen" role="status" aria-label="Good morning">
        <img src={'/images/themes/morning.png'} alt="Good Morning! Be a whisper of love, a spark of joy, and a calm of peace." onLoad={() => setMorningReady(true)} onError={() => setShowMorning(false)} />
      </div>}
    </AppearanceContext.Provider>
  );
}

export function AppearanceControls() {
  const { index, paused, select, toggle } = useAppearance();
  return <div className="appearance-controls" aria-label="Background theme">
    {backgrounds.map((background, i) => <Button key={background.theme} variant="ghost" className={`theme-swatch swatch-${background.theme} ${index === i ? 'is-selected' : ''}`} aria-label={`${background.name} background`} aria-pressed={index === i} title={`${background.name} background`} onClick={() => select(i)}><span /></Button>)}
    <Button variant="ghost" className="theme-pause" onClick={toggle} aria-label={paused ? 'Resume backgrounds' : 'Pause backgrounds'} title={paused ? 'Resume backgrounds' : 'Pause backgrounds'}>{paused ? <Play size={13} /> : <Pause size={13} />}</Button>
  </div>;
}

export function AppearanceSettings() {
  const { index, paused, select, toggle } = useAppearance();
  return (
    <div className="appearance-settings-grid">
      {backgrounds.map((background, i) => (
        <button key={background.theme} type="button" className={`appearance-option ${index === i ? 'is-selected' : ''}`} aria-pressed={index === i} onClick={() => select(i)}>
          <img src={background.url} alt="" />
          <span className="appearance-option-overlay" />
          <span className="appearance-option-label">{background.name}</span>
          {index === i && <span className="appearance-option-check">Selected</span>}
        </button>
      ))}
      <Button type="button" variant="secondary" className="appearance-pause" onClick={toggle}>{paused ? <Play size={15} /> : <Pause size={15} />} {paused ? 'Resume carousel' : 'Pause carousel'}</Button>
    </div>
  );
}
