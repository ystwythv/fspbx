<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Http\Controllers\ReceptionAgentController;
use App\Models\AiAgent;
use App\Models\Domain;
use App\Services\ProvisionNumberService;
use App\Services\Voxra\VoxraRoutingState as S;
use App\Services\Voxra\VoxraSuspendedAnnouncement;
use Tests\TestCase;

/**
 * Voxra Line £10 (voxragtm#162): the line_ai mode (#163), the AI-off
 * voicemail fallback for every non-Complete tenant (#164) and suspended
 * numbers (#173). Pure-logic tests in the ProvisionNumberServiceTest style —
 * no Postgres test database.
 */
class ProvisionLineAiRoutingTest extends TestCase
{
    private const MOBILE = '+447700900123';
    private const ANNOUNCEMENT = '/var/lib/freeswitch/recordings/voxra/number-unavailable-abc.wav';

    private function domain(): Domain
    {
        $domain = new Domain();
        $domain->setRawAttributes([
            'domain_uuid' => 'dom-uuid-1',
            'domain_name' => 'acme.voxra.uk',
        ]);

        return $domain;
    }

    private function agent(): AiAgent
    {
        $agent = new AiAgent();
        $agent->setRawAttributes(['agent_extension' => '9250']);

        return $agent;
    }

    private function route(string $mode, bool $agentEnabled, ?string $mobile, int $timeout = 25, bool $suspended = false): array
    {
        return (new ProvisionNumberService())->resolveDidRouting(
            $this->domain(), $this->agent(), $mode, $agentEnabled, $mobile, $timeout, $suspended, self::ANNOUNCEMENT,
        );
    }

    private function data(array $actions): array
    {
        return array_map(fn ($a) => $a['destination_app'] . ' ' . $a['destination_data'], $actions);
    }

    private function assertNeverReachesAgent(array $actions): void
    {
        foreach ($actions as $a) {
            $this->assertStringNotContainsString('9250 XML', $a['destination_data']);
        }
    }

    // ---- mode resolution ------------------------------------------------

    public function test_line_ai_wins_over_line_mode_and_loses_to_complete(): void
    {
        $this->assertTrue(ProvisionTenantController::resolveLineAi(true, false));
        $this->assertFalse(ProvisionTenantController::resolveLineAi(true, true));
        // voxraweb sends line_mode + line_ai together for old PBXs: line_ai wins
        $this->assertFalse(ProvisionTenantController::resolveLineMode(true, false, true));

        $this->assertSame(S::MODE_LINE_AI, S::modeFor(false, true, false));
        $this->assertSame(S::MODE_LINE_AI, S::modeFor(true, true, false));
        $this->assertSame(S::MODE_COMPLETE, S::modeFor(true, true, true));
        $this->assertSame(S::MODE_LINE, S::modeFor(true, false, false));
        $this->assertSame(S::MODE_PRO, S::modeFor(false, false, false));
    }

    public function test_line_ai_keeps_the_agent_line_v1_forces_it_off(): void
    {
        $lineAi = ProvisionTenantController::resolveLineAi(true, false);
        $lineMode = ProvisionTenantController::resolveLineMode(true, false, $lineAi);

        $this->assertTrue(ProvisionTenantController::resolveAgentEnabled(true, $lineMode));
        // the kill-switch still works on Line+AI
        $this->assertFalse(ProvisionTenantController::resolveAgentEnabled(false, $lineMode));
        // Line v1 unchanged
        $this->assertFalse(ProvisionTenantController::resolveAgentEnabled(true, true));
    }

    public function test_suspension_forces_the_agent_off(): void
    {
        $this->assertFalse(ProvisionTenantController::resolveAgentEnabled(true, false, true));
        $this->assertTrue(ProvisionTenantController::resolveAgentEnabled(true, false, false));
    }

