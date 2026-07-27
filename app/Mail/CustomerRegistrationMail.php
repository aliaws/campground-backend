<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent only for direct self-registration via /customer/register — CustomerVerificationMail covers the booking/contact-created path. */
class CustomerRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $customerUser,
        public string $rawCode,
        public string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome! Verify your email to finish creating your account',
        );
    }

    public function content(): Content
    {
        $verifyUrl = rtrim((string) config('app.frontend_url'), '/').'/customer-auth/verify?token='.urlencode($this->rawToken);

        return new Content(
            view: 'emails.customer.registration',
            with: [
                'customerName' => $this->customerUser->name,
                'verifyUrl' => $verifyUrl,
                'code' => $this->rawCode,
            ],
        );
    }
}
