<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Forgot-password flow for staff logins (owner/admin/staff/superadmin) — mirrors CustomerPasswordResetMail's shape, separate mailable since the reset link points at the staff /reset-password page, not the customer portal's. */
class StaffPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $staffUser,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your password',
        );
    }

    public function content(): Content
    {
        $resetUrl = rtrim((string) config('app.frontend_url'), '/').'/reset-password?token='.urlencode($this->token);

        return new Content(
            view: 'emails.staff.password-reset',
            with: [
                'staffName' => $this->staffUser->name,
                'resetUrl' => $resetUrl,
                'ttlMinutes' => (int) config('staff.password_reset_ttl_minutes', 60),
            ],
        );
    }
}
