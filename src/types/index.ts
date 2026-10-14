export type UserRole =
  | 'member'
  | 'admin'
  | 'treasurer'
  | 'secretary'
  | 'vice-secretary'
  | 'chairperson'
  | 'office'
  | 'super-admin';

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  avatar?: string;
  member_id?: string;
  phone?: string;
  must_change_password?: boolean;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;
  user: AuthUser;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Contribution {
  id: string;
  member_id: string;
  member_name: string;
  amount: number;
  type: string;
  status: 'pending' | 'confirmed' | 'failed';
  method: string;
  reference: string;
  period: string;
  date: string;
}

export interface Member {
  id: string;
  name: string;
  email: string;
  phone: string;
  member_number: string;
  role: UserRole;
  status: 'active' | 'inactive' | 'suspended';
  joined_date: string;
  contributions_total: number;
  penalties_total: number;
  donations_total: number;
  avatar?: string;
  county: string;
  workplace: string;
}

export interface Beneficiary {
  id: string;
  member_id: string;
  name: string;
  relationship: string;
  phone: string;
  email: string;
  percentage: number;
  id_number: string;
  date_of_birth: string;
}

export interface Notification {
  id: string;
  title: string;
  message: string;
  type: 'info' | 'success' | 'warning' | 'error';
  read: boolean;
  date: string;
}

export interface WalletTransaction {
  id: string;
  wallet: string;
  type: 'credit' | 'debit';
  amount: number;
  description: string;
  reference: string;
  balance_after: number;
  date: string;
  status: string;
}

export interface WalletSummary {
  name: string;
  balance: number;
  transactions_count: number;
  last_activity: string;
}

export interface Document {
  id: string;
  title: string;
  type: string;
  status: 'pending' | 'approved' | 'rejected' | 'expired';
  size: string;
  uploaded_by: string;
  date: string;
  url?: string;
}

export interface NewsArticle {
  id: string;
  title: string;
  slug: string;
  excerpt: string;
  content: string;
  category: string;
  status: 'published' | 'draft' | 'archived';
  author: string;
  date: string;
  image?: string;
  featured: boolean;
}

export interface EventItem {
  id: string;
  title: string;
  description: string;
  date: string;
  end_date?: string;
  location: string;
  status: 'upcoming' | 'ongoing' | 'past' | 'cancelled';
  attendees_count: number;
  image?: string;
}

export interface LeadershipPerson {
  id: string;
  name: string;
  slug: string;
  title: string;
  position: string;
  bio: string;
  photo?: string;
  email?: string;
  phone?: string;
  order: number;
}

export interface WithdrawalRequest {
  id: string;
  member_id: string;
  member_name: string;
  amount: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected' | 'paid';
  requested_date: string;
  approved_date?: string;
  approved_by?: string;
}

export interface Penalty {
  id: string;
  member_id: string;
  member_name: string;
  amount: number;
  reason: string;
  status: 'unpaid' | 'paid' | 'waived';
  date: string;
  paid_date?: string;
}

export interface Donation {
  id: string;
  member_id: string;
  member_name: string;
  amount: number;
  campaign: string;
  method: string;
  reference: string;
  status: 'pending' | 'confirmed' | 'failed';
  date: string;
}

export interface DonationCampaign {
  id: string;
  title: string;
  description: string;
  target: number;
  raised: number;
  contributors: number;
  status: 'active' | 'completed' | 'paused';
  start_date: string;
  end_date: string;
  image?: string;
}

export interface ContactMessage {
  id: string;
  name: string;
  email: string;
  subject: string;
  message: string;
  read: boolean;
  date: string;
}

export interface WebsiteContent {
  id: string;
  type: string;
  title: string;
  slug: string;
  content: string;
  status: 'published' | 'draft' | 'archived';
  author: string;
  date: string;
  excerpt?: string;
  image?: string;
  category?: string;
}

export interface WebsiteSettings {
  organization_name: string;
  tagline: string;
  about: string;
  email: string;
  phone: string;
  address: string;
  logo?: string;
  primary_color: string;
  social: {
    facebook?: string;
    twitter?: string;
    linkedin?: string;
    instagram?: string;
  };
}

export interface MeetingMinutes {
  id: string;
  title: string;
  meeting_date: string;
  meeting_type?: 'general' | 'executive';
  status: 'draft' | 'pending_review' | 'approved' | 'rejected' | 'submitted_to_secretary' | 'pending_chairperson_approval' | 'rejected_by_secretary';
  prepared_by: string;
  reviewed_by?: string;
  approved_by?: string;
  content: string;
  attendees: number;
  file_url?: string;
  rejection_notes?: string | null;
}

export interface ScheduledMeeting {
  id: string;
  title: string;
  date: string;
  time: string;
  location: string;
  type: string;
  agenda: string[];
  attendees_count: number;
  status: 'scheduled' | 'completed' | 'cancelled';
}

