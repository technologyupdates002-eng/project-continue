import { useState } from 'react';
import { useNavigate } from '@/lib/router-compat';
import { Lock, AlertCircle } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { Button, Input } from '@/components/ui';

export default function ForcePasswordChangePage() {
  const { updatePassword } = useAuth();
  const navigate = useNavigate();
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    if (newPassword.length < 8) {
      setError('Password must be at least 8 characters long.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.');
      return;
    }
    setLoading(true);
    try {
      await updatePassword('old', newPassword);
      navigate('/member');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to change password.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center px-4 py-8 bg-neutral-50">
      <div className="w-full max-w-sm">
        <div className="flex items-center gap-2.5 mb-8 justify-center">
          <img src="/images/image%20copy%203.png" alt="KHWWC logo" className="w-10 h-10 rounded-full object-cover bg-white" />
          <div>
            <p className="text-base font-bold text-neutral-900 leading-tight">KHWWC</p>
            <p className="text-xs text-neutral-400 leading-tight">Password Reset Required</p>
          </div>
        </div>

        <div className="card p-6">
          <h2 className="text-lg font-bold text-neutral-900">Change Your Password</h2>
          <p className="mt-1 text-sm text-neutral-500">For security reasons, you must set a new password before continuing.</p>

          <form onSubmit={handleSubmit} className="mt-5 space-y-4">
            {error && (
              <div className="flex items-start gap-2 p-3 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
                <AlertCircle size={16} className="flex-shrink-0 mt-0.5" />
                <span>{error}</span>
              </div>
            )}

            <div>
              <label className="label">New Password</label>
              <div className="relative">
                <Lock size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" />
                <input
                  type="password"
                  value={newPassword}
                  onChange={e => setNewPassword(e.target.value)}
                  placeholder="At least 8 characters"
                  className="input-field pl-9"
                  required
                  autoFocus
                />
              </div>
            </div>

            <Input
              label="Confirm Password"
              type="password"
              value={confirmPassword}
              onChange={e => setConfirmPassword(e.target.value)}
              placeholder="Re-enter new password"
              required
            />

            <Button type="submit" size="lg" className="w-full" disabled={loading}>
              {loading ? 'Updating...' : 'Update Password'}
            </Button>
          </form>
        </div>
      </div>
    </div>
  );
}
