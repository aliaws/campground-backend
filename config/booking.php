<?php

return [
    /*
    | How long an invoice must have gone unpaid (counted from when the
    | booking was created) before staff may void it by hand from the
    | Bookings page. This is the minimum for every void, manual or automatic.
    */
    'void_invoice_after_hours' => (int) env('BOOKING_VOID_INVOICE_AFTER_HOURS', 24),

    /*
    | `php artisan bookings:void-unpaid-invoices` voids the invoice and
    | cancels the booking for every booking that has been unpaid for at
    | least this many days. Change it here via the .env value, or override
    | it for a single run with the command's --days option.
    */
    'auto_void_unpaid_after_days' => (int) env('BOOKING_AUTO_VOID_UNPAID_AFTER_DAYS', 7),

    /*
    | The same command also cancels an unpaid booking that still has no Lead
    | Connector booking and no invoice this many hours after it was created
    | (nobody created and sent its invoice, and it was never paid). Override
    | for a single run with the command's --no-invoice-hours option.
    */
    'auto_cancel_without_invoice_after_hours' => (int) env('BOOKING_AUTO_CANCEL_WITHOUT_INVOICE_AFTER_HOURS', 24),
];
