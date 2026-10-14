import { useState } from 'react';

// ─── Area + Line Chart ───────────────────────────────────────────────────────

interface LinePoint { label: string; value: number }
interface LineSeries { points: LinePoint[]; color: string; label: string; fill?: string }

export function LineChart({ series, height = 200 }: { series: LineSeries[]; height?: number }) {
  const [hover, setHover] = useState<number | null>(null);
  if (!series.length || !series[0].points.length) return null;

  const W = 600; const H = height; const PL = 52; const PR = 16; const PT = 16; const PB = 36;
  const innerW = W - PL - PR; const innerH = H - PT - PB;
  const labels = series[0].points.map(p => p.label);
  const allVals = series.flatMap(s => s.points.map(p => p.value));
  const maxV = Math.max(...allVals, 1);
  const nMax = Math.ceil(maxV / 5) * 5;
  const rng = nMax || 1;

  const xStep = innerW / Math.max(labels.length - 1, 1);
  const toX = (i: number) => PL + i * xStep;
  const toY = (v: number) => PT + innerH - (v / rng) * innerH;

  const yTicks = 5;
  const yTickVals = Array.from({ length: yTicks + 1 }, (_, i) => (nMax / yTicks) * i);

  const fmt = (v: number) =>
    v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)}M` :
    v >= 1_000 ? `${(v / 1_000).toFixed(0)}K` : `${v}`;

  // smooth path via catmull-rom → bezier
  function smoothPath(pts: { x: number; y: number }[]): string {
    if (pts.length < 2) return '';
    let d = `M${pts[0].x},${pts[0].y}`;
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[i === 0 ? 0 : i - 1];
      const p1 = pts[i];
      const p2 = pts[i + 1];
      const p3 = pts[i + 2 < pts.length ? i + 2 : pts.length - 1];
      const cp1x = p1.x + (p2.x - p0.x) / 6;
      const cp1y = p1.y + (p2.y - p0.y) / 6;
      const cp2x = p2.x - (p3.x - p1.x) / 6;
      const cp2y = p2.y - (p3.y - p1.y) / 6;
      d += ` C${cp1x},${cp1y} ${cp2x},${cp2y} ${p2.x},${p2.y}`;
    }
    return d;
  }

  const gid = (i: number) => `area-grad-${i}`;

  return (
    <div className="relative">
      <svg
        viewBox={`0  0 ${W} ${H}`}
        className="w-full"
        style={{ height }}
        onMouseLeave={() => setHover(null)}
      >
        <defs>
          {series.map((s, i) => (
            <linearGradient key={i} id={gid(i)} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={s.color} stopOpacity="0.18" />
              <stop offset="100%" stopColor={s.color} stopOpacity="0" />
            </linearGradient>
          ))}
        </defs>

        {/* Grid */}
        {yTickVals.map((v, i) => {
          const y = toY(v);
          return (
            <g key={i}>
              <line x1={PL} y1={y} x2={W - PR} y2={y} stroke="#f1f5f9" strokeWidth="1" />
              <text x={PL - 6} y={y + 3.5} fontSize="9" fill="#94a3b8" textAnchor="end" fontFamily="Inter, sans-serif">
                {fmt(v)}
              </text>
            </g>
          );
        })}

        {/* X labels */}
        {labels.map((l, i) => (
          <text
            key={i}
            x={toX(i)}
            y={H - 8}
            fontSize="9.5"
            fill={hover === i ? '#1e293b' : '#94a3b8'}
            textAnchor="middle"
            fontFamily="Inter, sans-serif"
            fontWeight={hover === i ? 600 : 400}
          >
            {l}
          </text>
        ))}

        {/* Series */}
        {series.map((s, si) => {
          const pts = s.points.map((p, i) => ({ x: toX(i), y: toY(p.value) }));
          const linePath = smoothPath(pts);
          const areaPath = `${linePath} L${pts[pts.length - 1].x},${toY(0)} L${pts[0].x},${toY(0)} Z`;
          return (
            <g key={si}>
              <path d={areaPath} fill={`url(#${gid(si)})`} />
              <path d={linePath} fill="none" stroke={s.color} strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
              {pts.map((p, i) => (
                <g key={i}>
                  {hover === i && (
                    <circle cx={p.x} cy={p.y} r="6" fill={s.color} fillOpacity="0.15" />
                  )}
                  <circle
                    cx={p.x}
                    cy={p.y}
                    r={hover === i ? 4.5 : 3}
                    fill="white"
                    stroke={s.color}
                    strokeWidth={hover === i ? 2.5 : 2}
                    style={{ transition: 'r 0.15s' }}
                  />
                </g>
              ))}
            </g>
          );
        })}

        {/* Hover guide */}
        {hover !== null && (
          <line
            x1={toX(hover)}
            y1={PT}
            x2={toX(hover)}
            y2={PT + innerH}
            stroke="#cbd5e1"
            strokeWidth="1"
            strokeDasharray="4 3"
          />
        )}

        {/* Hover hit areas */}
        {labels.map((_, i) => (
          <rect
            key={i}
            x={toX(i) - xStep / 2}
            y={0}
            width={xStep}
            height={H}
            fill="transparent"
            onMouseEnter={() => setHover(i)}
          />
        ))}
      </svg>

      {/* Tooltip */}
      {hover !== null && (
        <div
          className="absolute pointer-events-none z-10 rounded-lg bg-neutral-900 text-white text-xs px-3 py-2 shadow-lg whitespace-nowrap"
          style={{
            left: `${((toX(hover) - PL) / innerW) * 100}%`,
            top: 4,
            transform: 'translateX(-50%)',
          }}
        >
          <p className="font-semibold text-[10px] text-neutral-400 mb-1">{labels[hover]}</p>
          {series.map((s, i) => (
            <p key={i} className="flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full" style={{ background: s.color }} />
              <span className="text-neutral-300">{s.label}:</span>
              <span className="font-semibold">{fmt(s.points[hover].value)}</span>
            </p>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Donut Chart ──────────────────────────────────────────────────────────────

interface DonutSegment { label: string; value: number; color: string }

export function DonutChart({ segments, centerLabel, centerSub }: {
  segments: DonutSegment[]; centerLabel: string; centerSub?: string;
}) {
  const [hoverIdx, setHoverIdx] = useState<number | null>(null);

  const size = 200; const cx = 100; const cy = 100; const R = 78; const r = 54;
  const total = segments.reduce((s, seg) => s + seg.value, 0) || 1;
  const gap = 0.02;

  let start = -Math.PI / 2;
  const arcs = segments.map((seg, i) => {
    const angle = (seg.value / total) * 2 * Math.PI - gap;
    const end = start + angle;
    const x1 = cx + R * Math.cos(start); const y1 = cy + R * Math.sin(start);
    const x2 = cx + R * Math.cos(end);   const y2 = cy + R * Math.sin(end);
    const ix1 = cx + r * Math.cos(start); const iy1 = cy + r * Math.sin(start);
    const ix2 = cx + r * Math.cos(end);   const iy2 = cy + r * Math.sin(end);
    const large = angle > Math.PI ? 1 : 0;
    const d = `M${x1},${y1} A${R},${R},0,${large},1,${x2},${y2} L${ix2},${iy2} A${r},${r},0,${large},0,${ix1},${iy1} Z`;
    start = end + gap;
    return { ...seg, d, idx: i };
  });

  return (
    <svg viewBox={`0 0 ${size} ${size}`} className="w-full max-w-[200px]" style={{ height: size }}>
      <defs>
        <filter id="donut-shadow" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="2" stdDeviation="3" floodOpacity="0.08" />
        </filter>
      </defs>
      <g filter="url(#donut-shadow)">
        {arcs.map(a => (
          <path
            key={a.idx}
            d={a.d}
            fill={a.color}
            opacity={hoverIdx === null || hoverIdx === a.idx ? 1 : 0.4}
            style={{ transition: 'opacity 0.2s' }}
            onMouseEnter={() => setHoverIdx(a.idx)}
            onMouseLeave={() => setHoverIdx(null)}
          />
        ))}
      </g>
      <text x={cx} y={cy - 6} textAnchor="middle" fontSize="22" fontWeight="700" fill="#1e293b" fontFamily="Inter, sans-serif">
        {hoverIdx !== null ? segments[hoverIdx].value.toLocaleString() : centerLabel}
      </text>
      {centerSub && (
        <text x={cx} y={cy + 12} textAnchor="middle" fontSize="9.5" fill="#94a3b8" fontFamily="Inter, sans-serif">
          {hoverIdx !== null ? segments[hoverIdx].label : centerSub}
        </text>
      )}
    </svg>
  );
}

// ─── Mini Bar Chart (sparkline-style) ─────────────────────────────────────────

export function MiniBars({ data, color = '#088177', height = 40 }: { data: number[]; color?: string; height?: number }) {
  const max = Math.max(...data, 1);
  const w = 100; const bw = w / data.length - 2;
  return (
    <svg viewBox={`0 0 ${w} ${height}`} className="w-full" style={{ height }} preserveAspectRatio="none">
      {data.map((v, i) => {
        const h = (v / max) * height;
        return (
          <rect
            key={i}
            x={i * (bw + 2)}
            y={height - h}
            width={bw}
            height={h}
            rx={1.5}
            fill={color}
            opacity={0.3 + (i / data.length) * 0.7}
          />
        );
      })}
    </svg>
  );
}

// ─── Progress Ring ─────────────────────────────────────────────────────────────

export function ProgressRing({ value, size = 56, stroke = 5, color = '#088177', label }: {
  value: number; size?: number; stroke?: number; color?: string; label?: string;
}) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const offset = c - (value / 100) * c;
  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#f1f5f9" strokeWidth={stroke} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={color}
          strokeWidth={stroke}
          strokeDasharray={c}
          strokeDashoffset={offset}
          strokeLinecap="round"
          style={{ transition: 'stroke-dashoffset 0.6s ease' }}
        />
      </svg>
      <span className="absolute text-xs font-bold text-neutral-800">{label ?? `${value}%`}</span>
    </div>
  );
}
