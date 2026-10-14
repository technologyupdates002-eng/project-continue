import type {
  Member,
  Contribution,
  Beneficiary,
  Notification,
  Document,
  EventItem,
  NewsArticle,
  LeadershipPerson,
  WebsiteSettings,
  WebsiteContent,
  ContactMessage,
  WalletTransaction,
  WalletSummary,
  WithdrawalRequest,
  Penalty,
  Donation,
  DonationCampaign,
  Defaulter,
  Expense,
  BankTransaction,
  MeetingMinutes,
  ScheduledMeeting,
  AuditLog,
  AccessRole,
  OnlineUser,
  LoginActivity,
  SystemMetric,
  EmailTemplate,
  MediaAsset,
  RegistrationApplicant,
  AuthUser,
  Memo,
  MemoTemplate,
  OrganizationSettings,
  Payout,
  EligibleMember,
} from '@/types';

function daysFromNow(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString();
}

function daysAgo(days: number): string {
  return daysFromNow(-days);
}

export const demoUser: AuthUser = {
  id: 'usr-001',
  name: 'Dr. Sarah Wanjiku Mwangi',
  email: 'sarah.mwangi@khwwc.co.ke',
  role: 'member',
  member_id: 'KHW-2024-0142',
  phone: '+254 712 345 678',
  must_change_password: false,
};

export const demoMembers: Member[] = [
  {
    id: 'm1', name: 'Dr. Sarah Wanjiku Mwangi', email: 'sarah.mwangi@khwwc.co.ke',
    phone: '+254 712 345 678', member_number: 'KHW-2024-0142', role: 'member',
    status: 'active', joined_date: '2024-01-15', contributions_total: 84000,
    penalties_total: 1500, donations_total: 12000, county: 'Kirinyaga',
    workplace: 'Kerugoya County Referral Hospital',
  },
  {
    id: 'm2', name: 'John Kamau Njoroge', email: 'j.kamau@khwwc.co.ke',
    phone: '+254 722 456 789', member_number: 'KHW-2023-0089', role: 'member',
    status: 'active', joined_date: '2023-03-20', contributions_total: 72000,
    penalties_total: 0, donations_total: 5000, county: 'Kirinyaga',
    workplace: 'Sagana Sub-County Hospital',
  },
  {
    id: 'm3', name: 'Mary Wambui Kariuki', email: 'm.wambui@khwwc.co.ke',
    phone: '+254 733 567 890', member_number: 'KHW-2023-0056', role: 'member',
    status: 'active', joined_date: '2023-02-10', contributions_total: 78000,
    penalties_total: 0, donations_total: 8000, county: 'Kirinyaga',
    workplace: 'Kutus Sub-County Hospital',
  },
  {
    id: 'm4', name: 'Peter Mbugua Thuo', email: 'p.mbugua@khwwc.co.ke',
    phone: '+254 700 678 901', member_number: 'KHW-2022-0103', role: 'member',
    status: 'suspended', joined_date: '2022-06-05', contributions_total: 45000,
    penalties_total: 4500, donations_total: 0, county: 'Kirinyaga',
    workplace: 'Kimbimbi Sub-County Hospital',
  },
  {
    id: 'm5', name: 'Grace Njeri Wangari', email: 'g.njeri@khwwc.co.ke',
    phone: '+254 711 789 012', member_number: 'KHW-2024-0155', role: 'member',
    status: 'active', joined_date: '2024-03-01', contributions_total: 21000,
    penalties_total: 0, donations_total: 15000, county: 'Kirinyaga',
    workplace: 'Kirinyaga County Hospital',
  },
  {
    id: 'm6', name: 'James Otieno Omondi', email: 'j.otieno@khwwc.co.ke',
    phone: '+254 723 890 123', member_number: 'KHW-2022-0078', role: 'member',
    status: 'active', joined_date: '2022-04-12', contributions_total: 96000,
    penalties_total: 0, donations_total: 20000, county: 'Kirinyaga',
    workplace: 'Kerugoya County Referral Hospital',
  },
  {
    id: 'm7', name: 'Esther Nyambura Githae', email: 'e.nyambura@khwwc.co.ke',
    phone: '+254 745 901 234', member_number: 'KHW-2023-0110', role: 'member',
    status: 'inactive', joined_date: '2023-05-18', contributions_total: 30000,
    penalties_total: 3000, donations_total: 2000, county: 'Kirinyaga',
    workplace: 'Sagana Sub-County Hospital',
  },
  {
    id: 'm8', name: 'Daniel Kiprop Kiptoo', email: 'd.kiprop@khwwc.co.ke',
    phone: '+254 756 012 345', member_number: 'KHW-2024-0167', role: 'member',
    status: 'active', joined_date: '2024-04-20', contributions_total: 15000,
    penalties_total: 0, donations_total: 3000, county: 'Kirinyaga',
    workplace: 'Kutus Sub-County Hospital',
  },
];

