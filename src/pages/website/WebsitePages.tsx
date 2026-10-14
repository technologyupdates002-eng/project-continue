import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Globe, FileEdit, Users, Inbox, Image as ImageIcon, Mail,
  LayoutDashboard, Plus, Trash2, Edit, Send, CheckCircle,
  AlertTriangle, Newspaper, FileText, Calendar,
} from 'lucide-react';
import { mockWebsite } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, PageHeader, StatCard, Badge, Button, Input, Textarea,
  Select, LoadingState, ErrorState, EmptyState, Avatar, Modal,
  ConfirmDialog, DataTable, Pagination, SearchInput,
} from '@/components/ui';
import { formatDate, relativeTime } from '@/lib/format';
import { GenericListPage, GenericFormPage, GenericDashboardPage } from '@/components/GenericPages';

export function WebsiteDashboard() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.websiteDashboard, queryFn: () => mockWebsite.dashboard() });
  if (isLoading) return <LoadingState />;
  const d = data!;
  return (
    <div>
      <PageHeader title="Website Management" subtitle="Content management dashboard" icon={<Globe size={20} />} />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <StatCard label="Total Content" value={d.total_content} icon={<FileEdit size={18} />} />
        <StatCard label="Published" value={d.published_content} icon={<CheckCircle size={18} />} color="success" />
        <StatCard label="Leadership" value={d.total_people} icon={<Users size={18} />} color="secondary" />
        <StatCard label="Unread Messages" value={d.unread_messages} icon={<Inbox size={18} />} color="warning" />
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
        <Card className="p-5">
          <h3 className="text-sm font-bold mb-3">Recent Content</h3>
          <div className="space-y-2">
            {d.recent_content.map((c: any) => (
              <div key={c.id} className="flex items-center justify-between py-2 border-b border-neutral-50 last:border-0">
                <div className="min-w-0"><p className="text-sm font-medium text-neutral-700 truncate">{c.title}</p><p className="text-xs text-neutral-400">{c.type} • {formatDate(c.date)}</p></div>
                <Badge status={c.status}>{c.status}</Badge>
              </div>
            ))}
          </div>
        </Card>
        <Card className="p-5">
          <h3 className="text-sm font-bold mb-3">Recent Messages</h3>
          <div className="space-y-2">
            {d.recent_messages.map((m: any) => (
              <div key={m.id} className="flex items-center justify-between py-2 border-b border-neutral-50 last:border-0">
                <div className="min-w-0"><p className="text-sm font-medium text-neutral-700 truncate">{m.subject}</p><p className="text-xs text-neutral-400">{m.name} • {relativeTime(m.date)}</p></div>
                {!m.read && <span className="w-2 h-2 rounded-full bg-primary-500" />}
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
}

export function WebsiteContentManagement() {
  return <GenericListPage config={{
    title: 'Content', subtitle: 'Manage website content pages', icon: FileEdit,
    searchPlaceholder: 'Search content...',
    fetchFn: (p) => mockWebsite.getContent(p.page), queryKey: (p) => queryKeys.websiteContent(p.page),
    statusFilters: [{ value: 'published', label: 'Published' }, { value: 'draft', label: 'Draft' }, { value: 'archived', label: 'Archived' }],
    addLabel: 'New Content',
    columns: [
      { key: 'title', label: 'Title', render: r => <span className="font-medium">{r.title}</span> },
      { key: 'type', label: 'Type' },
      { key: 'author', label: 'Author' },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function WebsitePeople() {
  const qc = useQueryClient();
  const [editModal, setEditModal] = useState<any>(null);
  const [deleteId, setDeleteId] = useState<string | null>(null);
  const [form, setForm] = useState({ name: '', title: '', position: '', bio: '', slug: '' });

  const { data, isLoading } = useQuery({ queryKey: queryKeys.websitePeople, queryFn: () => mockWebsite.getPeople() });
  const saveMutation = useMutation({
    mutationFn: (data: any) => data.id ? mockWebsite.updatePerson(data.id, data) : mockWebsite.createPerson(data),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.websitePeople }); setEditModal(null); },
  });
  const deleteMutation = useMutation({
    mutationFn: (id: string) => mockWebsite.deletePerson(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.websitePeople }); setDeleteId(null); },
  });

  return (
    <div>
      <PageHeader title="People" subtitle="Manage leadership and public profiles" icon={<Users size={20} />}
        action={<Button onClick={() => { setForm({ name: '', title: '', position: '', bio: '', slug: '' }); setEditModal({}); }}><Plus size={16} /> Add Person</Button>} />
      <Card className="p-4">
        {isLoading && <LoadingState />}
        {!isLoading && (data || []).length === 0 && <EmptyState icon={<Users size={24} />} title="No people added yet" />}
        {!isLoading && (data || []).length > 0 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {data!.map((p: any) => (
              <Card key={p.id} className="p-4">
                <div className="flex items-start gap-3">
                  <Avatar name={p.name} size="lg" src={p.photo} />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-bold text-neutral-900 truncate">{p.name}</p>
                    <p className="text-xs text-primary-700">{p.title}</p>
                  </div>
                </div>
                <div className="flex gap-2 mt-3">
                  <Button size="sm" variant="secondary" onClick={() => { setForm(p); setEditModal(p); }}><Edit size={14} /> Edit</Button>
                  <Button size="sm" variant="ghost" onClick={() => setDeleteId(p.id)}><Trash2 size={14} /></Button>
                </div>
              </Card>
            ))}
          </div>
        )}
      </Card>

      <Modal open={!!editModal} onClose={() => setEditModal(null)} title={editModal?.id ? 'Edit Person' : 'Add Person'}>
        <div className="space-y-4">
          <Input label="Name" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} required />
          <Input label="Title" value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} required />
          <Input label="Position" value={form.position} onChange={e => setForm({ ...form, position: e.target.value })} />
          <Input label="Slug" value={form.slug} onChange={e => setForm({ ...form, slug: e.target.value })} placeholder="dr-john-doe" />
          <div><label className="label">Biography</label><Textarea rows={4} value={form.bio} onChange={e => setForm({ ...form, bio: e.target.value })} /></div>
          <Button className="w-full" onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>Save</Button>
        </div>
      </Modal>

      <ConfirmDialog open={!!deleteId} onClose={() => setDeleteId(null)} onConfirm={() => deleteId && deleteMutation.mutate(deleteId)}
        title="Delete Person" message="Are you sure you want to remove this person from the public website?" confirmLabel="Delete" danger />
    </div>
  );
}

