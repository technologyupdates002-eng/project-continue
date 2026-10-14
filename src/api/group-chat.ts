import { createClient } from '@supabase/supabase-js';
import type {
  GroupConversation, GroupParticipant, GroupMessage, GroupReaction,
} from '@/types';
import { getDemoMode } from './client';

const supabaseUrl = import.meta.env.VITE_SUPABASE_URL;
const supabaseAnonKey = import.meta.env.VITE_SUPABASE_ANON_KEY;

const supabase = createClient(supabaseUrl || 'https://placeholder.supabase.co', supabaseAnonKey || 'placeholder', {
  realtime: { params: { eventsPerSecond: 20 } },
});

export { supabase as chatSupabase };

export async function fetchUserConversations(userId: string): Promise<GroupConversation[]> {
  const { data: parts, error: partError } = await supabase
    .from('chat_participants')
    .select('conversation_id')
    .eq('user_id', userId);

  if (partError) throw partError;
  if (!parts || parts.length === 0) return [];

  const convIds = parts.map((p: { conversation_id: string }) => p.conversation_id);

  const { data: convs, error: convError } = await supabase
    .from('chat_conversations')
    .select('*')
    .in('id', convIds)
    .order('updated_at', { ascending: false });

  if (convError) throw convError;
  if (!convs) return [];

  const result: GroupConversation[] = [];
  for (const conv of convs) {
    const { data: participants } = await supabase
      .from('chat_participants')
      .select('*')
      .eq('conversation_id', conv.id);

    const { data: lastMsgData } = await supabase
      .from('chat_messages')
      .select('*')
      .eq('conversation_id', conv.id)
      .order('created_at', { ascending: false })
      .limit(1);

    const lastMessage = lastMsgData && lastMsgData.length > 0 ? lastMsgData[0] : null;
    const myPart = participants?.find((p: GroupParticipant) => p.user_id === userId);
    let unreadCount = 0;
    if (lastMessage && myPart?.last_read_at) {
      const { count } = await supabase
        .from('chat_messages')
        .select('*', { count: 'exact', head: true })
        .eq('conversation_id', conv.id)
        .gt('created_at', myPart.last_read_at)
        .neq('user_id', userId);
      unreadCount = count || 0;
    } else if (lastMessage && !myPart?.last_read_at) {
      const { count } = await supabase
        .from('chat_messages')
        .select('*', { count: 'exact', head: true })
        .eq('conversation_id', conv.id)
        .neq('user_id', userId);
      unreadCount = count || 0;
    }

    result.push({
      ...conv,
      participants: participants || [],
      last_message: lastMessage,
      unread_count: unreadCount,
    });
  }

  return result;
}

export async function fetchMessages(conversationId: string): Promise<GroupMessage[]> {
  const { data: messages, error } = await supabase
    .from('chat_messages')
    .select('*')
    .eq('conversation_id', conversationId)
    .order('created_at', { ascending: true })
    .limit(100);

  if (error) throw error;
  if (!messages) return [];

  const messageIds = messages.map((m: GroupMessage) => m.id);
  if (messageIds.length === 0) return messages;

  const { data: reactions } = await supabase
    .from('chat_reactions')
    .select('*')
    .in('message_id', messageIds);

  const reactionsByMessage = new Map<string, GroupReaction[]>();
  for (const r of reactions || []) {
    const list = reactionsByMessage.get(r.message_id) || [];
    list.push(r);
    reactionsByMessage.set(r.message_id, list);
  }

  return messages.map((m: GroupMessage) => ({
    ...m,
    reactions: reactionsByMessage.get(m.id) || [],
  }));
}

export async function fetchParticipants(conversationId: string): Promise<GroupParticipant[]> {
  const { data, error } = await supabase
    .from('chat_participants')
    .select('*')
    .eq('conversation_id', conversationId)
    .order('joined_at', { ascending: true });

  if (error) throw error;
  return data || [];
}

export async function sendMessage(
  conversationId: string,
  userId: string,
  userName: string,
  userAvatar: string | null,
  content: string,
  replyToId?: string | null,
  mentions?: string[],
): Promise<GroupMessage> {
  const { data, error } = await supabase
    .from('chat_messages')
    .insert({
      conversation_id: conversationId,
      user_id: userId,
      user_name: userName,
      user_avatar: userAvatar,
      content,
      reply_to_id: replyToId || null,
      mentions: mentions || [],
    })
    .select('*')
    .single();

  if (error) throw error;

  await supabase
    .from('chat_conversations')
    .update({ updated_at: new Date().toISOString() })
    .eq('id', conversationId);

  return { ...data, reactions: [] };
}

export async function deleteMessage(messageId: string): Promise<void> {
  const { error } = await supabase.from('chat_messages').delete().eq('id', messageId);
  if (error) throw error;
}

export async function markConversationRead(
  conversationId: string,
  userId: string,
): Promise<void> {
  const { error } = await supabase
    .from('chat_participants')
    .update({ last_read_at: new Date().toISOString() })
    .eq('conversation_id', conversationId)
    .eq('user_id', userId);
  if (error) throw error;
}

export async function setTyping(
  conversationId: string,
  userId: string,
  userName: string,
  isTyping: boolean,
): Promise<void> {
  if (!isTyping) {
    await supabase
      .from('chat_typing')
      .delete()
      .eq('conversation_id', conversationId)
      .eq('user_id', userId);
    return;
  }

  const { data: existing } = await supabase
    .from('chat_typing')
    .select('id')
    .eq('conversation_id', conversationId)
    .eq('user_id', userId)
    .maybeSingle();

  if (existing) {
    await supabase
      .from('chat_typing')
      .update({ updated_at: new Date().toISOString() })
      .eq('id', existing.id);
  } else {
    await supabase.from('chat_typing').insert({
      conversation_id: conversationId,
      user_id: userId,
      user_name: userName,
    });
  }
}

