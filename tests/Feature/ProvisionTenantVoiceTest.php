<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use Tests\TestCase;

/**
 * The voice picked in voxraweb Settings (voxraweb#110) reaches the reception
 * agent: provision-tenant's optional voice_id wins when it's a Telnyx Ultra
 * id; otherwise the agent keeps its current voice, and a new agent gets the
 * UK default. Before, every provision forced the default.
 */
class ProvisionTenantVoiceTest extends TestCase
{
    private const DEFAULT = 'Telnyx.Ultra.c8f7835e-28a3-4f0c-80d7-c1302ac62aae';
    private const LUCY = 'Telnyx.Ultra.2f251ac3-89a9-4a77-a452-704b474ccd01';
    private const OLIVER = 'Telnyx.Ultra.ee7ea9f8-c0c1-498c-9279-764d6b56d189';

    public function test_requested_voice_wins(): void
    {
        $this->assertSame(self::LUCY, ProvisionTenantController::resolveVoice(self::LUCY, self::OLIVER));
    }

    public function test_omitted_voice_keeps_the_current_one(): void
    {
        $this->assertSame(self::OLIVER, ProvisionTenantController::resolveVoice(null, self::OLIVER));
        $this->assertSame(self::OLIVER, ProvisionTenantController::resolveVoice('', self::OLIVER));
    }

    public function test_malformed_voice_is_ignored(): void
    {
        $this->assertSame(self::OLIVER, ProvisionTenantController::resolveVoice('ultra/lily', self::OLIVER));
        $this->assertSame(self::DEFAULT, ProvisionTenantController::resolveVoice('rm -rf', null));
    }

    public function test_new_agent_gets_the_uk_default(): void
    {
        $this->assertSame(self::DEFAULT, ProvisionTenantController::resolveVoice(null, null));
        $this->assertSame(self::DEFAULT, ProvisionTenantController::resolveVoice(null, 'some-elevenlabs-id'));
    }

    public function test_voice_reaches_the_agent_inputs(): void
    {
        $controller = app(ProvisionTenantController::class);
        $this->assertSame(self::LUCY, $controller->receptionAgentInputs('Acme', true, self::LUCY)['telnyx_voice_id']);
        $this->assertSame(self::DEFAULT, $controller->receptionAgentInputs('Acme', true)['telnyx_voice_id']);
    }
}
