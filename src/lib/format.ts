export function formatCurrency(amount: number): string {
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency: 'KES',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount);
}

export function formatCurrencyShort(amount: number): string {
  if (amount >= 1_000_000) return `KES ${(amount / 1_000_000).toFixed(1)}M`;
  if (amount >= 1_000) return `KES ${(amount / 1_000).toFixed(0)}K`;
  return `KES ${amount}`;
}

export function formatDate(date: string | Date, format: 'short' | 'long' | 'relative' = 'short'): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  if (format === 'relative') return relativeTime(d);
  return d.toLocaleDateString('en-KE', {
    day: 'numeric',
    month: format === 'long' ? 'long' : 'short',
    year: 'numeric',
  });
}

export function formatDateTime(date: string | Date): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  return d.toLocaleDateString('en-KE', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function relativeTime(date: string | Date): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  const now = new Date();
  const diff = now.getTime() - d.getTime();
  const seconds = Math.floor(diff / 1000);
  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);

  if (seconds < 60) return 'Just now';
  if (minutes < 60) return `${minutes} min ago`;
  if (hours < 24) return `${hours} hr ago`;
  if (days < 7) return `${days} day${days > 1 ? 's' : ''} ago`;
  if (days < 30) return `${Math.floor(days / 7)} week${Math.floor(days / 7) > 1 ? 's' : ''} ago`;
  return formatDate(d);
}

export function initials(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((n) => n[0]?.toUpperCase())
    .join('');
}

export function classNames(...classes: (string | undefined | false | null)[]): string {
  return classes.filter(Boolean).join(' ');
}

export function statusColor(status: string): string {
  const map: Record<string, string> = {
    active: 'bg-success-100 text-success-700',
    confirmed: 'bg-success-100 text-success-700',
    approved: 'bg-success-100 text-success-700',
    paid: 'bg-success-100 text-success-700',
    published: 'bg-success-100 text-success-700',
    upcoming: 'bg-secondary-100 text-secondary-700',
    scheduled: 'bg-secondary-100 text-secondary-700',
    pending: 'bg-warning-100 text-warning-700',
    'pending_review': 'bg-warning-100 text-warning-700',
    draft: 'bg-neutral-100 text-neutral-600',
    unpaid: 'bg-error-100 text-error-700',
    failed: 'bg-error-100 text-error-700',
    rejected: 'bg-error-100 text-error-700',
    critical: 'bg-error-100 text-error-700',
    suspended: 'bg-error-100 text-error-700',
    cancelled: 'bg-error-100 text-error-700',
    expired: 'bg-neutral-100 text-neutral-600',
    inactive: 'bg-neutral-100 text-neutral-600',
    archived: 'bg-neutral-100 text-neutral-600',
    past: 'bg-neutral-100 text-neutral-600',
    warning: 'bg-warning-100 text-warning-700',
    healthy: 'bg-success-100 text-success-700',
    ongoing: 'bg-primary-100 text-primary-700',
    action: 'bg-error-100 text-error-700',
  };
  return map[status] || 'bg-neutral-100 text-neutral-600';
}

export function downloadCSV(filename: string, rows: Record<string, unknown>[]) {
  if (rows.length === 0) return;
  const headers = Object.keys(rows[0]);
  const csv = [
    headers.join(','),
    ...rows.map((row) =>
      headers.map((h) => {
        const val = row[h];
        const str = val === null || val === undefined ? '' : String(val);
        return `"${str.replace(/"/g, '""')}"`;
      }).join(','),
    ),
  ].join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
