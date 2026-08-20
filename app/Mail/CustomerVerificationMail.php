<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromOrganization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerVerificationMail extends Mailable
{
    use Queueable, SendsFromOrganization, SerializesModels;

    public function __construct(
        public User $customerUser,
        public string $rawCode,
        public string $rawToken,
        public ?string $organizationName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->organizationFromAddress($this->organizationName),
            subject: 'Verify your account & create a password',
        );
    }

    public function content(): Content
    {
        $verifyUrl = rtrim((string) config('app.frontend_url'), '/').'/customer-auth/verify?token='.urlencode($this->rawToken);

        return new Content(
            view: 'emails.customer.verification',
            with: [
                'customerName' => $this->customerUser->name,
                'verifyUrl' => $verifyUrl,
                'code' => $this->rawCode,
            ],
        );
    }
}
