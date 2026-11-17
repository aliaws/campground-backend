<?php

namespace App\Support;

/**
 * Where a booking or customer came from — stored in the `source` column of
 * engage_bookings and engage_customers.
 */
final class RecordSource
{
    public const POS = 'pos';

    public const WEBSITE = 'website';

    /** Created by an inbound Lead Connector webhook (e.g. AppointmentCreate, contact.created). */
    public const LEAD_CONNECTOR_WEBHOOK = 'lead_connector_webhook';

    /** Created by Pull Data, the 6-hourly sync or the 5-minute appointment sync. */
    public const LEAD_CONNECTOR_PULL = 'lead_connector_pull';

    /** Value used before webhook/pull were told apart; accepted as a filter meaning both. */
    public const LEGACY_LEAD_CONNECTOR = 'lead_connector';

    public const ALL = [self::POS, self::WEBSITE, self::LEAD_CONNECTOR_WEBHOOK, self::LEAD_CONNECTOR_PULL];

    /** Expands the legacy `lead_connector` filter value into both Lead Connector sources. */
    public static function expandFilter(array $values): array
    {
        $expanded = [];
        foreach ($values as $value) {
            if ($value === self::LEGACY_LEAD_CONNECTOR) {
                $expanded[] = self::LEAD_CONNECTOR_WEBHOOK;
                $expanded[] = self::LEAD_CONNECTOR_PULL;
            } else {
                $expanded[] = $value;
            }
        }

        return array_values(array_unique($expanded));
    }
}