export const demoContributions: Contribution[] = [
  { id: 'c1', member_id: 'm1', member_name: 'Dr. Sarah Wanjiku', amount: 2000, type: 'Monthly', status: 'confirmed', method: 'M-Pesa', reference: 'QKJ4X9PL21', period: 'Oct 2026', date: daysAgo(2) },
  { id: 'c2', member_id: 'm1', member_name: 'Dr. Sarah Wanjiku', amount: 2000, type: 'Monthly', status: 'confirmed', method: 'M-Pesa', reference: 'QKJ4X9PL18', period: 'Sep 2026', date: daysAgo(32) },
  { id: 'c3', member_id: 'm1', member_name: 'Dr. Sarah Wanjiku', amount: 2000, type: 'Monthly', status: 'confirmed', method: 'M-Pesa', reference: 'QKJ4X9PL15', period: 'Aug 2026', date: daysAgo(62) },
  { id: 'c4', member_id: 'm1', member_name: 'Dr. Sarah Wanjiku', amount: 5000, type: 'Registration', status: 'confirmed', method: 'Bank', reference: 'BNK001245', period: 'One-time', date: daysAgo(270) },
  { id: 'c5', member_id: 'm2', member_name: 'John Kamau', amount: 2000, type: 'Monthly', status: 'confirmed', method: 'M-Pesa', reference: 'QKJ4X9PL22', period: 'Oct 2026', date: daysAgo(1) },
  { id: 'c6', member_id: 'm3', member_name: 'Mary Wambui', amount: 2000, type: 'Monthly', status: 'pending', method: 'M-Pesa', reference: 'QKJ4X9PL23', period: 'Oct 2026', date: daysAgo(0) },
  { id: 'c7', member_id: 'm4', member_name: 'Peter Mbugua', amount: 2000, type: 'Monthly', status: 'failed', method: 'M-Pesa', reference: 'QKJ4X9PL19', period: 'Oct 2026', date: daysAgo(3) },
  { id: 'c8', member_id: 'm5', member_name: 'Grace Njeri', amount: 2000, type: 'Monthly', status: 'confirmed', method: 'M-Pesa', reference: 'QKJ4X9PL20', period: 'Oct 2026', date: daysAgo(4) },
];

export const demoBeneficiaries: Beneficiary[] = [
  { id: 'b1', member_id: 'm1', name: 'Michael Mwangi', relationship: 'Spouse', phone: '+254 712 987 654', email: 'm.mwangi@email.com', percentage: 50, id_number: '32145678', date_of_birth: '1985-06-15' },
  { id: 'b2', member_id: 'm1', name: 'Joy Wanjiku Mwangi', relationship: 'Daughter', phone: '', email: '', percentage: 30, id_number: '12987654', date_of_birth: '2015-03-20' },
  { id: 'b3', member_id: 'm1', name: 'David Mwangi Jr', relationship: 'Son', phone: '', email: '', percentage: 20, id_number: '12987655', date_of_birth: '2018-09-10' },
];

export const demoNotifications: Notification[] = [
  { id: 'n1', title: 'Contribution Confirmed', message: 'Your October 2026 contribution of KES 2,000 has been confirmed.', type: 'success', read: false, date: daysAgo(2) },
  { id: 'n2', title: 'AGM Reminder', message: 'The Annual General Meeting is scheduled for 15th November 2026.', type: 'info', read: false, date: daysAgo(5) },
  { id: 'n3', title: 'Penalty Outstanding', message: 'You have an outstanding penalty of KES 1,500. Please clear it to avoid suspension.', type: 'warning', read: true, date: daysAgo(10) },
  { id: 'n4', title: 'Donation Campaign', message: 'A new donation campaign "Medical Equipment Fund" is now active.', type: 'info', read: true, date: daysAgo(15) },
];

export const demoDocuments: Document[] = [
  { id: 'd1', title: 'Contribution Statement Jan-Oct 2026', type: 'Statement', status: 'approved', size: '245 KB', uploaded_by: 'System', date: daysAgo(2) },
  { id: 'd2', title: 'Membership Card', type: 'Card', status: 'approved', size: '120 KB', uploaded_by: 'Admin', date: daysAgo(60) },
  { id: 'd3', title: 'AGM Minutes 2025', type: 'Minutes', status: 'approved', size: '1.2 MB', uploaded_by: 'Secretary', date: daysAgo(180) },
  { id: 'd4', title: 'ID Document', type: 'Identification', status: 'pending', size: '850 KB', uploaded_by: 'You', date: daysAgo(1) },
];

export const demoEvents: EventItem[] = [
  { id: 'e1', title: 'Annual General Meeting 2026', description: 'Annual meeting to review welfare performance, elect officials, and set the agenda for 2027.', date: daysFromNow(33), location: 'Kerugoya County Hall', status: 'upcoming', attendees_count: 245 },
  { id: 'e2', title: 'Medical Equipment Fundraiser', description: 'Community fundraiser for new diagnostic equipment at Kerugoya Hospital.', date: daysFromNow(14), location: 'Sagana Community Centre', status: 'upcoming', attendees_count: 120 },
  { id: 'e3', title: 'Members Wellness Day', description: 'Free health screening and wellness talks for all KHWWC members.', date: daysFromNow(7), location: 'Kutus Hospital Grounds', status: 'upcoming', attendees_count: 85 },
  { id: 'e4', title: 'Q3 Members Meeting', description: 'Quarterly review meeting for all members.', date: daysAgo(15), location: 'Kerugoya County Hall', status: 'past', attendees_count: 180 },
];

export const demoNews: NewsArticle[] = [
  { id: 'nw1', title: 'KHWWC Surpasses 500 Member Milestone', slug: 'khwwc-surpasses-500-members', excerpt: 'The welfare organization has reached a significant milestone with over 500 active members.', content: 'The Kirinyaga Healthcare Workers Welfare has achieved a remarkable milestone of 500 active members, marking significant growth since its inception in 2022...', category: 'Announcement', status: 'published', author: 'Office', date: daysAgo(5), featured: true },
  { id: 'nw2', title: 'New Medical Equipment Fund Launched', slug: 'new-medical-equipment-fund', excerpt: 'A new donation campaign aims to raise KES 5M for diagnostic equipment.', content: 'The KHWWC has launched a new donation campaign to raise KES 5 million for state-of-the-art diagnostic equipment...', category: 'Campaign', status: 'published', author: 'Treasurer', date: daysAgo(12), featured: true },
  { id: 'nw3', title: 'AGM 2026 Announced', slug: 'agm-2026-announced', excerpt: 'The Annual General Meeting will be held on 15th November 2026.', content: 'The KHWWC Annual General Meeting for 2026 has been officially announced...', category: 'Event', status: 'published', author: 'Secretary', date: daysAgo(20), featured: false },
  { id: 'nw4', title: 'New Penalty Policy Effective October 2026', slug: 'new-penalty-policy-october-2026', excerpt: 'Updated penalty rules for missed contributions take effect this month.', content: 'The KHWWC management has approved an updated penalty policy effective October 2026...', category: 'Policy', status: 'published', author: 'Admin', date: daysAgo(30), featured: false },
];