    public function test_line_ai_always_rings_the_mobile_first_whatever_ring_mobile_first_says(): void
    {
        $this->assertSame(self::MOBILE, ProvisionTenantController::ringFirstMobileFor(S::MODE_LINE_AI, false, self::MOBILE));
        $this->assertSame(self::MOBILE, ProvisionTenantController::ringFirstMobileFor(S::MODE_LINE_AI, true, self::MOBILE));
        $this->assertSame(self::MOBILE, ProvisionTenantController::ringFirstMobileFor(S::MODE_PRO, true, self::MOBILE));
        $this->assertNull(ProvisionTenantController::ringFirstMobileFor(S::MODE_PRO, false, self::MOBILE));
        $this->assertNull(ProvisionTenantController::ringFirstMobileFor(S::MODE_LINE, true, self::MOBILE));
        $this->assertNull(ProvisionTenantController::ringFirstMobileFor(S::MODE_LINE_AI, true, null));
    }

    // ---- ring-first timeout ----------------------------------------------

    public function test_ring_first_timeout_is_a_parameter(): void
    {
        $svc = new ProvisionNumberService();

        $default = $this->data($svc->ringFirstActions($this->domain(), $this->agent(), self::MOBILE));
        $this->assertContains('set call_timeout=20', $default);

        $line = $this->data($svc->ringFirstActions($this->domain(), $this->agent(), self::MOBILE, 25));
        $this->assertContains('set call_timeout=25', $line);
        $this->assertNotContains('set call_timeout=20', $line);
    }

    public function test_ring_first_timeout_is_clamped_to_10_60(): void
    {
        $this->assertSame(10, ProvisionNumberService::clampRingFirstTimeout(3));
        $this->assertSame(60, ProvisionNumberService::clampRingFirstTimeout(600));
        $this->assertSame(25, ProvisionNumberService::clampRingFirstTimeout(25));
    }

    public function test_mode_default_timeouts(): void
    {
        $this->assertSame(25, ProvisionNumberService::defaultRingFirstTimeout(S::MODE_LINE_AI));
        $this->assertSame(25, ProvisionNumberService::defaultRingFirstTimeout(S::MODE_LINE));
        $this->assertSame(20, ProvisionNumberService::defaultRingFirstTimeout(S::MODE_PRO));
    }

    // ---- the decision table ----------------------------------------------

    public function test_line_ai_with_agent_and_mobile_rings_the_mobile_then_the_ai(): void
    {
        $r = $this->route(S::MODE_LINE_AI, true, self::MOBILE, 25);

        $this->assertSame('ring_first_ai', $r['kind']);
        $data = $this->data($r['actions']);
        $this->assertContains('set call_timeout=25', $data);
        $this->assertContains('set continue_on_fail=true', $data);
        $this->assertStringContainsString('loopback/' . self::MOBILE . '/acme.voxra.uk', $data[3]);
        $this->assertStringContainsString('group_confirm_key=exec', $data[3]);
        $this->assertSame('transfer 9250 XML acme.voxra.uk', end($data));
    }

    public function test_line_ai_without_a_mobile_goes_straight_to_the_ai(): void
    {
        $r = $this->route(S::MODE_LINE_AI, true, null);

        $this->assertSame('ai', $r['kind']);
        $this->assertSame(['transfer 9250 XML acme.voxra.uk'], $this->data($r['actions']));
    }

    public function test_line_ai_with_agent_off_falls_back_to_the_line_v1_path(): void
    {
        $r = $this->route(S::MODE_LINE_AI, false, self::MOBILE);

        $this->assertSame('line_voicemail', $r['kind']);
        $this->assertSame(['transfer 9260 XML acme.voxra.uk'], $this->data($r['actions']));
    }

    public function test_pro_with_agent_off_goes_to_voicemail_never_the_disabled_agent(): void
    {
        // voxragtm#164: the bug was DID → 9250 with 9250's dialplan disabled
        $r = $this->route(S::MODE_PRO, false, null);

        $this->assertSame('voicemail', $r['kind']);
        $this->assertSame(['transfer *999260 XML acme.voxra.uk'], $this->data($r['actions']));
        $this->assertNeverReachesAgent($r['actions']);
    }

