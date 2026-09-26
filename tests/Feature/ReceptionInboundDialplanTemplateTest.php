<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use Tests\TestCase;

/**
 * The Voxra inbound reception dialplan (ext 9250) must not answer the caller
 * before the Telnyx assistant answers: answering first (plus a 1 s sleep) put
 * a second of dead air and the whole Telnyx setup time between "call
 * answered" and the receptionist's first word. (first-word latency)
 */
class ReceptionInboundDialplanTemplateTest extends TestCase
{
    private function render(): string
    {
        $agent = new AiAgent();
        $agent->agent_name = 'Bloom Hair Reception';
        $agent->agent_extension = '9250';
        $agent->telnyx_assistant_id = 'assistant-test';
        $agent->domain_uuid = '5a7c8cc6-e327-41e8-ab6e-d4cc6f008c3e';
        $agent->ai_agent_uuid = '00000000-0000-0000-0000-000000000001';

        return view('layouts.xml.telnyx-ai-agent-inbound-reception-template', [
            'agent' => $agent,
            'dialplan_uuid' => '00000000-0000-0000-0000-000000000002',
            'attach_domain' => null,
            'dialplan_continue' => 'false',
        ])->render();
    }

    public function test_does_not_answer_or_sleep_before_the_bridge(): void
    {
        $xml = $this->render();

        $this->assertStringNotContainsString('application="answer"', $xml);
        $this->assertStringNotContainsString('application="sleep"', $xml);
        $this->assertStringContainsString('application="ring_ready"', $xml);
        $this->assertStringContainsString('sofia/external/sip:agent@assistant-test.sip.telnyx.com', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }
}
