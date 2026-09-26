<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use Tests\TestCase;

/**
 * The Voxra inbound reception dialplan (ext 9250) does not answer the caller
 * before the Telnyx assistant answers: the caller hears ringing (ring_ready)
 * until the assistant picks up, not dead air. #123 first shipped this; it was
 * rolled back (#126) when Telnyx stopped answering on 26 Sep, but that turned
 * out to be a Telnyx AI-platform stall (voxragtm#153), not this change. Each
 * Telnyx attempt is bounded and retried once (#127), and a caller Telnyx never
 * answers is ended as NO_ANSWER — still ringing, never silence.
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

    public function test_rings_until_telnyx_answers_no_answer_or_sleep_first(): void
    {
        $xml = $this->render();

        $this->assertStringNotContainsString('application="answer"', $xml);
        $this->assertStringNotContainsString('application="sleep"', $xml);
        $this->assertStringContainsString('application="ring_ready"', $xml);
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
