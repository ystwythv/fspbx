<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use Tests\TestCase;

/**
 * The Voxra inbound reception dialplan (ext 9250) answers the caller before
 * bridging to the Telnyx assistant. #123 tried leaving the caller unanswered
 * (ring_ready only) until Telnyx answered, to cut first-word latency — but in
 * production Telnyx then often never answered the SIP leg (QA 26 Sep 06:59:
 * 183 ringback for 39 s → ORIGINATOR_CANCEL on 2 of 4 calls), so the answer
 * before the bridge is load-bearing. Latency is handled elsewhere (short
 * greetings, fast dynamic variables).
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

    public function test_answers_the_caller_before_bridging_to_telnyx(): void
    {
        $xml = $this->render();

        $answer = strpos($xml, 'application="answer"');
        $bridge = strpos($xml, 'application="bridge"');
        $this->assertNotFalse($answer);
        $this->assertNotFalse($bridge);
        $this->assertLessThan($bridge, $answer);
        $this->assertStringContainsString('sofia/external/sip:agent@assistant-test.sip.telnyx.com', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }
}