    public function test_pro_ring_first_with_agent_off_rings_the_mobile_then_voicemail(): void
    {
        $r = $this->route(S::MODE_PRO, false, self::MOBILE, 20);

        $this->assertSame('ring_first_voicemail', $r['kind']);
        $data = $this->data($r['actions']);
        $this->assertContains('set call_timeout=20', $data);
        $this->assertStringContainsString('loopback/' . self::MOBILE . '/acme.voxra.uk', $data[3]);
        $this->assertSame('transfer *999260 XML acme.voxra.uk', end($data));
        $this->assertNeverReachesAgent($r['actions']);
    }

    public function test_pro_agent_on_is_unchanged(): void
    {
        $this->assertSame(['transfer 9250 XML acme.voxra.uk'], $this->data($this->route(S::MODE_PRO, true, null)['actions']));

        $ringFirst = $this->route(S::MODE_PRO, true, self::MOBILE, 20);
        $this->assertSame('ring_first_ai', $ringFirst['kind']);
        $this->assertSame(
            (new ProvisionNumberService())->ringFirstActions($this->domain(), $this->agent(), self::MOBILE),
            $ringFirst['actions'],
        );
    }

    public function test_agent_re_enabled_restores_ai_routing(): void
    {
        $off = $this->route(S::MODE_PRO, false, self::MOBILE, 20);
        $on = $this->route(S::MODE_PRO, true, self::MOBILE, 20);

        $this->assertSame('transfer *999260 XML acme.voxra.uk', implode('', array_slice($this->data($off['actions']), -1)));
        $this->assertSame('transfer 9250 XML acme.voxra.uk', implode('', array_slice($this->data($on['actions']), -1)));
    }

    public function test_suspended_plays_the_announcement_and_hangs_up_in_every_mode(): void
    {
        foreach ([S::MODE_LINE, S::MODE_LINE_AI, S::MODE_PRO] as $mode) {
            foreach ([true, false] as $agentEnabled) {
                $r = $this->route($mode, $agentEnabled, self::MOBILE, 25, true);

                $this->assertSame('suspended', $r['kind'], "$mode agent=" . ($agentEnabled ? 'on' : 'off'));
                $this->assertSame([
                    'set ' . ProvisionNumberService::SUSPENDED_MARKER,
                    'answer ',
                    'sleep 500',
                    'playback ' . self::ANNOUNCEMENT,
                    'hangup NORMAL_CLEARING',
                ], $this->data($r['actions']));
                // no mobile leg, no AI, no voicemail
                $json = json_encode($r['actions'], JSON_UNESCAPED_SLASHES);
                $this->assertStringNotContainsString('loopback', $json);
                $this->assertStringNotContainsString('9250', $json);
                $this->assertStringNotContainsString('9260', $json);
            }
        }
    }

    public function test_suspended_without_a_tts_file_plays_sit_tones(): void
    {
        $actions = (new ProvisionNumberService())->suspendedActions(null);

        $this->assertSame('playback', $actions[3]['destination_app']);
        $this->assertSame(VoxraSuspendedAnnouncement::FALLBACK_TONE, $actions[3]['destination_data']);
        $this->assertStringStartsWith('tone_stream://', VoxraSuspendedAnnouncement::FALLBACK_TONE);
    }

    public function test_unsuspending_restores_the_modes_routing(): void
    {
        $this->assertSame('ring_first_ai', $this->route(S::MODE_LINE_AI, true, self::MOBILE, 25, false)['kind']);
        $this->assertSame('voicemail', $this->route(S::MODE_PRO, false, null, 20, false)['kind']);
    }