export const demoLeadership: LeadershipPerson[] = [
  { id: 'l1', name: 'Dr. Robert Kibochi', slug: 'dr-robert-kibochi', title: 'Chairperson', position: 'Chairperson', bio: 'Dr. Kibochi is a seasoned medical practitioner with over 20 years of experience in healthcare management. He has been leading KHWWC since 2022, guiding its growth to over 500 members.', order: 1, email: 'chairperson@khwwc.co.ke', phone: '+254 700 111 111' },
  { id: 'l2', name: 'Jane Wairimu Ndegwa', slug: 'jane-wairimu-ndegwa', title: 'Vice Chairperson', position: 'Vice Chairperson', bio: 'Jane Ndegwa brings extensive experience in healthcare administration and member welfare programs.', order: 2, email: 'vice.chair@khwwc.co.ke', phone: '+254 700 222 222' },
  { id: 'l3', name: 'David Mwangi Kuria', slug: 'david-mwangi-kuria', title: 'Treasurer', position: 'Treasurer', bio: 'A certified public accountant with 15 years of financial management experience in the healthcare sector.', order: 3, email: 'treasurer@khwwc.co.ke', phone: '+254 700 333 333' },
  { id: 'l4', name: 'Lucy Wangari Kamau', slug: 'lucy-wangari-kamau', title: 'Secretary', position: 'Secretary', bio: 'Lucy Kamau manages all organizational documentation, meeting coordination, and member communications.', order: 4, email: 'secretary@khwwc.co.ke', phone: '+254 700 444 444' },
  { id: 'l5', name: 'Samuel Njoroge Mbugua', slug: 'samuel-njoroge-mbugua', title: 'Organizing Secretary', position: 'Organizing Secretary', bio: 'Samuel Mbugua coordinates all KHWWC events, member outreach, and community engagement activities.', order: 5, email: 'org.sec@khwwc.co.ke', phone: '+254 700 555 555' },
  { id: 'l6', name: 'Mary Njeri Kungu', slug: 'mary-njeri-kungu', title: 'Office Manager', position: 'Office Manager', bio: 'Mary Kungu oversees day-to-day office operations and member service delivery.', order: 6, email: 'office@khwwc.co.ke', phone: '+254 700 666 666' },
];

export const demoSettings: WebsiteSettings = {
  organization_name: 'Kirinyaga Healthcare Workers Welfare',
  tagline: 'Supporting those who care for our community',
  about: 'KHWWC is a welfare organization dedicated to supporting healthcare workers in Kirinyaga County. Founded in 2022, we provide financial security, emergency support, and professional development opportunities to our members.',
  email: 'info@khwwc.co.ke',
  phone: '+254 700 100 200',
  address: 'Kerugoya Town, Kirinyaga County, Kenya',
  primary_color: '#1a5e48',
  social: {
    facebook: 'https://facebook.com/khwwc',
    twitter: 'https://twitter.com/khwwc',
    linkedin: 'https://linkedin.com/company/khwwc',
  },
};

export const demoContactMessages: ContactMessage[] = [
  { id: 'cm1', name: 'Joseph Muturi', email: 'j.muturi@email.com', subject: 'Membership Inquiry', message: 'I am a nurse at Kerugoya Hospital and would like to join the welfare. What are the requirements?', read: false, date: daysAgo(1) },
  { id: 'cm2', name: 'Ann Waithera', email: 'a.waithera@email.com', subject: 'Donation Question', message: 'How can I contribute to the Medical Equipment Fund?', read: false, date: daysAgo(3) },
  { id: 'cm3', name: 'Francis Kimani', email: 'f.kimani@email.com', subject: 'Event Registration', message: 'I would like to attend the AGM on 15th November. How do I register?', read: true, date: daysAgo(7) },
];

export const demoWebsiteContent: WebsiteContent[] = [
  ...demoNews.map(n => ({ id: n.id, type: 'news', title: n.title, slug: n.slug, content: n.content, status: n.status as 'published' | 'draft' | 'archived', author: n.author, date: n.date, excerpt: n.excerpt, image: n.image })),
  ...demoEvents.map(e => ({ id: e.id, type: 'events', title: e.title, slug: e.id, content: e.description, status: 'published' as const, author: 'Office', date: e.date })),
  { id: 'wc1', type: 'about', title: 'About KHWWC', slug: 'about', content: demoSettings.about, status: 'published', author: 'Admin', date: daysAgo(90) },
  { id: 'wc2', type: 'documents', title: 'Constitution and Bylaws', slug: 'constitution-bylaws', content: 'The KHWWC constitution and bylaws document...', status: 'published', author: 'Secretary', date: daysAgo(120) },
];

export const demoWallets: WalletSummary[] = [
  { name: 'Operational Wallet', balance: 1850000, transactions_count: 342, last_activity: daysAgo(1) },
  { name: 'Contributions Wallet', balance: 4200000, transactions_count: 2856, last_activity: daysAgo(0) },
  { name: 'Penalty Wallet', balance: 28500, transactions_count: 45, last_activity: daysAgo(3) },
  { name: 'Donation Wallet', balance: 875000, transactions_count: 156, last_activity: daysAgo(2) },
];

