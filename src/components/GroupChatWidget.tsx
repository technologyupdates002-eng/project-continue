import { useState } from 'react';
import { MessageCircle, X } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import ChatPage from '@/pages/member/ChatPage';
import { Button } from '@/components/ui';

export function GroupChatWidget() {
  const { user } = useAuth();
  const [open, setOpen] = useState(false);

  if (!user) return null;

  return (
    <>
      {!open && (
        <Button
          onClick={() => setOpen(true)}
          className="chat-launcher fixed right-4 bottom-[76px] sm:right-6 sm:bottom-6 z-40 flex items-center justify-center gap-2 w-14 h-14 sm:w-auto sm:h-auto sm:px-5 sm:py-3 rounded-full transition-all hover:-translate-y-1 active:scale-95"
          aria-label="Open group chat"
        >
          <MessageCircle size={22} />
          <span className="hidden sm:inline text-sm font-semibold">Group chat</span>
        </Button>
      )}

      {open && (
        <div className="chat-window fixed inset-0 z-50 sm:inset-auto sm:right-6 sm:bottom-6 sm:w-[min(920px,calc(100vw-48px))] sm:h-[min(760px,calc(100dvh-48px))] sm:rounded-lg sm:overflow-hidden" role="dialog" aria-label="Group chat">
          <Button variant="secondary"
            onClick={() => setOpen(false)}
            className="absolute right-3 top-3 z-20 w-9 h-9 p-0"
            aria-label="Minimise group chat"
            title="Minimise chat"
          >
            <X size={18} />
          </Button>
          <ChatPage className="h-full min-h-0 rounded-none border-0 shadow-none" />
        </div>
      )}
    </>
  );
}
