import { useState, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { User, Mail, Phone, MapPin, Building2, Save, CheckCircle, Camera, Lock, AlertCircle, Key } from 'lucide-react';
import { mockMember } from '@/api/mock';
import { queryKeys } from '@/lib/query';
import {
  Card, Button, Input, PageHeader, Avatar, LoadingState, ErrorState,
} from '@/components/ui';
import { useAuth } from '@/auth/AuthContext';
import { initials } from '@/lib/format';
import type { Member } from '@/types';

interface ProfileForm {
  name: string;
  email: string;
  phone: string;
  county: string;
  workplace: string;
}

export default function MemberProfile() {
  const { user } = useAuth();
  const qc = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [form, setForm] = useState<ProfileForm | null>(null);
  const [saved, setSaved] = useState(false);
  const [photoUploading, setPhotoUploading] = useState(false);
  const [photoError, setPhotoError] = useState('');

  const [showPasswordForm, setShowPasswordForm] = useState(false);
  const [passwordForm, setPasswordForm] = useState({ new_password: '', confirm_password: '' });
  const [passwordError, setPasswordError] = useState('');
  const [passwordSuccess, setPasswordSuccess] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: queryKeys.memberProfile,
    queryFn: () => mockMember.getProfile(),
  });

  const profile = data as Member | undefined;

  if (profile && !form) {
    setForm({
      name: profile.name,
      email: profile.email,
      phone: profile.phone,
      county: profile.county,
      workplace: profile.workplace,
    });
  }

  const update = useMutation({
    mutationFn: (payload: Partial<Member>) => mockMember.updateProfile(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.memberProfile });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  const passwordMut = useMutation({
    mutationFn: (data: { new_password: string }) => mockMember.changePassword(data),
    onSuccess: () => {
      setPasswordSuccess(true);
      setPasswordForm({ new_password: '', confirm_password: '' });
      setShowPasswordForm(false);
      setTimeout(() => setPasswordSuccess(false), 3000);
    },
    onError: () => setPasswordError('Failed to change password. Please try again.'),
  });

  const handlePhotoChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !user) return;
    setPhotoUploading(true);
    setPhotoError('');
    try {
      await mockMember.uploadProfilePhoto({ file, userId: user.id });
      await mockMember.updateProfile({ avatar: `/profile-images/${user.id}/profile.png` } as any);
      qc.invalidateQueries({ queryKey: queryKeys.memberProfile });
    } catch {
      setPhotoError('Failed to upload photo. Please try again.');
    } finally {
      setPhotoUploading(false);
    }
  };

  const handlePasswordSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPasswordError('');
    if (passwordForm.new_password.length < 6) {
      setPasswordError('Password must be at least 6 characters.');
      return;
    }
    if (passwordForm.new_password !== passwordForm.confirm_password) {
      setPasswordError('Passwords do not match.');
      return;
    }
    passwordMut.mutate({ new_password: passwordForm.new_password });
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!form) return;
    update.mutate(form);
  };

  const set = (k: keyof ProfileForm, v: string) => setForm((f) => (f ? { ...f, [k]: v } : f));
  const displayName = form?.name || profile?.name || user?.name || 'Member';

  if (isLoading) {
    return (
      <div className="space-y-5">
        <PageHeader title="My Profile" subtitle="Update your personal details" icon={<User size={20} />} />
        <LoadingState text="Loading profile..." />
      </div>
    );
  }

  if (isError || !profile || !form) {
    return (
      <div className="space-y-5">
        <PageHeader title="My Profile" subtitle="Update your personal details" icon={<User size={20} />} />
        <ErrorState message="We couldn't load your profile." onRetry={() => refetch()} />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <PageHeader title="My Profile" subtitle="Update your personal details and security settings" icon={<User size={20} />} />

      {/* Profile summary with photo upload */}
      <Card className="p-5">
        <div className="flex flex-col sm:flex-row items-center sm:items-start gap-4">
          <div className="relative">
            <Avatar name={displayName} src={profile.avatar} size="xl" />
            <button
              onClick={() => fileInputRef.current?.click()}
              disabled={photoUploading}
              className="absolute -bottom-1 -right-1 w-8 h-8 rounded-full bg-primary-600 text-white flex items-center justify-center shadow-lg hover:bg-primary-700 transition-colors disabled:opacity-50"
              title="Change photo"
            >
              <Camera size={14} />
            </button>
            <input
              ref={fileInputRef}
              type="file"
              accept="image/png,image/jpeg"
              onChange={handlePhotoChange}
              className="hidden"
            />
          </div>
          <div className="text-center sm:text-left">
            <h2 className="text-base font-bold text-neutral-900">{profile.name}</h2>
            <p className="text-sm text-neutral-500">{profile.email}</p>
            <div className="mt-2 flex flex-wrap justify-center sm:justify-start gap-2">
              <span className="badge bg-primary-50 text-primary-700">{profile.member_number}</span>
              <span className="badge bg-success-100 text-success-700 capitalize">{profile.status}</span>
            </div>
            {photoError && <p className="text-xs text-error-600 mt-2">{photoError}</p>}
            {photoUploading && <p className="text-xs text-neutral-400 mt-2">Uploading photo...</p>}
          </div>
        </div>
      </Card>

      {/* Edit form */}
      <Card className="p-5">
        <h3 className="text-sm font-semibold text-neutral-900 mb-4">Edit Details</h3>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Input label="Full Name" value={form.name} onChange={(e) => set('name', e.target.value)} />
            <Input label="Email" type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
            <Input label="Phone" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
            <Input label="County" value={form.county} onChange={(e) => set('county', e.target.value)} />
            <Input label="Workplace" value={form.workplace} onChange={(e) => set('workplace', e.target.value)} />
          </div>
          <div className="flex items-center gap-3 pt-2">
            <Button type="submit" className="inline-flex items-center gap-2" disabled={update.isPending}>
              {update.isPending ? 'Saving...' : <><Save size={16} /> Save Changes</>}
            </Button>
            {saved && (
              <span className="text-sm text-success-600 inline-flex items-center gap-1.5">
                <CheckCircle size={15} /> Saved successfully
              </span>
            )}
          </div>
        </form>
      </Card>

      {/* Password change */}
      <Card className="p-5">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-sm font-semibold text-neutral-900 inline-flex items-center gap-2">
            <Lock size={16} /> Change Password
          </h3>
          {passwordSuccess && (
            <span className="text-sm text-success-600 inline-flex items-center gap-1.5">
              <CheckCircle size={15} /> Password updated
            </span>
          )}
        </div>
        {!showPasswordForm ? (
          <Button variant="secondary" onClick={() => setShowPasswordForm(true)}>
            <Key size={15} /> Change Password
          </Button>
        ) : (
          <form onSubmit={handlePasswordSubmit} className="space-y-4">
            {passwordError && (
              <div className="flex items-start gap-2 p-3 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
                <AlertCircle size={16} className="flex-shrink-0 mt-0.5" />
                <span>{passwordError}</span>
              </div>
            )}
            <Input label="New Password" type="password" value={passwordForm.new_password} onChange={e => setPasswordForm({ ...passwordForm, new_password: e.target.value })} required />
            <Input label="Confirm Password" type="password" value={passwordForm.confirm_password} onChange={e => setPasswordForm({ ...passwordForm, confirm_password: e.target.value })} required />
            <p className="text-xs text-neutral-400">Password must be at least 6 characters.</p>
            <div className="flex items-center gap-3 pt-2">
              <Button type="submit" disabled={passwordMut.isPending}>
                {passwordMut.isPending ? 'Updating...' : 'Update Password'}
              </Button>
              <Button variant="secondary" type="button" onClick={() => { setShowPasswordForm(false); setPasswordForm({ new_password: '', confirm_password: '' }); setPasswordError(''); }}>
                Cancel
              </Button>
            </div>
          </form>
        )}
      </Card>
    </div>
  );
}