export const demoWalletTransactions: WalletTransaction[] = [
  { id: 'wt1', wallet: 'Contributions', type: 'credit', amount: 2000, description: 'Monthly contribution - Dr. Sarah Wanjiku', reference: 'QKJ4X9PL21', balance_after: 4200000, date: daysAgo(2), status: 'confirmed' },
  { id: 'wt2', wallet: 'Contributions', type: 'credit', amount: 2000, description: 'Monthly contribution - John Kamau', reference: 'QKJ4X9PL22', balance_after: 4198000, date: daysAgo(1), status: 'confirmed' },
  { id: 'wt3', wallet: 'Operational', type: 'debit', amount: 45000, description: 'Office supplies and stationery', reference: 'EXP001245', balance_after: 1850000, date: daysAgo(5), status: 'confirmed' },
  { id: 'wt4', wallet: 'Penalty', type: 'credit', amount: 1500, description: 'Penalty payment - Peter Mbugua', reference: 'PNL000123', balance_after: 28500, date: daysAgo(3), status: 'confirmed' },
  { id: 'wt5', wallet: 'Donation', type: 'credit', amount: 5000, description: 'Donation - Medical Equipment Fund', reference: 'DON000456', balance_after: 875000, date: daysAgo(2), status: 'confirmed' },
];

export const demoWithdrawals: WithdrawalRequest[] = [
  { id: 'w1', member_id: 'm1', member_name: 'Dr. Sarah Wanjiku', amount: 15000, reason: 'Medical emergency - family member', status: 'pending', requested_date: daysAgo(3) },
  { id: 'w2', member_id: 'm3', member_name: 'Mary Wambui', amount: 10000, reason: 'Funeral support', status: 'approved', requested_date: daysAgo(10), approved_date: daysAgo(8), approved_by: 'David Kuria' },
  { id: 'w3', member_id: 'm6', member_name: 'James Otieno', amount: 25000, reason: 'Surgery assistance', status: 'pending', requested_date: daysAgo(1) },
  { id: 'w4', member_id: 'm2', member_name: 'John Kamau', amount: 8000, reason: 'Education support', status: 'paid', requested_date: daysAgo(30), approved_date: daysAgo(28), approved_by: 'David Kuria' },
];

export const demoPenalties: Penalty[] = [
  { id: 'p1', member_id: 'm1', member_name: 'Dr. Sarah Wanjiku', amount: 1500, reason: 'Late payment - Sep 2026', status: 'unpaid', date: daysAgo(25) },
  { id: 'p2', member_id: 'm4', member_name: 'Peter Mbugua', amount: 3000, reason: 'Missed contributions - Aug, Sep 2026', status: 'unpaid', date: daysAgo(45) },
  { id: 'p3', member_id: 'm7', member_name: 'Esther Nyambura', amount: 3000, reason: 'Missed contributions - Jul, Aug 2026', status: 'paid', date: daysAgo(60), paid_date: daysAgo(20) },
];

export const demoDonations: Donation[] = [
  { id: 'dn1', member_id: 'm1', member_name: 'Dr. Sarah Wanjiku', amount: 5000, campaign: 'Medical Equipment Fund', method: 'M-Pesa', reference: 'DON000456', status: 'confirmed', date: daysAgo(2) },
  { id: 'dn2', member_id: 'm6', member_name: 'James Otieno', amount: 10000, campaign: 'Medical Equipment Fund', method: 'Bank', reference: 'DON000457', status: 'confirmed', date: daysAgo(5) },
  { id: 'dn3', member_id: 'm5', member_name: 'Grace Njeri', amount: 3000, campaign: 'AGM Support', method: 'M-Pesa', reference: 'DON000458', status: 'pending', date: daysAgo(1) },
];

export const demoCampaigns: DonationCampaign[] = [
  { id: 'dc1', title: 'Medical Equipment Fund', description: 'Raising funds for state-of-the-art diagnostic equipment for Kirinyaga County hospitals.', target: 5000000, raised: 875000, contributors: 156, status: 'active', start_date: daysAgo(30), end_date: daysFromNow(60) },
  { id: 'dc2', title: 'AGM Support Fund', description: 'Support for organizing the 2026 Annual General Meeting.', target: 500000, raised: 320000, contributors: 85, status: 'active', start_date: daysAgo(20), end_date: daysFromNow(33) },
  { id: 'dc3', title: 'Member Emergency Relief', description: 'Emergency financial support for members facing medical crises.', target: 1000000, raised: 1000000, contributors: 210, status: 'completed', start_date: daysAgo(120), end_date: daysAgo(30) },
];

export const demoDefaulters: Defaulter[] = [
  { id: 'df1', member_name: 'Peter Mbugua Thuo', member_number: 'KHW-2022-0103', phone: '+254 700 678 901', missed_periods: 3, outstanding_amount: 6000, last_payment_date: daysAgo(95), status: 'critical' },
  { id: 'df2', member_name: 'Esther Nyambura Githae', member_number: 'KHW-2023-0110', phone: '+254 745 901 234', missed_periods: 2, outstanding_amount: 4000, last_payment_date: daysAgo(65), status: 'warning' },
  { id: 'df3', member_name: 'Daniel Kiprop Kiptoo', member_number: 'KHW-2024-0167', phone: '+254 756 012 345', missed_periods: 1, outstanding_amount: 2000, last_payment_date: daysAgo(35), status: 'warning' },
];