export interface AuditLog {
  id: string;
  user: string;
  action: string;
  module: string;
  ip: string;
  date: string;
  details: string;
}

export interface BankTransaction {
  id: string;
  date: string;
  description: string;
  reference: string;
  amount: number;
  type: 'credit' | 'debit';
  matched: boolean;
  matched_contribution?: string;
}

export interface Defaulter {
  id: string;
  member_name: string;
  member_number: string;
  phone: string;
  missed_periods: number;
  outstanding_amount: number;
  last_payment_date: string;
  status: 'warning' | 'critical' | 'action';
}

export interface Expense {
  id: string;
  description: string;
  amount: number;
  category: string;
  date: string;
  status: 'pending' | 'approved' | 'paid' | 'rejected';
  approved_by?: string;
  receipt_url?: string;
}

export type PayoutType = 'wedding' | 'death_member' | 'death_spouse' | 'death_child' | 'death_parent' | 'retirement' | 'emergency' | 'medical';

export interface Payout {
  id: string;
  reference: string;
  member_id: string;
  member_name: string;
  member_number: string;
  phone: string;
  payout_type: PayoutType;
  relation: string;
  amount: number;
  reason: string;
  wallet_type: 'operational' | 'donation' | 'penalty';
  status: 'pending' | 'approved' | 'paid' | 'rejected';
  created_at: string;
  paid_at?: string;
  signatories?: { role: string; status: string }[];
}

export interface EligibleMember {
  id: string;
  name: string;
  member_number: string;
  phone: string;
  eligible_amount: number;
}

export interface EmailTemplate {
  id: string;
  name: string;
  subject: string;
  body: string;
  variables: string[];
  last_modified: string;
}

export interface MediaAsset {
  id: string;
  name: string;
  type: string;
  size: string;
  url: string;
  uploaded_date: string;
}

export interface OnlineUser {
  id: string;
  name: string;
  email: string;
  role: UserRole;
  last_activity: string;
  ip: string;
  device: string;
}

export interface LoginActivity {
  id: string;
  user: string;
  email: string;
  ip: string;
  device: string;
  status: 'success' | 'failed';
  date: string;
}

export interface SystemMetric {
  label: string;
  value: string;
  status: 'healthy' | 'warning' | 'critical';
  detail?: string;
}

export interface AccessRole {
  id: string;
  name: string;
  description: string;
  permissions: string[];
  users_count: number;
}

export interface RegistrationApplicant {
  id: string;
  name: string;
  email: string;
  phone: string;
  county: string;
  workplace: string;
  status: 'pending' | 'approved' | 'rejected';
  date: string;
}

export interface Memo {
  id: string;
  title: string;
  subject: string;
  body: string;
  status: 'draft' | 'sent';
  prepared_by: string;
  recipients_count: number;
  created_at: string;
  sent_at?: string;
  template_id?: string;
}

export interface MemoTemplate {
  id: string;
  name: string;
  subject: string;
  body: string;
}

export interface ChatConversation {
  id: string;
  user_id: string;
  user_role: string;
  context_type: 'admin_assistant' | 'secretary_assistant';
  title: string | null;
  created_at: string;
  updated_at: string;
}

export interface ChatMessage {
  id: string;
  conversation_id: string;
  role: 'user' | 'assistant';
  content: string;
  created_at: string;
}

export interface GroupConversation {
  id: string;
  name: string | null;
  type: 'group' | 'direct';
  avatar_url: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
  participants?: GroupParticipant[];
  last_message?: GroupMessage | null;
  unread_count?: number;
}

export interface GroupParticipant {
  id: string;
  conversation_id: string;
  user_id: string;
  user_name: string;
  user_avatar: string | null;
  role: 'admin' | 'member';
  last_read_at: string | null;
  joined_at: string;
  is_typing?: boolean;
}

export interface GroupMessage {
  id: string;
  conversation_id: string;
  user_id: string;
  user_name: string;
  user_avatar: string | null;
  content: string;
  reply_to_id: string | null;
  reply_to?: GroupMessage | null;
  mentions: string[];
  created_at: string;
  reactions?: GroupReaction[];
}

export interface GroupReaction {
  id: string;
  message_id: string;
  user_id: string;
  emoji: string;
  created_at: string;
}

export interface ChatTypingUser {
  id: string;
  conversation_id: string;
  user_id: string;
  user_name: string;
  updated_at: string;
}

export interface OrganizationSettings {
  id: string;
  organization_name: string;
  tagline: string;
  email: string;
  phone: string;
  address: string;
  logo_url?: string;
  signature_url?: string;
  stamp_url?: string;
  payout_bereavement: number;
  payout_medical: number;
  payout_education: number;
}
