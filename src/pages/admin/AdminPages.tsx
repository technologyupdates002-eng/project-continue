import { GenericListPage, ImportPage, GenericFormPage, GenericDashboardPage } from '@/components/GenericPages';
import { mockAdmin, mockTreasurer } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import { Badge } from '@/components/ui';
import { formatCurrency, formatDate } from '@/lib/format';
import {
  Coins, Upload, UserPlus, Users, CreditCard, AlertTriangle,
  MessageSquare, Calendar, FileText, Newspaper, UserCheck,
  AlertCircle, Bell, Settings, Landmark, PenTool, CheckCircle,
  Wallet, HeartHandshake, Megaphone, FileCheck, BadgeCheck,
  ClipboardList,
} from 'lucide-react';

export function AdminContributions() {
  return <GenericListPage config={{
    title: 'Contributions', subtitle: 'Manage all member contributions', icon: Coins,
    searchPlaceholder: 'Search contributions...',
    fetchFn: (p) => mockAdmin.getContributions({ page: p.page, status: p.status }),
    queryKey: (p) => queryKeys.adminContributions(p),
    statusFilters: [{ value: 'confirmed', label: 'Confirmed' }, { value: 'pending', label: 'Pending' }, { value: 'failed', label: 'Failed' }],
    exportFilename: 'admin-contributions.csv',
    columns: [
      { key: 'member_name', label: 'Member', render: r => <span className="font-medium">{r.member_name}</span> },
      { key: 'amount', label: 'Amount', render: r => <span className="font-semibold">{formatCurrency(r.amount)}</span> },
      { key: 'type', label: 'Type' }, { key: 'method', label: 'Method' },
      { key: 'period', label: 'Period' },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminImport() {
  return <ImportPage title="Import Member Data" icon={Upload} onImport={(file) => mockAdmin.importExcel(file)} />;
}

export function AdminBeneficiaryImport() {
  return <ImportPage title="Import Beneficiaries" icon={UserPlus} onImport={(file) => mockAdmin.importBeneficiaries(file)} />;
}

export function AdminBeneficiaries() {
  return <GenericListPage config={{
    title: 'Beneficiaries', subtitle: 'All member beneficiaries', icon: Users,
    fetchFn: (p) => mockAdmin.getBeneficiaries(p.page), queryKey: (p) => queryKeys.adminBeneficiaries(p.page),
    exportFilename: 'beneficiaries.csv',
    columns: [
      { key: 'name', label: 'Name', render: r => <span className="font-medium">{r.name}</span> },
      { key: 'relationship', label: 'Relationship' },
      { key: 'phone', label: 'Phone' },
      { key: 'percentage', label: 'Percentage', render: r => <Badge>{r.percentage}%</Badge> },
    ],
  }} />;
}

export function AdminPayments() {
  return <GenericListPage config={{
    title: 'Payments', subtitle: 'Review and process payments', icon: CreditCard,
    fetchFn: (p) => mockAdmin.getPayments(p.page), queryKey: (p) => queryKeys.adminPayments(p.page),
    exportFilename: 'payments.csv',
    statusFilters: [{ value: 'confirmed', label: 'Confirmed' }, { value: 'pending', label: 'Pending' }],
    columns: [
      { key: 'member_name', label: 'Member' },
      { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
      { key: 'method', label: 'Method' },
      { key: 'reference', label: 'Reference', render: r => <span className="font-mono text-xs">{r.reference}</span> },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminUnmatchedPayments() {
  return <GenericListPage config={{
    title: 'Unmatched Payments', subtitle: 'Resolve payments without member matches', icon: AlertTriangle,
    fetchFn: () => mockAdmin.getUnmatchedPayments().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminUnmatched,
    columns: [
      { key: 'reference', label: 'Reference', render: r => <span className="font-mono text-xs">{r.reference}</span> },
      { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status="pending">Unmatched</Badge> },
    ],
  }} />;
}

export function AdminBulkSms() {
  return <GenericFormPage
    title="Bulk SMS" subtitle="Send SMS to members" icon={MessageSquare}
    fields={[{ key: 'message', label: 'Message', type: 'textarea', value: '' }]}
    onSubmit={async (data) => { await mockAdmin.sendBulkSms({ message: data.message, recipients: [] }); }}
    submitLabel="Send SMS"
  />;
}

export function AdminEvents() {
  return <GenericListPage config={{
    title: 'Events', subtitle: 'Manage KHWWC events', icon: Calendar,
    fetchFn: () => mockAdmin.getEvents().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminEvents,
    statusFilters: [{ value: 'upcoming', label: 'Upcoming' }, { value: 'past', label: 'Past' }],
    columns: [
      { key: 'title', label: 'Title', render: r => <span className="font-medium">{r.title}</span> },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'location', label: 'Location' },
      { key: 'attendees_count', label: 'Attendees' },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminDocuments() {
  return <GenericListPage config={{
    title: 'Documents', subtitle: 'Review and manage documents', icon: FileText,
    fetchFn: () => mockAdmin.getDocuments().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminDocuments,
    columns: [
      { key: 'title', label: 'Title', render: r => <span className="font-medium">{r.title}</span> },
      { key: 'type', label: 'Type' },
      { key: 'size', label: 'Size' },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminNews() {
  return <GenericListPage config={{
    title: 'News', subtitle: 'Create and manage news articles', icon: Newspaper,
    fetchFn: ({ page }) => mockAdmin.getNews(page),
    queryKey: (p) => ['admin', 'news', p.page],
    columns: [
      { key: 'title', label: 'Title', render: r => <span className="font-medium">{r.title}</span> },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
    ],
  }} />;
}

export function AdminBeneficiaryRequests() {
  return <GenericListPage config={{
    title: 'Beneficiary Requests', subtitle: 'Review member beneficiary requests', icon: UserCheck,
    fetchFn: () => mockAdmin.getBeneficiaries(1).then(d => d),
    queryKey: () => ['admin', 'beneficiary-requests'],
    columns: [
      { key: 'name', label: 'Beneficiary' },
      { key: 'relationship', label: 'Relationship' },
      { key: 'percentage', label: 'Percentage', render: r => <Badge>{r.percentage}%</Badge> },
    ],
  }} />;
}

export function AdminDefaulters() {
  return <GenericListPage config={{
    title: 'Defaulters', subtitle: 'Members with outstanding contributions', icon: AlertCircle,
    fetchFn: () => mockAdmin.getDefaulters().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminDefaulters,
    exportFilename: 'defaulters.csv',
    columns: [
      { key: 'member_name', label: 'Member', render: r => <span className="font-medium">{r.member_name}</span> },
      { key: 'member_number', label: 'Member #' },
      { key: 'phone', label: 'Phone' },
      { key: 'missed_periods', label: 'Missed' },
      { key: 'outstanding_amount', label: 'Outstanding', render: r => <span className="text-error-600 font-semibold">{formatCurrency(r.outstanding_amount)}</span> },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminNotifications() {
  return <GenericListPage config={{
    title: 'Notifications', subtitle: 'System notifications and alerts', icon: Bell,
    fetchFn: () => mockAdmin.getNotifications().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminNotifications,
    columns: [
      { key: 'title', label: 'Title', render: r => <span className="font-medium">{r.title}</span> },
      { key: 'type', label: 'Type', render: r => <Badge status={r.type}>{r.type}</Badge> },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'read', label: 'Read', render: r => <Badge status={r.read ? 'confirmed' : 'pending'}>{r.read ? 'Read' : 'Unread'}</Badge> },
    ],
  }} />;
}

export function AdminSettings() {
  return <GenericFormPage
    title="Settings" subtitle="General application settings" icon={Settings}
    fields={[
      { key: 'org_name', label: 'Organization Name', value: 'Kirinyaga Healthcare Workers Welfare' },
      { key: 'email', label: 'Contact Email', value: 'info@khwwc.co.ke' },
      { key: 'phone', label: 'Contact Phone', value: '+254 700 100 200' },
      { key: 'address', label: 'Address', value: 'Kerugoya, Kirinyaga County' },
    ]}
    onSubmit={async (data) => { await mockAdmin.updateSettings(data); }}
  />;
}

export function AdminBankDiagnostics() {
  return <GenericDashboardPage
    title="Banking Diagnostics" subtitle="Bank connection health and reconciliation" icon={Landmark}
    stats={[
      { label: 'Connection', value: 'Active', icon: CheckCircle, color: 'success' },
      { label: 'Last Sync', value: '1 day ago', icon: Landmark },
      { label: 'Unmatched', value: '2', icon: AlertTriangle, color: 'warning' },
      { label: 'Reconciliation', value: 'KES 33.5K', icon: AlertCircle, color: 'error' },
    ]}
  />;
}

export function AdminOfficeSignatures() {
  return <GenericFormPage
    title="Office Signatures" subtitle="Manage official office signatures" icon={PenTool}
    fields={[{ key: 'chair', label: 'Chairperson Signature', value: 'Dr. Robert Kibochi' }, { key: 'treasurer', label: 'Treasurer Signature', value: 'David Mwangi Kuria' }, { key: 'secretary', label: 'Secretary Signature', value: 'Lucy Wangari Kamau' }]}
    onSubmit={async (data) => { await mockAdmin.updateOfficeSignatures(data); }}
  />;
}

export function AdminVerifyPenaltyPayments() {
  return <GenericListPage config={{
    title: 'Verify Penalty Payments', subtitle: 'Approve pending penalty payments', icon: CheckCircle,
    fetchFn: () => mockAdmin.getPenalties().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminPenalties,
    columns: [
      { key: 'member_name', label: 'Member' },
      { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
      { key: 'reason', label: 'Reason' },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminPenaltyWallet() {
  return <GenericListPage config={{
    title: 'Penalty Wallet', subtitle: 'Penalty wallet transactions', icon: Wallet,
    fetchFn: (p) => mockTreasurer.getPenaltyWallet(p.page), queryKey: (p) => queryKeys.treasurerWallet('penalty', p.page),
    columns: [
      { key: 'description', label: 'Description' },
      { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminDonations() {
  return <GenericListPage config={{
    title: 'Donations', subtitle: 'All member donations', icon: HeartHandshake,
    fetchFn: () => mockAdmin.getDonations().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminDonations,
    exportFilename: 'donations.csv',
    columns: [
      { key: 'member_name', label: 'Member' },
      { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
      { key: 'campaign', label: 'Campaign' },
      { key: 'method', label: 'Method' },
      { key: 'date', label: 'Date', render: r => formatDate(r.date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminDonationCampaigns() {
  return <GenericListPage config={{
    title: 'Donation Campaigns', subtitle: 'Manage fundraising campaigns', icon: Megaphone,
    fetchFn: () => mockAdmin.getDonationCampaigns().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminCampaigns,
    columns: [
      { key: 'title', label: 'Campaign', render: r => <span className="font-medium">{r.title}</span> },
      { key: 'raised', label: 'Raised', render: r => formatCurrency(r.raised) },
      { key: 'target', label: 'Target', render: r => formatCurrency(r.target) },
      { key: 'contributors', label: 'Contributors' },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminWithdrawalApproval() {
  return <GenericListPage config={{
    title: 'Withdrawal Approvals', subtitle: 'Approve member withdrawal requests', icon: FileCheck,
    fetchFn: () => mockAdmin.getWithdrawalApprovals().then(d => ({ data: d, current_page: 1, last_page: 1, total: d.length })),
    queryKey: () => queryKeys.adminWithdrawals,
    columns: [
      { key: 'member_name', label: 'Member' },
      { key: 'amount', label: 'Amount', render: r => formatCurrency(r.amount) },
      { key: 'reason', label: 'Reason' },
      { key: 'requested_date', label: 'Requested', render: r => formatDate(r.requested_date) },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminRegistrationSettings() {
  return <GenericFormPage
    title="Registration Settings" subtitle="Configure member registration options" icon={Settings}
    fields={[
      { key: 'reg_open', label: 'Registration Status', type: 'text', value: 'Open' },
      { key: 'fee', label: 'Registration Fee (KES)', type: 'number', value: '5000' },
      { key: 'monthly', label: 'Monthly Contribution (KES)', type: 'number', value: '2000' },
    ]}
    onSubmit={async (data) => { await mockAdmin.updateRegistrationSettings(data); }}
  />;
}

export function AdminRegistrationManagement() {
  return <GenericListPage config={{
    title: 'Registration Management', subtitle: 'Review membership applications', icon: ClipboardList,
    fetchFn: ({ page }) => mockAdmin.getRegistrationApplicants(page),
    queryKey: (p) => ['admin', 'registration-management', p.page],
    columns: [
      { key: 'name', label: 'Applicant' },
      { key: 'email', label: 'Email' },
      { key: 'phone', label: 'Phone' },
      { key: 'status', label: 'Status', render: r => <Badge status={r.status}>{r.status}</Badge> },
    ],
  }} />;
}

export function AdminRegistrationApiDocs() {
  return <GenericDashboardPage
    title="Registration API Docs" subtitle="API reference for registration integration" icon={Settings}
    stats={[
      { label: 'Endpoint', value: '/api/register', icon: Settings },
      { label: 'Method', value: 'POST', icon: Settings },
      { label: 'Auth', value: 'Public', icon: Settings },
      { label: 'Rate Limit', value: '5/min', icon: Settings },
    ]}
  />;
}

export function AdminExecutiveBadges() {
  return <GenericListPage config={{
    title: 'Executive Badges', subtitle: 'Manage executive role badges and profiles', icon: BadgeCheck,
    fetchFn: ({ page }) => mockAdmin.getExecutiveBadges(page),
    queryKey: (p) => ['admin', 'executive-badges', p.page],
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'title', label: 'Title' },
      { key: 'badge', label: 'Badge', render: r => <Badge>{r.badge || 'N/A'}</Badge> },
    ],
  }} />;
}
