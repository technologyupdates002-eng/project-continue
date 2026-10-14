import type { ReactNode } from 'react';
import { classNames, statusColor } from '@/lib/format';

export function Card({ children, className, elevated }: { children: ReactNode; className?: string; elevated?: boolean }) {
  return (
    <div className={classNames(elevated ? 'card-elevated' : 'card', className)}>
      {children}
    </div>
  );
}

export function StatCard({
  label, value, icon, trend, color = 'primary',
}: {
  label: string; value: string | number; icon?: ReactNode;
  trend?: { value: string; positive: boolean }; color?: 'primary' | 'secondary' | 'accent' | 'success' | 'warning' | 'error';
}) {
  const colorMap = {
    primary: 'bg-primary-50 text-primary-700',
    secondary: 'bg-secondary-50 text-secondary-700',
    accent: 'bg-accent-50 text-accent-700',
    success: 'bg-success-50 text-success-700',
    warning: 'bg-warning-50 text-warning-700',
    error: 'bg-error-50 text-error-700',
  };
  return (
    <Card className="stat-card p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-medium text-neutral-500">{label}</p>
          <p className="mt-1 text-xl sm:text-2xl font-bold text-neutral-900 tracking-tight">{value}</p>
          {trend && (
            <p className={classNames('mt-1 text-xs font-medium', trend.positive ? 'text-success-600' : 'text-error-600')}>
              {trend.positive ? '↑' : '↓'} {trend.value}
            </p>
          )}
        </div>
        {icon && (
          <div className={classNames('flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center', colorMap[color])}>
            {icon}
          </div>
        )}
      </div>
    </Card>
  );
}

export function Badge({ status, children, className }: { status?: string; children: ReactNode; className?: string }) {
  return (
    <span className={classNames('badge', status ? statusColor(status) : 'bg-neutral-100 text-neutral-600', className)}>
      {children}
    </span>
  );
}

export function Button({
  children, variant = 'primary', size = 'md', className, ...props
}: {
  children: ReactNode;
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  size?: 'sm' | 'md' | 'lg';
} & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const variantClass = {
    primary: 'btn-primary',
    secondary: 'btn-secondary',
    ghost: 'btn-ghost',
    danger: 'btn-danger',
  }[variant];
  const sizeClass = {
    sm: 'text-xs px-3 py-1.5',
    md: '',
    lg: 'text-base px-5 py-3',
  }[size];
  return (
    <button className={classNames(variantClass, sizeClass, className)} {...props}>
      {children}
    </button>
  );
}

export function Input({ label, error, className, ...props }: { label?: string; error?: string } & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div>
      {label && <label className="label">{label}</label>}
      <input className={classNames('input-field', error && 'border-error-300 focus:border-error-500 focus:ring-error-500/20', className)} {...props} />
      {error && <p className="mt-1 text-xs text-error-600">{error}</p>}
    </div>
  );
}

export function Select({ label, error, className, children, ...props }: { label?: string; error?: string } & React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <div>
      {label && <label className="label">{label}</label>}
      <select className={classNames('input-field', error && 'border-error-300', className)} {...props}>
        {children}
      </select>
      {error && <p className="mt-1 text-xs text-error-600">{error}</p>}
    </div>
  );
}

export function Textarea({ label, error, className, ...props }: { label?: string; error?: string } & React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <div>
      {label && <label className="label">{label}</label>}
      <textarea className={classNames('input-field', error && 'border-error-300', className)} {...props} />
      {error && <p className="mt-1 text-xs text-error-600">{error}</p>}
    </div>
  );
}

export function Avatar({ name, src, size = 'md' }: { name: string; src?: string; size?: 'sm' | 'md' | 'lg' | 'xl' }) {
  const sizes = {
    sm: 'w-8 h-8 text-xs',
    md: 'w-10 h-10 text-sm',
    lg: 'w-12 h-12 text-base',
    xl: 'w-20 h-20 text-2xl',
  };
  if (src) {
    return <img src={src} alt={name} className={classNames('rounded-full object-cover', sizes[size])} />;
  }
  return (
    <div className={classNames('rounded-full bg-primary-50 text-primary-700 font-semibold flex items-center justify-center', sizes[size])}>
      {name.split(' ').filter(Boolean).slice(0, 2).map(n => n[0]?.toUpperCase()).join('')}
    </div>
  );
}

