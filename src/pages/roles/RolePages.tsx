import { useState, useMemo, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from '@/lib/router-compat';
import {
  LayoutDashboard, FileSearch, FileText, CalendarDays, Plus, CheckCircle,
  XCircle, Clock, Calendar, PenTool, FileCheck, Upload, Trash2, Edit,
  Eye, Send, AlertTriangle, Users, Search, ArrowRight, TrendingUp,
  TrendingDown, Bell, Printer, FileX,
} from 'lucide-react';
import { mockSecretary, mockChairperson, mockOffice, mockViceSecretary } from '@/api/mock';
import { minutesApi, type HydratedMinute, type MinuteAttendance, type MinuteMember, type CreateMinutePayload, type MinuteStatus } from '@/api/minutes';
import { queryKeys } from '@/lib/query';
import {
  Card, PageHeader, Badge, Button, LoadingState, ErrorState,
  EmptyState, Modal, Input, Textarea, Select, ConfirmDialog,
} from '@/components/ui';
import { formatDate, formatDateTime, relativeTime, classNames, formatCurrency, initials } from '@/lib/format';
import { useAuth } from '@/auth/AuthContext';

// ─── Status helpers ──────────────────────────────────────────────────────────
const STATUS_LABELS: Record<MinuteStatus, string> = {
  draft: 'Draft',
  submitted_to_secretary: 'Submitted to Secretary',
  pending_chairperson_approval: 'Pending Chairperson',
  rejected_by_secretary: 'Returned by Secretary',
  rejected: 'Rejected by Chairperson',
  approved: 'Approved',
};

const STATUS_BADGE: Record<MinuteStatus, string> = {
  draft: 'draft',
  submitted_to_secretary: 'pending',
  pending_chairperson_approval: 'pending',
  rejected_by_secretary: 'failed',
  rejected: 'failed',
  approved: 'confirmed',
};

function statusLabel(s: string): string {
  return STATUS_LABELS[s as MinuteStatus] || s.replace(/_/g, ' ');
}
function statusBadge(s: string): string {
  return STATUS_BADGE[s as MinuteStatus] || 'draft';
}

// ─── Attendance bucket ────────────────────────────────────────────────────────
type AttendanceBucket = 'PRESENT' | 'ABSENT_WITH_APOLOGY' | 'ABSENT';

const BUCKET_LABELS: Record<AttendanceBucket, string> = {
  PRESENT: 'Present',
  ABSENT_WITH_APOLOGY: 'Absent with Apology',
  ABSENT: 'Absent without Apology',
};

const BUCKET_COLORS: Record<AttendanceBucket, string> = {
  PRESENT: 'bg-success-50 text-success-700 border-success-200',
  ABSENT_WITH_APOLOGY: 'bg-warning-50 text-warning-700 border-warning-200',
  ABSENT: 'bg-error-50 text-error-700 border-error-200',
};

// ─── Shared premium KPI card ──────────────────────────────────────────────────
function KpiCard({ label, value, icon: Icon, accent, delta, positive }: {
  label: string; value: string | number; icon: typeof Users;
  accent: string; delta?: string; positive?: boolean;
}) {
  return (
    <div className="group relative bg-white rounded-n26-card border border-neutral-100 p-5 transition-all hover:shadow-float hover:border-neutral-200">
      <div className="flex items-start justify-between mb-4">
        <div className={classNames('w-10 h-10 rounded-xl flex items-center justify-center', accent)}>
          <Icon size={20} />
        </div>
        {delta && (
          <span className={classNames(
            'flex items-center gap-0.5 text-xs font-semibold rounded-full px-2 py-0.5',
            positive ? 'bg-success-50 text-success-700' : 'bg-error-50 text-error-700',
          )}>
            {positive ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
            {delta}
          </span>
        )}
      </div>
      <p className="text-2xl font-bold text-neutral-900 tracking-tight">{value}</p>
      <p className="mt-1 text-sm text-neutral-500">{label}</p>
    </div>
  );
}

// ─── Section header ───────────────────────────────────────────────────────────
function SectionHeader({ title, action }: { title: string; action?: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between mb-4">
      <h3 className="text-base font-bold text-neutral-900">{title}</h3>
      {action}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SECRETARY DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════
export function SecretaryDashboard() {
  const { user } = useAuth();
  const { data, isLoading } = useQuery({
    queryKey: queryKeys.secretaryDashboard,
    queryFn: () => mockSecretary.dashboard(),
  });
  if (isLoading) return <LoadingState />;
  if (!data) return <ErrorState message="Unable to load records." />;
  const d = data;

  const greeting = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  })();

  const quickActions = [
    { label: 'New Minutes', icon: FileText, to: '/secretary/meeting-minutes', accent: 'bg-primary-50 text-primary-600' },
    { label: 'Review Queue', icon: FileSearch, to: '/secretary/minutes-review', accent: 'bg-warning-50 text-warning-600' },
    { label: 'Schedule Meeting', icon: CalendarDays, to: '/secretary/meeting-scheduling', accent: 'bg-secondary-50 text-secondary-600' },
    { label: 'Upload Signature', icon: PenTool, to: '/secretary/signature', accent: 'bg-accent-50 text-accent-600' },
  ];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{greeting}, {user?.name?.split(' ')[0] ?? 'Secretary'}</h1>
          <p className="mt-1 text-sm text-neutral-500">Manage minutes, reviews, and meeting schedules.</p>
        </div>
        <div className="flex items-center gap-3">
          <button className="relative p-2.5 rounded-xl border border-neutral-200 bg-white hover:bg-neutral-50 transition-colors">
            <Bell size={18} className="text-neutral-500" />
            {d.pending_minutes > 0 && <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-error-500 rounded-full ring-2 ring-white" />}
          </button>
          <div className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2">
            <div className="w-9 h-9 rounded-full bg-primary-500 text-white text-sm font-bold flex items-center justify-center">
              {initials(user?.name ?? 'SC')}
            </div>
            <div className="hidden sm:block">
              <p className="text-sm font-semibold text-neutral-800">{user?.name ?? 'Secretary'}</p>
              <p className="text-xs text-neutral-400">Secretary</p>
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard label="Pending Review" value={d.pending_minutes} icon={Clock} accent="bg-warning-50 text-warning-600" delta="Action" positive={false} />
        <KpiCard label="Approved" value={d.approved_minutes} icon={CheckCircle} accent="bg-success-50 text-success-600" />
        <KpiCard label="Upcoming Meetings" value={d.upcoming_meetings} icon={CalendarDays} accent="bg-secondary-50 text-secondary-600" />
        <KpiCard label="Total Minutes" value={d.total_minutes} icon={FileText} accent="bg-primary-50 text-primary-600" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-6">
          <SectionHeader title="Quick Actions" />
          <div className="grid grid-cols-2 gap-3">
            {quickActions.map(a => (
              <Link key={a.label} to={a.to} className="group flex flex-col items-start gap-2 p-3.5 rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-float transition-all">
                <div className={classNames('w-9 h-9 rounded-lg flex items-center justify-center', a.accent)}>
                  <a.icon size={17} />
                </div>
                <span className="text-xs font-medium text-neutral-700 group-hover:text-neutral-900">{a.label}</span>
              </Link>
            ))}
          </div>
        </Card>

        <Card className="p-6 lg:col-span-2">
          <SectionHeader title="Recent Minutes" action={
            <Link to="/secretary/meeting-minutes" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          } />
          <div className="space-y-1">
            {d.recent_minutes.map((m: any) => (
              <div key={m.id} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-neutral-800 truncate">{m.title}</p>
                  <p className="text-xs text-neutral-400 mt-0.5">{formatDate(m.meeting_date)} · {m.attendees} attendees</p>
                </div>
                <Badge status={statusBadge(m.status)}>{statusLabel(m.status)}</Badge>
              </div>
            ))}
          </div>
        </Card>
      </div>

      {d.upcoming && d.upcoming.length > 0 && (
        <Card className="p-6">
          <SectionHeader title="Upcoming Meetings" action={
            <Link to="/secretary/meeting-scheduling" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          } />
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {d.upcoming.slice(0, 3).map((m: any) => (
              <div key={m.id} className="p-4 rounded-xl border border-neutral-100 hover:border-neutral-200 transition-colors">
                <div className="flex items-center gap-2 mb-2">
                  <div className="w-8 h-8 rounded-lg bg-primary-50 flex items-center justify-center">
                    <CalendarDays size={16} className="text-primary-600" />
                  </div>
                  <span className="text-xs text-neutral-400">{formatDate(m.date)}</span>
                </div>
                <p className="text-sm font-semibold text-neutral-800">{m.title}</p>
                <p className="text-xs text-neutral-400 mt-0.5">{m.time} · {m.location}</p>
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MINUTES AUTHORING (shared by Secretary and Vice Secretary)
// ═══════════════════════════════════════════════════════════════════════════════
interface MinutesAuthoringProps {
  role: 'secretary' | 'vice-secretary';
  title: string;
  subtitle: string;
  queryKey: readonly unknown[];
  fetchFn: () => Promise<any[]>;
  createFn: (data: CreateMinutePayload) => Promise<any>;
  updateFn: (id: string, data: Partial<CreateMinutePayload>) => Promise<any>;
  deleteFn: (id: string) => Promise<any>;
  submitFn: (id: string) => Promise<any>;
}

export function MinutesAuthoring({
  role, title, subtitle, queryKey, fetchFn, createFn, updateFn, deleteFn, submitFn,
}: MinutesAuthoringProps) {
  const qc = useQueryClient();
  const { user } = useAuth();
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [viewingMinute, setViewingMinute] = useState<any | null>(null);
  const [deleteId, setDeleteId] = useState<string | null>(null);
  const [submitId, setSubmitId] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({ queryKey, queryFn: fetchFn });

  const createMut = useMutation({
    mutationFn: (payload: CreateMinutePayload) => createFn(payload),
    onSuccess: () => { qc.invalidateQueries({ queryKey }); setShowForm(false); },
  });

  const updateMut = useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<CreateMinutePayload> }) => updateFn(id, data),
    onSuccess: () => { qc.invalidateQueries({ queryKey }); setShowForm(false); setEditingId(null); },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => deleteFn(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey }); setDeleteId(null); },
  });

  const submitMut = useMutation({
    mutationFn: (id: string) => submitFn(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey });
      qc.invalidateQueries({ queryKey: queryKeys.secretaryMinutesReview });
      setSubmitId(null);
    },
  });

  const items = data || [];

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load minutes" onRetry={refetch} />;

  const draftCount = items.filter((m: any) => m.status === 'draft' || m.status === 'rejected_by_secretary' || m.status === 'rejected').length;
  const submittedCount = items.filter((m: any) => m.status === 'submitted_to_secretary' || m.status === 'pending_chairperson_approval').length;
  const approvedCount = items.filter((m: any) => m.status === 'approved').length;

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{title}</h1>
          <p className="mt-1 text-sm text-neutral-500">{subtitle}</p>
        </div>
        <Button onClick={() => { setEditingId(null); setShowForm(true); }}>
          <Plus size={16} /> New Minutes
        </Button>
      </div>

      <div className="grid grid-cols-3 gap-4">
        <KpiCard label="Drafts" value={draftCount} icon={FileText} accent="bg-neutral-100 text-neutral-500" />
        <KpiCard label="In Review" value={submittedCount} icon={Clock} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Approved" value={approvedCount} icon={CheckCircle} accent="bg-success-50 text-success-600" />
      </div>

      <Card className="p-6">
        <SectionHeader title="All Minutes" />
        {items.length === 0 ? (
          <EmptyState icon={<FileText size={24} />} title="No minutes found" message="Create your first meeting minutes to get started." />
        ) : (
          <div className="space-y-1">
            {items.map((m: any) => {
              const canEdit = m.status === 'draft' || m.status === 'rejected_by_secretary' || m.status === 'rejected';
              return (
                <div key={m.id} className="group flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 mb-1">
                      <p className="text-sm font-semibold text-neutral-800">{m.title}</p>
                      {m.meeting_type === 'executive' && (
                        <span className="text-2xs font-bold uppercase text-secondary-600 bg-secondary-50 px-1.5 py-0.5 rounded">Executive</span>
                      )}
                    </div>
                    <p className="text-xs text-neutral-400">{formatDate(m.meeting_date)} · {m.attendees} attendees · By {m.prepared_by}</p>
                    {m.rejection_notes && (
                      <p className="text-xs text-error-600 mt-1 flex items-center gap-1">
                        <AlertTriangle size={12} /> {m.rejection_notes}
                      </p>
                    )}
                  </div>
                  <div className="flex items-center gap-2 flex-shrink-0">
                    <Badge status={statusBadge(m.status)}>{statusLabel(m.status)}</Badge>
                    <button onClick={() => setViewingMinute(m)} className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 transition-colors">
                      <Eye size={15} />
                    </button>
                    {canEdit && (
                      <>
                        <button onClick={() => { setEditingId(m.id); setShowForm(true); }} className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 transition-colors">
                          <Edit size={15} />
                        </button>
                        <button onClick={() => setDeleteId(m.id)} className="p-1.5 rounded-lg text-neutral-400 hover:text-error-500 hover:bg-error-50 transition-colors">
                          <Trash2 size={15} />
                        </button>
                        <Button size="sm" onClick={() => setSubmitId(m.id)}>
                          <Send size={13} /> Submit
                        </Button>
                      </>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      {showForm && (
        <MinutesForm
          role={role}
          userName={user?.name || ''}
          editingId={editingId}
          existingItems={items}
          onSubmit={(payload) => {
            if (editingId) updateMut.mutate({ id: editingId, data: payload });
            else createMut.mutate(payload);
          }}
          onClose={() => { setShowForm(false); setEditingId(null); }}
          isPending={createMut.isPending || updateMut.isPending}
        />
      )}

      {viewingMinute && (
        <MinuteViewModal minute={viewingMinute} onClose={() => setViewingMinute(null)} />
      )}

      <ConfirmDialog
        open={!!deleteId}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteId && deleteMut.mutate(deleteId)}
        title="Delete Minutes"
        message="Are you sure you want to delete these minutes? This cannot be undone."
        confirmLabel="Delete"
        danger
      />

      <ConfirmDialog
        open={!!submitId}
        onClose={() => setSubmitId(null)}
        onConfirm={() => submitId && submitMut.mutate(submitId)}
        title={role === 'secretary' ? 'Submit to Chairperson' : 'Submit to Secretary'}
        message={role === 'secretary'
          ? 'These minutes will be sent to the Chairperson for final approval.'
          : 'These minutes will be sent to the Secretary for review.'}
        confirmLabel="Submit"
      />
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MINUTES FORM (authoring with attendance picker)
// ═══════════════════════════════════════════════════════════════════════════════
function MinutesForm({
  role, userName, editingId, existingItems, onSubmit, onClose, isPending,
}: {
  role: 'secretary' | 'vice-secretary';
  userName: string;
  editingId: string | null;
  existingItems: any[];
  onSubmit: (payload: CreateMinutePayload) => void;
  onClose: () => void;
  isPending: boolean;
}) {
  const editing = editingId ? existingItems.find((m) => m.id === editingId) : null;

  const [form, setForm] = useState({
    title: editing?.title || '',
    meeting_date: editing?.meeting_date?.split('T')[0] || '',
    meeting_type: editing?.meeting_type || 'general',
    agenda: editing?.agenda || '',
    discussions: editing?.discussions || editing?.content || '',
    decisions: editing?.decisions || '',
    action_items: editing?.action_items || '',
    next_meeting_date: editing?.next_meeting_date?.split('T')[0] || '',
  });

  const [attendance, setAttendance] = useState<Record<string, AttendanceBucket | null>>({});
  const [memberSearch, setMemberSearch] = useState('');
  const [showMemberPicker, setShowMemberPicker] = useState(false);

  const { data: members, isLoading: membersLoading } = useQuery({
    queryKey: ['minutes-members', form.meeting_type],
    queryFn: () => form.meeting_type === 'executive'
      ? minutesApi.getExecutiveMembers()
      : minutesApi.getMembers(),
  });

  const { data: secSig } = useQuery({
    queryKey: queryKeys.signature('secretary'),
    queryFn: async () => { try { return await minutesApi.getSignature('secretary'); } catch { return null; } },
    initialData: null as any,
  });

  const { data: chairSig } = useQuery({
    queryKey: queryKeys.signature('chairperson'),
    queryFn: async () => { try { return await minutesApi.getSignature('chairperson'); } catch { return null; } },
    initialData: null as any,
  });

  // Hydrate attendance from editing record
  useEffect(() => {
    if (editing?.attendees) {
      const init: Record<string, AttendanceBucket | null> = {};
      editing.attendees.forEach((a: any) => { init[a.id] = 'PRESENT'; });
      editing.absent_with_apology?.forEach((a: any) => { init[a.id] = 'ABSENT_WITH_APOLOGY'; });
      editing.absent_without_apology?.forEach((a: any) => { init[a.id] = 'ABSENT'; });
      setAttendance(init);
    }
  }, [editingId]);

  const filteredMembers = useMemo(() => {
    const all = members || [];
    if (!memberSearch.trim()) return all;
    const q = memberSearch.toLowerCase();
    return all.filter((m: MinuteMember) =>
      m.name.toLowerCase().includes(q) || m.phone.includes(q),
    );
  }, [members, memberSearch]);

  const presentCount = Object.values(attendance).filter((v) => v === 'PRESENT').length;
  const absentApologyCount = Object.values(attendance).filter((v) => v === 'ABSENT_WITH_APOLOGY').length;
  const absentCount = Object.values(attendance).filter((v) => v === 'ABSENT').length;

  const setMemberBucket = (memberId: string, bucket: AttendanceBucket | null) => {
    setAttendance((prev) => {
      if (bucket === null) { const next = { ...prev }; delete next[memberId]; return next; }
      return { ...prev, [memberId]: bucket };
    });
  };

  const handleSubmit = () => {
    const attendanceArray: MinuteAttendance[] = Object.entries(attendance)
      .filter(([, status]) => status !== null)
      .map(([memberId, status]) => ({ member_id: memberId, status: status as AttendanceBucket }));
    onSubmit({
      title: form.title,
      meeting_date: form.meeting_date,
      meeting_type: form.meeting_type as 'general' | 'executive',
      agenda: form.agenda,
      discussions: form.discussions,
      decisions: form.decisions,
      action_items: form.action_items,
      next_meeting_date: form.next_meeting_date || null,
      attendance: attendanceArray,
    });
  };

  const isFormValid = form.title.trim() && form.meeting_date;

  return (
    <Modal open onClose={onClose} title={editingId ? 'Edit Minutes' : 'Create Meeting Minutes'} size="xl">
      <div className="space-y-5 max-h-[70vh] overflow-y-auto">
        {/* Meeting Details */}
        <div className="space-y-3">
          <h4 className="text-xs font-bold text-neutral-400 uppercase tracking-wider">Meeting Details</h4>
          <Input label="Title" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Monthly General Meeting" required />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input label="Meeting Date" type="date" value={form.meeting_date} onChange={(e) => setForm({ ...form, meeting_date: e.target.value })} required />
            <Select label="Meeting Type" value={form.meeting_type} onChange={(e) => { setForm({ ...form, meeting_type: e.target.value }); setAttendance({}); }}>
              <option value="general">General</option>
              <option value="executive">Executive</option>
            </Select>
          </div>
        </div>

        {/* Attendance */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h4 className="text-xs font-bold text-neutral-400 uppercase tracking-wider">Attendance</h4>
            <button onClick={() => setShowMemberPicker(!showMemberPicker)} className="text-xs text-primary-600 font-medium hover:text-primary-700">
              {showMemberPicker ? 'Hide' : 'Show'} member picker
            </button>
          </div>
          <div className="grid grid-cols-3 gap-2">
            {[
              { label: 'Present', count: presentCount, bucket: 'PRESENT' as const },
              { label: 'Absent w/ Apology', count: absentApologyCount, bucket: 'ABSENT_WITH_APOLOGY' as const },
              { label: 'Absent', count: absentCount, bucket: 'ABSENT' as const },
            ].map(s => (
              <div key={s.bucket} className={classNames('rounded-xl p-3 text-center border transition-colors', s.count > 0 ? BUCKET_COLORS[s.bucket] : 'bg-neutral-50 border-neutral-100')}>
                <p className="text-xl font-bold">{s.count}</p>
                <p className="text-2xs font-medium mt-0.5">{s.label}</p>
              </div>
            ))}
          </div>

          {showMemberPicker && (
            <div className="border border-neutral-200 rounded-xl p-3 space-y-2">
              {membersLoading ? (
                <LoadingState text="Loading members..." />
              ) : (
                <>
                  <div className="relative">
                    <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
                    <input
                      type="text"
                      placeholder="Search by name or phone..."
                      value={memberSearch}
                      onChange={(e) => setMemberSearch(e.target.value)}
                      className="w-full pl-9 pr-3 py-2 text-sm rounded-n26-btn border border-neutral-200 focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-500/15"
                    />
                  </div>
                  <div className="max-h-52 overflow-y-auto space-y-0.5">
                    {filteredMembers.map((m: MinuteMember) => {
                      const bucket = attendance[m.id];
                      return (
                        <div key={m.id} className="flex items-center justify-between py-1.5 px-2 rounded-lg hover:bg-neutral-50">
                          <div className="min-w-0">
                            <p className="text-sm font-medium text-neutral-800 truncate">{m.name}</p>
                            <p className="text-xs text-neutral-400">{m.phone}</p>
                          </div>
                          <div className="flex gap-1 flex-shrink-0">
                            {(['PRESENT', 'ABSENT_WITH_APOLOGY', 'ABSENT'] as AttendanceBucket[]).map((b) => (
                              <button
                                key={b}
                                onClick={() => setMemberBucket(m.id, bucket === b ? null : b)}
                                className={classNames(
                                  'px-2.5 py-1 rounded-lg text-2xs font-medium transition-colors',
                                  bucket === b ? BUCKET_COLORS[b] : 'bg-neutral-100 text-neutral-400 hover:bg-neutral-200',
                                )}
                              >
                                {b === 'PRESENT' ? 'P' : b === 'ABSENT_WITH_APOLOGY' ? 'A/A' : 'A'}
                              </button>
                            ))}
                          </div>
                        </div>
                      );
                    })}
                    {filteredMembers.length === 0 && <p className="text-sm text-neutral-400 text-center py-4">No members found</p>}
                  </div>
                  {form.meeting_type === 'executive' && (
                    <p className="text-xs text-secondary-600 bg-secondary-50 rounded-lg p-2">
                      Executive meetings: only active office bearers are listed.
                    </p>
                  )}
                </>
              )}
            </div>
          )}
        </div>

        {/* Minutes Content */}
        <div className="space-y-3">
          <h4 className="text-xs font-bold text-neutral-400 uppercase tracking-wider">Minutes Content</h4>
          <Textarea label="Agenda" rows={2} value={form.agenda} onChange={(e) => setForm({ ...form, agenda: e.target.value })} placeholder="Opening; reports; decisions..." />
          <Textarea label="Discussions" rows={4} value={form.discussions} onChange={(e) => setForm({ ...form, discussions: e.target.value })} placeholder="Discussion notes..." />
          <Textarea label="Decisions" rows={3} value={form.decisions} onChange={(e) => setForm({ ...form, decisions: e.target.value })} placeholder="Agreed actions and decisions..." />
          <Textarea label="Action Items" rows={2} value={form.action_items} onChange={(e) => setForm({ ...form, action_items: e.target.value })} placeholder="Owner and due date..." />
          <Input label="Next Meeting Date" type="date" value={form.next_meeting_date} onChange={(e) => setForm({ ...form, next_meeting_date: e.target.value })} />
        </div>

        {/* Signature Preview */}
        <div className="space-y-3">
          <h4 className="text-xs font-bold text-neutral-400 uppercase tracking-wider">Officer Signatures</h4>
          <div className="grid grid-cols-2 gap-3">
            <div className="p-3 rounded-xl border border-neutral-100">
              <p className="text-xs text-neutral-400 mb-1">Secretary</p>
              {secSig?.url ? (
                <img src={secSig.url} alt="Secretary signature" className="max-h-12 object-contain" />
              ) : (
                <p className="text-xs text-neutral-300 italic">No signature on file</p>
              )}
              <p className="text-xs font-medium text-neutral-700 mt-1">{secSig?.name ?? '—'}</p>
            </div>
            <div className="p-3 rounded-xl border border-neutral-100">
              <p className="text-xs text-neutral-400 mb-1">Chairperson</p>
              {chairSig?.url ? (
                <img src={chairSig.url} alt="Chairperson signature" className="max-h-12 object-contain" />
              ) : (
                <p className="text-xs text-neutral-300 italic">No signature on file</p>
              )}
              <p className="text-xs font-medium text-neutral-700 mt-1">{chairSig?.name ?? '—'}</p>
            </div>
          </div>
        </div>

        {/* Actions */}
        <div className="flex justify-end gap-3 pt-2 border-t border-neutral-100">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button onClick={handleSubmit} disabled={!isFormValid || isPending}>
            {isPending ? 'Saving...' : editingId ? 'Save Changes' : 'Create Draft'}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MINUTE VIEW MODAL
// ═══════════════════════════════════════════════════════════════════════════════
function MinuteViewModal({ minute, onClose }: { minute: any; onClose: () => void }) {
  const handlePrint = () => window.print();
  return (
    <Modal open onClose={onClose} title={minute.title} size="lg">
      <div className="space-y-4 max-h-[70vh] overflow-y-auto">
        <div className="grid grid-cols-2 gap-3">
          <div><p className="text-xs text-neutral-400">Meeting Date</p><p className="text-sm font-semibold text-neutral-800">{formatDate(minute.meeting_date)}</p></div>
          <div><p className="text-xs text-neutral-400">Type</p><p className="text-sm font-semibold text-neutral-800 capitalize">{minute.meeting_type || 'general'}</p></div>
          <div><p className="text-xs text-neutral-400">Prepared By</p><p className="text-sm font-semibold text-neutral-800">{minute.prepared_by}</p></div>
          <div><p className="text-xs text-neutral-400">Status</p><Badge status={statusBadge(minute.status)}>{statusLabel(minute.status)}</Badge></div>
        </div>

        {minute.agenda && <div><p className="text-xs font-bold text-neutral-400 uppercase mb-1">Agenda</p><p className="text-sm text-neutral-700 whitespace-pre-wrap">{minute.agenda}</p></div>}
        {minute.discussions && <div><p className="text-xs font-bold text-neutral-400 uppercase mb-1">Discussions</p><p className="text-sm text-neutral-700 whitespace-pre-wrap">{minute.discussions}</p></div>}
        {minute.decisions && <div><p className="text-xs font-bold text-neutral-400 uppercase mb-1">Decisions</p><p className="text-sm text-neutral-700 whitespace-pre-wrap">{minute.decisions}</p></div>}
        {minute.action_items && <div><p className="text-xs font-bold text-neutral-400 uppercase mb-1">Action Items</p><p className="text-sm text-neutral-700 whitespace-pre-wrap">{minute.action_items}</p></div>}

        {(minute.attendees?.length > 0 || minute.absent_with_apology?.length > 0 || minute.absent_without_apology?.length > 0) && (
          <div className="grid grid-cols-3 gap-3">
            {minute.attendees?.length > 0 && (
              <div className="p-3 rounded-xl bg-success-50 border border-success-200">
                <p className="text-xs font-bold text-success-700 mb-1">Present ({minute.attendees.length})</p>
                <div className="space-y-0.5">{minute.attendees.map((a: any) => <p key={a.id} className="text-xs text-success-700">{a.name}</p>)}</div>
              </div>
            )}
            {minute.absent_with_apology?.length > 0 && (
              <div className="p-3 rounded-xl bg-warning-50 border border-warning-200">
                <p className="text-xs font-bold text-warning-700 mb-1">Absent w/ Apology ({minute.absent_with_apology.length})</p>
                <div className="space-y-0.5">{minute.absent_with_apology.map((a: any) => <p key={a.id} className="text-xs text-warning-700">{a.name}</p>)}</div>
              </div>
            )}
            {minute.absent_without_apology?.length > 0 && (
              <div className="p-3 rounded-xl bg-error-50 border border-error-200">
                <p className="text-xs font-bold text-error-700 mb-1">Absent ({minute.absent_without_apology.length})</p>
                <div className="space-y-0.5">{minute.absent_without_apology.map((a: any) => <p key={a.id} className="text-xs text-error-700">{a.name}</p>)}</div>
              </div>
            )}
          </div>
        )}

        {/* Signature previews */}
        {(minute.secretary_signature_url || minute.chairperson_signature_url) && (
          <div className="grid grid-cols-2 gap-4 pt-3 border-t border-neutral-100">
            <div>
              <p className="text-xs text-neutral-400 mb-1">Secretary</p>
              {minute.secretary_signature_url && <img src={minute.secretary_signature_url} alt="Secretary" className="max-h-14 object-contain" />}
              <p className="text-xs font-medium text-neutral-700 mt-1">{minute.secretary_name ?? '—'}</p>
            </div>
            <div>
              <p className="text-xs text-neutral-400 mb-1">Chairperson</p>
              {minute.chairperson_signature_url && <img src={minute.chairperson_signature_url} alt="Chairperson" className="max-h-14 object-contain" />}
              <p className="text-xs font-medium text-neutral-700 mt-1">{minute.chairperson_name ?? '—'}</p>
            </div>
          </div>
        )}

        {minute.rejection_notes && (
          <div className="p-3 rounded-xl bg-error-50 border border-error-200">
            <p className="text-xs font-bold text-error-600 uppercase mb-1">Rejection Notes</p>
            <p className="text-sm text-error-700">{minute.rejection_notes}</p>
          </div>
        )}

        <div className="flex justify-end gap-3 pt-2 border-t border-neutral-100">
          <Button variant="secondary" onClick={handlePrint}><Printer size={15} /> Print</Button>
          <Button variant="secondary" onClick={onClose}>Close</Button>
        </div>
      </div>
    </Modal>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SECRETARY MINUTES MANAGEMENT
// ═══════════════════════════════════════════════════════════════════════════════
export function MinutesManagement() {
  return (
    <MinutesAuthoring
      role="secretary"
      title="Meeting Minutes"
      subtitle="Create and manage meeting minutes"
      queryKey={queryKeys.secretaryMinutes}
      fetchFn={() => mockSecretary.getMeetingMinutes()}
      createFn={(data) => mockSecretary.createMinutes(data as any)}
      updateFn={(id, data) => mockSecretary.updateMinutes(id, data as any)}
      deleteFn={(id) => mockSecretary.deleteMinutes?.(id) ?? Promise.resolve({ message: 'Deleted' })}
      submitFn={(id) => mockSecretary.submitForReview(id)}
    />
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  VICE SECRETARY MINUTES
// ═══════════════════════════════════════════════════════════════════════════════
export function ViceSecretaryMinutes() {
  return (
    <MinutesAuthoring
      role="vice-secretary"
      title="Meeting Minutes"
      subtitle="Create and submit minutes to the Secretary"
      queryKey={queryKeys.viceSecretaryMinutes}
      fetchFn={() => mockViceSecretary.getMeetingMinutes()}
      createFn={(data) => mockViceSecretary.createMinutes(data as any)}
      updateFn={(id, data) => mockViceSecretary.updateMinutes(id, data as any)}
      deleteFn={(id) => Promise.resolve({ message: 'Deleted' })}
      submitFn={(id) => mockViceSecretary.submitMinutes(id)}
    />
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SECRETARY MINUTES REVIEW
// ═══════════════════════════════════════════════════════════════════════════════
export function MinutesReview() {
  const qc = useQueryClient();
  const [viewingMinute, setViewingMinute] = useState<any | null>(null);
  const [editMinute, setEditMinute] = useState<any | null>(null);
  const [reviewAction, setReviewAction] = useState<{ id: string; decision: 'approve' | 'reject' } | null>(null);
  const [reviewNotes, setReviewNotes] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.secretaryMinutesReview,
    queryFn: () => mockSecretary.getMinutesReview(),
  });

  const reviewMut = useMutation({
    mutationFn: ({ id, decision, notes }: { id: string; decision: 'approve' | 'reject'; notes?: string }) =>
      mockSecretary.secretaryReview?.(id, decision, notes) ?? Promise.resolve({ message: 'Reviewed' }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.secretaryMinutesReview });
      qc.invalidateQueries({ queryKey: queryKeys.chairpersonApprovals });
      setReviewAction(null); setReviewNotes(''); setViewingMinute(null);
    },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load review queue" onRetry={refetch} />;

  const items = data || [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Minutes Review</h1>
        <p className="mt-1 text-sm text-neutral-500">Review Vice Secretary submissions and forward to Chairperson</p>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <KpiCard label="Pending Review" value={items.length} icon={FileSearch} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Forwarded Today" value="0" icon={CheckCircle} accent="bg-success-50 text-success-600" />
      </div>

      <Card className="p-6">
        <SectionHeader title="Review Queue" />
        {items.length === 0 ? (
          <EmptyState icon={<FileSearch size={24} />} title="No pending reviews" message="Submitted minutes will appear here for your review." />
        ) : (
          <div className="space-y-1">
            {items.map((m: any) => (
              <div key={m.id} className="group flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <p className="text-sm font-semibold text-neutral-800">{m.title}</p>
                    {m.meeting_type === 'executive' && (
                      <span className="text-2xs font-bold uppercase text-secondary-600 bg-secondary-50 px-1.5 py-0.5 rounded">Executive</span>
                    )}
                  </div>
                  <p className="text-xs text-neutral-400">{formatDate(m.meeting_date)} · {m.attendees} attendees · By {m.prepared_by}</p>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <button onClick={() => setViewingMinute(m)} className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 transition-colors"><Eye size={15} /></button>
                  <button onClick={() => setEditMinute(m)} className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 transition-colors"><Edit size={15} /></button>
                  <Button size="sm" onClick={() => setReviewAction({ id: m.id, decision: 'approve' })}><CheckCircle size={13} /> Forward</Button>
                  <Button size="sm" variant="danger" onClick={() => setReviewAction({ id: m.id, decision: 'reject' })}><XCircle size={13} /> Return</Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {viewingMinute && !editMinute && <MinuteViewModal minute={viewingMinute} onClose={() => setViewingMinute(null)} />}
      {editMinute && (
        <MinutesForm
          role="secretary" userName="" editingId={editMinute.id} existingItems={items}
          onSubmit={(payload) => { mockSecretary.updateMinutes(editMinute.id, payload as any); setEditMinute(null); qc.invalidateQueries({ queryKey: queryKeys.secretaryMinutesReview }); }}
          onClose={() => setEditMinute(null)} isPending={false}
        />
      )}

      <Modal
        open={!!reviewAction}
        onClose={() => { setReviewAction(null); setReviewNotes(''); }}
        title={reviewAction?.decision === 'approve' ? 'Forward to Chairperson' : 'Return for Correction'}
        size="md"
      >
        <div className="space-y-4">
          {reviewAction?.decision === 'reject' && (
            <Textarea label="Notes for the author (required)" rows={4} value={reviewNotes} onChange={(e) => setReviewNotes(e.target.value)} placeholder="Explain what needs to be corrected..." required />
          )}
          {reviewAction?.decision === 'approve' && (
            <Textarea label="Notes (optional)" rows={2} value={reviewNotes} onChange={(e) => setReviewNotes(e.target.value)} placeholder="Any notes for the Chairperson..." />
          )}
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={() => { setReviewAction(null); setReviewNotes(''); }}>Cancel</Button>
            <Button
              variant={reviewAction?.decision === 'reject' ? 'danger' : 'primary'}
              disabled={reviewAction?.decision === 'reject' && !reviewNotes.trim()}
              onClick={() => reviewAction && reviewMut.mutate({ id: reviewAction.id, decision: reviewAction.decision, notes: reviewNotes.trim() || undefined })}
            >
              {reviewAction?.decision === 'approve' ? 'Forward to Chairperson' : 'Return to Author'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MEETING SCHEDULING (Secretary)
// ═══════════════════════════════════════════════════════════════════════════════
export function MeetingScheduling() {
  const qc = useQueryClient();
  const [createModal, setCreateModal] = useState(false);
  const [form, setForm] = useState({ title: '', date: '', time: '', location: '', type: '' });

  const { data, isLoading } = useQuery({ queryKey: queryKeys.secretarySchedule, queryFn: () => mockSecretary.getSchedule() });
  const createMutation = useMutation({
    mutationFn: (data: any) => mockSecretary.createMeeting(data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.secretarySchedule }); setCreateModal(false); setForm({ title: '', date: '', time: '', location: '', type: '' }); },
  });

  if (isLoading) return <LoadingState />;

  const meetings = data || [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Meeting Scheduling</h1>
          <p className="mt-1 text-sm text-neutral-500">Plan and schedule meetings</p>
        </div>
        <Button onClick={() => setCreateModal(true)}><Plus size={16} /> Schedule Meeting</Button>
      </div>

      <Card className="p-6">
        <SectionHeader title="Upcoming Meetings" />
        {meetings.length === 0 ? (
          <EmptyState icon={<CalendarDays size={24} />} title="No meetings scheduled" />
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {meetings.map((m: any) => (
              <div key={m.id} className="p-4 rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-float transition-all">
                <div className="flex items-start justify-between gap-3 mb-2">
                  <div className="w-10 h-10 rounded-xl bg-primary-50 flex items-center justify-center flex-shrink-0">
                    <CalendarDays size={18} className="text-primary-600" />
                  </div>
                  <Badge status={m.status}>{m.status}</Badge>
                </div>
                <p className="text-sm font-bold text-neutral-800">{m.title}</p>
                <p className="text-xs text-neutral-400 mt-1">{formatDate(m.date)} at {m.time} · {m.location}</p>
                <p className="text-xs text-neutral-400">Type: {m.type} · {m.attendees_count} attendees</p>
                {m.agenda?.length > 0 && (
                  <ul className="mt-3 text-xs text-neutral-500 space-y-1">
                    {m.agenda.map((a: string, i: number) => <li key={i} className="flex items-center gap-2"><span className="w-1 h-1 rounded-full bg-neutral-300" />{a}</li>)}
                  </ul>
                )}
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal open={createModal} onClose={() => setCreateModal(false)} title="Schedule Meeting">
        <div className="space-y-4">
          <Input label="Title" value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} required />
          <div className="grid grid-cols-2 gap-3">
            <Input label="Date" type="date" value={form.date} onChange={e => setForm({ ...form, date: e.target.value })} required />
            <Input label="Time" type="time" value={form.time} onChange={e => setForm({ ...form, time: e.target.value })} required />
          </div>
          <Input label="Location" value={form.location} onChange={e => setForm({ ...form, location: e.target.value })} required />
          <Input label="Type" value={form.type} onChange={e => setForm({ ...form, type: e.target.value })} placeholder="AGM, Executive, Planning..." required />
          <Button className="w-full" onClick={() => createMutation.mutate(form)} disabled={createMutation.isPending}>Schedule</Button>
        </div>
      </Modal>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SECRETARY EVENTS
// ═══════════════════════════════════════════════════════════════════════════════
export function SecretaryEvents() {
  const qc = useQueryClient();
  const [createModal, setCreateModal] = useState(false);
  const [form, setForm] = useState({ title: '', description: '', date: '', location: '', attendees_count: '' });

  const { data, isLoading } = useQuery({ queryKey: queryKeys.secretaryEvents, queryFn: () => mockSecretary.getEvents() });
  const createMut = useMutation({
    mutationFn: (data: any) => mockSecretary.createEvent(data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.secretaryEvents }); setCreateModal(false); setForm({ title: '', description: '', date: '', location: '', attendees_count: '' }); },
  });

  if (isLoading) return <LoadingState />;
  const events = data || [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Events</h1>
          <p className="mt-1 text-sm text-neutral-500">Create and manage KHWWC events</p>
        </div>
        <Button onClick={() => setCreateModal(true)}><Plus size={16} /> New Event</Button>
      </div>

      <Card className="p-6">
        <SectionHeader title="All Events" />
        {events.length === 0 ? <EmptyState icon={<Calendar size={24} />} title="No events found" /> : (
          <div className="space-y-1">
            {events.map((e: any) => (
              <div key={e.id} className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-neutral-800">{e.title}</p>
                  <p className="text-xs text-neutral-400 mt-0.5">{formatDate(e.date)} · {e.location} · {e.attendees_count} attendees</p>
                </div>
                <Badge status={e.status}>{e.status}</Badge>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal open={createModal} onClose={() => setCreateModal(false)} title="Create Event">
        <div className="space-y-4">
          <Input label="Title" value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} required />
          <Input label="Date" type="date" value={form.date} onChange={e => setForm({ ...form, date: e.target.value })} required />
          <Input label="Location" value={form.location} onChange={e => setForm({ ...form, location: e.target.value })} required />
          <Input label="Expected Attendees" type="number" value={form.attendees_count} onChange={e => setForm({ ...form, attendees_count: e.target.value })} required />
          <div><label className="label">Description</label><Textarea rows={3} value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} required /></div>
          <Button className="w-full" onClick={() => createMut.mutate({ ...form, attendees_count: Number(form.attendees_count), status: 'upcoming' })} disabled={createMut.isPending}>Create Event</Button>
        </div>
      </Modal>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  SIGNATURE UPLOAD (shared)
// ═══════════════════════════════════════════════════════════════════════════════
export function SignatureUpload({ role, title }: { role: string; title: string }) {
  const qc = useQueryClient();
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  const { data: signature, isLoading } = useQuery({
    queryKey: queryKeys.signature(role),
    queryFn: async () => { try { return await minutesApi.getSignature(role as 'secretary' | 'chairperson'); } catch { return null; } },
    initialData: null as any,
  });

  const uploadMut = useMutation({
    mutationFn: (file: File) => minutesApi.uploadSignature(role, file),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.signature(role) }); setSelectedFile(null); setPreviewUrl(null); },
  });

  useEffect(() => {
    if (selectedFile) {
      const url = URL.createObjectURL(selectedFile);
      setPreviewUrl(url);
      return () => URL.revokeObjectURL(url);
    }
    setPreviewUrl(null);
  }, [selectedFile]);

  if (isLoading) return <LoadingState />;

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{title}</h1>
        <p className="mt-1 text-sm text-neutral-500">Upload your official signature for document signing</p>
      </div>

      <Card className="p-8">
        <div className="flex flex-col items-center gap-5">
          <div className="w-64 h-32 border-2 border-dashed border-neutral-200 rounded-n26-card flex items-center justify-center bg-neutral-50 overflow-hidden">
            {previewUrl ? (
              <img src={previewUrl} alt="Signature preview" className="max-w-full max-h-full object-contain" />
            ) : signature?.url ? (
              <img src={signature.url} alt="Signature" className="max-w-full max-h-full object-contain" />
            ) : (
              <div className="flex flex-col items-center gap-2 text-neutral-300">
                <PenTool size={36} />
                <p className="text-xs">No signature yet</p>
              </div>
            )}
          </div>

          <div className="text-center">
            <p className="text-sm text-neutral-600">
              {signature ? 'Your signature is on file. Upload a new image to replace it.' : 'No signature uploaded yet. Upload a PNG or JPG of your signature.'}
            </p>
            {signature?.uploaded_at && <p className="text-xs text-neutral-400 mt-1">Uploaded {formatDate(signature.uploaded_at)}</p>}
          </div>

          <div className="w-full max-w-sm space-y-3">
            <div>
              <label className="label">Choose signature image</label>
              <input
                type="file"
                accept="image/png,image/jpeg"
                onChange={e => setSelectedFile(e.target.files?.[0] || null)}
                className="w-full text-sm text-neutral-600 file:mr-3 file:py-2 file:px-4 file:rounded-n26-btn file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 transition-colors"
              />
            </div>
            <Button className="w-full" onClick={() => selectedFile && uploadMut.mutate(selectedFile)} disabled={!selectedFile || uploadMut.isPending}>
              <Upload size={16} /> {uploadMut.isPending ? 'Uploading...' : 'Upload Signature'}
            </Button>
            {uploadMut.isError && <p className="text-sm text-error-600 text-center">Failed to upload. Try again.</p>}
          </div>
        </div>
      </Card>
    </div>
  );
}

export function SecretarySignatureUpload() { return <SignatureUpload role="secretary" title="Secretary Signature" />; }
export function ChairpersonSignatureUpload() { return <SignatureUpload role="chairperson" title="Chairperson Signature" />; }

// ═══════════════════════════════════════════════════════════════════════════════
//  WITHDRAWAL APPROVALS (shared by Secretary and Chairperson)
// ═══════════════════════════════════════════════════════════════════════════════
export function SecretaryWithdrawalApprovals() {
  return <RoleWithdrawalApprovals title="Withdrawal Approvals" subtitle="Review and approve withdrawal requests as Secretary signatory" queryKey={queryKeys.secretaryWithdrawals} fetchFn={() => mockSecretary.getWithdrawalApprovals()} approveFn={(id, action) => mockSecretary.approveWithdrawal(id, action)} />;
}

export function ChairpersonWithdrawalApprovals() {
  return <RoleWithdrawalApprovals title="Withdrawal Approvals" subtitle="Review and approve withdrawal requests as Chairperson signatory" queryKey={queryKeys.chairpersonWithdrawals} fetchFn={() => mockChairperson.getWithdrawalApprovals()} approveFn={(id, action) => mockChairperson.approveWithdrawal(id, action)} />;
}

function RoleWithdrawalApprovals({ title, subtitle, queryKey, fetchFn, approveFn }: {
  title: string; subtitle: string; queryKey: readonly string[]; fetchFn: () => Promise<any[]>; approveFn: (id: string, action: string) => Promise<any>;
}) {
  const qc = useQueryClient();
  const [confirmAction, setConfirmAction] = useState<{ id: string; action: 'approve' | 'reject' } | null>(null);
  const [selected, setSelected] = useState<any>(null);

  const { data, isLoading, isError, refetch } = useQuery({ queryKey, queryFn: fetchFn });
  const mutation = useMutation({
    mutationFn: ({ id, action }: { id: string; action: string }) => approveFn(id, action),
    onSuccess: () => { qc.invalidateQueries({ queryKey }); setConfirmAction(null); setSelected(null); },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load withdrawal requests" onRetry={refetch} />;

  const pending = (data || []).filter((w: any) => w.status === 'pending');
  const processed = (data || []).filter((w: any) => w.status !== 'pending');

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{title}</h1>
        <p className="mt-1 text-sm text-neutral-500">{subtitle}</p>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <KpiCard label="Pending" value={pending.length} icon={Clock} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Processed" value={processed.length} icon={CheckCircle} accent="bg-success-50 text-success-600" />
      </div>

      <Card className="p-6">
        <SectionHeader title="Pending Requests" />
        {pending.length === 0 ? <EmptyState icon={<FileCheck size={24} />} title="No pending requests" /> : (
          <div className="space-y-1">
            {pending.map((w: any) => (
              <div key={w.id} className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-neutral-800">{w.member_name}</p>
                  <p className="text-xs text-neutral-400 mt-0.5">{formatCurrency(w.amount)} · {w.reason}</p>
                  <p className="text-xs text-neutral-300 mt-0.5">Requested {formatDate(w.requested_date)}</p>
                </div>
                <div className="flex gap-2 flex-shrink-0">
                  <Button size="sm" onClick={() => { setSelected(w); setConfirmAction({ id: w.id, action: 'approve' }); }}><CheckCircle size={14} /> Approve</Button>
                  <Button size="sm" variant="danger" onClick={() => { setSelected(w); setConfirmAction({ id: w.id, action: 'reject' }); }}><XCircle size={14} /> Reject</Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {processed.length > 0 && (
        <Card className="p-6">
          <SectionHeader title="Processed" />
          <div className="space-y-1">
            {processed.map((w: any) => (
              <div key={w.id} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <div>
                  <span className="text-sm font-medium text-neutral-700">{w.member_name}</span>
                  <span className="text-sm text-neutral-400 ml-2">{formatCurrency(w.amount)}</span>
                </div>
                <Badge status={w.status}>{w.status}</Badge>
              </div>
            ))}
          </div>
        </Card>
      )}

      <ConfirmDialog
        open={!!confirmAction}
        onClose={() => setConfirmAction(null)}
        onConfirm={() => confirmAction && mutation.mutate(confirmAction)}
        title={confirmAction?.action === 'approve' ? 'Approve Withdrawal' : 'Reject Withdrawal'}
        message={`Are you sure you want to ${confirmAction?.action} the withdrawal of ${selected ? formatCurrency(selected.amount) : ''} for ${selected?.member_name}?`}
        confirmLabel={confirmAction?.action === 'approve' ? 'Approve' : 'Reject'}
        danger={confirmAction?.action === 'reject'}
      />
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  VICE SECRETARY DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════
export function ViceSecretaryDashboard() {
  const { user } = useAuth();
  const { data, isLoading } = useQuery({ queryKey: queryKeys.viceSecretaryDashboard, queryFn: () => mockViceSecretary.dashboard() });
  if (isLoading) return <LoadingState />;
  if (!data) return <ErrorState message="Unable to load records." />;
  const d = data;

  const greeting = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  })();

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{greeting}, {user?.name?.split(' ')[0] ?? 'Vice Secretary'}</h1>
          <p className="mt-1 text-sm text-neutral-500">Events, documents, and organizational overview</p>
        </div>
        <div className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2">
          <div className="w-9 h-9 rounded-full bg-secondary-500 text-white text-sm font-bold flex items-center justify-center">
            {initials(user?.name ?? 'VS')}
          </div>
          <div className="hidden sm:block">
            <p className="text-sm font-semibold text-neutral-800">{user?.name ?? 'Vice Secretary'}</p>
            <p className="text-xs text-neutral-400">Vice Secretary</p>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard label="Total Events" value={d.total_events} icon={Calendar} accent="bg-primary-50 text-primary-600" />
        <KpiCard label="Active Events" value={d.active_events} icon={CalendarDays} accent="bg-success-50 text-success-600" />
        <KpiCard label="Pending Docs" value={d.pending_documents} icon={FileText} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Active Members" value={d.active_members} icon={Users} accent="bg-secondary-50 text-secondary-600" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card className="p-6">
          <SectionHeader title="Recent Documents" />
          <div className="space-y-1">
            {d.recent_documents.map((doc: any, i: number) => (
              <div key={i} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-neutral-800 truncate">{doc.title}</p>
                  <p className="text-xs text-neutral-400">{doc.type}</p>
                </div>
                <Badge status={doc.status}>{doc.status}</Badge>
              </div>
            ))}
          </div>
        </Card>
        <Card className="p-6">
          <SectionHeader title="Event Summary" />
          <div className="space-y-1">
            {d.event_summary.map((e: any, i: number) => (
              <div key={i} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                <span className="text-sm text-neutral-700">{e.type}</span>
                <Badge>{e.count}</Badge>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <Card className="p-6">
        <SectionHeader title="Recent Activity" />
        <div className="space-y-1">
          {d.recent_activity.map((a: any, i: number) => (
            <div key={i} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
              <p className="text-sm text-neutral-700">{a.description}</p>
              <span className="text-xs text-neutral-400 whitespace-nowrap">{relativeTime(a.date)}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  CHAIRPERSON DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════
export function ChairpersonDashboard() {
  const { user } = useAuth();
  const { data, isLoading } = useQuery({ queryKey: queryKeys.chairpersonDashboard, queryFn: () => mockChairperson.dashboard() });
  if (isLoading) return <LoadingState />;
  if (!data) return <ErrorState message="Unable to load records." />;
  const d = data;

  const greeting = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  })();

  const total = d.approved_count + d.rejected_count + d.pending_approvals;

  const quickActions = [
    { label: 'Approve Minutes', icon: CheckCircle, to: '/chairperson/approve-minutes', accent: 'bg-success-50 text-success-600' },
    { label: 'Upload Signature', icon: PenTool, to: '/chairperson/signature', accent: 'bg-accent-50 text-accent-600' },
    { label: 'Withdrawal Approvals', icon: FileCheck, to: '/chairperson/withdrawal-approvals', accent: 'bg-primary-50 text-primary-600' },
  ];

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{greeting}, {user?.name?.split(' ')[0] ?? 'Chairperson'}</h1>
          <p className="mt-1 text-sm text-neutral-500">Oversight and approval authority</p>
        </div>
        <div className="flex items-center gap-3">
          <button className="relative p-2.5 rounded-xl border border-neutral-200 bg-white hover:bg-neutral-50 transition-colors">
            <Bell size={18} className="text-neutral-500" />
            {d.pending_approvals > 0 && <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-error-500 rounded-full ring-2 ring-white" />}
          </button>
          <div className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2">
            <div className="w-9 h-9 rounded-full bg-secondary-600 text-white text-sm font-bold flex items-center justify-center">
              {initials(user?.name ?? 'CP')}
            </div>
            <div className="hidden sm:block">
              <p className="text-sm font-semibold text-neutral-800">{user?.name ?? 'Chairperson'}</p>
              <p className="text-xs text-neutral-400">Chairperson</p>
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard label="Pending Approvals" value={d.pending_approvals} icon={Clock} accent="bg-warning-50 text-warning-600" delta="Action" positive={false} />
        <KpiCard label="Approved" value={d.approved_count} icon={CheckCircle} accent="bg-success-50 text-success-600" />
        <KpiCard label="Rejected" value={d.rejected_count} icon={XCircle} accent="bg-error-50 text-error-500" />
        <KpiCard label="Total" value={total} icon={FileText} accent="bg-primary-50 text-primary-600" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="p-6">
          <SectionHeader title="Quick Actions" />
          <div className="space-y-2">
            {quickActions.map(a => (
              <Link key={a.label} to={a.to} className="group flex items-center gap-3 p-3 rounded-xl border border-neutral-100 hover:border-neutral-200 hover:shadow-float transition-all">
                <div className={classNames('w-9 h-9 rounded-lg flex items-center justify-center', a.accent)}>
                  <a.icon size={17} />
                </div>
                <span className="text-sm font-medium text-neutral-700 group-hover:text-neutral-900 flex-1">{a.label}</span>
                <ArrowRight size={14} className="text-neutral-300 group-hover:text-neutral-500" />
              </Link>
            ))}
          </div>
        </Card>

        <Card className="p-6 lg:col-span-2">
          <SectionHeader title="Pending Approvals" action={
            <Link to="/chairperson/approve-minutes" className="text-xs font-medium text-primary-600 hover:text-primary-700 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </Link>
          } />
          {d.pending_items.length === 0 ? (
            <EmptyState icon={<CheckCircle size={24} />} title="No pending approvals" message="All minutes have been reviewed." />
          ) : (
            <div className="space-y-1">
              {d.pending_items.map((m: any) => (
                <div key={m.id} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-neutral-800 truncate">{m.title}</p>
                    <p className="text-xs text-neutral-400 mt-0.5">{formatDate(m.meeting_date)}</p>
                  </div>
                  <Badge status="pending">Pending</Badge>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  CHAIRPERSON APPROVE MINUTES
// ═══════════════════════════════════════════════════════════════════════════════
export function ApproveMinutes() {
  const qc = useQueryClient();
  const [viewingMinute, setViewingMinute] = useState<any | null>(null);
  const [action, setAction] = useState<{ id: string; type: 'approve' | 'reject' } | null>(null);
  const [rejectNotes, setRejectNotes] = useState('');

  const { data: signature } = useQuery({
    queryKey: queryKeys.signature('chairperson'),
    queryFn: async () => { try { return await minutesApi.getSignature('chairperson'); } catch { return null; } },
    initialData: null as any,
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.chairpersonApprovals,
    queryFn: () => mockChairperson.getPendingApprovals(),
  });

  const approveMut = useMutation({
    mutationFn: (id: string) => mockChairperson.approveMinutes(id, 'approve'),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.chairpersonApprovals }); qc.invalidateQueries({ queryKey: queryKeys.chairpersonDashboard }); setAction(null); setViewingMinute(null); },
  });

  const rejectMut = useMutation({
    mutationFn: ({ id, notes }: { id: string; notes: string }) => mockChairperson.approveMinutes(id, 'reject'),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.chairpersonApprovals }); qc.invalidateQueries({ queryKey: queryKeys.chairpersonDashboard }); setAction(null); setRejectNotes(''); setViewingMinute(null); },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message="Failed to load pending approvals" onRetry={refetch} />;

  const items = data || [];

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-bold text-neutral-900 tracking-tight">Approve Minutes</h1>
        <p className="mt-1 text-sm text-neutral-500">Review and approve meeting minutes forwarded by the Secretary</p>
      </div>

      {!signature && (
        <div className="flex items-center gap-3 p-4 rounded-xl bg-warning-50 border border-warning-200">
          <div className="w-9 h-9 rounded-lg bg-warning-100 flex items-center justify-center flex-shrink-0">
            <AlertTriangle size={18} className="text-warning-600" />
          </div>
          <div className="flex-1">
            <p className="text-sm font-semibold text-warning-800">No signature on file</p>
            <p className="text-xs text-warning-700 mt-0.5">Upload your signature before approving minutes.</p>
          </div>
          <Link to="/chairperson/signature" className="text-xs font-medium text-warning-700 hover:text-warning-800 flex items-center gap-1 flex-shrink-0">
            Upload <ArrowRight size={12} />
          </Link>
        </div>
      )}

      <div className="grid grid-cols-2 gap-4">
        <KpiCard label="Pending" value={items.length} icon={Clock} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Signature" value={signature ? 'Ready' : 'Missing'} icon={PenTool} accent={signature ? 'bg-success-50 text-success-600' : 'bg-error-50 text-error-500'} />
      </div>

      <Card className="p-6">
        <SectionHeader title="Approval Queue" />
        {items.length === 0 ? (
          <EmptyState icon={<CheckCircle size={24} />} title="No pending approvals" message="All minutes have been reviewed." />
        ) : (
          <div className="space-y-1">
            {items.map((m: any) => (
              <div key={m.id} className="group flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 -mx-3 rounded-xl hover:bg-neutral-50 transition-colors">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <p className="text-sm font-semibold text-neutral-800">{m.title}</p>
                    {m.meeting_type === 'executive' && (
                      <span className="text-2xs font-bold uppercase text-secondary-600 bg-secondary-50 px-1.5 py-0.5 rounded">Executive</span>
                    )}
                  </div>
                  <p className="text-xs text-neutral-400">{formatDate(m.meeting_date)} · {m.attendees} attendees · By {m.prepared_by}</p>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <button onClick={() => setViewingMinute(m)} className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 transition-colors"><Eye size={15} /></button>
                  <Button size="sm" onClick={() => setAction({ id: m.id, type: 'approve' })} disabled={!signature}><CheckCircle size={13} /> Approve</Button>
                  <Button size="sm" variant="danger" onClick={() => setAction({ id: m.id, type: 'reject' })}><XCircle size={13} /> Reject</Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {viewingMinute && <MinuteViewModal minute={viewingMinute} onClose={() => setViewingMinute(null)} />}

      <ConfirmDialog
        open={action?.type === 'approve'}
        onClose={() => setAction(null)}
        onConfirm={() => action && approveMut.mutate(action.id)}
        title="Approve Minutes"
        message="Are you sure you want to approve these minutes? Your signature will be applied."
        confirmLabel="Approve"
      />

      <Modal
        open={action?.type === 'reject'}
        onClose={() => { setAction(null); setRejectNotes(''); }}
        title="Reject Minutes"
        size="md"
      >
        <div className="space-y-4">
          <Textarea label="Rejection notes (required)" rows={4} value={rejectNotes} onChange={(e) => setRejectNotes(e.target.value)} placeholder="Explain why these minutes are being rejected..." required />
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={() => { setAction(null); setRejectNotes(''); }}>Cancel</Button>
            <Button variant="danger" disabled={!rejectNotes.trim()} onClick={() => action && rejectMut.mutate({ id: action.id, notes: rejectNotes })}>
              Reject Minutes
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════════════════════
//  OFFICE DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════
export function OfficeDashboard() {
  const { user } = useAuth();
  const { data, isLoading } = useQuery({ queryKey: queryKeys.officeDashboard, queryFn: () => mockOffice.dashboard() });
  if (isLoading) return <LoadingState />;
  if (!data) return <ErrorState message="Unable to load records." />;
  const d = data;

  const greeting = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  })();

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-neutral-900 tracking-tight">{greeting}, {user?.name?.split(' ')[0] ?? 'Office'}</h1>
          <p className="mt-1 text-sm text-neutral-500">Office operations and member services</p>
        </div>
        <div className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2">
          <div className="w-9 h-9 rounded-full bg-neutral-600 text-white text-sm font-bold flex items-center justify-center">
            {initials(user?.name ?? 'OF')}
          </div>
          <div className="hidden sm:block">
            <p className="text-sm font-semibold text-neutral-800">{user?.name ?? 'Office'}</p>
            <p className="text-xs text-neutral-400">Office Staff</p>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <KpiCard label="Total Members" value={d.total_members} icon={Users} accent="bg-primary-50 text-primary-600" />
        <KpiCard label="Pending Applications" value={d.pending_applications} icon={Clock} accent="bg-warning-50 text-warning-600" />
        <KpiCard label="Pending Documents" value={d.pending_documents} icon={FileText} accent="bg-secondary-50 text-secondary-600" />
      </div>

      <Card className="p-6">
        <SectionHeader title="Recent Activities" />
        <div className="space-y-1">
          {d.recent_activities.map((a: any, i: number) => (
            <div key={i} className="flex items-center justify-between p-2.5 -mx-2.5 rounded-xl hover:bg-neutral-50 transition-colors">
              <p className="text-sm text-neutral-700">{a.description}</p>
              <span className="text-xs text-neutral-400 whitespace-nowrap">{relativeTime(a.date)}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