    public function test_announcement_path_is_stable_per_text_and_voice(): void
    {
        $a = VoxraSuspendedAnnouncement::relativePath('This number is temporarily unavailable.', 'v1');
        $this->assertSame($a, VoxraSuspendedAnnouncement::relativePath('This number is temporarily unavailable.', 'v1'));
        $this->assertNotSame($a, VoxraSuspendedAnnouncement::relativePath('Other text.', 'v1'));
        $this->assertNotSame($a, VoxraSuspendedAnnouncement::relativePath('This number is temporarily unavailable.', 'v2'));
        $this->assertStringStartsWith('voxra/number-unavailable-', $a);
    }

    // ---- reading the current DID -----------------------------------------

    public function test_current_ring_first_mobile_and_suspension_are_read_back_from_the_stored_json(): void
    {
        $svc = new ProvisionNumberService();
        // stored exactly as the destination row holds it (json_encode escapes '/')
        $ringFirst = json_encode($svc->ringFirstActions($this->domain(), $this->agent(), self::MOBILE));
        $suspended = json_encode($svc->suspendedActions(self::ANNOUNCEMENT));

        $this->assertSame(self::MOBILE, ProvisionNumberService::ringFirstMobileIn($ringFirst));
        $this->assertNull(ProvisionNumberService::ringFirstMobileIn(json_encode($svc->lineActions($this->domain()))));
        $this->assertNull(ProvisionNumberService::ringFirstMobileIn(null));

        $this->assertTrue(ProvisionNumberService::isSuspendedRouting($suspended));
        $this->assertFalse(ProvisionNumberService::isSuspendedRouting($ringFirst));
    }

    // ---- request → routing inputs ----------------------------------------

    public function test_explicit_request_values_win(): void
    {
        $r = ProvisionTenantController::resolveRoutingInputs(
            ['owner_mobile' => '07700 900999', 'ring_mobile_first' => false, 'ring_first_timeout' => 30, 'service_suspended' => true],
            S::MODE_PRO,
            ['mode' => S::MODE_PRO, 'owner_mobile' => self::MOBILE, 'ring_first' => true, 'ring_first_timeout' => 20, 'service_suspended' => false],
            null,
            null,
        );

        $this->assertSame(['owner_mobile' => '07700 900999', 'ring_first' => false, 'timeout' => 30, 'suspended' => true], $r);
    }

    public function test_omitted_fields_keep_the_stored_state(): void
    {
        // e.g. voxraweb's answering sync: no routing fields at all
        $r = ProvisionTenantController::resolveRoutingInputs(
            [],
            S::MODE_LINE_AI,
            ['mode' => S::MODE_LINE_AI, 'owner_mobile' => self::MOBILE, 'ring_first' => false, 'ring_first_timeout' => 30, 'service_suspended' => true],
            null,
            null,
        );

        $this->assertSame(['owner_mobile' => self::MOBILE, 'ring_first' => false, 'timeout' => 30, 'suspended' => true], $r);
    }

    public function test_without_stored_state_the_current_did_decides(): void
    {
        $svc = new ProvisionNumberService();
        $ringFirst = json_encode($svc->ringFirstActions($this->domain(), $this->agent(), self::MOBILE));

        $r = ProvisionTenantController::resolveRoutingInputs([], S::MODE_PRO, [], $ringFirst, null);
        $this->assertSame(self::MOBILE, $r['owner_mobile']);
        $this->assertTrue($r['ring_first']);
        $this->assertFalse($r['suspended']);
        $this->assertSame(20, $r['timeout']);

        $r = ProvisionTenantController::resolveRoutingInputs([], S::MODE_PRO, [], json_encode($svc->suspendedActions(null)), null);
        $this->assertTrue($r['suspended']);
        $this->assertFalse($r['ring_first']);

        // Line modes also know the 9260 follow-me mobile
        $r = ProvisionTenantController::resolveRoutingInputs([], S::MODE_LINE_AI, [], json_encode($svc->lineActions($this->domain())), self::MOBILE);
        $this->assertSame(self::MOBILE, $r['owner_mobile']);
        $r = ProvisionTenantController::resolveRoutingInputs([], S::MODE_PRO, [], null, self::MOBILE);
        $this->assertNull($r['owner_mobile']);
    }

