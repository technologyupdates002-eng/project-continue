import { useState, type ReactNode } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Card, StatCard, Badge, Button, Input, Select, Modal, ConfirmDialog,
  LoadingState, ErrorState, EmptyState, PageHeader, Pagination,
  DataTable, SearchInput, SkeletonCard,
} from '@/components/ui';
import { formatCurrency, formatDate, formatDateTime, relativeTime, classNames, statusColor, downloadCSV } from '@/lib/format';
import type { LucideIcon } from 'lucide-react';
import {
  Coins, Wallet, FileCheck, FileText, Landmark, Scale, Receipt,
  RefreshCw, Upload, BarChart3, Users, AlertCircle, CreditCard,
  AlertTriangle, MessageSquare, Calendar, Newspaper, UserCheck,
  Settings, PenTool, CheckCircle, Megaphone, HeartHandshake,
  ClipboardList, BadgeCheck, Globe, FileEdit, Inbox, Image, Mail,
  LayoutDashboard, FileSearch, CalendarDays, Shield, Key, Lock,
  LogIn, Activity, Database, Server, Search, Wrench, ScrollText,
  Download, TrendingUp, SlidersHorizontal, Plus, Eye, CheckSquare,
  Trash2, Edit, ArrowLeft, User as UserIcon, Bell,
} from 'lucide-react';

// ---- Shared query keys helper ----
import { queryKeys } from '@/lib/query';
import { mockTreasurer, mockAdmin, mockWebsite, mockSecretary, mockChairperson, mockOffice, mockSuperAdmin, mockMember } from '@/api/mock';

// ============================================================
// Generic List Page — handles most list/table routes
// ============================================================
export interface ListPageConfig {
  title: string;
  subtitle?: string;
  icon: LucideIcon;
  fetchFn: (params: { page: number; search?: string; status?: string }) => Promise<{ data: any[]; current_page: number; last_page: number; total: number } | any[]>;
  queryKey: (params: Record<string, any>) => readonly unknown[];
  columns: { key: string; label: string; render?: (row: any) => ReactNode }[];
  searchPlaceholder?: string;
  statusFilters?: { value: string; label: string }[];
  rowAction?: (row: any) => void;
  rowActionLabel?: string;
  rowActionIcon?: LucideIcon;
  addLabel?: string;
  onAdd?: () => void;
  exportFilename?: string;
  stats?: { label: string; value: string; icon: LucideIcon; color?: 'primary' | 'secondary' | 'accent' | 'success' | 'warning' | 'error' }[];
}

export function GenericListPage({ config }: { config: ListPageConfig }) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: config.queryKey({ page, search, status: statusFilter }),
    queryFn: () => config.fetchFn({ page, search, status: statusFilter }),
  });

  const result = data as any;
  const items: any[] = result?.data || result || [];
  const currentPage = result?.current_page || 1;
  const lastPage = result?.last_page || 1;

  return (
    <div>
      <PageHeader
        title={config.title}
        subtitle={config.subtitle}
        icon={<config.icon size={20} />}
        action={config.addLabel && config.onAdd ? <Button onClick={config.onAdd}><Plus size={16} /> {config.addLabel}</Button> : undefined}
      />

      {config.stats && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4">
          {config.stats.map((stat, i) => (
            <StatCard key={i} label={stat.label} value={stat.value} icon={<stat.icon size={18} />} color={stat.color || 'primary'} />
          ))}
        </div>
      )}

      <Card className="p-4">
        <div className="flex flex-col sm:flex-row gap-3 mb-4">
          <div className="flex-1">
            <SearchInput value={search} onChange={setSearch} placeholder={config.searchPlaceholder || 'Search...'} />
          </div>
          {config.statusFilters && (
            <Select value={statusFilter} onChange={e => setStatusFilter(e.target.value)} className="sm:w-48">
              <option value="">All Status</option>
              {config.statusFilters.map(f => <option key={f.value} value={f.value}>{f.label}</option>)}
            </Select>
          )}
          {config.exportFilename && (
            <Button variant="secondary" onClick={() => downloadCSV(config.exportFilename!, items)}><Download size={16} /> Export</Button>
          )}
        </div>

        {isLoading && <LoadingState />}
        {isError && <ErrorState message="Failed to load data" onRetry={refetch} />}
        {!isLoading && !isError && items.length === 0 && <EmptyState icon={<config.icon size={24} />} title="No records found" message="Try adjusting your filters." />}
        {!isLoading && !isError && items.length > 0 && (
          <>
            <DataTable
              columns={config.columns}
              data={items}
              onRowClick={config.rowAction}
            />
            <Pagination currentPage={currentPage} lastPage={lastPage} onPage={setPage} />
          </>
        )}
      </Card>
    </div>
  );
}

// ============================================================
// Generic Dashboard Page — handles dashboard routes
// ============================================================
export function GenericDashboardPage({
  title, subtitle, icon: Icon, stats, sections,
}: {
  title: string;
  subtitle?: string;
  icon: LucideIcon;
  stats: { label: string; value: string | number; icon: LucideIcon; color?: 'primary' | 'secondary' | 'accent' | 'success' | 'warning' | 'error' }[];
  sections?: { title: string; content: ReactNode }[];
}) {
  return (
    <div>
      <PageHeader title={title} subtitle={subtitle} icon={<Icon size={20} />} />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        {stats.map((stat, i) => (
          <StatCard key={i} label={stat.label} value={stat.value} icon={<stat.icon size={18} />} color={stat.color || 'primary'} />
        ))}
      </div>
      {sections?.map((section, i) => (
        <Card key={i} className="p-5 mt-4">
          <h3 className="text-sm font-bold text-neutral-900 mb-3">{section.title}</h3>
          {section.content}
        </Card>
      ))}
    </div>
  );
}

