import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  FileText, FileSpreadsheet, FileImage, File as FileIcon, Download, Calendar,
  HardDrive, Upload, Eye, RefreshCw, Trash2, AlertCircle, CheckCircle,
} from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Badge, Button, PageHeader, Tabs, Modal, Select, Textarea, Input,
  EmptyState, ErrorState, LoadingState, ConfirmDialog,
} from '@/components/ui';
import { formatDate } from '@/lib/format';
import type { Document } from '@/types';

function docIcon(type: string) {
  const t = type.toLowerCase();
  if (t.includes('sheet') || t.includes('excel') || t.includes('csv')) return <FileSpreadsheet size={18} />;
  if (t.includes('image') || t.includes('photo')) return <FileImage size={18} />;
  if (t.includes('pdf') || t.includes('document') || t.includes('text')) return <FileText size={18} />;
  return <FileIcon size={18} />;
}

const ALLOWED_TYPES = [
  { code: 'national_id', label: 'National ID' },
  { code: 'passport', label: 'Passport' },
];

export default function MemberDocuments() {
  const qc = useQueryClient();
  const [tab, setTab] = useState('all');
  const [search, setSearch] = useState('');
  const [uploadModal, setUploadModal] = useState(false);
  const [previewDoc, setPreviewDoc] = useState<Document | null>(null);
  const [replaceTarget, setReplaceTarget] = useState<Document | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Document | null>(null);
  const [uploadForm, setUploadForm] = useState({ document_type_code: '', notes: '', expires_at: '' });
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [uploadError, setUploadError] = useState('');

  const { data: documents, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberDocuments,
    queryFn: () => mockMember.getDocuments(),
  });
  const { data: summary } = useQuery({
    queryKey: ['member', 'document-summary'],
    queryFn: () => mockMember.getDocumentSummary(),
  });

  const uploadMut = useMutation({
    mutationFn: (data: any) => mockMember.uploadDocument(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.memberDocuments });
      qc.invalidateQueries({ queryKey: ['member', 'document-summary'] });
      setUploadModal(false);
      setUploadForm({ document_type_code: '', notes: '', expires_at: '' });
      setSelectedFile(null);
      setUploadError('');
    },
    onError: () => setUploadError('Failed to upload document. Please try again.'),
  });

  const replaceMut = useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) => mockMember.replaceDocument(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.memberDocuments });
      qc.invalidateQueries({ queryKey: ['member', 'document-summary'] });
      setReplaceTarget(null);
      setSelectedFile(null);
    },
  });

  const deleteMut = useMutation({
    mutationFn: (id: string) => mockMember.deleteDocument(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.memberDocuments });
      qc.invalidateQueries({ queryKey: ['member', 'document-summary'] });
      setDeleteTarget(null);
    },
  });

  const docs = (documents as Document[] | undefined) ?? [];
  const s = summary as any;

  let filtered = docs;
  if (tab === 'approved') filtered = filtered.filter(d => d.status === 'approved');
  else if (tab === 'pending') filtered = filtered.filter(d => d.status === 'pending');
  else if (tab === 'rejected') filtered = filtered.filter(d => d.status === 'rejected');
  else if (tab === 'expired') filtered = filtered.filter(d => d.status === 'expired');
  if (search) {
    const q = search.toLowerCase();
    filtered = filtered.filter(d => d.title.toLowerCase().includes(q) || d.type.toLowerCase().includes(q));
  }

  const handleUploadSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!uploadForm.document_type_code) { setUploadError('Please select a document type.'); return; }
    if (!selectedFile) { setUploadError('Please choose a file to upload.'); return; }
    setUploadError('');
    uploadMut.mutate({
      document_type_code: uploadForm.document_type_code,
      file: selectedFile,
      notes: uploadForm.notes || undefined,
      expires_at: uploadForm.expires_at || undefined,
    });
  };

  const handleReplaceSubmit = () => {
    if (!replaceTarget || !selectedFile) return;
    replaceMut.mutate({ id: replaceTarget.id, data: { file: selectedFile } });
  };

  return (
    <div className="space-y-5">
      <PageHeader
        title="My Documents"
        subtitle="Upload and manage your ID and passport documents"
        icon={<FileText size={20} />}
        action={<Button onClick={() => { setUploadModal(true); setSelectedFile(null); setUploadError(''); }}><Upload size={16} /> Upload Document</Button>}
      />

      {s && (
        <Card className="p-4">
          <div className="flex items-center justify-between mb-3">
            <p className="text-sm font-semibold text-neutral-700">Document Completion</p>
            <span className="text-sm font-bold text-primary-700">{s.completion_pct}%</span>
          </div>
          <div className="h-2 rounded-full bg-neutral-100 overflow-hidden mb-3">
            <div className="h-full rounded-full bg-primary-600 transition-all" style={{ width: `${s.completion_pct}%` }} />
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-center">
            <div><p className="text-xs text-neutral-400">Required</p><p className="text-sm font-bold text-neutral-700">{s.total}</p></div>
            <div><p className="text-xs text-neutral-400">Uploaded</p><p className="text-sm font-bold text-neutral-700">{s.uploaded}</p></div>
            <div><p className="text-xs text-neutral-400">Pending</p><p className="text-sm font-bold text-warning-600">{s.pending}</p></div>
            <div><p className="text-xs text-neutral-400">Approved</p><p className="text-sm font-bold text-success-600">{s.approved}</p></div>
            <div><p className="text-xs text-neutral-400">Rejected</p><p className="text-sm font-bold text-error-600">{s.rejected}</p></div>
          </div>
        </Card>
      )}

      <Input placeholder="Search documents..." value={search} onChange={e => setSearch(e.target.value)} />

      <Tabs
        tabs={[
          { key: 'all', label: 'All', count: docs.length },
          { key: 'approved', label: 'Approved', count: docs.filter(d => d.status === 'approved').length },
          { key: 'pending', label: 'Pending', count: docs.filter(d => d.status === 'pending').length },
          { key: 'rejected', label: 'Rejected', count: docs.filter(d => d.status === 'rejected').length },
        ]}
        active={tab}
        onChange={setTab}
      />

      {isLoading ? (
        <LoadingState text="Loading documents..." />
      ) : isError ? (
        <ErrorState message="We couldn't load your documents." onRetry={() => refetch()} />
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={<FileText size={22} />}
          title={docs.length === 0 ? 'No documents uploaded' : 'No documents found'}
          message={docs.length === 0 ? 'Upload your National ID or Passport to get started.' : 'Try adjusting your search or filter.'}
          action={docs.length === 0 ? <Button onClick={() => setUploadModal(true)}><Upload size={16} /> Upload Now</Button> : undefined}
        />
      ) : (
        <Card className="divide-y divide-neutral-50">
          {filtered.map((d) => (
            <div key={d.id} className="flex items-center gap-3 p-4">
              <div className="flex-shrink-0 w-11 h-11 rounded-xl bg-primary-50 text-primary-700 flex items-center justify-center">
                {docIcon(d.type)}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-neutral-900 truncate">{d.title}</p>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-neutral-500">
                  <span className="flex items-center gap-1"><HardDrive size={12} /> {d.size}</span>
                  <span className="flex items-center gap-1"><Calendar size={12} /> {formatDate(d.date)}</span>
                  <span className="hidden sm:inline">by {d.uploaded_by}</span>
                </div>
              </div>
              <div className="flex items-center gap-2 flex-shrink-0">
                <Badge status={d.status}>{d.status}</Badge>
                <Button variant="ghost" size="sm" onClick={() => setPreviewDoc(d)} title="Preview"><Eye size={14} /></Button>
                {d.url && <Button variant="ghost" size="sm" onClick={() => window.open(d.url, '_blank')} title="Download"><Download size={14} /></Button>}
                <Button variant="ghost" size="sm" onClick={() => { setReplaceTarget(d); setSelectedFile(null); }} title="Replace"><RefreshCw size={14} /></Button>
                <Button variant="ghost" size="sm" onClick={() => setDeleteTarget(d)} title="Delete"><Trash2 size={14} className="text-error-600" /></Button>
              </div>
            </div>
          ))}
        </Card>
      )}

      {/* Upload Modal */}
      <Modal open={uploadModal} onClose={() => setUploadModal(false)} title="Upload Document">
        <form onSubmit={handleUploadSubmit} className="space-y-4">
          {uploadError && (
            <div className="flex items-start gap-2 p-3 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
              <AlertCircle size={16} className="flex-shrink-0 mt-0.5" />
              <span>{uploadError}</span>
            </div>
          )}
          <Select label="Document Type" value={uploadForm.document_type_code} onChange={e => setUploadForm({ ...uploadForm, document_type_code: e.target.value })} required>
            <option value="">Select document type...</option>
            {ALLOWED_TYPES.map(t => <option key={t.code} value={t.code}>{t.label}</option>)}
          </Select>
          <div>
            <label className="label">Choose File</label>
            <input
              type="file"
              accept="image/png,image/jpeg,application/pdf"
              onChange={e => setSelectedFile(e.target.files?.[0] || null)}
              className="input-field"
            />
            {selectedFile && <p className="text-xs text-neutral-400 mt-1">Selected: {selectedFile.name} ({Math.round(selectedFile.size / 1024)} KB)</p>}
          </div>
          <Textarea label="Notes (optional)" rows={2} value={uploadForm.notes} onChange={e => setUploadForm({ ...uploadForm, notes: e.target.value })} placeholder="Any additional notes about this document..." />
          <Input label="Expiry Date (optional)" type="date" value={uploadForm.expires_at} onChange={e => setUploadForm({ ...uploadForm, expires_at: e.target.value })} />
          <div className="flex justify-end gap-3 pt-2">
            <Button variant="secondary" type="button" onClick={() => setUploadModal(false)}>Cancel</Button>
            <Button type="submit" disabled={uploadMut.isPending}>
              {uploadMut.isPending ? 'Uploading...' : 'Submit Upload'}
            </Button>
          </div>
        </form>
      </Modal>

      {/* Preview Modal */}
      <Modal open={!!previewDoc} onClose={() => setPreviewDoc(null)} title="Document Preview" size="lg">
        {previewDoc && (
          <div className="space-y-3">
            <div className="flex items-center gap-3 p-3 rounded-xl bg-neutral-50">
              <div className="w-10 h-10 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center">{docIcon(previewDoc.type)}</div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-neutral-900 truncate">{previewDoc.title}</p>
                <p className="text-xs text-neutral-400">{previewDoc.type} • {previewDoc.size} • {formatDate(previewDoc.date)}</p>
              </div>
              <Badge status={previewDoc.status}>{previewDoc.status}</Badge>
            </div>
            {previewDoc.url ? (
              <div className="rounded-xl border border-neutral-100 overflow-hidden">
                {previewDoc.url.match(/\.(png|jpg|jpeg|gif|webp)$/i) ? (
                  <img src={previewDoc.url} alt={previewDoc.title} className="w-full max-h-96 object-contain" />
                ) : previewDoc.url.match(/\.pdf$/i) ? (
                  <iframe src={previewDoc.url} className="w-full h-96" title={previewDoc.title} />
                ) : (
                  <div className="p-8 text-center text-sm text-neutral-500">
                    <FileIcon size={32} className="mx-auto mb-2 text-neutral-300" />
                    <p>Preview not available for this file type.</p>
                    <a href={previewDoc.url} download className="text-primary-600 mt-2 inline-block">Download file</a>
                  </div>
                )}
              </div>
            ) : (
              <div className="p-8 text-center text-sm text-neutral-500">
                <FileIcon size={32} className="mx-auto mb-2 text-neutral-300" />
                <p>No file URL available. The document may still be processing.</p>
              </div>
            )}
          </div>
        )}
      </Modal>

      {/* Replace Modal */}
      <Modal open={!!replaceTarget} onClose={() => setReplaceTarget(null)} title="Replace Document">
        {replaceTarget && (
          <div className="space-y-4">
            <p className="text-sm text-neutral-600">Replace <span className="font-medium text-neutral-900">{replaceTarget.title}</span> with a new file. The old file will be replaced.</p>
            <div>
              <label className="label">Choose New File</label>
              <input
                type="file"
                accept="image/png,image/jpeg,application/pdf"
                onChange={e => setSelectedFile(e.target.files?.[0] || null)}
                className="input-field"
              />
              {selectedFile && <p className="text-xs text-neutral-400 mt-1">Selected: {selectedFile.name}</p>}
            </div>
            <div className="flex justify-end gap-3 pt-2">
              <Button variant="secondary" onClick={() => setReplaceTarget(null)}>Cancel</Button>
              <Button onClick={handleReplaceSubmit} disabled={!selectedFile || replaceMut.isPending}>
                {replaceMut.isPending ? 'Replacing...' : 'Replace Document'}
              </Button>
            </div>
          </div>
        )}
      </Modal>

      {/* Delete Confirmation */}
      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteTarget && deleteMut.mutate(deleteTarget.id)}
        title="Delete Document"
        message={`Are you sure you want to delete "${deleteTarget?.title}"? This action cannot be undone.`}
        confirmLabel="Delete"
        danger
      />
    </div>
  );
}
