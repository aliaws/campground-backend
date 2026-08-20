<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromOrganization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerPasswordResetMail extends Mailable
{
    use Queueable, SendsFromOrganization, SerializesModels;

    public function __construct(
        public User $customerUser,
        public string $token,
        public ?string $organizationName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizationFromAddress($this->organizationName),
            subject: 'Reset your account password',
        );
    }

    public function content(): Content
    {
        $resetUrl = rtrim((string) config('app.frontend_url'), '/').'/customer-auth/reset-password?token='.urlencode($this->token);

        return new Content(
            view: 'emails.customer.password-reset',
            with: [
                'customerName' => $this->customerUser->name,
                'resetUrl' => $resetUrl,
                'ttlMinutes' => (int) config('customer.password_reset_ttl_minutes', 60),
            ],
        );
    }
}
