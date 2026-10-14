import type {
  GroupConversation, GroupMessage, GroupParticipant, GroupReaction,
} from '@/types';

function minsAgo(mins: number): string {
  const d = new Date();
  d.setMinutes(d.getMinutes() - mins);
  return d.toISOString();
}

function hoursAgo(hours: number): string {
  const d = new Date();
  d.setHours(d.getHours() - hours);
  return d.toISOString();
}

function daysAgoStr(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString();
}

export const demoProfilePhotos: Record<string, string> = {
  'usr-001': 'https://images.pexels.com/photos/37272329/pexels-photo-37272329.png?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
  'usr-002': 'https://images.pexels.com/photos/19601385/pexels-photo-19601385.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
  'usr-003': 'https://images.pexels.com/photos/29995629/pexels-photo-29995629.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
  'usr-004': 'https://images.pexels.com/photos/7640741/pexels-photo-7640741.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
  'usr-005': 'https://images.pexels.com/photos/28332542/pexels-photo-28332542.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
  'usr-006': 'https://images.pexels.com/photos/19601385/pexels-photo-19601385.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
  'usr-007': 'https://images.pexels.com/photos/29995629/pexels-photo-29995629.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
  'usr-008': 'https://images.pexels.com/photos/7640741/pexels-photo-7640741.jpeg?auto=compress&cs=tinysrgb&w=160&h=160&fit=crop',
};

export const demoChatMembers = [
  { id: 'usr-001', name: 'Dr. Sarah Wanjiku Mwangi', avatar: demoProfilePhotos['usr-001'] },
  { id: 'usr-002', name: 'John Kamau Njoroge', avatar: demoProfilePhotos['usr-002'] },
  { id: 'usr-003', name: 'Mary Wambui Kariuki', avatar: demoProfilePhotos['usr-003'] },
  { id: 'usr-004', name: 'David Mwangi Kuria', avatar: demoProfilePhotos['usr-004'] },
  { id: 'usr-005', name: 'Lucy Wangari Kamau', avatar: demoProfilePhotos['usr-005'] },
  { id: 'usr-006', name: 'Dr. Robert Kibochi', avatar: demoProfilePhotos['usr-006'] },
  { id: 'usr-007', name: 'Grace Njeri Wangari', avatar: demoProfilePhotos['usr-007'] },
  { id: 'usr-008', name: 'James Otieno Omondi', avatar: demoProfilePhotos['usr-008'] },
];

interface DemoConversation extends GroupConversation {
  _messages: GroupMessage[];
  _readBy: Set<string>;
  _typing: Array<{ user_id: string; user_name: string }>;
}

let demoConversations: DemoConversation[] = [];
let initialized = false;