    public function test_ring_mobile_first_on_a_line_call_does_not_become_the_pro_preference(): void
    {
        // voxraweb sends ring_mobile_first:true with line_ai for older PBXs
        $line = ProvisionTenantController::resolveRoutingInputs(
            ['owner_mobile' => self::MOBILE, 'ring_mobile_first' => true], S::MODE_LINE_AI, [], null, null,
        );
        $this->assertFalse($line['ring_first']);

        // so an upgrade to Pro that doesn't say otherwise is AI-first
        $pro = ProvisionTenantController::resolveRoutingInputs(
            [], S::MODE_PRO, ['mode' => S::MODE_LINE_AI, 'ring_first' => $line['ring_first'], 'owner_mobile' => self::MOBILE], null, null,
        );
        $this->assertFalse($pro['ring_first']);
        $this->assertNull(ProvisionTenantController::ringFirstMobileFor(S::MODE_PRO, $pro['ring_first'], $pro['owner_mobile']));
    }

    public function test_an_explicit_null_mobile_clears_it(): void
    {
        $r = ProvisionTenantController::resolveRoutingInputs(
            ['owner_mobile' => null],
            S::MODE_LINE_AI,
            ['mode' => S::MODE_LINE_AI, 'owner_mobile' => self::MOBILE],
            null,
            self::MOBILE,
        );

        $this->assertNull($r['owner_mobile']);
    }

    public function test_timeout_resets_to_the_new_modes_default_on_a_plan_switch(): void
    {
        $pro = ['mode' => S::MODE_PRO, 'ring_first_timeout' => 20];
        $this->assertSame(25, ProvisionTenantController::resolveRoutingInputs([], S::MODE_LINE_AI, $pro, null, null)['timeout']);
        $this->assertSame(20, ProvisionTenantController::resolveRoutingInputs([], S::MODE_PRO, $pro, null, null)['timeout']);
        $this->assertSame(25, ProvisionTenantController::resolveRoutingInputs([], S::MODE_LINE_AI, [], null, null)['timeout']);
        $this->assertSame(60, ProvisionTenantController::resolveRoutingInputs(['ring_first_timeout' => 99], S::MODE_PRO, [], null, null)['timeout']);
    }

    /**
     * Line → Line+AI → Pro → Line, the way successive provision calls see it:
     * each call's state is what the previous one stored. Every switch
     * rewrites the DID from the table, never patches the old actions.
     */
    public function test_switching_line_to_line_ai_to_pro_and_back_rewrites_cleanly(): void
    {
        $svc = new ProvisionNumberService();
        $state = [];
        $actions = null;

        $provision = function (string $mode, array $input, bool $agentEnabled) use (&$state, &$actions, $svc) {
            $in = ProvisionTenantController::resolveRoutingInputs($input, $mode, $state, $actions, null);
            $mobile = ProvisionTenantController::ringFirstMobileFor($mode, $in['ring_first'], $in['owner_mobile']);
            $lineMode = $mode === S::MODE_LINE;
            $r = $svc->resolveDidRouting(
                $this->domain(), $this->agent(), $mode,
                ProvisionTenantController::resolveAgentEnabled($agentEnabled, $lineMode, $in['suspended']),
                $mobile, $in['timeout'], $in['suspended'], self::ANNOUNCEMENT,
            );
            $state = ['mode' => $mode, 'ring_first' => $in['ring_first'], 'owner_mobile' => $in['owner_mobile'],
                'ring_first_timeout' => $in['timeout'], 'service_suspended' => $in['suspended']];
            $actions = json_encode($r['actions']);

            return $r;
        };

        $r = $provision(S::MODE_LINE, ['owner_mobile' => self::MOBILE], true);
        $this->assertSame('line_voicemail', $r['kind']);

        $r = $provision(S::MODE_LINE_AI, ['owner_mobile' => self::MOBILE, 'ring_mobile_first' => true, 'ring_first_timeout' => 25], true);
        $this->assertSame('ring_first_ai', $r['kind']);
        $this->assertContains('set call_timeout=25', $this->data($r['actions']));

        // minutes run out: bare kill-switch re-provision (no routing fields)
        $r = $provision(S::MODE_LINE_AI, [], false);
        $this->assertSame('line_voicemail', $r['kind']);

        // minutes topped up: the mobile + 25 s come back from the stored state
        $r = $provision(S::MODE_LINE_AI, [], true);
        $this->assertSame('ring_first_ai', $r['kind']);
        $this->assertContains('set call_timeout=25', $this->data($r['actions']));

        // upgrade to Pro, AI first
        $r = $provision(S::MODE_PRO, ['owner_mobile' => self::MOBILE, 'ring_mobile_first' => false], true);
        $this->assertSame('ai', $r['kind']);

        // unpaid: suspended, then a bare re-provision must NOT un-suspend
        $r = $provision(S::MODE_PRO, ['service_suspended' => true], false);
        $this->assertSame('suspended', $r['kind']);
        $r = $provision(S::MODE_PRO, [], true);
        $this->assertSame('suspended', $r['kind']);
        $r = $provision(S::MODE_PRO, ['service_suspended' => false], true);
        $this->assertSame('ai', $r['kind']);

        // back down to Line
        $r = $provision(S::MODE_LINE, [], false);
        $this->assertSame('line_voicemail', $r['kind']);
    }

