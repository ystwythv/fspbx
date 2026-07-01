<?php

namespace Tests\Feature;

use App\Models\ReceptionTeamMember;
use App\Services\FreeswitchEslService;
use App\Services\ReceptionAgent\ReceptionAgentToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Business memory (#90), team identity/provenance (#92) and the retrieval brief
 * (#93).
 */
class ReceptionBusinessMemoryTest extends TestCase
{
    use RefreshDatabase;

    private string $domainUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainUuid = (string) Str::uuid();
    }

    private function service(): ReceptionAgentToolService
    {
        return new ReceptionAgentToolService(Mockery::mock(FreeswitchEslService::class));
    }

    public function test_owner_can_remember_a_fact_and_recall_it(): void
    {
        ReceptionTeamMember::create([
            'domain_uuid' => $this->domainUuid,
            'phone_number' => '+447700900001',
            'name' => 'Dave',
            'role' => 'owner',
        ]);
        $session = ['domain_uuid' => $this->domainUuid, 'caller_number' => '+447700900001'];

        $r = $this->service()->remember($session, ['fact' => 'We now charge £70 call-out', 'category' => 'pricing']);
        $this->assertTrue($r['ok']);
        $this->assertSame('active', $r['status']);

        $recall = $this->service()->recallBusiness($session, []);
        $this->assertSame(1, $recall['count']);
        $this->assertSame('We now charge £70 call-out', $recall['facts'][0]['fact']);
    }

    public function test_sensitive_fact_from_non_owner_is_pending(): void
    {
        // Unknown speaker (not a team member) -> not owner.
        $session = ['domain_uuid' => $this->domainUuid, 'caller_number' => '+447700900777'];

        $r = $this->service()->remember($session, ['fact' => 'Drop prices 20%', 'category' => 'pricing']);
        $this->assertTrue($r['ok']);
        $this->assertSame('pending', $r['status']);

        // Pending facts are not returned by recall (only active).
        $this->assertSame(0, $this->service()->recallBusiness($session, [])['count']);
        $this->assertDatabaseHas('v_reception_memory', ['status' => 'pending', 'category' => 'pricing']);
    }

    public function test_context_brief_combines_caller_history_and_business_facts(): void
    {
        $svc = $this->service();
        $session = ['domain_uuid' => $this->domainUuid, 'conversation_id' => 'c1'];

        $svc->captureLead($session, ['name' => 'Mrs Patel', 'caller_number' => '+447700900123', 'job_description' => 'Boiler']);
        // General facts are active regardless of speaker, so they appear in the brief.
        $svc->remember(
            ['domain_uuid' => $this->domainUuid, 'caller_number' => '+447700900001'],
            ['fact' => 'Emergencies only after 6pm'],
        );

        $brief = $svc->callerContextBrief($this->domainUuid, '+447700900123');
        $this->assertStringContainsString('Mrs Patel', $brief);
        $this->assertStringContainsString('Emergencies only after 6pm', $brief);
    }
}