export const demoExpenses: Expense[] = [
  { id: 'ex1', description: 'Office rent - October 2026', amount: 25000, category: 'Operations', date: daysAgo(5), status: 'paid', approved_by: 'David Kuria' },
  { id: 'ex2', description: 'Printing of member statements', amount: 8500, category: 'Operations', date: daysAgo(3), status: 'approved', approved_by: 'David Kuria' },
  { id: 'ex3', description: 'Catering for wellness event', amount: 18000, category: 'Events', date: daysAgo(1), status: 'pending' },
  { id: 'ex4', description: 'Stationery and supplies', amount: 4500, category: 'Operations', date: daysAgo(7), status: 'paid', approved_by: 'David Kuria' },
];

export const demoPayouts: Payout[] = [
  { id: 'po1', reference: 'PAY-001', member_id: 'm1', member_name: 'John Kamau Mwangi', member_number: 'KHW-2021-0001', phone: '+254 700 111 222', payout_type: 'death_parent', relation: 'Parent', amount: 50000, reason: 'Bereavement support - loss of father', wallet_type: 'operational', status: 'paid', created_at: daysAgo(20), paid_at: daysAgo(15), signatories: [{ role: 'secretary', status: 'approved' }, { role: 'chairperson', status: 'approved' }] },
  { id: 'po2', reference: 'PAY-002', member_id: 'm2', member_name: 'Mary Wanjiru Njoroge', member_number: 'KHW-2021-0005', phone: '+254 700 333 444', payout_type: 'medical', relation: 'Self', amount: 100000, reason: 'Medical surgery reimbursement', wallet_type: 'donation', status: 'approved', created_at: daysAgo(8), signatories: [{ role: 'secretary', status: 'approved' }, { role: 'chairperson', status: 'pending' }] },
  { id: 'po3', reference: 'PAY-003', member_id: 'm3', member_name: 'Peter Mbugua Thuo', member_number: 'KHW-2022-0103', phone: '+254 700 678 901', payout_type: 'wedding', relation: 'Self', amount: 30000, reason: 'Wedding celebration support', wallet_type: 'operational', status: 'pending', created_at: daysAgo(2), signatories: [{ role: 'secretary', status: 'pending' }, { role: 'chairperson', status: 'pending' }] },
];

export const demoEligibleMembers: EligibleMember[] = [
  { id: 'm1', name: 'John Kamau Mwangi', member_number: 'KHW-2021-0001', phone: '+254 700 111 222', eligible_amount: 50000 },
  { id: 'm2', name: 'Mary Wanjiru Njoroge', member_number: 'KHW-2021-0005', phone: '+254 700 333 444', eligible_amount: 100000 },
  { id: 'm3', name: 'Peter Mbugua Thuo', member_number: 'KHW-2022-0103', phone: '+254 700 678 901', eligible_amount: 50000 },
  { id: 'm4', name: 'Esther Nyambura Githae', member_number: 'KHW-2023-0110', phone: '+254 745 901 234', eligible_amount: 30000 },
  { id: 'm5', name: 'Daniel Kiprop Kiptoo', member_number: 'KHW-2024-0167', phone: '+254 756 012 345', eligible_amount: 50000 },
];

export const demoBankTransactions: BankTransaction[] = [
  { id: 'bt1', date: daysAgo(1), description: 'M-Pesa Paybill Transfer', reference: 'MPESA100245', amount: 2000, type: 'credit', matched: true, matched_contribution: 'c5' },
  { id: 'bt2', date: daysAgo(2), description: 'M-Pesa Paybill Transfer', reference: 'MPESA100246', amount: 2000, type: 'credit', matched: true, matched_contribution: 'c1' },
  { id: 'bt3', date: daysAgo(3), description: 'Bank Transfer - Unknown', reference: 'TRF009876', amount: 5000, type: 'credit', matched: false },
  { id: 'bt4', date: daysAgo(5), description: 'Office rent payment', reference: 'CHQ001234', amount: 25000, type: 'debit', matched: true, matched_contribution: 'ex1' },
  { id: 'bt5', date: daysAgo(7), description: 'M-Pesa Paybill Transfer', reference: 'MPESA100247', amount: 3000, type: 'credit', matched: false },
];

export const demoImportBatches = [
  {
    id: 'bch1', file_name: 'coop_statement_oct_2026.xlsx', source_name: 'Co-op Bank',
    status: 'completed', imported_at: daysAgo(1), total_rows: 145,
    new_members: 8, matched_members: 127, duplicates: 5, unmatched: 3, invalid_phone: 2,
    total_amount: 285400, failures: 0,
  },
  {
    id: 'bch2', file_name: 'mpesa_statement_sep_2026.xlsx', source_name: 'M-Pesa',
    status: 'completed', imported_at: daysAgo(7), total_rows: 89,
    new_members: 3, matched_members: 82, duplicates: 2, unmatched: 1, invalid_phone: 1,
    total_amount: 168200, failures: 0,
  },
  {
    id: 'bch3', file_name: 'coop_statement_aug_2026.xlsx', source_name: 'Co-op Bank',
    status: 'completed', imported_at: daysAgo(14), total_rows: 210,
    new_members: 12, matched_members: 190, duplicates: 5, unmatched: 2, invalid_phone: 1,
    total_amount: 412600, failures: 0,
  },
  {
    id: 'bch4', file_name: 'bank_transfer_q3.xlsx', source_name: 'KCB',
    status: 'failed', imported_at: daysAgo(20), total_rows: 0,
    new_members: 0, matched_members: 0, duplicates: 0, unmatched: 0, invalid_phone: 0,
    total_amount: 0, failures: 1,
  },
];

