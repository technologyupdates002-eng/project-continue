import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from '@/lib/router-compat';
import { Mail, ArrowLeft, Save, Send } from 'lucide-react';
import { mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, PageHeader, Button, Input, Textarea, Select,
  LoadingState, ErrorState,
} from '@/components/ui';

export default function CreateMemo() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { id: editId } = useParams<{ id: string }>();
  const isEdit = !!editId;

  const [title, setTitle] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [templateId, setTemplateId] = useState('');
  const [recipients, setRecipients] = useState<string[]>([]);
  const [savedMsg, setSavedMsg] = useState('');

  const { data: templates } = useQuery({
    queryKey: queryKeys.treasurerMemoTemplates,
    queryFn: () => mockTreasurer.getMemoTemplates(),
  });

  const { data: memo, isLoading, isError } = useQuery({
    queryKey: queryKeys.treasurerMemo(editId || 'new'),
    queryFn: () => mockTreasurer.getMemos(),
    enabled: isEdit,
  });

  useEffect(() => {
    if (isEdit && memo) {
      const found = (memo as any[]).find((m: any) => m.id === editId);
      if (found) {
        setTitle(found.title || '');
        setSubject(found.subject || '');
        setBody(found.body || '');
        setTemplateId(found.template_id || '');
      }
    }
  }, [memo, editId, isEdit]);

  const saveMut = useMutation({
    mutationFn: (status: 'draft' | 'sent') => {
      const payload = { title, subject, body, status, template_id: templateId || undefined, recipients_count: recipients.length || (status === 'sent' ? 512 : 0) };
      if (isEdit && editId) {
        return mockTreasurer.updateMemo(editId, payload);
      }
      return mockTreasurer.createMemo(payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.treasurerMemos });
      setSavedMsg(isEdit ? 'Memo updated successfully.' : 'Memo created successfully.');
      setTimeout(() => navigate('/treasurer/memos'), 800);
    },
  });

  function applyTemplate(tid: string) {
    setTemplateId(tid);
    const t = (templates as any[])?.find((tpl: any) => tpl.id === tid);
    if (t) {
      setSubject(t.subject);
      setBody(t.body);
    }
  }

  if (isEdit && isLoading) return <LoadingState />;
  if (isEdit && isError) return <ErrorState message="Failed to load memo" onRetry={() => navigate('/treasurer/memos')} />;

  return (
    <div>
      <button onClick={() => navigate('/treasurer/memos')} className="inline-flex items-center gap-1.5 text-sm text-primary-700 font-medium mb-4">
        <ArrowLeft size={16} /> Back to Memos
      </button>

      <PageHeader
        title={isEdit ? 'Edit Memo' : 'Create Memo'}
        subtitle="Compose, save as draft, or send to members"
        icon={<Mail size={20} />}
      />

      {savedMsg && (
        <div className="mb-4 p-3 rounded-xl bg-success-50 border border-success-200 text-sm text-success-700">{savedMsg}</div>
      )}

      <Card className="p-5">
        <form onSubmit={e => { e.preventDefault(); }} className="space-y-4">
          {(templates as any[]) && (templates as any[]).length > 0 && (
            <Select label="Template (optional)" value={templateId} onChange={e => applyTemplate(e.target.value)}>
              <option value="">No template</option>
              {(templates as any[]).map((t: any) => (
                <option key={t.id} value={t.id}>{t.name}</option>
              ))}
            </Select>
          )}

          <Input label="Title" value={title} onChange={e => setTitle(e.target.value)} placeholder="Memo title" required />

          <Input label="Subject" value={subject} onChange={e => setSubject(e.target.value)} placeholder="Email/memo subject line" required />

          <Textarea label="Body" rows={8} value={body} onChange={e => setBody(e.target.value)} placeholder="Write the memo content here..." required />

          <div>
            <label className="label">Recipients</label>
            <div className="flex flex-wrap gap-2 mt-1">
              {['All Members', 'Executive Committee', 'Treasurer Only'].map(r => (
                <button
                  key={r}
                  type="button"
                  onClick={() => setRecipients(prev => prev.includes(r) ? prev.filter(x => x !== r) : [...prev, r])}
                  className={recipients.includes(r) ? 'badge bg-primary-100 text-primary-700' : 'badge bg-neutral-100 text-neutral-600'}
                >
                  {r}
                </button>
              ))}
            </div>
            <p className="mt-1 text-xs text-neutral-400">{recipients.length} group(s) selected</p>
          </div>

          <div className="flex justify-end gap-3 pt-2">
            <Button variant="secondary" onClick={() => saveMut.mutate('draft')} disabled={saveMut.isPending}>
              <Save size={16} /> Save Draft
            </Button>
            <Button type="submit" onClick={() => saveMut.mutate('sent')} disabled={saveMut.isPending}>
              <Send size={16} /> {saveMut.isPending ? 'Sending...' : 'Save & Send'}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
