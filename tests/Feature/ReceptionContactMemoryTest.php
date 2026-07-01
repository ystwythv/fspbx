<?php

namespace Tests\Feature;

use App\Models\ReceptionContact;
use App\Services\FreeswitchEslService;
use App\Services\ReceptionAgent\ReceptionAgentToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Per-customer contact memory: aggregate a caller's history across
 * conversations and let the agent recall / annotate it. (voxragtm#89)
 */
class ReceptionContactMemoryTest extends TestCase
{
    use RefreshDatabase;

    private string $domainUuid;
    private string $number = '+447700900321';

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainUuid = (string) Str::uuid();
    }

    private function service(): ReceptionAgentToolService
    {
        return new ReceptionAgentToolService(Mockery::mock(FreeswitchEslService::class));
    }

    private function session(string $convId): array
    {
        return ['domain_uuid' => $this->domainUuid, 'conversation_id' => $convId];
    }

    public function test_contact_aggregates_calls_and_recognises_returning_callers(): void
    {
        $svc = $this->service();

        $first = $svc->captureLead($this->session('conv-1'), [
            'name' => 'Sam',
            'caller_number' => $this->number,
            'job_description' => 'Dripping tap',
        ]);
        $this->assertFalse($first['returning_caller']);
        $this->assertSame(1, $first['times_called']);

        // A later call from the same number (new conversation) is recognised.
        $second = $svc->captureLead($this->session('conv-2'), [
            'caller_number' => $this->number,
            'job_description' => 'Now the boiler',
        ]);
        $this->assertTrue($second['returning_caller']);
        $this->assertSame(2, $second['times_called']);

        $this->assertDatabaseHas('v_reception_contacts', [
            'domain_uuid' => $this->domainUuid,
            'phone_number' => $this->number,
            'name' => 'Sam',
            'total_calls' => 2,
        ]);
    }

    public function test_bookings_count_and_recall_and_remember(): void
    {
        $svc = $this->service();

        $svc->captureLead($this->session('conv-1'), [
            'name' => 'Jo',
            'caller_number' => $this->number,
            'job_description' => 'Service',
        ]);

        $svc->bookAppointment($this->session('conv-1'), [
            'starts_at' => '2026-07-05 10:00',
            'service' => 'Boiler service',
        ]);

        $this->assertDatabaseHas('v_reception_contacts', [
            'phone_number' => $this->number,
            'total_bookings' => 1,
        ]);

        // Save a note, then recall it.
        $remember = $svc->rememberAboutCaller($this->session('conv-1'), [
            'number' => $this->number,
            'note' => 'Prefers mornings',
        ]);
        $this->assertTrue($remember['ok']);

        $recall = $svc->recallCaller($this->session('conv-1'), ['number' => $this->number]);
        $this->assertTrue($recall['found']);
        $this->assertSame('Jo', $recall['name']);
        $this->assertSame(1, $recall['times_booked']);
        $this->assertStringContainsString('Prefers mornings', $recall['notes']);
    }

    public function test_recall_unknown_caller_is_graceful(): void
    {
        $recall = $this->service()->recallCaller($this->session('conv-x'), ['number' => '+447700900999']);
        $this->assertTrue($recall['ok']);
        $this->assertFalse($recall['found']);
        $this->assertSame(0, ReceptionContact::where('domain_uuid', $this->domainUuid)->count());
    }
}
