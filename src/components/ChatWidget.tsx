import { useState, useRef, useEffect, useCallback } from 'react';
import {
  Sparkles, X, Send, MessageCircle, Trash2, ChevronLeft,
} from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import {
  fetchConversations, fetchMessages, streamAssistantResponse,
} from '@/api/chat';
import { classNames, relativeTime } from '@/lib/format';
import type { ChatConversation, ChatMessage } from '@/types';

interface UIMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  created_at: string;
}

const SUGGESTIONS: Record<string, string[]> = {
  admin_assistant: [
    'How do I confirm a pending contribution?',
    'What is the withdrawal approval workflow?',
    'How do I import member data?',
    'How are penalty payments verified?',
  ],
  secretary_assistant: [
    'How do I create meeting minutes?',
    'What is the minutes approval process?',
    'How do I schedule a meeting?',
    'How do I upload my signature?',
  ],
};

export function ChatWidget() {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);
  const [conversations, setConversations] = useState<ChatConversation[]>([]);
  const [activeConvId, setActiveConvId] = useState<string | null>(null);
  const [messages, setMessages] = useState<UIMessage[]>([]);
  const [input, setInput] = useState('');
  const [streaming, setStreaming] = useState(false);
  const [loadingHistory, setLoadingHistory] = useState(false);
  const [showHistory, setShowHistory] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const scrollRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  const contextType: 'admin_assistant' | 'secretary_assistant' =
    user?.role === 'secretary' || user?.role === 'vice-secretary' || user?.role === 'chairperson'
      ? 'secretary_assistant'
      : 'admin_assistant';

  const suggestions = SUGGESTIONS[contextType] || SUGGESTIONS.admin_assistant;

  const scrollToBottom = useCallback(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
    }
  }, []);

  useEffect(() => {
    scrollToBottom();
  }, [messages, scrollToBottom]);

  useEffect(() => {
    if (open && inputRef.current) {
      setTimeout(() => inputRef.current?.focus(), 200);
    }
  }, [open]);

  const loadConversations = useCallback(async () => {
    if (!user) return;
    try {
      const convs = await fetchConversations(user.id, contextType);
      setConversations(convs);
    } catch {
      /* ignore — history is non-critical */
    }
  }, [contextType, user]);

  const loadMessages = useCallback(async (convId: string) => {
    setLoadingHistory(true);
    try {
      const msgs = await fetchMessages(convId, contextType);
      setMessages(msgs.map((m: ChatMessage) => ({
        id: m.id,
        role: m.role,
        content: m.content,
        created_at: m.created_at,
      })));
      setActiveConvId(convId);
      setShowHistory(false);
    } catch {
      setError('Failed to load conversation');
    } finally {
      setLoadingHistory(false);
    }
  }, [contextType]);

  useEffect(() => {
    if (open) loadConversations();
  }, [open, loadConversations]);

  const handleSend = useCallback(async (text?: string) => {
    const messageText = (text || input).trim();
    if (!messageText || !user || streaming) return;

    setInput('');
    setError(null);

    const userMsg: UIMessage = {
      id: `temp-user-${Date.now()}`,
      role: 'user',
      content: messageText,
      created_at: new Date().toISOString(),
    };
    const assistantMsg: UIMessage = {
      id: `temp-assistant-${Date.now()}`,
      role: 'assistant',
      content: '',
      created_at: new Date().toISOString(),
    };

    setMessages((prev) => [...prev, userMsg, assistantMsg]);
    setStreaming(true);

    await streamAssistantResponse(
      {
        message: messageText,
        conversationId: activeConvId || undefined,
        userId: user.id,
        userRole: user.role,
        userName: user.name,
        contextType,
      },
      {
        onChunk: (chunk) => {
          setMessages((prev) =>
            prev.map((m) =>
              m.id === assistantMsg.id
                ? { ...m, content: m.content + chunk }
                : m,
            ),
          );
        },
        onDone: (convId) => {
          setActiveConvId(convId);
          setStreaming(false);
          loadConversations();
        },
        onError: (err) => {
          setMessages((prev) =>
            prev.map((m) =>
              m.id === assistantMsg.id
                ? { ...m, content: `Sorry, I couldn't respond right now. ${err}` }
                : m,
            ),
          );
          setStreaming(false);
        },
      },
    );
  }, [input, user, streaming, activeConvId, contextType, loadConversations]);

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  const startNewConversation = () => {
    setActiveConvId(null);
    setMessages([]);
    setShowHistory(false);
    setError(null);
  };

  if (!user) return null;

  return (
    <>
      {/* Floating Button */}
      {!open && (
        <button
          onClick={() => setOpen(true)}
          className="fixed bottom-6 right-6 z-40 flex items-center gap-2 rounded-full bg-primary-500 px-4 py-3 text-white shadow-float-lg transition-all hover:bg-primary-600 hover:scale-105 active:scale-95"
          aria-label="Open AI Assistant"
        >
          <Sparkles size={20} className="animate-pulse" />
          <span className="text-sm font-medium hidden sm:inline">AI Assistant</span>
        </button>
      )}

      {/* Chat Drawer */}
      {open && (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center sm:justify-end p-0 sm:p-6">
          <div
            className="absolute inset-0 bg-neutral-950/30 backdrop-blur-sm animate-fade-in"
            onClick={() => setOpen(false)}
          />

          <div className="relative flex flex-col bg-white rounded-t-2xl sm:rounded-2xl shadow-float-lg w-full sm:w-[420px] h-[85vh] sm:h-[600px] max-h-[700px] animate-slide-up overflow-hidden">
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-3 bg-neutral-950 text-white">
              <div className="flex items-center gap-2.5 min-w-0">
                {showHistory ? (
                  <button
                    onClick={() => setShowHistory(false)}
                    className="p-1 -ml-1 rounded-lg hover:bg-white/10 transition-colors flex-shrink-0"
                  >
                    <ChevronLeft size={18} />
                  </button>
                ) : (
                  <div className="w-8 h-8 rounded-lg bg-primary-500 flex items-center justify-center flex-shrink-0">
                    <Sparkles size={16} />
                  </div>
                )}
                <div className="min-w-0">
                  <p className="text-sm font-semibold truncate">
                    {showHistory ? 'Chat History' : 'KHWWC Assistant'}
                  </p>
                  <p className="text-2xs text-neutral-400 truncate">
                    {showHistory ? `${conversations.length} conversations` : contextType === 'secretary_assistant' ? 'Secretary Assistant' : 'Admin Assistant'}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-1 flex-shrink-0">
                {!showHistory && (
                  <button
                    onClick={() => setShowHistory(true)}
                    className="p-1.5 rounded-lg hover:bg-white/10 transition-colors"
                    aria-label="Chat history"
                  >
                    <MessageCircle size={16} />
                  </button>
                )}
                <button
                  onClick={() => {
                    setOpen(false);
                    setShowHistory(false);
                  }}
                  className="p-1.5 rounded-lg hover:bg-white/10 transition-colors"
                  aria-label="Close chat"
                >
                  <X size={18} />
                </button>
              </div>
            </div>

            {/* History Panel */}
            {showHistory ? (
              <div className="flex-1 overflow-y-auto">
                {conversations.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-full text-neutral-400 px-6">
                    <MessageCircle size={32} className="mb-2 text-neutral-300" />
                    <p className="text-sm">No previous conversations yet</p>
                  </div>
                ) : (
                  <div className="p-2 space-y-1">
                    <button
                      onClick={startNewConversation}
                      className="w-full flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium text-primary-600 bg-primary-50 hover:bg-primary-100 transition-colors"
                    >
                      <Sparkles size={16} />
                      Start new conversation
                    </button>
                    {conversations.map((conv) => (
                      <button
                        key={conv.id}
                        onClick={() => loadMessages(conv.id)}
                        className={classNames(
                          'w-full text-left px-3 py-2.5 rounded-lg text-sm transition-colors',
                          conv.id === activeConvId
                            ? 'bg-primary-50 text-neutral-900'
                            : 'hover:bg-neutral-50 text-neutral-700',
                        )}
                      >
                        <p className="font-medium truncate">
                          {conv.title || 'Untitled conversation'}
                        </p>
                        <p className="text-2xs text-neutral-400 mt-0.5">
                          {relativeTime(conv.updated_at)}
                        </p>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            ) : (
              <>
                {/* Messages */}
                <div
                  ref={scrollRef}
                  className="flex-1 overflow-y-auto px-4 py-4 space-y-3 bg-neutral-50"
                >
                  {messages.length === 0 && !loadingHistory && (
                    <div className="flex flex-col items-center justify-center h-full text-center px-4">
                      <div className="w-12 h-12 rounded-xl bg-primary-100 flex items-center justify-center mb-3">
                        <Sparkles size={24} className="text-primary-500" />
                      </div>
                      <p className="text-sm font-semibold text-neutral-900 mb-1">
                        How can I help you?
                      </p>
                      <p className="text-xs text-neutral-500 mb-4">
                        Ask me about members, contributions, penalties, withdrawals, events, and more.
                      </p>
                      <div className="w-full space-y-1.5">
                        {suggestions.map((s) => (
                          <button
                            key={s}
                            onClick={() => handleSend(s)}
                            className="w-full text-left px-3 py-2 rounded-lg text-xs text-neutral-700 bg-white border border-neutral-200 hover:border-primary-300 hover:bg-primary-50 transition-colors"
                          >
                            {s}
                          </button>
                        ))}
                      </div>
                    </div>
                  )}

                  {loadingHistory && (
                    <div className="flex items-center justify-center h-full">
                      <div className="w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full animate-spin" />
                    </div>
                  )}

                  {messages.map((msg) => (
                    <div
                      key={msg.id}
                      className={classNames(
                        'flex',
                        msg.role === 'user' ? 'justify-end' : 'justify-start',
                      )}
                    >
                      <div
                        className={classNames(
                          'max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm',
                          msg.role === 'user'
                            ? 'bg-primary-500 text-white rounded-br-md'
                            : 'bg-white text-neutral-800 border border-neutral-200 rounded-bl-md',
                        )}
                      >
                        {msg.content ? (
                          <p className="whitespace-pre-wrap leading-relaxed">{msg.content}</p>
                        ) : (
                          <div className="flex items-center gap-1 py-1">
                            <span className="w-1.5 h-1.5 bg-neutral-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
                            <span className="w-1.5 h-1.5 bg-neutral-400 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
                            <span className="w-1.5 h-1.5 bg-neutral-400 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
                          </div>
                        )}
                      </div>
                    </div>
                  ))}

                  {error && (
                    <div className="flex justify-center">
                      <p className="text-xs text-error-500 bg-error-50 px-3 py-1.5 rounded-lg">{error}</p>
                    </div>
                  )}
                </div>

                {/* Input */}
                <div className="px-3 py-3 bg-white border-t border-neutral-100">
                  <div className="flex items-end gap-2">
                    <textarea
                      ref={inputRef}
                      value={input}
                      onChange={(e) => setInput(e.target.value)}
                      onKeyDown={handleKeyDown}
                      placeholder="Type your message..."
                      rows={1}
                      className="flex-1 resize-none rounded-xl border border-neutral-200 bg-neutral-50 px-3.5 py-2.5 text-sm text-neutral-900 placeholder-neutral-400 transition-colors focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 max-h-32"
                      style={{ minHeight: '42px' }}
                      disabled={streaming}
                    />
                    <button
                      onClick={() => handleSend()}
                      disabled={!input.trim() || streaming}
                      className="flex-shrink-0 w-10 h-10 rounded-xl bg-primary-500 text-white flex items-center justify-center transition-all hover:bg-primary-600 disabled:opacity-40 disabled:cursor-not-allowed"
                      aria-label="Send message"
                    >
                      <Send size={16} />
                    </button>
                  </div>
                  <p className="text-2xs text-neutral-400 mt-1.5 text-center">
                    AI assistant may provide general guidance — verify specifics in the system.
                  </p>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </>
  );
}
