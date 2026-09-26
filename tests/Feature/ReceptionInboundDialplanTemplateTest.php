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
 *
 * Later analysis (voxragtm#153): those no-answers were Telnyx's AI platform
 * stalling session starts (06:53-07:20 UTC, also hitting the QA caller's
 * own outbound dials), not the ring_ready change. So each Telnyx attempt is
 * bounded, retried once, and a caller Telnyx never answers is ended as
 * NO_ANSWER (a missed call) instead of ringing until they give up.
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

    public function test_bounds_each_telnyx_attempt_retries_once_then_ends_as_no_answer(): void
    {
        $xml = $this->render();
        $doc = simplexml_load_string($xml);
        $actions = [];
        foreach ($doc->condition->action as $a) {
            $actions[] = [(string) $a['application'], (string) $a['data']];
        }

        $telnyx = array_keys(array_filter($actions, fn ($a) => $a[0] === 'bridge'
            && str_contains($a[1], 'sip:agent@assistant-test.sip.telnyx.com')));
        $this->assertCount(2, $telnyx, 'one retry after a Telnyx no-answer');
        foreach ($telnyx as $i) {
            $this->assertStringContainsString('[leg_timeout=10]sofia/external/', $actions[$i][1]);
            $this->assertStringContainsString('sip_h_X-Voxra-Conversation-Id=${uuid}', $actions[$i][1]);
        }

        $this->assertContains(['set', 'continue_on_fail=true'], $actions);
        $this->assertContains(['set', 'hangup_after_bridge=true'], $actions);
        $last = end($actions);
        $this->assertSame(['hangup', 'NO_ANSWER'], $last);
        $this->assertGreaterThan(max($telnyx), array_key_last($actions));
    }
}
