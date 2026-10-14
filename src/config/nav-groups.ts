import type { NavItem } from './nav';

export interface NavGroup { label: string; icon: string; items: NavItem[] }

// Ordered rules: first match wins. Keeps the sidebar to a few dropdowns.
const rules: { label: string; icon: string; test: RegExp }[] = [
  { label: 'Website', icon: 'Globe', test: /\/website\// },
  { label: 'Payments', icon: 'CreditCard', test: /pay-penalty|donate|welfare|payments|unmatched|penalty-payments|donations|campaigns|receipts/ },
  { label: 'Wallets & Banking', icon: 'Wallet', test: /wallet|bank|book-balance|statement-import|import-history|withdrawal|expenses|payouts/ },
  { label: 'Members', icon: 'Users', test: /members|beneficiar|defaulters|registration|executive-badges|import$|contribution/ },
  { label: 'Meetings & Records', icon: 'FileText', test: /events|minutes|meeting|documents|downloads|signature|reports|memos/ },
  { label: 'Communication', icon: 'MessageSquare', test: /sms|news|notifications/ },
  { label: 'System', icon: 'Settings', test: /settings|security|password|access|online|login|monitor|system|database|trace|audit|troubleshoot|diagnostics/ },
];

/** Splits a role's pages into a pinned list (dashboard/profile) and grouped dropdowns. */
export function groupNav(items: NavItem[]): { pinned: NavItem[]; groups: NavGroup[] } {
  const pinned: NavItem[] = [];
  const map = new Map<string, NavGroup>();
  for (const item of items) {
    if (item.label === 'Dashboard' || item.label === 'Profile') { pinned.push(item); continue; }
    const rule = rules.find(r => r.test.test(item.path));
    const key = rule?.label ?? 'More';
    if (!map.has(key)) map.set(key, { label: key, icon: rule?.icon ?? 'LayoutDashboard', items: [] });
    map.get(key)!.items.push(item);
  }
  const order = [...rules.map(r => r.label), 'More'];
  const groups = order.map(l => map.get(l)).filter((g): g is NavGroup => !!g);
  // A group with a single page doesn't need a dropdown.
  const singles = groups.filter(g => g.items.length === 1).flatMap(g => g.items);
  return { pinned: [...pinned, ...singles], groups: groups.filter(g => g.items.length > 1) };
}
