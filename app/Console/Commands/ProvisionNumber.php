<?php

namespace App\Console\Commands;

use App\Services\TelnyxNumberService;
use Illuminate\Console\Command;

/**
 * Search and order UK numbers from Telnyx (voxragtm#19).
 *
 * Examples:
 *   # See what's available in London (020) without ordering:
 *   php artisan numbers:provision --area-code=20 --search-only
 *
 *   # Order one London number and attach it to a Telnyx connection:
 *   php artisan numbers:provision --area-code=20 --connection=1234567890
 *
 * Associating the ordered number with a tenant's dialplan/destinations is a
 * follow-up (voxragtm#23) and is intentionally not done here.
 */
class ProvisionNumber extends Command
{
    protected $signature = 'numbers:provision
        {--country=GB : ISO country code}
        {--type=local : local|national|toll_free|mobile}
        {--area-code= : national destination code, e.g. 20 for London, 161 for Manchester}
        {--quantity=1 : how many numbers to order}
        {--connection= : Telnyx connection_id to attach the number(s) to}
        {--messaging-profile= : Telnyx messaging_profile_id for SMS/WhatsApp}
        {--search-only : list matches and exit without ordering}
        {--dry-run : resolve matches and show the order that WOULD be placed}';

    protected $description = 'Search and order UK phone numbers from Telnyx.';

    public function handle(TelnyxNumberService $numbers): int
    {
        $quantity = max(1, (int) $this->option('quantity'));

        $matches = $numbers->searchAvailable([
            'country'   => (string) $this->option('country'),
            'type'      => (string) $this->option('type'),
            'area_code' => (string) ($this->option('area-code') ?: ''),
            'limit'     => max($quantity, 10),
        ]);

        if ($matches === []) {
            $this->error('No available numbers matched those filters.');

            return self::FAILURE;
        }

        $this->table(
            ['Number', 'Region', 'Locality', 'Upfront', 'Monthly'],
            array_map(fn (array $n) => [
                $n['phone_number'],
                $n['region'] ?? '—',
                $n['locality'] ?? '—',
                $n['upfront_cost'] ?? '—',
                $n['monthly_cost'] ?? '—',
            ], $matches),
        );

        if ($this->option('search-only')) {
            return self::SUCCESS;
        }

        $selected = array_slice(array_column($matches, 'phone_number'), 0, $quantity);

        if ($this->option('dry-run')) {
            $this->info('Dry run — would order: ' . implode(', ', $selected));

            return self::SUCCESS;
        }

        $this->info('Ordering: ' . implode(', ', $selected));

        $order = $numbers->createOrder(
            $selected,
            (string) $this->option('connection') ?: null,
            (string) $this->option('messaging-profile') ?: null,
        );

        $this->info("Order {$order['id']} created — status: {$order['status']}");
        $this->line('Number orders settle asynchronously; poll with the Telnyx dashboard or getOrder().');

        return self::SUCCESS;
    }
}