export const demoImportBatchRows = [
  { id: 'r1', batch_id: 'bch1', row_number: 1, name: 'John Kamau', phone: '+254722456789', reference: 'MPESA100245', amount: 2000, status: 'matched', match_status: 'matched', member_id: 'm2', member_name: 'John Kamau Njoroge' },
  { id: 'r2', batch_id: 'bch1', row_number: 2, name: 'Mary Wambui', phone: '+254733567890', reference: 'MPESA100246', amount: 2000, status: 'matched', match_status: 'matched', member_id: 'm3', member_name: 'Mary Wambui Kariuki' },
  { id: 'r3', batch_id: 'bch1', row_number: 3, name: 'Patrick Maina', phone: '+254712111222', reference: 'TRF009876', amount: 5000, status: 'new_member', match_status: 'matched', member_id: 'm9', member_name: 'Patrick Maina' },
  { id: 'r4', batch_id: 'bch1', row_number: 4, name: '', phone: '', reference: 'TRF009877', amount: 3000, status: 'skipped', match_status: 'no_phone', member_id: null, member_name: null },
  { id: 'r5', batch_id: 'bch1', row_number: 5, name: 'Grace Njeri', phone: '+254711789012', reference: 'MPESA100247', amount: 3000, status: 'skipped', match_status: 'duplicate', member_id: 'm5', member_name: 'Grace Njeri Wangari' },
  { id: 'r6', batch_id: 'bch1', row_number: 6, name: '', phone: '', reference: 'CHQ001234', amount: 0, status: 'unmatched', match_status: 'unmatched', member_id: null, member_name: null },
];

export const demoUnmatchedTransactions = [
  { id: 'um1', bank_transaction_id: 'bt3', reference: 'TRF009876', narration: 'Bank Transfer - Unknown Sender', amount: 5000, date: daysAgo(3), status: 'pending', reason: 'unknown_member', phone_extracted: '+254700000000' },
  { id: 'um2', bank_transaction_id: 'bt5', reference: 'MPESA100247', narration: 'M-Pesa Paybill Transfer - Grace Njeri', amount: 3000, date: daysAgo(7), status: 'pending', reason: 'ambiguous_member', phone_extracted: '+254711789012' },
  { id: 'um3', bank_transaction_id: 'bt8', reference: 'TRF009881', narration: 'Bank Deposit - No phone', amount: 15000, date: daysAgo(10), status: 'investigating', reason: 'unknown_member', phone_extracted: null },
];

export const demoMinutes: MeetingMinutes[] = [
  { id: 'min1', title: 'Q3 Members Meeting Minutes', meeting_date: daysAgo(15), meeting_type: 'general', status: 'approved', prepared_by: 'Lucy Kamau', reviewed_by: 'Lucy Kamau', approved_by: 'Dr. Robert Kibochi', content: 'Minutes of the Q3 members meeting held at Kerugoya County Hall...', attendees: 180 },
  { id: 'min2', title: 'Executive Committee Meeting - October', meeting_date: daysAgo(7), meeting_type: 'executive', status: 'submitted_to_secretary', prepared_by: 'Samuel Mbugua', content: 'Minutes of the executive committee meeting...', attendees: 12 },
  { id: 'min3', title: 'Emergency Board Meeting', meeting_date: daysAgo(3), meeting_type: 'executive', status: 'draft', prepared_by: 'Samuel Mbugua', content: 'Emergency board meeting to discuss...', attendees: 8 },
  { id: 'min4', title: 'AGM Preparation Meeting', meeting_date: daysAgo(5), meeting_type: 'general', status: 'pending_chairperson_approval', prepared_by: 'Lucy Kamau', reviewed_by: 'Lucy Kamau', content: 'Preparation for the upcoming AGM...', attendees: 15 },
  { id: 'min5', title: 'September General Meeting', meeting_date: daysAgo(40), meeting_type: 'general', status: 'approved', prepared_by: 'Lucy Kamau', reviewed_by: 'Lucy Kamau', approved_by: 'Dr. Robert Kibochi', content: 'September general meeting minutes...', attendees: 165 },
  { id: 'min6', title: 'Finance Committee Meeting', meeting_date: daysAgo(2), meeting_type: 'executive', status: 'rejected_by_secretary', prepared_by: 'Samuel Mbugua', content: 'Finance committee review meeting...', attendees: 6, rejection_notes: 'Please add the financial figures for Q3 and clarify the action items with owners.' },
];

export const demoMeetings: ScheduledMeeting[] = [
  { id: 'mt1', title: 'Annual General Meeting 2026', date: daysFromNow(33), time: '09:00', location: 'Kerugoya County Hall', type: 'AGM', agenda: ['Annual report presentation', 'Election of officials', 'Budget approval for 2027', 'Member Q&A'], attendees_count: 245, status: 'scheduled' },
  { id: 'mt2', title: 'Executive Committee Meeting', date: daysFromNow(10), time: '14:00', location: 'KHWWC Office', type: 'Executive', agenda: ['Review financial reports', 'Approve new memberships', 'Event planning'], attendees_count: 12, status: 'scheduled' },
  { id: 'mt3', title: 'Members Wellness Day Planning', date: daysFromNow(5), time: '11:00', location: 'Kutus Hospital', type: 'Planning', agenda: ['Finalize event logistics', 'Assign volunteer roles'], attendees_count: 15, status: 'scheduled' },
];

export const demoAuditLogs: AuditLog[] = [
  { id: 'al1', user: 'David Kuria', action: 'APPROVE_WITHDRAWAL', module: 'Finance', ip: '41.90.64.1', date: daysAgo(1), details: 'Approved withdrawal KES 10,000 for Mary Wambui' },
  { id: 'al2', user: 'Admin User', action: 'IMPORT_MEMBERS', module: 'Members', ip: '41.90.64.2', date: daysAgo(2), details: 'Imported 12 new members via Excel' },
  { id: 'al3', user: 'Lucy Kamau', action: 'CREATE_MINUTES', module: 'Secretary', ip: '41.90.64.3', date: daysAgo(3), details: 'Created meeting minutes: Executive Committee Meeting' },
  { id: 'al4', user: 'Dr. Robert Kibochi', action: 'APPROVE_MINUTES', module: 'Chairperson', ip: '41.90.64.4', date: daysAgo(5), details: 'Approved Q3 Members Meeting Minutes' },
  { id: 'al5', user: 'Admin User', action: 'UPDATE_SETTINGS', module: 'Settings', ip: '41.90.64.2', date: daysAgo(7), details: 'Updated website settings: contact section' },
  { id: 'al6', user: 'Super Admin', action: 'UPDATE_ROLE', module: 'Access Control', ip: '41.90.64.5', date: daysAgo(10), details: 'Updated permissions for treasurer role' },
];