function initConversations(): void {
  if (initialized) return;
  initialized = true;

  const execMessages: GroupMessage[] = [
    {
      id: 'msg-1', conversation_id: 'conv-exec', user_id: 'usr-006',
      user_name: 'Dr. Robert Kibochi', user_avatar: null,
      content: 'Good morning team. We need to finalize the AGM agenda by end of week.',
      reply_to_id: null, mentions: [], created_at: hoursAgo(5),
      reactions: [
        { id: 'rxn-1', message_id: 'msg-1', user_id: 'usr-004', emoji: '👍', created_at: hoursAgo(5) },
        { id: 'rxn-2', message_id: 'msg-1', user_id: 'usr-005', emoji: '👍', created_at: hoursAgo(5) },
      ],
    },
    {
      id: 'msg-2', conversation_id: 'conv-exec', user_id: 'usr-004',
      user_name: 'David Mwangi Kuria', user_avatar: null,
      content: 'I have the financial reports ready for review. @Dr. Robert Kibochi when should I present?',
      reply_to_id: null, mentions: ['usr-006'], created_at: hoursAgo(4),
      reactions: [],
    },
    {
      id: 'msg-3', conversation_id: 'conv-exec', user_id: 'usr-005',
      user_name: 'Lucy Wangari Kamau', user_avatar: null,
      content: 'I can send the minutes template to everyone today.',
      reply_to_id: null, mentions: [], created_at: hoursAgo(3),
      reactions: [
        { id: 'rxn-3', message_id: 'msg-3', user_id: 'usr-006', emoji: '👏', created_at: hoursAgo(3) },
      ],
    },
    {
      id: 'msg-4', conversation_id: 'conv-exec', user_id: 'usr-006',
      user_name: 'Dr. Robert Kibochi', user_avatar: null,
      content: 'Perfect. Let us meet Thursday at 2pm to finalize everything.',
      reply_to_id: 'msg-2', mentions: [], created_at: hoursAgo(2),
      reactions: [],
    },
    {
      id: 'msg-5', conversation_id: 'conv-exec', user_id: 'usr-002',
      user_name: 'John Kamau Njoroge', user_avatar: null,
      content: 'I will prepare the venue booking by tomorrow.',
      reply_to_id: null, mentions: [], created_at: minsAgo(45),
      reactions: [],
    },
  ];

  const genMessages: GroupMessage[] = [
    {
      id: 'msg-10', conversation_id: 'conv-gen', user_id: 'usr-007',
      user_name: 'Grace Njeri Wangari', user_avatar: null,
      content: 'Hi everyone! The wellness day is coming up next week. Who is available to help with setup?',
      reply_to_id: null, mentions: [], created_at: daysAgoStr(1),
      reactions: [
        { id: 'rxn-10', message_id: 'msg-10', user_id: 'usr-001', emoji: '👍', created_at: daysAgoStr(1) },
        { id: 'rxn-11', message_id: 'msg-10', user_id: 'usr-003', emoji: '👍', created_at: daysAgoStr(1) },
      ],
    },
    {
      id: 'msg-11', conversation_id: 'conv-gen', user_id: 'usr-003',
      user_name: 'Mary Wambui Kariuki', user_avatar: null,
      content: 'I can help! I am free from 8am.',
      reply_to_id: null, mentions: [], created_at: hoursAgo(20),
      reactions: [],
    },
    {
      id: 'msg-12', conversation_id: 'conv-gen', user_id: 'usr-008',
      user_name: 'James Otieno Omondi', user_avatar: null,
      content: 'Count me in too. I will bring the sound equipment.',
      reply_to_id: 'msg-10', mentions: [], created_at: hoursAgo(18),
      reactions: [
        { id: 'rxn-12', message_id: 'msg-12', user_id: 'usr-007', emoji: '🎉', created_at: hoursAgo(18) },
      ],
    },
    {
      id: 'msg-13', conversation_id: 'conv-gen', user_id: 'usr-001',
      user_name: 'Dr. Sarah Wanjiku Mwangi', user_avatar: null,
      content: 'Thank you all! This is going to be great.',
      reply_to_id: null, mentions: [], created_at: hoursAgo(10),
      reactions: [],
    },
  ];

  const treasMessages: GroupMessage[] = [
    {
      id: 'msg-20', conversation_id: 'conv-treas', user_id: 'usr-004',
      user_name: 'David Mwangi Kuria', user_avatar: null,
      content: 'Reminder: monthly contributions are due by the 5th. Please clear early to avoid penalties.',
      reply_to_id: null, mentions: [], created_at: daysAgoStr(2),
      reactions: [
        { id: 'rxn-20', message_id: 'msg-20', user_id: 'usr-001', emoji: '✅', created_at: daysAgoStr(2) },
        { id: 'rxn-21', message_id: 'msg-20', user_id: 'usr-002', emoji: '✅', created_at: daysAgoStr(2) },
        { id: 'rxn-22', message_id: 'msg-20', user_id: 'usr-003', emoji: '✅', created_at: daysAgoStr(2) },
      ],
    },
  ];

  const execConv: DemoConversation = {
    id: 'conv-exec', name: 'Executive Committee', type: 'group',
    avatar_url: null, created_by: 'usr-006',
    created_at: daysAgoStr(30), updated_at: minsAgo(45),
    participants: [
      { id: 'p1', conversation_id: 'conv-exec', user_id: 'usr-006', user_name: 'Dr. Robert Kibochi', user_avatar: null, role: 'admin', last_read_at: hoursAgo(2), joined_at: daysAgoStr(30) },
      { id: 'p2', conversation_id: 'conv-exec', user_id: 'usr-004', user_name: 'David Mwangi Kuria', user_avatar: null, role: 'member', last_read_at: hoursAgo(2), joined_at: daysAgoStr(30) },
      { id: 'p3', conversation_id: 'conv-exec', user_id: 'usr-005', user_name: 'Lucy Wangari Kamau', user_avatar: null, role: 'member', last_read_at: hoursAgo(3), joined_at: daysAgoStr(30) },
      { id: 'p4', conversation_id: 'conv-exec', user_id: 'usr-002', user_name: 'John Kamau Njoroge', user_avatar: null, role: 'member', last_read_at: hoursAgo(5), joined_at: daysAgoStr(30) },
    ],
    last_message: execMessages[execMessages.length - 1],
    unread_count: 1,
    _messages: execMessages,
    _readBy: new Set(['usr-006', 'usr-004']),
    _typing: [],
  };

  const genConv: DemoConversation = {
    id: 'conv-gen', name: 'General Members', type: 'group',
    avatar_url: null, created_by: 'usr-007',
    created_at: daysAgoStr(15), updated_at: hoursAgo(10),
    participants: [
      { id: 'p5', conversation_id: 'conv-gen', user_id: 'usr-007', user_name: 'Grace Njeri Wangari', user_avatar: null, role: 'admin', last_read_at: hoursAgo(10), joined_at: daysAgoStr(15) },
      { id: 'p6', conversation_id: 'conv-gen', user_id: 'usr-001', user_name: 'Dr. Sarah Wanjiku Mwangi', user_avatar: null, role: 'member', last_read_at: hoursAgo(10), joined_at: daysAgoStr(15) },
      { id: 'p7', conversation_id: 'conv-gen', user_id: 'usr-003', user_name: 'Mary Wambui Kariuki', user_avatar: null, role: 'member', last_read_at: hoursAgo(20), joined_at: daysAgoStr(15) },
      { id: 'p8', conversation_id: 'conv-gen', user_id: 'usr-008', user_name: 'James Otieno Omondi', user_avatar: null, role: 'member', last_read_at: hoursAgo(18), joined_at: daysAgoStr(15) },
    ],
    last_message: genMessages[genMessages.length - 1],
    unread_count: 0,
    _messages: genMessages,
    _readBy: new Set(['usr-007', 'usr-001', 'usr-003', 'usr-008']),
    _typing: [],
  };

  const treasConv: DemoConversation = {
    id: 'conv-treas', name: 'Treasurer Announcements', type: 'group',
    avatar_url: null, created_by: 'usr-004',
    created_at: daysAgoStr(10), updated_at: daysAgoStr(2),
    participants: [
      { id: 'p9', conversation_id: 'conv-treas', user_id: 'usr-004', user_name: 'David Mwangi Kuria', user_avatar: null, role: 'admin', last_read_at: daysAgoStr(2), joined_at: daysAgoStr(10) },
      { id: 'p10', conversation_id: 'conv-treas', user_id: 'usr-001', user_name: 'Dr. Sarah Wanjiku Mwangi', user_avatar: null, role: 'member', last_read_at: daysAgoStr(2), joined_at: daysAgoStr(10) },
      { id: 'p11', conversation_id: 'conv-treas', user_id: 'usr-002', user_name: 'John Kamau Njoroge', user_avatar: null, role: 'member', last_read_at: null, joined_at: daysAgoStr(10) },
      { id: 'p12', conversation_id: 'conv-treas', user_id: 'usr-003', user_name: 'Mary Wambui Kariuki', user_avatar: null, role: 'member', last_read_at: null, joined_at: daysAgoStr(10) },
    ],
    last_message: treasMessages[treasMessages.length - 1],
    unread_count: 1,
    _messages: treasMessages,
    _readBy: new Set(['usr-004', 'usr-001']),
    _typing: [],
  };

  demoConversations = [execConv, genConv, treasConv];
}

