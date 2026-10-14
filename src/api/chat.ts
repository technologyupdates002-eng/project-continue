import { createClient } from '@supabase/supabase-js';
import type { ChatMessage, ChatConversation } from '@/types';
import { API_BASE_URL, getDemoMode, getToken } from './client';

const supabaseUrl = import.meta.env.VITE_SUPABASE_URL;
const supabaseAnonKey = import.meta.env.VITE_SUPABASE_ANON_KEY;
const supabase = createClient(supabaseUrl || 'https://placeholder.supabase.co', supabaseAnonKey || 'placeholder');

type ContextType = 'admin_assistant' | 'secretary_assistant';

type ProductionConversationResponse = {
  conversation_id?: string;
  messages?: Array<{
    id?: string;
    conversation_id?: string;
    role?: string;
    content?: string;
    created_at?: string;
  }>;
};

function productionHeaders(): HeadersInit {
  const token = getToken();
  return {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

async function productionConversation(
  contextType: ContextType,
  conversationId?: string,
): Promise<ProductionConversationResponse> {
  const params = new URLSearchParams({ context_type: contextType });
  if (conversationId) params.set('conversation_id', conversationId);

  const response = await fetch(
    `${API_BASE_URL}/functions/v1/ai-assistant/conversation?${params.toString()}`,
    { headers: productionHeaders() },
  );
  if (!response.ok) {
    throw new Error(`Chat history request failed (${response.status})`);
  }
  return (await response.json()) as ProductionConversationResponse;
}

function mapProductionMessages(
  response: ProductionConversationResponse,
  conversationId: string,
): ChatMessage[] {
  return (response.messages || [])
    .filter((message) => message.role === 'user' || message.role === 'assistant')
    .map((message, index) => ({
      id: message.id || `${conversationId}-${index}`,
      conversation_id: message.conversation_id || conversationId,
      role: message.role as 'user' | 'assistant',
      content: message.content || '',
      created_at: message.created_at || new Date().toISOString(),
    }));
}

export async function fetchConversations(
  userId: string,
  contextType: ContextType = 'admin_assistant',
): Promise<ChatConversation[]> {
  if (!getDemoMode()) {
    const response = await productionConversation(contextType);
    if (!response.conversation_id) return [];
    const now = new Date().toISOString();
    return [{
      id: response.conversation_id,
      user_id: userId,
      user_role: '',
      context_type: contextType,
      title: response.messages?.find((message) => message.role === 'user')?.content?.slice(0, 60) || 'Assistant conversation',
      created_at: now,
      updated_at: response.messages?.[response.messages.length - 1]?.created_at || now,
    }];
  }

  const { data, error } = await supabase
    .from('ai_assistant_conversations')
    .select('*')
    .eq('user_id', userId)
    .order('updated_at', { ascending: false })
    .limit(20);

  if (error) throw error;
  return (data || []) as ChatConversation[];
}

export async function fetchMessages(
  conversationId: string,
  contextType: ContextType = 'admin_assistant',
): Promise<ChatMessage[]> {
  if (!getDemoMode()) {
    const response = await productionConversation(contextType, conversationId);
    return mapProductionMessages(response, response.conversation_id || conversationId);
  }

  const { data, error } = await supabase
    .from('ai_assistant_messages')
    .select('*')
    .eq('conversation_id', conversationId)
    .order('created_at', { ascending: true });

  if (error) throw error;
  return (data || []) as ChatMessage[];
}

interface StreamCallbacks {
  onChunk: (text: string) => void;
  onDone: (conversationId: string) => void;
  onError: (error: string) => void;
}

interface AssistantPayload {
  message: string;
  conversationId?: string;
  userId: string;
  userRole: string;
  userName: string;
  contextType?: ContextType;
  history?: { role: string; content: string }[];
}

function extractChunkText(value: unknown): string {
  if (!value || typeof value !== 'object') return '';
  const data = value as {
    content?: unknown;
    text?: unknown;
    choices?: Array<{ delta?: { content?: unknown }; text?: unknown }>;
  };
  if (typeof data.content === 'string') return data.content;
  if (typeof data.text === 'string') return data.text;
  const choice = data.choices?.[0];
  if (typeof choice?.delta?.content === 'string') return choice.delta.content;
  if (typeof choice?.text === 'string') return choice.text;
  return '';
}

function extractConversationId(value: unknown): string | null {
  if (!value || typeof value !== 'object') return null;
  const data = value as { conversation_id?: unknown; conversationId?: unknown };
  if (typeof data.conversation_id === 'string') return data.conversation_id;
  if (typeof data.conversationId === 'string') return data.conversationId;
  return null;
}

async function streamProduction(
  payload: AssistantPayload,
  callbacks: StreamCallbacks,
): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/functions/v1/ai-assistant`, {
    method: 'POST',
    headers: { ...productionHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify({
      query: payload.message,
      conversation_id: payload.conversationId,
      context_type: payload.contextType || 'admin_assistant',
      messages: payload.history,
    }),
  });

  if (!response.ok) {
    callbacks.onError((await response.text()) || `Request failed (${response.status})`);
    return;
  }

  const reader = response.body?.getReader();
  if (!reader) {
    callbacks.onError('No response stream available');
    return;
  }

  const headerConversationId = response.headers.get('X-AI-Conversation-Id');
  const decoder = new TextDecoder();
  let buffer = '';
  let conversationId = headerConversationId || payload.conversationId || '';
  let completed = false;

  const finish = (): void => {
    if (!completed) {
      completed = true;
      callbacks.onDone(conversationId);
    }
  };

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const events = buffer.split(/\r?\n\r?\n/);
    buffer = events.pop() || '';

    for (const event of events) {
      const dataLine = event.split(/\r?\n/).find((line) => line.startsWith('data:'));
      if (!dataLine) continue;
      const raw = dataLine.slice(5).trim();
      if (!raw) continue;
      if (raw === '[DONE]') {
        finish();
        continue;
      }
      try {
        const parsed: unknown = JSON.parse(raw);
        conversationId = extractConversationId(parsed) || conversationId;
        const text = extractChunkText(parsed);
        if (text) callbacks.onChunk(text);
      } catch {
        callbacks.onChunk(raw);
      }
    }
  }

  finish();
}

export async function streamAssistantResponse(
  payload: AssistantPayload,
  callbacks: StreamCallbacks,
): Promise<void> {
  if (!getDemoMode()) {
    try {
      await streamProduction(payload, callbacks);
    } catch (error) {
      callbacks.onError(error instanceof Error ? error.message : 'Network error');
    }
    return;
  }

  const url = `${supabaseUrl}/functions/v1/ai-assistant`;

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${supabaseAnonKey}`,
      },
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      const errText = await response.text();
      callbacks.onError(errText || `Request failed (${response.status})`);
      return;
    }

    const reader = response.body?.getReader();
    if (!reader) {
      callbacks.onError('No response stream available');
      return;
    }

    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n\n');
      buffer = lines.pop() || '';

      for (const line of lines) {
        if (!line.startsWith('data: ')) continue;
        const jsonStr = line.slice(6).trim();
        if (!jsonStr) continue;
        if (jsonStr === '[DONE]') continue;

        try {
          const data = JSON.parse(jsonStr) as { content?: string; done?: boolean; conversationId?: string };
          if (data.content) callbacks.onChunk(data.content);
          if (data.done && data.conversationId) callbacks.onDone(data.conversationId);
        } catch {
          /* skip malformed chunks */
        }
      }
    }
  } catch (error) {
    callbacks.onError(error instanceof Error ? error.message : 'Network error');
  }
}