    // ---- 9250 inbound dialplan + *9 bind ----------------------------------

    private function renderInbound(?string $voicemailFallback): \SimpleXMLElement
    {
        $agent = new AiAgent();
        $agent->agent_name = 'Acme Reception';
        $agent->agent_extension = '9250';
        $agent->telnyx_assistant_id = 'assistant-test';
        $agent->domain_uuid = '5a7c8cc6-e327-41e8-ab6e-d4cc6f008c3e';
        $agent->ai_agent_uuid = '00000000-0000-0000-0000-000000000001';

        $xml = view('layouts.xml.telnyx-ai-agent-inbound-reception-template', [
            'agent' => $agent,
            'dialplan_uuid' => '00000000-0000-0000-0000-000000000002',
            'attach_domain' => null,
            'dialplan_continue' => 'false',
            'voicemail_fallback' => $voicemailFallback,
            'domain_name' => 'acme.voxra.uk',
        ])->render();

        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);

        return $doc;
    }

    private function inboundActions(\SimpleXMLElement $doc): array
    {
        $actions = [];
        foreach ($doc->condition->action as $a) {
            $actions[] = [(string) $a['application'], (string) $a['data']];
        }

        return $actions;
    }

    public function test_line_ai_inbound_falls_back_to_voicemail_after_failed_telnyx_bridges(): void
    {
        $actions = $this->inboundActions($this->renderInbound('9260'));

        $this->assertSame(['transfer', '*999260 XML acme.voxra.uk'], end($actions));
        $this->assertNotContains(['hangup', 'NO_ANSWER'], $actions);
        $bridges = array_keys(array_filter($actions, fn ($a) => $a[0] === 'bridge'));
        $this->assertCount(2, $bridges);
        $this->assertGreaterThan(max($bridges), array_key_last($actions));
    }

    public function test_other_tenants_still_end_unanswered_ai_calls_as_no_answer(): void
    {
        $actions = $this->inboundActions($this->renderInbound(null));

        $this->assertSame(['hangup', 'NO_ANSWER'], end($actions));
        foreach ($actions as $a) {
            $this->assertStringNotContainsString('*99', $a[1]);
        }
    }

    public function test_star9_bind_is_off_on_line_ai(): void
    {
        $this->assertSame('true', ReceptionAgentController::bindEnabled('true', false));
        $this->assertSame('false', ReceptionAgentController::bindEnabled('true', true));
        $this->assertSame('false', ReceptionAgentController::bindEnabled('false', false));
    }
}