// ============================================================
// Generic Detail Page
// ============================================================
export function GenericDetailPage({
  title, subtitle, icon: Icon, onBack, sections, actions,
}: {
  title: string;
  subtitle?: string;
  icon: LucideIcon;
  onBack?: () => void;
  sections: { label: string; value: ReactNode }[];
  actions?: ReactNode;
}) {
  return (
    <div>
      <PageHeader title={title} subtitle={subtitle} icon={<Icon size={20} />} action={actions} />
      {onBack && <button onClick={onBack} className="inline-flex items-center gap-1.5 text-sm text-primary-700 font-medium mb-4"><ArrowLeft size={16} /> Back</button>}
      <Card className="p-5">
        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {sections.map((s, i) => (
            <div key={i}>
              <dt className="text-xs font-medium text-neutral-400">{s.label}</dt>
              <dd className="mt-0.5 text-sm font-semibold text-neutral-800">{s.value}</dd>
            </div>
          ))}
        </dl>
      </Card>
    </div>
  );
}

// ============================================================
// Generic Import Page — handles import routes
// ============================================================
export function ImportPage({ title, icon: Icon, accept, onImport }: {
  title: string;
  icon: LucideIcon;
  accept?: string;
  onImport: (file: File) => Promise<{ message: string; imported: number }>;
}) {
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<{ message: string; imported: number } | null>(null);
  const mutation = useMutation({ mutationFn: (f: File) => onImport(f), onSuccess: setResult });

  return (
    <div>
      <PageHeader title={title} subtitle="Upload Excel or CSV file to import data" icon={<Icon size={20} />} />
      <Card className="p-6">
        <div className="border-2 border-dashed border-neutral-200 rounded-xl p-8 text-center">
          <Upload size={32} className="mx-auto text-neutral-300 mb-3" />
          <p className="text-sm text-neutral-500 mb-4">Drag and drop your file here, or click to browse</p>
          <input
            type="file"
            accept={accept || '.xlsx,.xls,.csv'}
            onChange={e => { const f = e.target.files?.[0]; if (f) { setFile(f); setResult(null); } }}
            className="hidden"
            id="file-upload"
          />
          <label htmlFor="file-upload" className="btn-secondary cursor-pointer inline-flex">Choose File</label>
          {file && <p className="mt-3 text-sm font-medium text-neutral-700">{file.name}</p>}
        </div>
        {file && (
          <Button className="w-full mt-4" onClick={() => mutation.mutate(file)} disabled={mutation.isPending}>
            {mutation.isPending ? 'Importing...' : 'Start Import'}
          </Button>
        )}
        {result && (
          <div className="mt-4 p-4 rounded-xl bg-success-50 border border-success-200 text-sm text-success-700">
            {result.message} — {result.imported} records imported.
          </div>
        )}
        {mutation.isError && (
          <div className="mt-4 p-4 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
            Import failed. Please check the file format and try again.
          </div>
        )}
      </Card>
    </div>
  );
}

// ============================================================
// Generic Form Page — for settings, create/edit forms
// ============================================================
export function GenericFormPage({ title, subtitle, icon: Icon, fields, onSubmit, submitLabel = 'Save' }: {
  title: string;
  subtitle?: string;
  icon: LucideIcon;
  fields: { key: string; label: string; type?: string; value: string; options?: { value: string; label: string }[]; required?: boolean }[];
  onSubmit: (data: Record<string, string>) => Promise<void>;
  submitLabel?: string;
}) {
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(fields.map(f => [f.key, f.value])));
  const [saved, setSaved] = useState(false);
  const mutation = useMutation({ mutationFn: (data: Record<string, string>) => onSubmit(data), onSuccess: () => setSaved(true) });

  return (
    <div>
      <PageHeader title={title} subtitle={subtitle} icon={<Icon size={20} />} />
      <Card className="p-5">
        {saved && <div className="mb-4 p-3 rounded-xl bg-success-50 border border-success-200 text-sm text-success-700">Saved successfully.</div>}
        <form onSubmit={e => { e.preventDefault(); mutation.mutate(values); }} className="space-y-4">
          {fields.map(field => (
            <div key={field.key}>
              {field.type === 'textarea' ? (
                <div>
                  <label className="label">{field.label}</label>
                  <textarea className="input-field" rows={4} value={values[field.key] || ''} onChange={e => setValues({ ...values, [field.key]: e.target.value })} required={field.required} />
                </div>
              ) : field.options ? (
                <Select label={field.label} value={values[field.key] || ''} onChange={e => setValues({ ...values, [field.key]: e.target.value })} required={field.required}>
                  <option value="">Select...</option>
                  {field.options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </Select>
              ) : (
                <Input label={field.label} type={field.type || 'text'} value={values[field.key] || ''} onChange={e => setValues({ ...values, [field.key]: e.target.value })} required={field.required} />
              )}
            </div>
          ))}
          <Button type="submit" size="lg" disabled={mutation.isPending}>{mutation.isPending ? 'Saving...' : submitLabel}</Button>
        </form>
      </Card>
    </div>
  );
}
