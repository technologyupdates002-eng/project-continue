import { type ReactNode, useState } from 'react';
import { Link, Outlet, useLocation } from '@/lib/router-compat';
import { Menu, X, Phone, Mail, MapPin, Facebook, Twitter, Linkedin } from 'lucide-react';
import { classNames } from '@/lib/format';

const publicNav = [
  { label: 'Home', path: '/' },
  { label: 'About', path: '/public/website/content/about' },
  { label: 'Leadership', path: '/public/website/leadership' },
  { label: 'News', path: '/public/website/content/news' },
  { label: 'Events', path: '/public/website/content/events' },
  { label: 'Documents', path: '/public/website/content/documents' },
  { label: 'Contact', path: '/public/website/contact' },
];

export function PublicLayout({ children }: { children?: ReactNode }) {
  const location = useLocation();
  const [mobileOpen, setMobileOpen] = useState(false);

  const isActive = (path: string) => {
    if (path === '/') return location.pathname === '/';
    return location.pathname.startsWith(path);
  };

  return (
    <div className="public-shell min-h-screen flex flex-col">
      <header className="sticky top-0 z-30 bg-white/95 backdrop-blur-md border-b border-neutral-100">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between h-16">
            <Link to="/" className="flex items-center gap-2.5">
              <img src="/images/image%20copy%203.png" alt="KHWWC logo" className="w-10 h-10 rounded-full object-cover bg-white" />
              <div>
                <p className="text-sm font-bold text-neutral-900 leading-tight">KHWWC</p>
                <p className="text-2xs text-neutral-400 leading-tight hidden sm:block">Kirinyaga Healthcare Workers Welfare</p>
              </div>
            </Link>

            <nav className="hidden md:flex items-center gap-1">
              {publicNav.map(item => (
                <Link
                  key={item.path}
                  to={item.path}
                  className={classNames(
                    'px-3 py-2 rounded-md text-sm font-medium transition-colors',
                    isActive(item.path) ? 'text-primary-700 bg-primary-50' : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-50',
                  )}
                >
                  {item.label}
                </Link>
              ))}
            </nav>

            <div className="flex items-center gap-2">
              <Link to="/login" className="btn-primary text-sm hidden sm:inline-flex">
                Member Login
              </Link>
              <button
                onClick={() => setMobileOpen(!mobileOpen)}
                className="md:hidden btn-ghost p-1.5"
                aria-label="Toggle menu"
              >
                {mobileOpen ? <X size={22} /> : <Menu size={22} />}
              </button>
            </div>
          </div>
        </div>

        {mobileOpen && (
          <div className="md:hidden border-t border-neutral-100 py-3 px-4 space-y-1 animate-slide-down">
            {publicNav.map(item => (
              <Link
                key={item.path}
                to={item.path}
                onClick={() => setMobileOpen(false)}
                className={classNames(
                  'block px-3 py-2.5 rounded-md text-sm font-medium',
                  isActive(item.path) ? 'text-primary-700 bg-primary-50' : 'text-neutral-600 hover:bg-neutral-50',
                )}
              >
                {item.label}
              </Link>
            ))}
            <Link to="/login" onClick={() => setMobileOpen(false)} className="btn-primary w-full mt-2">
              Member Login
            </Link>
          </div>
        )}
      </header>

      <main className="flex-1">{children || <Outlet />}</main>

      <footer className="bg-neutral-950 text-neutral-400">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <div>
              <div className="flex items-center gap-2.5 mb-4">
                <img src="/images/image%20copy%203.png" alt="KHWWC logo" className="w-10 h-10 rounded-full object-cover bg-white" />
                <div>
                  <p className="text-sm font-bold text-white leading-tight">KHWWC</p>
                  <p className="text-2xs text-neutral-500 leading-tight">Healthcare Workers Welfare</p>
                </div>
              </div>
              <p className="text-sm text-neutral-500">Supporting healthcare workers in Kirinyaga County through financial security and community.</p>
            </div>
            <div>
              <h3 className="text-sm font-semibold text-white mb-3">Quick Links</h3>
              <ul className="space-y-2">
                {publicNav.slice(0, 5).map(item => (
                  <li key={item.path}>
                    <Link to={item.path} className="text-sm hover:text-primary-400 transition-colors">{item.label}</Link>
                  </li>
                ))}
              </ul>
            </div>
            <div>
              <h3 className="text-sm font-semibold text-white mb-3">Resources</h3>
              <ul className="space-y-2">
                <li><Link to="/public/website/content/documents" className="text-sm hover:text-primary-400 transition-colors">Documents</Link></li>
                <li><Link to="/public/website/contact" className="text-sm hover:text-primary-400 transition-colors">Contact Us</Link></li>
                <li><Link to="/login" className="text-sm hover:text-primary-400 transition-colors">Member Portal</Link></li>
              </ul>
            </div>
            <div>
              <h3 className="text-sm font-semibold text-white mb-3">Contact</h3>
              <ul className="space-y-2.5 text-sm">
                <li className="flex items-center gap-2"><Phone size={14} className="text-primary-500" /> +254 700 100 200</li>
                <li className="flex items-center gap-2"><Mail size={14} className="text-primary-500" /> info@khwwc.co.ke</li>
                <li className="flex items-center gap-2"><MapPin size={14} className="text-primary-500" /> Kerugoya, Kirinyaga</li>
              </ul>
              <div className="flex gap-3 mt-4">
                <a href="#" className="text-neutral-500 hover:text-primary-400 transition-colors" aria-label="Facebook"><Facebook size={18} /></a>
                <a href="#" className="text-neutral-500 hover:text-primary-400 transition-colors" aria-label="Twitter"><Twitter size={18} /></a>
                <a href="#" className="text-neutral-500 hover:text-primary-400 transition-colors" aria-label="LinkedIn"><Linkedin size={18} /></a>
              </div>
            </div>
          </div>
          <div className="border-t border-white/10 mt-8 pt-6 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p className="text-xs text-neutral-500">© 2026 Kirinyaga Healthcare Workers Welfare. All rights reserved.</p>
            <p className="text-xs text-neutral-500">Supporting those who care for our community</p>
          </div>
        </div>
      </footer>
    </div>
  );
}
