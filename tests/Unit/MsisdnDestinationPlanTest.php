<?php

namespace Tests\Unit;

use App\Models\Destinations;
use App\Services\ProvisionCompleteService as Svc;
use Tests\TestCase;

/**
 * voxragtm#259: after a mobile port-in the eSIM's number changes, and the
 * PBX must route the new number (once) and drop the temporary one.
 */
class MsisdnDestinationPlanTest extends TestCase
{
    private static function row(string $uuid, string $number, ?string $description, ?string $prefix = null): Destinations
    {
        $d = new Destinations();
        $d->setRawAttributes([
            'destination_uuid'        => $uuid,
            'destination_number'      => $number,
            'destination_prefix'      => $prefix,
            'destination_description' => $description,
        ]);

        return $d;
    }

    private static function plan(array $rows, string $digits): array
    {
        $p = Svc::planMsisdnDestination(collect($rows), $digits);

        return [
            $p['keep']?->destination_uuid,
            array_map(fn ($d) => $d->destination_uuid, $p['delete']),
        ];
    }

    public function test_same_number_in_every_stored_format(): void
    {
        foreach (['+447435845636', '447435845636', '07435845636', '7435845636'] as $stored) {
            $this->assertTrue(Svc::isSameNumber(self::row('x', $stored, null, '44'), '447435845636'), $stored);
        }
        $this->assertFalse(Svc::isSameNumber(self::row('x', '+447396054197', null), '447435845636'));
        $this->assertFalse(Svc::isSameNumber(self::row('x', '5636', null), '447435845636'));
        $this->assertFalse(Svc::isSameNumber(self::row('x', '', null), '447435845636'));
    }

    public function test_unchanged_number_keeps_its_row(): void
    {
        $this->assertSame(['m', []], self::plan([
            self::row('m', '+447396054197', Svc::MSISDN_DESTINATION_DESCRIPTION),
            self::row('did', '2033932915', 'Inbound +442033932915', '44'),
        ], '447396054197'));
    }

    public function test_port_moves_the_marker_row_to_the_new_number(): void
    {
        $this->assertSame(['m', []], self::plan([
            self::row('did', '2033932915', 'Inbound +442033932915', '44'),
            self::row('m', '+447396054197', Svc::MSISDN_DESTINATION_DESCRIPTION),
        ], '447435845636'));
    }

    public function test_hand_made_route_for_the_new_number_is_adopted_and_old_number_dropped(): void
    {
        // Ecohights: staff added "7435845636" after the port; the marker row still had the old number.
        $this->assertSame(['manual', ['m']], self::plan([
            self::row('manual', '7435845636', 'Post-port Inbound Route', '44'),
            self::row('did', '2033932915', 'Inbound +442033932915', '44'),
            self::row('m', '+447396054197', Svc::MSISDN_DESTINATION_DESCRIPTION),
        ], '447435845636'));
    }

    public function test_marker_on_the_number_wins_and_duplicates_go(): void
    {
        $this->assertSame(['m-new', ['manual', 'm-old']], self::plan([
            self::row('manual', '7435845636', 'Post-port Inbound Route', '44'),
            self::row('m-new', '+447435845636', Svc::MSISDN_DESTINATION_DESCRIPTION),
            self::row('m-old', '+447396054197', Svc::MSISDN_DESTINATION_DESCRIPTION),
        ], '447435845636'));
    }

    public function test_no_rows_means_create(): void
    {
        $this->assertSame([null, []], self::plan([
            self::row('did', '2033932915', 'Inbound +442033932915', '44'),
        ], '447435845636'));
    }
}
