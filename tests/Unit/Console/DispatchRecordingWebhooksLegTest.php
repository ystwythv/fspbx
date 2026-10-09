<?php

namespace Tests\Unit\Console;

use App\Console\Commands\DispatchRecordingWebhooks;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Which CDR a shared recording file is announced under. The file is named
 * after the leg that recorded it; announcing it under a ring-group sibling
 * (LOSE_RACE, billsec 0) or a receptionist originate leg made the CRM file
 * the call twice, once as a 0-second recording.
 */
class DispatchRecordingWebhooksLegTest extends TestCase
{
    private const RECORDED = '18977bb9-b6fc-4f5f-abd3-d13516e7004f';

    private function leg(string $uuid, int $billsec, string $start = '2026-09-22 15:27:48'): object
    {
        return (object) ['xml_cdr_uuid' => $uuid, 'billsec' => $billsec, 'start_stamp' => $start];
    }

    private function now(string $at = '2026-09-22 15:41:00'): Carbon
    {
        return Carbon::parse($at);
    }

    public function test_recorded_leg_uuid_comes_from_the_file_name(): void
    {
        $this->assertSame(self::RECORDED, DispatchRecordingWebhooks::recordedLegUuid(self::RECORDED . '.wav'));
        $this->assertSame(self::RECORDED, DispatchRecordingWebhooks::recordedLegUuid('iqmobile.uk/2026/09/22/' . strtoupper(self::RECORDED) . '.mp3'));
        $this->assertNull(DispatchRecordingWebhooks::recordedLegUuid('owner-call-201.wav'));
        $this->assertNull(DispatchRecordingWebhooks::recordedLegUuid(null));
    }

    public function test_the_recorded_leg_wins_over_a_longer_receptionist_leg(): void
    {
        $legs = [$this->leg('8ebce380-7850-43d3-ba1f-7e0a7b878059', 765), $this->leg(self::RECORDED, 762)];

        $chosen = DispatchRecordingWebhooks::selectLeg(self::RECORDED . '.wav', $legs, null, $this->now(), 180);

        $this->assertSame(self::RECORDED, $chosen->xml_cdr_uuid);
    }

    public function test_waits_while_only_a_lose_race_sibling_has_ended(): void
    {
        $legs = [$this->leg('cd8da393-873a-421f-abb0-9b6e1662c03d', 0, '2026-08-20 09:35:56')];

        $chosen = DispatchRecordingWebhooks::selectLeg(
            '6c367fac-82b2-4947-82a7-cdd18e422d6b.wav', $legs, null, $this->now('2026-08-20 09:37:01'), 180
        );

        $this->assertNull($chosen);
    }

    public function test_uses_the_recorded_leg_found_outside_the_candidates(): void
    {
        $legs = [$this->leg('cd8da393-873a-421f-abb0-9b6e1662c03d', 0)];
        $recorded = $this->leg('6c367fac-82b2-4947-82a7-cdd18e422d6b', 423);

        $chosen = DispatchRecordingWebhooks::selectLeg(
            '6c367fac-82b2-4947-82a7-cdd18e422d6b.wav', $legs, $recorded, $this->now(), 180
        );

        $this->assertSame($recorded, $chosen);
    }

    public function test_falls_back_to_the_longest_leg_after_the_grace(): void
    {
        $legs = [$this->leg('aaaaaaaa-0000-0000-0000-000000000001', 0), $this->leg('aaaaaaaa-0000-0000-0000-000000000002', 30)];

        $chosen = DispatchRecordingWebhooks::selectLeg(
            '6c367fac-82b2-4947-82a7-cdd18e422d6b.wav', $legs, null, $this->now('2026-09-22 18:28:00'), 180
        );

        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000002', $chosen->xml_cdr_uuid);
    }

    public function test_files_not_named_after_a_leg_keep_the_longest_leg_rule(): void
    {
        $legs = [$this->leg('aaaaaaaa-0000-0000-0000-000000000001', 5), $this->leg('aaaaaaaa-0000-0000-0000-000000000002', 50)];

        $chosen = DispatchRecordingWebhooks::selectLeg('owner-call.wav', $legs, null, $this->now(), 180);

        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000002', $chosen->xml_cdr_uuid);
    }

    public function test_a_recorded_leg_with_its_own_recording_is_ignored(): void
    {
        $legs = [$this->leg('aaaaaaaa-0000-0000-0000-000000000001', 5), $this->leg('aaaaaaaa-0000-0000-0000-000000000002', 50)];

        $chosen = DispatchRecordingWebhooks::selectLeg(self::RECORDED . '.wav', $legs, null, $this->now(), 180, true);

        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000002', $chosen->xml_cdr_uuid);
    }
}
