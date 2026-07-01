<?php

namespace Tests\Feature;

use App\Services\ReceptionAgent\ReceptionAgentSummonService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ReceptionAgentSummonService::ensureInboundSession — the session bootstrap for
 * a direct inbound (FMC no-answer) call so the qualify/book tools resolve the
 * tenant without a *9 summon. (voxragtm#23)
 */
class ReceptionInboundSessionTest extends TestCase
{
    public function test_creates_a_minimal_session_when_none_exists(): void
    {
        Redis::shouldReceive('get')->once()->andReturn(null);
        Redis::shouldReceive('setex')->once();

        $session = ReceptionAgentSummonService::ensureInboundSession(
            'domain-uuid-1',
            'conv-1',
            '+447700900123',
        );

        $this->assertSame('domain-uuid-1', $session['domain_uuid']);
        $this->assertSame('conv-1', $session['conversation_id']);
        $this->assertSame('+447700900123', $session['caller_number']);
        $this->assertSame('inbound_reception', $session['source']);
    }

    public function test_is_idempotent_and_preserves_an_existing_session(): void
    {
        $existing = json_encode([
            'domain_uuid' => 'domain-uuid-1',
            'conversation_id' => 'conv-1',
            'source' => 'inbound_reception',
        ]);
        Redis::shouldReceive('get')->once()->andReturn($existing);
        Redis::shouldReceive('setex')->never();

        $session = ReceptionAgentSummonService::ensureInboundSession(
            'domain-uuid-DIFFERENT',
            'conv-1',
            null,
        );

        // Existing session is returned untouched, not overwritten.
        $this->assertSame('domain-uuid-1', $session['domain_uuid']);
    }

    public function test_requires_domain_and_conversation(): void
    {
        $this->expectException(\RuntimeException::class);
        ReceptionAgentSummonService::ensureInboundSession('', 'conv-1', null);
    }
}
