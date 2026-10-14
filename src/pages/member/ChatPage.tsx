import { useState, useEffect, useRef, useCallback } from 'react';
import {
  MessageSquare, Send, Smile, ArrowLeft, Plus, Search, Users,
  Hash, X, Check, Trash2, Reply, ChevronDown, Paperclip, Phone,
  MoreVertical, Settings, UserPlus, Bell, BellOff,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { classNames, initials, relativeTime } from '@/lib/format';
import { Button } from '@/components/ui';
import type {
  GroupConversation, GroupMessage, GroupParticipant, GroupReaction,
} from '@/types';
import { getDemoMode } from '@/api/client';
import {
  fetchUserConversations, fetchMessages, fetchParticipants,
  sendMessage, deleteMessage, markConversationRead,
  setTyping, toggleReaction, createGroupConversation,
  subscribeToMessages, subscribeToReactions, subscribeToTyping,
  subscribeToConversations, chatSupabase,
} from '@/api/group-chat';
import { demoChatData, demoProfilePhotos } from '@/api/demo-chat';

const EMOJI_LIST = ['👍', '❤️', '😂', '😮', '😢', '🎉', '👏', '🙏', '🔥', '💯', '✅', '❌'];

interface ChatPageProps {
  preselectedConversationId?: string;
  className?: string;
}

export default function ChatPage({ preselectedConversationId, className }: ChatPageProps) {
  const { user, demoMode } = useAuth();
  const [conversations, setConversations] = useState<GroupConversation[]>([]);
  const [activeConv, setActiveConv] = useState<GroupConversation | null>(null);
  const [messages, setMessages] = useState<GroupMessage[]>([]);
  const [participants, setParticipants] = useState<GroupParticipant[]>([]);
  const [input, setInput] = useState('');
  const [showEmoji, setShowEmoji] = useState(false);
  const [showNewGroup, setShowNewGroup] = useState(false);
  const [showSearch, setShowSearch] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [showParticipants, setShowParticipants] = useState(false);
  const [typingUsers, setTypingUsers] = useState<Array<{ user_id: string; user_name: string }>>([]);
  const [replyTo, setReplyTo] = useState<GroupMessage | null>(null);
  const [showReactionPicker, setShowReactionPicker] = useState<string | null>(null);
  const [notificationsEnabled, setNotificationsEnabled] = useState(false);
  const [loading, setLoading] = useState(true);
  const [loadingMessages, setLoadingMessages] = useState(false);

  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const typingTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const activeConvRef = useRef<string | null>(null);
  const pollIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const isDemo = demoMode || getDemoMode();
  const demoData = isDemo ? demoChatData : null;

  activeConvRef.current = activeConv?.id || null;

  const scrollToBottom = useCallback(() => {
    setTimeout(() => {
      messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, 50);
  }, []);

  const requestNotificationPermission = useCallback(async () => {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
      setNotificationsEnabled(true);
    } else if (Notification.permission !== 'denied') {
      const result = await Notification.requestPermission();
      setNotificationsEnabled(result === 'granted');
    }
  }, []);

  const showNotification = useCallback((title: string, body: string) => {
    if (notificationsEnabled && 'Notification' in window && Notification.permission === 'granted') {
      const notif = new Notification(title, {
        body,
        icon: '/icon.svg',
        tag: 'khwwc-chat',
      });
      notif.onclick = () => {
        window.focus();
        notif.close();
      };
    }
  }, [notificationsEnabled]);

  const loadConversations = useCallback(async () => {
    if (!user) return;
    if (isDemo && demoData) {
      setConversations(demoData.getConversations(user.id));
      setLoading(false);
      return;
    }
    try {
      const convs = await fetchUserConversations(user.id);
      setConversations(convs);
    } catch {
      /* ignore */
    } finally {
      setLoading(false);
    }
  }, [user, isDemo, demoData]);

  const loadMessages = useCallback(async (convId: string) => {
    if (!user) return;
    setLoadingMessages(true);
    try {
      if (isDemo && demoData) {
        const msgs = demoData.getMessages(convId);
        setMessages(msgs);
        const conv = demoData.getConversations(user.id).find((c) => c.id === convId);
        setActiveConv(conv || null);
        setParticipants(conv?.participants || []);
        demoData.markRead(convId, user.id);
      } else {
        const [msgs, parts] = await Promise.all([
          fetchMessages(convId),
          fetchParticipants(convId),
        ]);
        setMessages(msgs);
        setParticipants(parts);
        const conv = conversations.find((c) => c.id === convId) || null;
        setActiveConv(conv);
        await markConversationRead(convId, user.id);
      }
      setReplyTo(null);
      setShowEmoji(false);
      scrollToBottom();
    } catch {
      /* ignore */
    } finally {
      setLoadingMessages(false);
    }
  }, [user, isDemo, demoData, conversations, scrollToBottom]);

  const handleSend = useCallback(async () => {
    const text = input.trim();
    if (!text || !user || !activeConv) return;

    setInput('');
    setReplyTo(null);
    setShowEmoji(false);

    if (isDemo && demoData) {
      const newMsg = demoData.sendMessage(activeConv.id, user.id, user.name, text, replyTo?.id || null);
      setMessages((prev) => [...prev, newMsg]);
      demoData.markRead(activeConv.id, user.id);
      scrollToBottom();
      return;
    }

    try {
      await sendMessage(
        activeConv.id, user.id, user.name, user.avatar || null,
        text, replyTo?.id || null,
      );
    } catch {
      /* ignore */
    }
  }, [input, user, activeConv, isDemo, demoData, replyTo, scrollToBottom]);

  const handleDeleteMessage = useCallback(async (msgId: string) => {
    if (!user) return;
    if (isDemo && demoData) {
      demoData.deleteMessage(msgId);
      setMessages((prev) => prev.filter((m) => m.id !== msgId));
      return;
    }
    try {
      await deleteMessage(msgId);
    } catch {
      /* ignore */
    }
  }, [user, isDemo, demoData]);

  const handleReaction = useCallback(async (msgId: string, emoji: string) => {
    if (!user) return;
    setShowReactionPicker(null);

    if (isDemo && demoData) {
      demoData.toggleReaction(msgId, user.id, emoji);
      setMessages((prev) =>
        prev.map((m) => {
          if (m.id !== msgId) return m;
          const existing = m.reactions || [];
          const mine = existing.find((r) => r.user_id === user.id && r.emoji === emoji);
          if (mine) {
            return { ...m, reactions: existing.filter((r) => r.id !== mine.id) };
          }
          return {
            ...m,
            reactions: [...existing, {
              id: `rxn-${Date.now()}`, message_id: msgId, user_id: user.id,
              emoji, created_at: new Date().toISOString(),
            }],
          };
        }),
      );
      return;
    }

    try {
      await toggleReaction(msgId, user.id, emoji);
    } catch {
      /* ignore */
    }
  }, [user, isDemo, demoData]);

  const handleTyping = useCallback((text: string) => {
    setInput(text);
    if (!user || !activeConv) return;

    if (isDemo) return;

    setTyping(activeConv.id, user.id, user.name, true);

    if (typingTimeoutRef.current) clearTimeout(typingTimeoutRef.current);
    typingTimeoutRef.current = setTimeout(() => {
      setTyping(activeConv.id, user.id, user.name, false);
    }, 3000);
  }, [user, activeConv, isDemo]);

  const handleCreateGroup = useCallback(async (name: string, memberIds: string[]) => {
    if (!user) return;
    if (isDemo && demoData) {
      const newConv = demoData.createGroup(name, user.id, user.name, memberIds);
      setConversations((prev) => [newConv, ...prev]);
      setShowNewGroup(false);
      loadMessages(newConv.id);
      return;
    }
    try {
      const allMembers = [
        ...memberIds.map((id) => {
          const m = demoChatData?.allMembers.find((x) => x.id === id);
          return { id, name: m?.name || id, avatar: m?.avatar };
        }),
      ];
      const newConv = await createGroupConversation(name, user.id, user.name, allMembers);
      setConversations((prev) => [newConv, ...prev]);
      setShowNewGroup(false);
      loadMessages(newConv.id);
      loadConversations();
    } catch {
      /* ignore */
    }
  }, [user, isDemo, demoData, loadMessages, loadConversations]);

  // Load conversations on mount
  useEffect(() => {
    loadConversations();
  }, [loadConversations]);

  // Auto-select preselected conversation
  useEffect(() => {
    if (preselectedConversationId && conversations.length > 0) {
      loadMessages(preselectedConversationId);
    }
  }, [preselectedConversationId, conversations, loadMessages]);

  // Subscribe to realtime updates (production only)
  useEffect(() => {
    if (isDemo || !user) return;
    const unsubConvs = subscribeToConversations(user.id, () => {
      loadConversations();
    });
    return () => { unsubConvs(); };
  }, [isDemo, user, loadConversations]);

  // Subscribe to messages in active conversation
  useEffect(() => {
    if (!activeConv || isDemo) return;
    const unsub = subscribeToMessages(activeConv.id, (msg) => {
      if (msg.id) {
        setMessages((prev) => {
          if (prev.some((m) => m.id === msg.id)) return prev;
          return [...prev, msg];
        });
        if (msg.user_id !== user?.id) {
          showNotification(`${msg.user_name} in ${activeConv.name || 'chat'}`, msg.content);
        }
        scrollToBottom();
        markConversationRead(activeConv.id, user!.id);
      } else {
        loadMessages(activeConv.id);
      }
    });
    return () => { unsub(); };
  }, [activeConv, isDemo, user, scrollToBottom, showNotification, loadMessages]);

  // Subscribe to reactions
  useEffect(() => {
    if (!activeConv || isDemo) return;
    const unsub = subscribeToReactions(activeConv.id, () => {
      if (activeConv.id) loadMessages(activeConv.id);
    });
    return () => { unsub(); };
  }, [activeConv, isDemo, loadMessages]);

  // Subscribe to typing indicators
  useEffect(() => {
    if (!activeConv || isDemo || !user) return;
    const unsub = subscribeToTyping(activeConv.id, (users) => {
      setTypingUsers(users);
    }, user.id);
    return () => { unsub(); };
  }, [activeConv, isDemo, user]);

  // Demo mode: poll for new messages
  useEffect(() => {
    if (!isDemo || !activeConv || !demoData) return;
    pollIntervalRef.current = setInterval(() => {
      const msgs = demoData.getMessages(activeConv.id);
      setMessages(msgs);
      const typing = demoData.getTypingUsers(activeConv.id, user?.id || '');
      setTypingUsers(typing);
    }, 2000);
    return () => { if (pollIntervalRef.current) clearInterval(pollIntervalRef.current); };
  }, [isDemo, activeConv, demoData, user]);

  // Cleanup typing on unmount
  useEffect(() => {
    return () => {
      if (typingTimeoutRef.current) clearTimeout(typingTimeoutRef.current);
      if (activeConvRef.current && user && !isDemo) {
        setTyping(activeConvRef.current, user.id, user.name, false);
      }
    };
  }, [user, isDemo]);

  if (!user) return null;

  const filteredConversations = conversations.filter((c) =>
    !searchQuery ||
    (c.name || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
    (c.last_message?.content || '').toLowerCase().includes(searchQuery.toLowerCase()),
  );

  return (
    <div className={classNames(
      'member-chat flex flex-col overflow-hidden',
      className || 'member-chat-page',
    )}>
      {/* Notification toggle - top bar */}
      <div className="chat-toolbar flex items-center justify-between px-4 py-3">
        <div className="flex items-center gap-2">
          <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-surface text-primary">
            <MessageSquare size={16} />
          </div>
          <div>
            <h1 className="text-sm font-bold text-white">Messages</h1>
            <p className="text-xs text-white/70">KHWWC members</p>
          </div>
          {isDemo && (
            <span className="px-1.5 py-0.5 rounded text-2xs font-medium bg-white/15 text-white">Demo</span>
          )}
        </div>
        <Button variant="ghost"
          onClick={requestNotificationPermission}
          className="chat-notifications flex items-center gap-1.5 px-2.5 py-1.5 text-xs"
          aria-label={notificationsEnabled ? 'Notifications on' : 'Enable notifications'}
          title="Enable push notifications"
        >
          {notificationsEnabled ? <Bell size={14} /> : <BellOff size={14} />}
          <span className="hidden sm:inline">{notificationsEnabled ? 'Notifications on' : 'Enable notifications'}</span>
        </Button>
      </div>

      <div className="flex flex-1 overflow-hidden">
        {/* Conversation List */}
        <div className={classNames(
          'chat-conversations flex flex-col border-r border-border bg-white',
          activeConv ? 'hidden md:flex w-72 shrink-0' : 'flex flex-1 md:flex-none md:w-72',
        )}>
          <div className="px-3 py-3 border-b border-neutral-100 space-y-2">
            <div className="flex items-center gap-2">
              <div className="flex-1 relative">
                <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-neutral-400" />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Search conversations..."
                  className="input-field w-full pl-8 pr-3 py-2.5 text-sm"
                  aria-label="Search conversations"
                />
              </div>
              <Button
                onClick={() => setShowNewGroup(true)}
                className="w-10 h-10 p-0 flex-shrink-0"
                aria-label="New group chat"
                title="New group chat"
              >
                <Plus size={18} />
              </Button>
            </div>
          </div>

          <div className="flex-1 overflow-y-auto">
            {loading ? (
              <div className="flex items-center justify-center py-8">
                <div className="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin" />
              </div>
            ) : filteredConversations.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 px-4 text-center">
                <MessageSquare size={32} className="text-neutral-300 mb-2" />
                <p className="text-sm text-neutral-500">No conversations yet</p>
                <button
                  onClick={() => setShowNewGroup(true)}
                  className="mt-3 text-sm text-primary-600 hover:text-primary-700 font-medium"
                >
                  Start a group chat
                </button>
              </div>
            ) : (
              filteredConversations.map((conv) => (
                <ConversationListItem
                  key={conv.id}
                  conv={conv}
                  active={activeConv?.id === conv.id}
                  currentUserId={user.id}
                  onClick={() => loadMessages(conv.id)}
                />
              ))
            )}
          </div>
        </div>

        {/* Message View */}
        {activeConv ? (
          <div className="flex flex-col flex-1 min-w-0">
            {/* Chat Header */}
            <div className="flex items-center justify-between px-4 py-3 border-b border-neutral-200 bg-white">
              <div className="flex items-center gap-3 min-w-0">
                <button
                  onClick={() => { setActiveConv(null); setMessages([]); }}
                  className="md:hidden p-1 -ml-1 rounded-lg hover:bg-neutral-100"
                >
                  <ArrowLeft size={20} />
                </button>
                <Avatar
                  id={activeConv.participants?.find((p) => p.user_id !== user.id)?.user_id || activeConv.created_by}
                  name={activeConv.name || 'Chat'}
                  url={activeConv.participants?.find((p) => p.user_id !== user.id)?.user_avatar}
                  size="w-10 h-10"
                  fallbackIcon={activeConv.type === 'group' ? <Users size={18} /> : undefined}
                />
                <div className="min-w-0">
                  <p className="font-semibold text-sm text-neutral-900 truncate">
                    {activeConv.name || 'Direct message'}
                  </p>
                  <p className="text-xs text-neutral-500 truncate">
                    {typingUsers.length > 0 ? (
                      <span className="text-primary-600 flex items-center gap-1">
                        {typingUsers.map((t) => t.user_name).join(', ')} typing
                        <span className="flex gap-0.5">
                          <span className="w-1 h-1 bg-primary-500 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
                          <span className="w-1 h-1 bg-primary-500 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
                          <span className="w-1 h-1 bg-primary-500 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
                        </span>
                      </span>
                    ) : (
                      `${participants.length} participants`
                    )}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-1">
                <button
                  onClick={() => setShowParticipants(!showParticipants)}
                  className="p-2 rounded-lg hover:bg-neutral-100 transition-colors"
                  title="Group info"
                >
                  <Users size={18} className="text-neutral-600" />
                </button>
              </div>
            </div>

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-3 sm:px-5 py-4 bg-[#faf9fe] space-y-2">
              {loadingMessages ? (
                <div className="flex items-center justify-center h-full">
                  <div className="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin" />
                </div>
              ) : messages.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-full text-center">
                  <div className="w-14 h-14 rounded-full bg-primary-100 flex items-center justify-center mb-3">
                    <MessageSquare size={24} className="text-primary-500" />
                  </div>
                  <p className="text-sm font-medium text-neutral-700">No messages yet</p>
                  <p className="text-xs text-neutral-400 mt-1">Send the first message to start the conversation</p>
                </div>
              ) : (
                messages.map((msg, idx) => {
                  const isMine = msg.user_id === user.id;
                  const prevMsg = messages[idx - 1];
                  const showAvatar = !prevMsg || prevMsg.user_id !== msg.user_id ||
                    new Date(msg.created_at).getTime() - new Date(prevMsg.created_at).getTime() > 300000;
                  return (
                    <MessageBubble
                      key={msg.id}
                      msg={msg}
                      isMine={isMine}
                      showAvatar={showAvatar}
                      currentUserId={user.id}
                      replyTo={messages.find((m) => m.id === msg.reply_to_id) || null}
                      onReply={() => { setReplyTo(msg); inputRef.current?.focus(); }}
                      onDelete={() => handleDeleteMessage(msg.id)}
                      onReaction={(emoji) => handleReaction(msg.id, emoji)}
                      showReactionPicker={showReactionPicker === msg.id}
                      onToggleReactionPicker={() =>
                        setShowReactionPicker(showReactionPicker === msg.id ? null : msg.id)
                      }
                    />
                  );
                })
              )}
              <div ref={messagesEndRef} />
            </div>

            {/* Reply Preview */}
            {replyTo && (
              <div className="flex items-center justify-between px-4 py-2 bg-primary-50 border-t border-primary-100">
                <div className="flex items-center gap-2 min-w-0">
                  <Reply size={14} className="text-primary-600 flex-shrink-0" />
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-primary-700">
                      Replying to {replyTo.user_name}
                    </p>
                    <p className="text-xs text-primary-600 truncate">{replyTo.content}</p>
                  </div>
                </div>
                <button onClick={() => setReplyTo(null)} className="p-1 rounded hover:bg-primary-100">
                  <X size={14} className="text-primary-600" />
                </button>
              </div>
            )}

            {/* Emoji Picker */}
            {showEmoji && (
              <div className="px-4 py-2 bg-white border-t border-neutral-100">
                <div className="flex flex-wrap gap-1">
                  {EMOJI_LIST.map((emoji) => (
                    <button
                      key={emoji}
                      onClick={() => { setInput((prev) => prev + emoji); inputRef.current?.focus(); }}
                      className="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-neutral-100 text-xl transition-colors"
                    >
                      {emoji}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {/* Input */}
            <div className="px-3 py-3 bg-white border-t border-neutral-200">
              <div className="flex items-end gap-2">
                <button
                  onClick={() => setShowEmoji(!showEmoji)}
                  className="p-2 rounded-lg hover:bg-neutral-100 transition-colors flex-shrink-0"
                  title="Emoji"
                >
                  <Smile size={20} className={classNames(showEmoji ? 'text-primary-600' : 'text-neutral-500')} />
                </button>
                <textarea
                  ref={inputRef}
                  value={input}
                  onChange={(e) => handleTyping(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                      e.preventDefault();
                      handleSend();
                    }
                  }}
                  placeholder="Type a message..."
                  rows={1}
                  className="flex-1 resize-none rounded-xl bg-neutral-100 px-3 py-2.5 text-sm text-neutral-900 placeholder:text-neutral-400 border-0 focus:ring-2 focus:ring-primary-500/30 outline-none max-h-32"
                  style={{ minHeight: '40px' }}
                  aria-label="Message"
                />
                <Button
                  onClick={handleSend}
                  disabled={!input.trim()}
                  className="w-10 h-10 p-0 flex-shrink-0"
                  aria-label="Send message"
                  title="Send"
                >
                  <Send size={18} />
                </Button>
              </div>
            </div>
          </div>
        ) : (
          <div className="hidden md:flex flex-1 flex-col items-center justify-center bg-neutral-50">
            <div className="w-16 h-16 rounded-full bg-primary-100 flex items-center justify-center mb-4">
              <MessageSquare size={28} className="text-primary-500" />
            </div>
            <p className="text-base font-semibold text-neutral-700">Your Messages</p>
            <p className="text-sm text-neutral-400 mt-1">Select a conversation to start chatting</p>
          </div>
        )}

        {/* Participants Panel */}
        {showParticipants && activeConv && (
          <div className="hidden lg:flex w-64 flex-col border-l border-neutral-200 bg-white">
            <div className="flex items-center justify-between px-4 py-3 border-b border-neutral-100">
              <p className="text-sm font-semibold text-neutral-900">Members</p>
              <button onClick={() => setShowParticipants(false)} className="p-1 rounded hover:bg-neutral-100">
                <X size={16} className="text-neutral-500" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto">
              {participants.map((p) => (
                <div key={p.id} className="flex items-center gap-3 px-4 py-2.5 hover:bg-neutral-50">
                  <Avatar
                    id={p.user_id}
                    name={p.user_name}
                    url={p.user_avatar}
                    size="w-9 h-9"
                  />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-neutral-900 truncate">{p.user_name}</p>
                    <p className="text-xs text-neutral-400">{p.role === 'admin' ? 'Admin' : 'Member'}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* New Group Modal */}
      {showNewGroup && (
        <NewGroupModal
          currentUserId={user.id}
          currentUserName={user.name}
          allMembers={demoChatData?.allMembers || []}
          onCreate={handleCreateGroup}
          onClose={() => setShowNewGroup(false)}
        />
      )}
    </div>
  );
}

function convAvatarClass(conv: GroupConversation): string {
  const colors = [
    'bg-[#6c55c7]', 'bg-[#4f8ac9]', 'bg-[#3f9b7b]',
    'bg-[#d59a46]', 'bg-[#d16d7f]', 'bg-[#4aabb6]',
  ];
  const hash = conv.id.split('').reduce((a, c) => a + c.charCodeAt(0), 0);
  return colors[hash % colors.length];
}

interface AvatarProps {
  id: string;
  name: string;
  url?: string | null;
  size: string;
  fallbackClass?: string;
  fallbackIcon?: React.ReactNode;
  online?: boolean;
}

function Avatar({ id, name, url, size, fallbackClass = 'bg-[#6c55c7]', fallbackIcon, online = true }: AvatarProps) {
  const photo = url || demoProfilePhotos[id];
  return (
    <span className="relative inline-flex flex-shrink-0">
      {photo ? (
        <img
          src={photo}
          alt={`${name} profile`}
          className={classNames(size, 'rounded-full object-cover flex-shrink-0 ring-2 ring-white shadow-sm')}
          loading="lazy"
        />
      ) : (
        <span className={classNames(size, fallbackClass, 'rounded-full text-white font-semibold flex items-center justify-center text-xs flex-shrink-0 ring-2 ring-white shadow-sm')}>
          {fallbackIcon || initials(name)}
        </span>
      )}
      {online && <span className="absolute right-0 bottom-0 w-2.5 h-2.5 rounded-full bg-[#45c678] ring-2 ring-white" />}
    </span>
  );
}

interface ConversationListItemProps {
  conv: GroupConversation;
  active: boolean;
  currentUserId: string;
  onClick: () => void;
}

function ConversationListItem({ conv, active, currentUserId, onClick }: ConversationListItemProps) {
  const lastMsg = conv.last_message;
  const isMyLast = lastMsg?.user_id === currentUserId;
  const otherTyping = (conv as GroupConversation & { typing_users?: Array<{ user_name: string }> }).typing_users;
  const avatarClass = convAvatarClass(conv);
  const previewParticipant = conv.participants?.find((p) => p.user_id !== currentUserId);

  return (
    <button
      onClick={onClick}
      className={classNames(
        'w-full flex items-center gap-3 px-3 py-3 transition-colors text-left',
        active ? 'bg-primary-50' : 'hover:bg-neutral-50',
      )}
    >
      <Avatar
        id={previewParticipant?.user_id || conv.created_by}
        name={conv.name || 'DM'}
        url={previewParticipant?.user_avatar}
        size="w-11 h-11"
        fallbackClass={avatarClass}
        fallbackIcon={conv.type === 'group' ? <Users size={18} /> : undefined}
      />
      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between gap-2">
          <p className={classNames(
            'text-sm truncate',
            active ? 'font-semibold text-neutral-900' : 'font-medium text-neutral-800',
          )}>
            {conv.name || 'Direct message'}
          </p>
          {lastMsg && (
            <span className="text-2xs text-neutral-400 flex-shrink-0">
              {relativeTime(lastMsg.created_at)}
            </span>
          )}
        </div>
        <div className="flex items-center justify-between gap-2 mt-0.5">
          <p className="text-xs text-neutral-500 truncate">
            {otherTyping && otherTyping.length > 0 ? (
              <span className="text-primary-600">{otherTyping.map((t) => t.user_name).join(', ')} typing...</span>
            ) : lastMsg ? (
              <>{isMyLast ? 'You: ' : ''}{lastMsg.content}</>
            ) : (
              'No messages yet'
            )}
          </p>
          {conv.unread_count && conv.unread_count > 0 ? (
            <span className="flex-shrink-0 min-w-[20px] h-5 px-1.5 rounded-full bg-primary-500 text-white text-2xs font-bold flex items-center justify-center">
              {conv.unread_count > 99 ? '99+' : conv.unread_count}
            </span>
          ) : null}
        </div>
      </div>
    </button>
  );
}

interface MessageBubbleProps {
  msg: GroupMessage;
  isMine: boolean;
  showAvatar: boolean;
  currentUserId: string;
  replyTo: GroupMessage | null;
  onReply: () => void;
  onDelete: () => void;
  onReaction: (emoji: string) => void;
  showReactionPicker: boolean;
  onToggleReactionPicker: () => void;
}

function MessageBubble({
  msg, isMine, showAvatar, currentUserId, replyTo,
  onReply, onDelete, onReaction, showReactionPicker, onToggleReactionPicker,
}: MessageBubbleProps) {
  const [showMenu, setShowMenu] = useState(false);
  const mentioned = msg.mentions && msg.mentions.includes(currentUserId);

  return (
    <div className={classNames('flex gap-2 group', isMine ? 'flex-row-reverse' : 'flex-row')}>
      {/* Avatar */}
      <div className="w-8 flex-shrink-0">
        {showAvatar && !isMine && (
          <Avatar id={msg.user_id} name={msg.user_name} url={msg.user_avatar} size="w-8 h-8" />
        )}
      </div>

      {/* Message content */}
      <div className={classNames('max-w-[75%] flex flex-col', isMine ? 'items-end' : 'items-start')}>
        {showAvatar && !isMine && (
          <p className="text-xs font-medium text-neutral-600 mb-0.5 px-1">{msg.user_name}</p>
        )}

        {/* Reply context */}
        {replyTo && (
          <div className={classNames(
            'px-2.5 py-1.5 rounded-lg mb-1 text-xs border-l-2 max-w-full',
            isMine ? 'bg-primary-50 border-primary-400' : 'bg-neutral-100 border-neutral-300',
          )}>
            <p className="font-medium text-neutral-700">{replyTo.user_name}</p>
            <p className="text-neutral-500 truncate">{replyTo.content}</p>
          </div>
        )}

        {/* Bubble */}
        <div className={classNames(
          'chat-bubble relative rounded-lg px-3.5 py-2.5 text-sm break-words',
          isMine
            ? 'bg-primary-500 text-white rounded-br-md'
            : 'bg-white text-neutral-800 border border-neutral-200 rounded-bl-md',
          mentioned && 'ring-2 ring-amber-300',
        )}>
          {mentioned && !isMine && (
            <span className="absolute -top-2 -left-1 text-xs">🔔</span>
          )}
          <p className="whitespace-pre-wrap leading-relaxed">{renderContentWithMentions(msg.content, isMine)}</p>

          {/* Reactions */}
          {msg.reactions && msg.reactions.length > 0 && (
            <div className="flex flex-wrap gap-1 mt-1.5">
              {aggregateReactions(msg.reactions).map((r) => (
                <button
                  key={r.emoji}
                  onClick={() => onReaction(r.emoji)}
                  className={classNames(
                    'flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs transition-colors',
                    r.mine
                      ? 'bg-primary-100 ring-1 ring-primary-300'
                      : 'bg-neutral-100 hover:bg-neutral-200',
                    isMine && 'bg-white/20',
                  )}
                >
                  <span>{r.emoji}</span>
                  <span className={isMine ? 'text-white/90' : 'text-neutral-600'}>{r.count}</span>
                </button>
              ))}
            </div>
          )}

          {/* Time */}
          <p className={classNames(
            'text-2xs mt-1',
            isMine ? 'text-white/60' : 'text-neutral-400',
          )}>
            {relativeTime(msg.created_at)}
          </p>

          {/* Hover actions */}
          <div className={classNames(
            'absolute top-0 flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity',
            isMine ? '-left-20' : '-right-20',
          )}>
            <button
              onClick={onToggleReactionPicker}
              className="p-1.5 rounded-lg bg-white shadow-sm border border-neutral-200 hover:bg-neutral-50"
              title="React"
            >
              <Smile size={14} className="text-neutral-600" />
            </button>
            <button
              onClick={onReply}
              className="p-1.5 rounded-lg bg-white shadow-sm border border-neutral-200 hover:bg-neutral-50"
              title="Reply"
            >
              <Reply size={14} className="text-neutral-600" />
            </button>
            {isMine && (
              <button
                onClick={() => { setShowMenu(!showMenu); onDelete(); }}
                className="p-1.5 rounded-lg bg-white shadow-sm border border-neutral-200 hover:bg-neutral-50"
                title="Delete"
              >
                <Trash2 size={14} className="text-neutral-600" />
              </button>
            )}
          </div>

          {/* Reaction picker */}
          {showReactionPicker && (
            <div className={classNames(
              'absolute -top-10 z-10 flex items-center gap-1 px-1.5 py-1 rounded-full bg-white shadow-lg border border-neutral-200',
              isMine ? 'right-0' : 'left-0',
            )}>
              {EMOJI_LIST.slice(0, 6).map((emoji) => (
                <button
                  key={emoji}
                  onClick={() => onReaction(emoji)}
                  className="w-7 h-7 flex items-center justify-center rounded-full hover:bg-neutral-100 text-base transition-transform hover:scale-125"
                >
                  {emoji}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function renderContentWithMentions(content: string, isMine: boolean): React.ReactNode {
  const parts = content.split(/(@\w+)/g);
  return parts.map((part, i) => {
    if (part.startsWith('@')) {
      return (
        <span key={i} className={isMine ? 'font-semibold text-white underline' : 'font-semibold text-primary-600 bg-primary-50 rounded px-0.5'}>
          {part}
        </span>
      );
    }
    return part;
  });
}

function aggregateReactions(reactions: GroupReaction[]): Array<{ emoji: string; count: number; mine: boolean }> {
  const map = new Map<string, { count: number; mine: boolean }>();
  for (const r of reactions) {
    const existing = map.get(r.emoji) || { count: 0, mine: false };
    existing.count += 1;
    map.set(r.emoji, existing);
  }
  return Array.from(map.entries()).map(([emoji, data]) => ({ emoji, ...data }));
}

interface NewGroupModalProps {
  currentUserId: string;
  currentUserName: string;
  allMembers: Array<{ id: string; name: string; avatar?: string }>;
  onCreate: (name: string, memberIds: string[]) => void;
  onClose: () => void;
}

function NewGroupModal({ currentUserId, allMembers, onCreate, onClose }: NewGroupModalProps) {
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [search, setSearch] = useState('');

  const filtered = allMembers.filter((m) =>
    m.id !== currentUserId &&
    (!search || m.name.toLowerCase().includes(search.toLowerCase())),
  );

  const toggle = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-neutral-950/40 backdrop-blur-sm animate-fade-in" onClick={onClose} />
      <div className="relative w-full max-w-md bg-white rounded-2xl shadow-xl flex flex-col max-h-[80vh] animate-slide-up">
        <div className="flex items-center justify-between px-5 py-4 border-b border-neutral-100">
          <div className="flex items-center gap-2">
            <Users size={20} className="text-primary-600" />
            <h2 className="text-base font-bold text-neutral-900">New Group Chat</h2>
          </div>
          <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-neutral-100">
            <X size={18} className="text-neutral-500" />
          </button>
        </div>

        <div className="px-5 py-4 space-y-4 overflow-y-auto">
          <div>
            <label className="label">Group Name</label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Executive Committee"
              className="input-field"
              autoFocus
            />
          </div>

          <div>
            <label className="label">Add Members ({selected.size} selected)</label>
            <div className="relative mb-2">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search members..."
                className="input-field pl-9"
              />
            </div>
            <div className="space-y-1 max-h-48 overflow-y-auto">
              {filtered.length === 0 ? (
                <p className="text-sm text-neutral-400 text-center py-4">No members found</p>
              ) : (
                filtered.map((m) => (
                  <button
                    key={m.id}
                    onClick={() => toggle(m.id)}
                    className={classNames(
                      'w-full flex items-center gap-3 px-3 py-2 rounded-lg transition-colors text-left',
                      selected.has(m.id) ? 'bg-primary-50' : 'hover:bg-neutral-50',
                    )}
                  >
                    <Avatar id={m.id} name={m.name} url={m.avatar} size="w-9 h-9" />
                    <p className="text-sm font-medium text-neutral-800 flex-1 truncate">{m.name}</p>
                    <div className={classNames(
                      'w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0',
                      selected.has(m.id) ? 'bg-primary-500 border-primary-500' : 'border-neutral-300',
                    )}>
                      {selected.has(m.id) && <Check size={12} className="text-white" />}
                    </div>
                  </button>
                ))
              )}
            </div>
          </div>
        </div>

        <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-neutral-100">
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button
            onClick={() => onCreate(name.trim(), Array.from(selected))}
            disabled={!name.trim() || selected.size === 0}
          >
            Create Group
          </Button>
        </div>
      </div>
    </div>
  );
}