export async function toggleReaction(
  messageId: string,
  userId: string,
  emoji: string,
): Promise<void> {
  const { data: existing } = await supabase
    .from('chat_reactions')
    .select('id')
    .eq('message_id', messageId)
    .eq('user_id', userId)
    .eq('emoji', emoji)
    .maybeSingle();

  if (existing) {
    await supabase.from('chat_reactions').delete().eq('id', existing.id);
  } else {
    await supabase.from('chat_reactions').insert({
      message_id: messageId,
      user_id: userId,
      emoji,
    });
  }
}

export async function createGroupConversation(
  name: string,
  createdBy: string,
  createdByName: string,
  participantIds: Array<{ id: string; name: string; avatar?: string }>,
): Promise<GroupConversation> {
  const { data: conv, error: convError } = await supabase
    .from('chat_conversations')
    .insert({
      name,
      type: 'group',
      created_by: createdBy,
    })
    .select('*')
    .single();

  if (convError) throw convError;

  const allParticipants = [
    { id: createdBy, name: createdByName, avatar: null as string | null, role: 'admin' as const },
    ...participantIds.map((p) => ({ id: p.id, name: p.name, avatar: p.avatar || null, role: 'member' as const })),
  ];

  const uniqueParticipants = allParticipants.filter(
    (p, idx, arr) => arr.findIndex((x) => x.id === p.id) === idx,
  );

  const { error: partError } = await supabase.from('chat_participants').insert(
    uniqueParticipants.map((p) => ({
      conversation_id: conv.id,
      user_id: p.id,
      user_name: p.name,
      user_avatar: p.avatar,
      role: p.role,
    })),
  );

  if (partError) throw partError;

  return { ...conv, participants: [], last_message: null, unread_count: 0 };
}

export async function addParticipant(
  conversationId: string,
  userId: string,
  userName: string,
  userAvatar?: string | null,
): Promise<void> {
  const { error } = await supabase.from('chat_participants').insert({
    conversation_id: conversationId,
    user_id: userId,
    user_name: userName,
    user_avatar: userAvatar || null,
    role: 'member',
  });
  if (error) throw error;
}

export async function removeParticipant(
  conversationId: string,
  userId: string,
): Promise<void> {
  const { error } = await supabase
    .from('chat_participants')
    .delete()
    .eq('conversation_id', conversationId)
    .eq('user_id', userId);
  if (error) throw error;
}

export function subscribeToMessages(
  conversationId: string,
  callback: (message: GroupMessage) => void,
): () => void {
  if (getDemoMode()) return () => {};
  const channel = supabase
    .channel(`messages:${conversationId}`)
    .on(
      'postgres_changes',
      { event: 'INSERT', schema: 'public', table: 'chat_messages', filter: `conversation_id=eq.${conversationId}` },
      (payload) => callback(payload.new as GroupMessage),
    )
    .on(
      'postgres_changes',
      { event: 'DELETE', schema: 'public', table: 'chat_messages', filter: `conversation_id=eq.${conversationId}` },
      () => callback({} as GroupMessage),
    )
    .subscribe();
  return () => { supabase.removeChannel(channel); };
}

export function subscribeToReactions(
  conversationId: string,
  callback: () => void,
): () => void {
  if (getDemoMode()) return () => {};
  const channel = supabase
    .channel(`reactions:${conversationId}`)
    .on(
      'postgres_changes',
      { event: '*', schema: 'public', table: 'chat_reactions' },
      () => callback(),
    )
    .subscribe();
  return () => { supabase.removeChannel(channel); };
}

export function subscribeToTyping(
  conversationId: string,
  callback: (typingUsers: Array<{ user_id: string; user_name: string }>) => void,
  currentUserId: string,
): () => void {
  if (getDemoMode()) return () => {};
  const channel = supabase
    .channel(`typing:${conversationId}`)
    .on(
      'postgres_changes',
      { event: '*', schema: 'public', table: 'chat_typing', filter: `conversation_id=eq.${conversationId}` },
      async () => {
        const { data } = await supabase
          .from('chat_typing')
          .select('user_id, user_name, updated_at')
          .eq('conversation_id', conversationId)
          .neq('user_id', currentUserId);

        const now = Date.now();
        const active = (data || []).filter((t: { updated_at: string }) => {
          return now - new Date(t.updated_at).getTime() < 5000;
        });
        callback(active.map((a: { user_id: string; user_name: string }) => ({ user_id: a.user_id, user_name: a.user_name })));
      },
    )
    .subscribe();
  return () => { supabase.removeChannel(channel); };
}

export function subscribeToConversations(
  userId: string,
  callback: () => void,
): () => void {
  if (getDemoMode()) return () => {};
  const channel = supabase
    .channel(`conversations:${userId}`)
    .on(
      'postgres_changes',
      { event: '*', schema: 'public', table: 'chat_messages' },
      () => callback(),
    )
    .on(
      'postgres_changes',
      { event: '*', schema: 'public', table: 'chat_conversations' },
      () => callback(),
    )
    .on(
      'postgres_changes',
      { event: '*', schema: 'public', table: 'chat_participants' },
      () => callback(),
    )
    .subscribe();
  return () => { supabase.removeChannel(channel); };
}
