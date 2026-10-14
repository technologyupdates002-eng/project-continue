import { useState } from 'react';
import { useNavigate, Link } from '@/lib/router-compat';
import { AlertCircle, ArrowRight, Cloud, FlaskConical, Lock, Phone, Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { useAuth, roleDashboardPath } from '@/auth/AuthContext';
import { Button } from '@/components/ui';
import { AppearanceControls } from '@/components/AppearanceProvider';

export default function LoginPage() {
  const { login, demoMode, setDemoMode } = useAuth();
  const navigate = useNavigate();
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setError(''); setLoading(true);
    try { const user = await login(phone, password); navigate(roleDashboardPath[user.role]); }
    catch (err) { setError(err instanceof Error ? err.message : 'Login failed. Please try again.'); }
    finally { setLoading(false); }
  };
  const demoAccounts = [
    { role: 'Member', phone: '0700 000 001' }, { role: 'Admin', phone: '0700 000 002' },
    { role: 'Treasurer', phone: '0700 000 003' }, { role: 'Secretary', phone: '0700 000 004' },
    { role: 'Chairperson', phone: '0700 000 005' }, { role: 'Super Admin', phone: '0700 000 006' },
  ];
  return <main className="auth-page relative">
    <section className="auth-panel relative">
      <div className="flex items-center justify-between gap-4">
        <Link to="/" className="auth-brand"><img src="/images/image%20copy%203.png" alt="KHWWC logo" /><span><span className="block text-lg font-extrabold font-display">KHWWC</span><span className="block text-xs text-muted-foreground">Healthcare Workers Welfare</span></span></Link>
        <Button variant="ghost" size="sm" onClick={() => setDemoMode(!demoMode)} title={demoMode ? 'Switch to production mode' : 'Switch to demo mode'}>{demoMode ? <FlaskConical size={14} /> : <Cloud size={14} />}{demoMode ? 'Demo' : 'Live'}</Button>
      </div>
      <h1 className="font-bold">Welcome back.</h1><p className="mt-3 text-sm leading-6">Sign in to your KHWWC account.</p>
      <form onSubmit={handleSubmit} className="mt-8 space-y-5">
        {error && <div role="alert" className="flex items-start gap-2 rounded-lg bg-error-50 p-3 text-sm text-error-700"><AlertCircle size={18} /><span>{error}</span></div>}
        <label className="auth-field">Phone number<span className="relative"><Phone size={17} /><input type="tel" value={phone} onChange={e => setPhone(e.target.value)} placeholder="0700 000 000" className="input-field" required autoComplete="tel" /></span></label>
        <label className="auth-field">Password<span className="relative"><Lock size={17} /><input type={visible ? 'text' : 'password'} value={password} onChange={e => setPassword(e.target.value)} placeholder="Enter your password" className="input-field" required autoComplete="current-password" /><Button variant="ghost" type="button" className="password-toggle" onClick={() => setVisible(value => !value)} aria-label={visible ? 'Hide password' : 'Show password'}>{visible ? <EyeOff size={17} /> : <Eye size={17} />}</Button></span></label>
        <Button type="submit" disabled={loading} className="w-full py-3.5">{loading ? 'Signing in…' : 'Sign in'}<ArrowRight size={17} /></Button>
      </form>
      <div className="mt-4 flex items-center justify-center gap-1.5 text-xs text-muted-foreground"><ShieldCheck size={13} />KHWWC member portal</div>
      {demoMode && <div className="mt-7"><p className="mb-3 text-xs font-semibold">Demo accounts</p><div className="grid grid-cols-2 gap-2">{demoAccounts.map(account => <Button variant="ghost" type="button" key={account.phone} onClick={() => { setPhone(account.phone); setPassword('demo123'); }} className="demo-account"><span>{account.role}</span><ArrowRight size={12} /></Button>)}</div></div>}
      <div className="auth-footer mt-7 flex items-center justify-between gap-3"><Link to="/" className="text-xs text-muted-foreground">← Back to website</Link><AppearanceControls /></div>
      <p className="mt-5 text-center text-[11px]">© 2026 KHWWC</p>
    </section>
  </main>;
}
