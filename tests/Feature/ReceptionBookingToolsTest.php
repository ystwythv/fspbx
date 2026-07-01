<?php

namespace Tests\Feature;

use App\Models\ReceptionLead;
use App\Services\FreeswitchEslService;
use App\Services\ReceptionAgent\ReceptionAgentToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Covers the reception agent's qualify (capture_lead) and booking
 * (check_availability / book_appointment) tools. (voxragtm#28, #29)
 */
class ReceptionBookingToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $domainUuid;
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainUuid = (string) Str::uuid();
        $this->session = ['domain_uuid' => $this->domainUuid, 'conversation_id' => 'conv-1'];
    }

    private function service(): ReceptionAgentToolService
    {
        // These tools don't touch FreeSWITCH, so a bare mock is enough.
        return new ReceptionAgentToolService(Mockery::mock(FreeswitchEslService::class));
    }

    public function test_capture_lead_persists_and_recognises_repeat_callers(): void
    {
        $svc = $this->service();

        $result = $svc->captureLead($this->session, [
            'name' => 'Dave',
            'caller_number' => '+447700900123',
            'postcode' => 'sw11 2ab',
            'job_description' => 'Burst pipe under the sink',
            'urgency' => 'emergency',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['returning_caller']);
        $this->assertDatabaseHas('v_reception_leads', [
            'domain_uuid' => $this->domainUuid,
            'name' => 'Dave',
            'postcode' => 'SW11 2AB',
            'urgency' => 'emergency',
            'status' => 'qualified',
        ]);

        // A later call from the same number (different conversation) is recognised.
        $second = $svc->captureLead(
            ['domain_uuid' => $this->domainUuid, 'conversation_id' => 'conv-2'],
            ['caller_number' => '+447700900123', 'job_description' => 'Same tap again'],
        );

        $this->assertTrue($second['returning_caller']);
        $this->assertSame('Burst pipe under the sink', $second['previous_job']);
    }

    public function test_book_appointment_creates_booking_links_lead_and_blocks_clashes(): void
    {
        $svc = $this->service();

        $svc->captureLead($this->session, [
            'name' => 'Mrs Patel',
            'caller_number' => '+447700900555',
            'job_description' => 'Boiler service',
        ]);

        $booked = $svc->bookAppointment($this->session, [
            'starts_at' => '2026-07-03 09:00',
            'service' => 'Boiler service',
            'duration_minutes' => 60,
        ]);

        $this->assertTrue($booked['ok']);
        $this->assertNotEmpty($booked['appointment_ref']);
        $this->assertDatabaseHas('v_reception_appointments', [
            'domain_uuid' => $this->domainUuid,
            'service' => 'Boiler service',
            'customer_name' => 'Mrs Patel',
            'status' => 'booked',
        ]);
        // The lead is advanced to booked.
        $this->assertSame('booked', ReceptionLead::where('conversation_id', 'conv-1')->first()->status);

        // Availability excludes the taken 9am slot.
        $avail = $svc->checkAvailability($this->session, ['date' => '2026-07-03']);
        $this->assertTrue($avail['ok']);
        $this->assertNotContains('9:00 AM', $avail['slots']);

        // An overlapping booking is rejected.
        $clash = $svc->bookAppointment($this->session, [
            'starts_at' => '2026-07-03 09:30',
            'service' => 'Second job',
        ]);
        $this->assertFalse($clash['ok']);
    }
}
