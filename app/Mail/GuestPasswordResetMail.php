<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $guestUser,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your guest account password',
        );
    }

    public function content(): Content
    {
        $resetUrl = rtrim((string) config('app.frontend_url'), '/').'/guest/reset-password?token='.urlencode($this->token);

        return new Content(
            view: 'emails.guest.password-reset',
            with: [
                'customerName' => $this->guestUser->name,
                'resetUrl' => $resetUrl,
            ],
        );
    }
}