export const demoChatData = {
  allMembers: demoChatMembers,

  getConversations(userId: string): GroupConversation[] {
    initConversations();
    return demoConversations
      .filter((c) => (c.participants || []).some((p) => p.user_id === userId))
      .map((c) => {
        const myPart = (c.participants || []).find((p) => p.user_id === userId);
        const unread = c._messages.filter(
          (m) => m.user_id !== userId &&
          (!myPart?.last_read_at || new Date(m.created_at) > new Date(myPart.last_read_at)),
        ).length;
        return {
          id: c.id, name: c.name, type: c.type, avatar_url: c.avatar_url,
          created_by: c.created_by, created_at: c.created_at, updated_at: c.updated_at,
          participants: c.participants,
          last_message: c._messages[c._messages.length - 1] || null,
          unread_count: unread,
        };
      })
      .sort((a, b) => new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime());
  },

  getMessages(convId: string): GroupMessage[] {
    initConversations();
    const conv = demoConversations.find((c) => c.id === convId);
    return conv ? [...conv._messages] : [];
  },

  sendMessage(convId: string, userId: string, userName: string, content: string, replyToId: string | null): GroupMessage {
    initConversations();
    const conv = demoConversations.find((c) => c.id === convId);
    if (!conv) throw new Error('Conversation not found');
    const mentions = content.match(/@(\w+)/g)?.map((m) => m.slice(1)) || [];
    const msg: GroupMessage = {
      id: `msg-${Date.now()}`, conversation_id: convId, user_id: userId,
      user_name: userName, user_avatar: null, content,
      reply_to_id: replyToId, mentions, created_at: new Date().toISOString(),
      reactions: [],
    };
    conv._messages.push(msg);
    conv.updated_at = msg.created_at;
    conv.last_message = msg;
    return msg;
  },

  deleteMessage(msgId: string): void {
    initConversations();
    for (const conv of demoConversations) {
      conv._messages = conv._messages.filter((m) => m.id !== msgId);
    }
  },

  markRead(convId: string, userId: string): void {
    initConversations();
    const conv = demoConversations.find((c) => c.id === convId);
    if (!conv) return;
    const part = (conv.participants || []).find((p) => p.user_id === userId);
    if (part) part.last_read_at = new Date().toISOString();
  },

  toggleReaction(msgId: string, userId: string, emoji: string): void {
    initConversations();
    for (const conv of demoConversations) {
      const msg = conv._messages.find((m) => m.id === msgId);
      if (!msg) continue;
      if (!msg.reactions) msg.reactions = [];
      const existing = msg.reactions.find((r) => r.user_id === userId && r.emoji === emoji);
      if (existing) {
        msg.reactions = msg.reactions.filter((r) => r.id !== existing.id);
      } else {
        msg.reactions.push({
          id: `rxn-${Date.now()}`, message_id: msgId, user_id: userId,
          emoji, created_at: new Date().toISOString(),
        });
      }
    }
  },

  getTypingUsers(convId: string, currentUserId: string): Array<{ user_id: string; user_name: string }> {
    initConversations();
    const conv = demoConversations.find((c) => c.id === convId);
    if (!conv) return [];
    return conv._typing.filter((t) => t.user_id !== currentUserId);
  },

  createGroup(name: string, createdBy: string, createdByName: string, memberIds: string[]): GroupConversation {
    initConversations();
    const convId = `conv-${Date.now()}`;
    const participants: GroupParticipant[] = [
      { id: `p-${Date.now()}-0`, conversation_id: convId, user_id: createdBy, user_name: createdByName, user_avatar: null, role: 'admin', last_read_at: new Date().toISOString(), joined_at: new Date().toISOString() },
      ...memberIds.map((id, idx) => {
        const m = demoChatMembers.find((x) => x.id === id);
        return {
          id: `p-${Date.now()}-${idx + 1}`, conversation_id: convId,
          user_id: id, user_name: m?.name || id, user_avatar: null,
          role: 'member' as const, last_read_at: null, joined_at: new Date().toISOString(),
        };
      }),
    ];
    const newConv: DemoConversation = {
      id: convId, name, type: 'group', avatar_url: null, created_by: createdBy,
      created_at: new Date().toISOString(), updated_at: new Date().toISOString(),
      participants, last_message: null, unread_count: 0,
      _messages: [], _readBy: new Set([createdBy]), _typing: [],
    };
    demoConversations.unshift(newConv);
    return { ...newConv, _messages: undefined, _readBy: undefined, _typing: undefined } as GroupConversation;
  },
};
