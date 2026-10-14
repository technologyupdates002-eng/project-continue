import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Mail, Phone, MapPin, Send, CheckCircle, AlertCircle } from 'lucide-react';
import { mockPublic } from '@/api/mock';
import { Card, Button, Input, Textarea, PageHeader } from '@/components/ui';

export default function ContactPage() {
  const [form, setForm] = useState({ name: '', email: '', subject: '', message: '' });
  const [success, setSuccess] = useState(false);

  const mutation = useMutation({
    mutationFn: (data: typeof form) => mockPublic.submitContact(data),
    onSuccess: () => {
      setSuccess(true);
      setForm({ name: '', email: '', subject: '', message: '' });
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    mutation.mutate(form);
  };

  const contactInfo = [
    { icon: Phone, label: 'Phone', value: '+254 700 100 200' },
    { icon: Mail, label: 'Email', value: 'info@khwwc.co.ke' },
    { icon: MapPin, label: 'Address', value: 'Kerugoya Town, Kirinyaga County, Kenya' },
  ];

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
      <PageHeader title="Contact Us" subtitle="Get in touch with the KHWWC team" icon={<Mail size={20} />} />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
        <div className="space-y-4">
          {contactInfo.map((info) => (
            <Card key={info.label} className="p-5">
              <div className="flex items-start gap-3">
                <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-primary-50 text-primary-700 flex items-center justify-center">
                  <info.icon size={18} />
                </div>
                <div>
                  <p className="text-xs font-medium text-neutral-400">{info.label}</p>
                  <p className="text-sm font-semibold text-neutral-800 mt-0.5">{info.value}</p>
                </div>
              </div>
            </Card>
          ))}
        </div>

        <div className="lg:col-span-2">
          <Card className="p-6">
            {success ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <div className="w-14 h-14 rounded-2xl bg-success-50 text-success-600 flex items-center justify-center mb-4">
                  <CheckCircle size={28} />
                </div>
                <h3 className="text-base font-bold text-neutral-900">Message Sent</h3>
                <p className="mt-1 text-sm text-neutral-500">Thank you for reaching out. We'll get back to you soon.</p>
                <Button variant="secondary" className="mt-4" onClick={() => setSuccess(false)}>Send Another</Button>
              </div>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <Input label="Your Name" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} required placeholder="John Doe" />
                  <Input label="Email Address" type="email" value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} required placeholder="you@email.com" />
                </div>
                <Input label="Subject" value={form.subject} onChange={e => setForm({ ...form, subject: e.target.value })} required placeholder="How can we help?" />
                <Textarea label="Message" rows={5} value={form.message} onChange={e => setForm({ ...form, message: e.target.value })} required placeholder="Write your message here..." />

                {mutation.isError && (
                  <div className="flex items-center gap-2 p-3 rounded-xl bg-error-50 border border-error-200 text-sm text-error-700">
                    <AlertCircle size={16} /> Failed to send message. Please try again.
                  </div>
                )}

                <Button type="submit" size="lg" disabled={mutation.isPending}>
                  {mutation.isPending ? 'Sending...' : <><Send size={16} /> Send Message</>}
                </Button>
              </form>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
