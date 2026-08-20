<?php

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Address;

/**
 * Every outbound Mailable in this app uses this to build its Envelope's
 * `from` — the display NAME is the sending organization's own name (never
 * the framework's stock "Laravel" default, and never a single hardcoded
 * app-wide name once an organization is known), while the actual mailbox
 * ADDRESS stays the one configured system mailbox (MAIL_FROM_ADDRESS) —
 * there's no per-organization SMTP credential to send real mail *from* a
 * different address, only the display name customers see can vary.
 */
trait SendsFromOrganization
{
    protected function organizationFromAddress(?string $organizationName): Address
    {
        $name = $organizationName !== null && trim($organizationName) !== ''
            ? trim($organizationName)
            : config('mail.from.name');

        return new Address(config('mail.from.address'), $name);
    }
}