export const demoAccessRoles: AccessRole[] = [
  { id: 'r1', name: 'Member', description: 'Basic member access - view own records, contribute, donate', permissions: ['member.dashboard', 'member.contributions', 'member.beneficiaries', 'member.documents', 'member.profile', 'member.donate', 'member.pay-penalty'], users_count: 512 },
  { id: 'r2', name: 'Admin', description: 'Administrative access - manage members, contributions, content', permissions: ['admin.*', 'website.*'], users_count: 5 },
  { id: 'r3', name: 'Treasurer', description: 'Financial management - wallets, contributions, expenses, reports', permissions: ['treasurer.*', 'admin.contributions'], users_count: 2 },
  { id: 'r4', name: 'Secretary', description: 'Meeting minutes and scheduling management', permissions: ['secretary.*'], users_count: 2 },
  { id: 'r5', name: 'Chairperson', description: 'Approval authority for minutes and oversight', permissions: ['chairperson.*'], users_count: 1 },
  { id: 'r6', name: 'Office', description: 'Office operations and member services', permissions: ['office.*'], users_count: 3 },
  { id: 'r7', name: 'Super Admin', description: 'Full system access - security, monitoring, configuration', permissions: ['super-admin.*', '*'], users_count: 1 },
];

export const demoOnlineUsers: OnlineUser[] = [
  { id: 'ou1', name: 'David Kuria', email: 'treasurer@khwwc.co.ke', role: 'treasurer', last_activity: 'Just now', ip: '41.90.64.1', device: 'Chrome / Windows' },
  { id: 'ou2', name: 'Lucy Kamau', email: 'secretary@khwwc.co.ke', role: 'secretary', last_activity: '2 min ago', ip: '41.90.64.3', device: 'Safari / macOS' },
  { id: 'ou3', name: 'Dr. Sarah Wanjiku', email: 'sarah.mwangi@khwwc.co.ke', role: 'member', last_activity: '5 min ago', ip: '41.90.64.10', device: 'Chrome / Android' },
  { id: 'ou4', name: 'Admin User', email: 'admin@khwwc.co.ke', role: 'admin', last_activity: '12 min ago', ip: '41.90.64.2', device: 'Chrome / Windows' },
  { id: 'ou5', name: 'Dr. Robert Kibochi', email: 'chairperson@khwwc.co.ke', role: 'chairperson', last_activity: '25 min ago', ip: '41.90.64.4', device: 'Safari / iPad' },
];

export const demoLoginActivity: LoginActivity[] = [
  { id: 'la1', user: 'David Kuria', email: 'treasurer@khwwc.co.ke', ip: '41.90.64.1', device: 'Chrome / Windows', status: 'success', date: daysAgo(0) },
  { id: 'la2', user: 'Lucy Kamau', email: 'secretary@khwwc.co.ke', ip: '41.90.64.3', device: 'Safari / macOS', status: 'success', date: daysAgo(0) },
  { id: 'la3', user: 'Unknown', email: 'admin@khwwc.co.ke', ip: '193.32.162.10', device: 'Unknown', status: 'failed', date: daysAgo(1) },
  { id: 'la4', user: 'Dr. Sarah Wanjiku', email: 'sarah.mwangi@khwwc.co.ke', ip: '41.90.64.10', device: 'Chrome / Android', status: 'success', date: daysAgo(1) },
  { id: 'la5', user: 'Admin User', email: 'admin@khwwc.co.ke', ip: '41.90.64.2', device: 'Chrome / Windows', status: 'success', date: daysAgo(2) },
];

export const demoSystemMetrics: SystemMetric[] = [
  { label: 'API Response Time', value: '142ms', status: 'healthy', detail: 'Average across all endpoints' },
  { label: 'Database Connection Pool', value: '8 / 50', status: 'healthy', detail: 'Active connections' },
  { label: 'Cache Hit Rate', value: '94.2%', status: 'healthy', detail: 'Redis cache performance' },
  { label: 'Disk Usage', value: '68%', status: 'warning', detail: '1.2 TB of 2 TB used' },
  { label: 'CPU Load', value: '23%', status: 'healthy', detail: 'Average across all cores' },
  { label: 'Memory Usage', value: '4.2 / 8 GB', status: 'healthy', detail: 'RAM utilization' },
  { label: 'Queue Backlog', value: '12 jobs', status: 'healthy', detail: 'Pending background jobs' },
  { label: 'Failed Jobs (24h)', value: '0', status: 'healthy', detail: 'No failed background jobs' },
];

export const demoEmailTemplates: EmailTemplate[] = [
  { id: 'et1', name: 'Welcome Email', subject: 'Welcome to KHWWC', body: 'Dear {{name}}, welcome to the Kirinyaga Healthcare Workers Welfare...', variables: ['{{name}}', '{{member_number}}'], last_modified: daysAgo(30) },
  { id: 'et2', name: 'Contribution Confirmation', subject: 'Contribution Received - {{amount}}', body: 'Dear {{name}}, we have received your contribution of KES {{amount}}...', variables: ['{{name}}', '{{amount}}', '{{period}}'], last_modified: daysAgo(15) },
  { id: 'et3', name: 'Penalty Notice', subject: 'Outstanding Penalty Notice', body: 'Dear {{name}}, this is to notify you of an outstanding penalty...', variables: ['{{name}}', '{{amount}}', '{{reason}}'], last_modified: daysAgo(20) },
  { id: 'et4', name: 'Event Invitation', subject: 'You are invited: {{event_name}}', body: 'Dear {{name}}, you are invited to attend {{event_name}}...', variables: ['{{name}}', '{{event_name}}', '{{date}}', '{{location}}'], last_modified: daysAgo(10) },
];