export function WebsiteLeadershipMembers() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.websiteLeadershipMembers, queryFn: () => mockWebsite.getLeadershipMembers() });
  return (
    <div>
      <PageHeader title="Leadership Members" subtitle="Select members to assign leadership positions" icon={<Users size={20} />} />
      <Card className="p-4">
        {isLoading && <LoadingState />}
        {!isLoading && (
          <div className="space-y-2">
            {(data || []).map((m: any) => (
              <div key={m.id} className="flex items-center justify-between py-2 border-b border-neutral-50 last:border-0">
                <div className="flex items-center gap-3">
                  <Avatar name={m.name} size="sm" />
                  <div><p className="text-sm font-medium text-neutral-700">{m.name}</p><p className="text-xs text-neutral-400">{m.member_number}</p></div>
                </div>
                <Button size="sm" variant="secondary">Assign</Button>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}

export function WebsiteContactInbox() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const { data, isLoading } = useQuery({ queryKey: queryKeys.websiteContactMessages(page), queryFn: () => mockWebsite.getContactMessages(page) });
  const markRead = useMutation({ mutationFn: (id: string) => mockWebsite.markMessageRead(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['website', 'contact-messages'] }) });
  const result = data as any;

  return (
    <div>
      <PageHeader title="Contact Messages" subtitle="Inbox of website contact form submissions" icon={<Inbox size={20} />} />
      <Card className="p-4">
        {isLoading && <LoadingState />}
        {!isLoading && (result?.data || []).length === 0 && <EmptyState icon={<Inbox size={24} />} title="No messages" />}
        {!isLoading && (result?.data || []).length > 0 && (
          <div className="space-y-2">
            {result.data.map((m: any) => (
              <div key={m.id} className={`p-4 rounded-xl border ${m.read ? 'border-neutral-100' : 'border-primary-200 bg-primary-50/30'}`}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-neutral-900">{m.subject}</p>
                    <p className="text-xs text-neutral-500">From: {m.name} ({m.email}) • {relativeTime(m.date)}</p>
                    <p className="text-sm text-neutral-600 mt-2">{m.message}</p>
                  </div>
                  {!m.read && <Button size="sm" variant="ghost" onClick={() => markRead.mutate(m.id)}>Mark Read</Button>}
                </div>
              </div>
            ))}
          </div>
        )}
        {result && <Pagination currentPage={result.current_page} lastPage={result.last_page} onPage={setPage} />}
      </Card>
    </div>
  );
}

export function WebsiteMedia() {
  const { data, isLoading } = useQuery({ queryKey: queryKeys.websiteMedia, queryFn: () => mockWebsite.getMedia() });
  return (
    <div>
      <PageHeader title="Media Library" subtitle="Manage uploaded images and files" icon={<ImageIcon size={20} />}
        action={<Button><Plus size={16} /> Upload</Button>} />
      <Card className="p-4">
        {isLoading && <LoadingState />}
        {!isLoading && (
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            {(data || []).map((m: any) => (
              <div key={m.id} className="p-3 rounded-xl border border-neutral-100">
                <div className="w-full aspect-video rounded-lg bg-neutral-100 flex items-center justify-center mb-2">
                  <ImageIcon size={24} className="text-neutral-300" />
                </div>
                <p className="text-xs font-medium text-neutral-700 truncate">{m.name}</p>
                <p className="text-xs text-neutral-400">{m.size}</p>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}

export function WebsiteSettings({ section = 'general' }: { section?: string }) {
  const sectionLabels: Record<string, string> = { general: 'General', contact: 'Contact', social: 'Social Media', appearance: 'Appearance' };
  return <GenericFormPage
    key={section}
    title={`${sectionLabels[section] || section} Settings`} subtitle="Edit website configuration" icon={Globe}
    fields={
      section === 'general' ? [
        { key: 'organization_name', label: 'Organization Name', value: 'Kirinyaga Healthcare Workers Welfare' },
        { key: 'tagline', label: 'Tagline', value: 'Supporting those who care for our community' },
        { key: 'about', label: 'About', type: 'textarea', value: 'KHWWC is a welfare organization dedicated to supporting healthcare workers in Kirinyaga County.' },
      ] : section === 'contact' ? [
        { key: 'email', label: 'Email', value: 'info@khwwc.co.ke' },
        { key: 'phone', label: 'Phone', value: '+254 700 100 200' },
        { key: 'address', label: 'Address', value: 'Kerugoya Town, Kirinyaga County, Kenya' },
      ] : section === 'social' ? [
        { key: 'facebook', label: 'Facebook URL', value: 'https://facebook.com/khwwc' },
        { key: 'twitter', label: 'Twitter URL', value: 'https://twitter.com/khwwc' },
        { key: 'linkedin', label: 'LinkedIn URL', value: 'https://linkedin.com/company/khwwc' },
      ] : [
        { key: 'primary_color', label: 'Primary Color', value: '#1a5e48' },
        { key: 'logo', label: 'Logo URL', value: '' },
      ]
    }
    onSubmit={async (data) => { await mockWebsite.updateSettings(section, data); }}
  />;
}

export function WebsiteEmailTemplates() {
  const qc = useQueryClient();
  const [editModal, setEditModal] = useState<any>(null);
  const [form, setForm] = useState({ name: '', subject: '', body: '' });
  const [testEmail, setTestEmail] = useState<string | null>(null);
  const [testAddress, setTestAddress] = useState('');

  const { data, isLoading } = useQuery({ queryKey: queryKeys.websiteEmailTemplates, queryFn: () => mockWebsite.getEmailTemplates() });
  const saveMutation = useMutation({ mutationFn: (d: any) => mockWebsite.updateEmailTemplate(d.id, d), onSuccess: () => { qc.invalidateQueries({ queryKey: queryKeys.websiteEmailTemplates }); setEditModal(null); } });
  const testMutation = useMutation({ mutationFn: ({ id, email }: { id: string; email: string }) => mockWebsite.testEmail(id, email), onSuccess: () => setTestEmail(null) });

  return (
    <div>
      <PageHeader title="Email Templates" subtitle="Manage automated email templates" icon={<Mail size={20} />} />
      <Card className="p-4">
        {isLoading && <LoadingState />}
        {!isLoading && (
          <div className="space-y-2">
            {(data || []).map((t: any) => (
              <div key={t.id} className="flex items-center justify-between p-4 rounded-xl border border-neutral-100">
                <div><p className="text-sm font-semibold text-neutral-800">{t.name}</p><p className="text-xs text-neutral-400">Subject: {t.subject} • {t.variables.length} variables</p></div>
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => { setForm(t); setEditModal(t); }}><Edit size={14} /> Edit</Button>
                  <Button size="sm" variant="ghost" onClick={() => { setTestEmail(t.id); setTestAddress(''); }}><Send size={14} /> Test</Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Modal open={!!editModal} onClose={() => setEditModal(null)} title="Edit Email Template">
        <div className="space-y-4">
          <Input label="Name" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} />
          <Input label="Subject" value={form.subject} onChange={e => setForm({ ...form, subject: e.target.value })} />
          <div><label className="label">Body</label><Textarea rows={8} value={form.body} onChange={e => setForm({ ...form, body: e.target.value })} /></div>
          <Button className="w-full" onClick={() => saveMutation.mutate({ ...form, id: editModal.id })} disabled={saveMutation.isPending}>Save Template</Button>
        </div>
      </Modal>

      <ConfirmDialog
        open={!!testEmail}
        onClose={() => setTestEmail(null)}
        onConfirm={() => testEmail && testMutation.mutate({ id: testEmail, email: testAddress })}
        title="Send Test Email"
        message="This will send a real test email. Make sure the address is correct."
        confirmLabel="Send Test"
      />
    </div>
  );
}
