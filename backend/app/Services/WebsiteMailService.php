<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class WebsiteMailService
{
    public function sendTemplate(string $templateKey, string $recipient, array $variables = []): void
    {
        $settings = $this->emailSettings();
        abort_unless($settings['enabled'], 422, 'Website email delivery is disabled.');
        abort_unless($settings['smtp_host'] !== '' && $settings['from_email'] !== '', 422, 'Configure the SMTP host and sender address first.');

        $template = DB::table('website_email_templates')->where('template_key', $templateKey)->first();
        abort_unless($template && $template->enabled, 404, 'Enabled email template not found.');

        $replace = [];
        foreach ($variables as $key => $value) {
            $replace['{{'.$key.'}}'] = (string) $value;
        }

        $transport = Transport::fromDsn($this->dsn($settings));
        $message = (new Email)
            ->from(new Address($settings['from_email'], $settings['from_name']))
            ->to($recipient)
            ->subject(strtr($template->subject, $replace))
            ->text(strtr($template->body, $replace));

        (new Mailer($transport))->send($message);
    }

    public function emailSettings(): array
    {
        $stored = DB::table('website_cms_settings')
            ->where('setting_key', 'email')
            ->value('setting_value');
        $settings = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : [];
        $settings['smtp_password'] = isset($settings['smtp_password'])
            ? Crypt::decryptString($settings['smtp_password'])
            : '';

        return array_merge([
            'enabled' => false,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'encryption' => 'tls',
            'from_email' => '',
            'from_name' => 'KHCWW Website',
        ], $settings);
    }

    private function dsn(array $settings): string
    {
        $scheme = match ($settings['encryption']) {
            'ssl' => 'smtps',
            default => 'smtp',
        };
        $credentials = '';
        if ($settings['smtp_username'] !== '') {
            $credentials = rawurlencode($settings['smtp_username']).':'.rawurlencode($settings['smtp_password']).'@';
        }

        $dsn = sprintf(
            '%s://%s%s:%d',
            $scheme,
            $credentials,
            $settings['smtp_host'],
            $settings['smtp_port']
        );

        return $settings['encryption'] === 'none' ? $dsn.'?auto_tls=false' : $dsn;
    }
}
