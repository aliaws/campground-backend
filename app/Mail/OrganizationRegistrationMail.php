<?php

namespace App\Mail;

use App\Models\EngageOrganizationLocation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to business_email after Complete Registration is submitted, mirrors CustomerRegistrationMail's shape. */
class OrganizationRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public EngageOrganizationLocation $organization,
        public User $ownerUser,
        public string $rawCode,
        public string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Activate your organization',
        );
    }

    public function content(): Content
    {
        $verifyUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/register-application/verify?token='.urlencode($this->rawToken);

        return new Content(
            view: 'emails.organization.registration',
            with: [
                'ownerName' => $this->ownerUser->name,
                'organizationName' => $this->organization->name ?: 'your organization',
                'verifyUrl' => $verifyUrl,
                'code' => $this->rawCode,
            ],
        );
    }
}