export const demoMediaAssets: MediaAsset[] = [
  { id: 'ma1', name: 'khwwc-logo.png', type: 'image/png', size: '45 KB', url: '/media/khwwc-logo.png', uploaded_date: daysAgo(90) },
  { id: 'ma2', name: 'hero-banner.jpg', type: 'image/jpeg', size: '1.2 MB', url: '/media/hero-banner.jpg', uploaded_date: daysAgo(60) },
  { id: 'ma3', name: 'agm-2025.jpg', type: 'image/jpeg', size: '850 KB', url: '/media/agm-2025.jpg', uploaded_date: daysAgo(180) },
  { id: 'ma4', name: 'wellness-day.jpg', type: 'image/jpeg', size: '620 KB', url: '/media/wellness-day.jpg', uploaded_date: daysAgo(45) },
];

export const demoRegistrationApplicants: RegistrationApplicant[] = [
  { id: 'ra1', name: 'Patrick Maina', email: 'p.maina@email.com', phone: '+254 712 111 222', county: 'Kirinyaga', workplace: 'Kerugoya Hospital', status: 'pending', date: daysAgo(2) },
  { id: 'ra2', name: 'Susan Wairimu', email: 's.wairimu@email.com', phone: '+254 722 333 444', county: 'Kirinyaga', workplace: 'Sagana Hospital', status: 'pending', date: daysAgo(5) },
  { id: 'ra3', name: 'Kevin Otieno', email: 'k.otieno@email.com', phone: '+254 733 555 666', county: 'Kirinyaga', workplace: 'Kutus Hospital', status: 'approved', date: daysAgo(15) },
];

export const demoMemos: Memo[] = [
  { id: 'mem1', title: 'Contribution Reminder - October 2026', subject: 'Monthly Contribution Reminder', body: 'Dear members, this is a reminder that October 2026 contributions are due by 5th of the month. Please ensure timely payment to avoid penalties.', status: 'sent', prepared_by: 'David Kuria', recipients_count: 512, created_at: daysAgo(8), sent_at: daysAgo(7) },
  { id: 'mem2', title: 'AGM Financial Report Notice', subject: 'AGM Financial Report', body: 'Dear members, the annual financial report will be presented at the AGM on 15th November 2026. Please review the attached summary beforehand.', status: 'sent', prepared_by: 'David Kuria', recipients_count: 512, created_at: daysAgo(5), sent_at: daysAgo(5) },
  { id: 'mem3', title: 'Penalty Waiver Notice', subject: 'Penalty Waiver - Q3 2026', body: 'This is to notify all members that the committee has approved a one-time penalty waiver for Q3 2026 late payments. Members have until 30th October to clear outstanding balances.', status: 'draft', prepared_by: 'David Kuria', recipients_count: 0, created_at: daysAgo(1) },
];

export const demoMemoTemplates: MemoTemplate[] = [
  { id: 'mt1', name: 'Contribution Reminder', subject: 'Monthly Contribution Reminder', body: 'Dear members, this is a reminder that contributions for the current month are due. Please ensure timely payment.' },
  { id: 'mt2', name: 'Penalty Notice', subject: 'Outstanding Penalty Notice', body: 'Dear member, this is to notify you of an outstanding penalty on your account. Please clear it at your earliest convenience.' },
  { id: 'mt3', name: 'Meeting Notice', subject: 'Upcoming Meeting Notice', body: 'Dear members, please be informed of an upcoming meeting. Details will be shared separately.' },
  { id: 'mt4', name: 'Financial Report', subject: 'Quarterly Financial Report', body: 'Dear members, please find attached the quarterly financial report for your review.' },
];

export const demoOrgSettings: OrganizationSettings = {
  id: 'org1',
  organization_name: 'Kirinyaga Healthcare Workers Welfare',
  tagline: 'Supporting those who care for our community',
  email: 'info@khwwc.co.ke',
  phone: '+254 700 100 200',
  address: 'Kerugoya Town, Kirinyaga County, Kenya',
  payout_bereavement: 50000,
  payout_medical: 100000,
  payout_education: 30000,
};

export const demoRoleUsers: Record<string, AuthUser> = {
  'member': demoUser,
  'admin': { ...demoUser, name: 'Admin User', email: 'admin@khwwc.co.ke', role: 'admin' },
  'treasurer': { ...demoUser, name: 'David Mwangi Kuria', email: 'treasurer@khwwc.co.ke', role: 'treasurer' },
  'secretary': { ...demoUser, name: 'Lucy Wangari Kamau', email: 'secretary@khwwc.co.ke', role: 'secretary' },
  'vice-secretary': { ...demoUser, name: 'Samuel Njoroge Mbugua', email: 'vice.secretary@khwwc.co.ke', role: 'vice-secretary' as any },
  'chairperson': { ...demoUser, name: 'Dr. Robert Kibochi', email: 'chairperson@khwwc.co.ke', role: 'chairperson' },
  'office': { ...demoUser, name: 'Mary Njeri Kungu', email: 'office@khwwc.co.ke', role: 'office' },
  'super-admin': { ...demoUser, name: 'Super Admin', email: 'superadmin@khwwc.co.ke', role: 'super-admin' },
};
