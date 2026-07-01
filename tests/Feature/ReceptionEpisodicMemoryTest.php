<?php

namespace Tests\Feature;

use App\Services\FreeswitchEslService;
use App\Services\ReceptionAgent\ReceptionAgentToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Episodic memory: record_summary + timeline in recall and the context brief (#91). */
class ReceptionEpisodicMemoryTest extends TestCase
{
    use RefreshDatabase;

    private string $domainUuid;
    private string $number = '+447700900654';

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainUuid = (string) Str::uuid();
    }

    private function service(): ReceptionAgentToolService
    {
        return new ReceptionAgentToolService(Mockery::mock(FreeswitchEslService::class));
    }

    public function test_summary_is_recorded_and_surfaced(): void
    {
        $svc = $this->service();
        $session = [
            'domain_uuid' => $this->domainUuid,
            'conversation_id' => 'c1',
            'caller_number' => $this->number,
        ];

        $svc->captureLead($session, ['name' => 'Ken', 'caller_number' => $this->number, 'job_description' => 'Leak']);

        $rec = $svc->recordSummary($session, ['summary' => 'Booked a boiler service for Friday', 'outcome' => 'booked']);
        $this->assertTrue($rec['ok']);
        $this->assertDatabaseHas('v_reception_interactions', [
            'domain_uuid' => $this->domainUuid,
            'summary' => 'Booked a boiler service for Friday',
            'outcome' => 'booked',
        ]);

        $recall = $svc->recallCaller($session, ['number' => $this->number]);
        $this->assertContains('Booked a boiler service for Friday', $recall['recent']);

        $brief = $svc->callerContextBrief($this->domainUuid, $this->number);
        $this->assertStringContainsString('Last time: Booked a boiler service for Friday', $brief);
    }
}