export function EmptyState({ icon, title, message, action }: { icon?: ReactNode; title: string; message?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center py-12 px-4 text-center">
      {icon && <div className="w-14 h-14 rounded-lg bg-neutral-100 flex items-center justify-center text-neutral-400 mb-4">{icon}</div>}
      <h3 className="text-sm font-semibold text-neutral-700">{title}</h3>
      {message && <p className="mt-1 text-sm text-neutral-500 max-w-sm">{message}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function LoadingState({ text = 'Loading...' }: { text?: string }) {
  return (
    <div className="flex items-center justify-center py-12">
      <div className="flex items-center gap-3 text-neutral-400">
        <div className="w-5 h-5 border-2 border-neutral-200 border-t-primary-500 rounded-full animate-spin" />
        <span className="text-sm">{text}</span>
      </div>
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center py-12 px-4 text-center">
      <div className="w-14 h-14 rounded-lg bg-error-50 flex items-center justify-center text-error-500 mb-4">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <h3 className="text-sm font-semibold text-neutral-700">Something went wrong</h3>
      <p className="mt-1 text-sm text-neutral-500 max-w-sm">{message}</p>
      {onRetry && <button onClick={onRetry} className="btn-secondary mt-4">Try again</button>}
    </div>
  );
}

export function SkeletonCard() {
  return (
    <div className="card p-5">
      <div className="skeleton h-4 w-24 rounded mb-3" />
      <div className="skeleton h-8 w-32 rounded mb-2" />
      <div className="skeleton h-3 w-20 rounded" />
    </div>
  );
}

export function SkeletonRow() {
  return (
    <div className="flex items-center gap-3 py-3">
      <div className="skeleton w-10 h-10 rounded-full" />
      <div className="flex-1 space-y-2">
        <div className="skeleton h-4 w-3/4 rounded" />
        <div className="skeleton h-3 w-1/2 rounded" />
      </div>
      <div className="skeleton h-6 w-16 rounded-full" />
    </div>
  );
}

export function PageHeader({ title, subtitle, action, icon }: { title: string; subtitle?: string; action?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="page-heading flex flex-wrap items-start justify-between gap-4 mb-5">
      <div className="flex items-start gap-3 min-w-0">
        {icon && <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center">{icon}</div>}
        <div className="min-w-0">
          <h1 className="text-lg sm:text-xl font-bold text-neutral-900">{title}</h1>
          {subtitle && <p className="mt-0.5 text-sm text-neutral-500">{subtitle}</p>}
        </div>
      </div>
      {action && <div className="flex-shrink-0">{action}</div>}
    </div>
  );
}

export function Pagination({ currentPage, lastPage, onPage }: { currentPage: number; lastPage: number; onPage: (page: number) => void }) {
  if (lastPage <= 1) return null;
  return (
    <div className="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
      <p className="text-xs text-neutral-500">Page {currentPage} of {lastPage}</p>
      <div className="flex gap-2">
        <button disabled={currentPage <= 1} onClick={() => onPage(currentPage - 1)} className="btn-secondary text-xs px-3 py-1.5 disabled:opacity-40">Previous</button>
        <button disabled={currentPage >= lastPage} onClick={() => onPage(currentPage + 1)} className="btn-secondary text-xs px-3 py-1.5 disabled:opacity-40">Next</button>
      </div>
    </div>
  );
}

export function Modal({ open, onClose, title, children, size = 'md' }: { open: boolean; onClose: () => void; title: string; children: ReactNode; size?: 'sm' | 'md' | 'lg' | 'xl' }) {
  if (!open) return null;
  const sizes = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };
  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4 animate-fade-in" onClick={onClose}>
      <div className="absolute inset-0 bg-neutral-950/50 backdrop-blur-sm" />
      <div className={classNames('relative bg-white rounded-t-n26-card sm:rounded-n26-card shadow-float-lg w-full max-h-[90vh] overflow-y-auto animate-slide-up', sizes[size])} onClick={e => e.stopPropagation()}>
        <div className="sticky top-0 bg-white border-b border-neutral-100 px-5 py-4 flex items-center justify-between rounded-t-n26-card">
          <h2 className="text-base font-bold text-neutral-900">{title}</h2>
          <button onClick={onClose} className="btn-ghost p-1.5 -mr-1" aria-label="Close">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}

export function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel = 'Confirm', danger }: { open: boolean; onClose: () => void; onConfirm: () => void; title: string; message: string; confirmLabel?: string; danger?: boolean }) {
  return (
    <Modal open={open} onClose={onClose} title={title} size="sm">
      <p className="text-sm text-neutral-600">{message}</p>
      <div className="flex justify-end gap-3 mt-6">
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button variant={danger ? 'danger' : 'primary'} onClick={() => { onConfirm(); onClose(); }}>{confirmLabel}</Button>
      </div>
    </Modal>
  );
}

export function Tabs({ tabs, active, onChange }: { tabs: { key: string; label: string; count?: number }[]; active: string; onChange: (key: string) => void }) {
  return (
    <div className="flex gap-1 border-b border-neutral-200 overflow-x-auto scrollbar-hide">
      {tabs.map(tab => (
        <button
          key={tab.key}
          onClick={() => onChange(tab.key)}
          className={classNames(
            'px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors',
            active === tab.key ? 'border-primary-500 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700',
          )}
        >
          {tab.label}
          {tab.count !== undefined && <span className="ml-1.5 text-xs text-neutral-400">{tab.count}</span>}
        </button>
      ))}
    </div>
  );
}

export function SearchInput({ value, onChange, placeholder = 'Search...' }: { value: string; onChange: (v: string) => void; placeholder?: string }) {
  return (
    <div className="relative">
      <svg className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input
        type="text"
        value={value}
        onChange={e => onChange(e.target.value)}
        placeholder={placeholder}
        className="input-field pl-9"
      />
    </div>
  );
}

export function DataTable<T>({ columns, data, emptyMessage, onRowClick }: {
  columns: { key: string; label: string; render?: (row: T) => ReactNode; className?: string }[];
  data: T[];
  emptyMessage?: string;
  onRowClick?: (row: T) => void;
}) {
  if (data.length === 0) {
    return <EmptyState title={emptyMessage || 'No data available'} />;
  }
  return (
    <div className="overflow-x-auto -mx-4 sm:mx-0">
      <table className="w-full min-w-[600px]">
        <thead>
          <tr className="border-b border-neutral-100">
            {columns.map(col => (
              <th key={col.key} className={classNames('text-left py-2.5 px-3 text-xs font-semibold text-neutral-500 uppercase tracking-wider', col.className)}>
                {col.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((row, i) => (
            <tr
              key={i}
              onClick={() => onRowClick?.(row)}
              className={classNames('border-b border-neutral-50 transition-colors', onRowClick && 'cursor-pointer hover:bg-neutral-50')}
            >
              {columns.map(col => (
                <td key={col.key} className={classNames('py-3 px-3 text-sm text-neutral-700', col.className)}>
                  {col.render ? col.render(row) : String((row as Record<string, unknown>)[col.key] ?? '')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
