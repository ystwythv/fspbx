<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Models\AiAgent;
use App\Services\ReceptionAgent\ReceptionAgentToolDefinitions;
use App\Services\TelnyxConvaiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Receptionist conversation behaviour found by the QA harness (26 Sep):
 *  - nutty.price: a question overlapping the tail of the un-interruptible
 *    greeting was dropped and the agent sat silent — re-ask after a short
 *    silence (user_idle_reply_secs) and tell the prompt why;
 *  - "(End of call)" / "The call has ended" spoken aloud — no stage directions;
 *  - a price caller greeted as "Priya", a name remembered from an earlier call
 *    from the same (shared) number — confirm before using a remembered name.
 */
class VoxraReceptionConversationTest extends TestCase
{
    private const ASSISTANT = 'assistant-nutty';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telnyx.api_key' => 'test-key',
            'services.telnyx.base_url' => 'https://api.telnyx.test',
            'app.url' => 'https://lon1.voxra.test',
            'services.voxra.app_url' => 'https://voxra.test',
            'services.voxra.agent_tool_secret' => 'tool-secret',
        ]);
    }

    /** @return array<string,mixed> the body POSTed by applyVoxraCallPolicy */
    private function policyBody(array $current, ?bool $recording = null): array
    {
        $sent = null;
        Http::fake(function (Request $r) use ($current, &$sent) {
            if ($r->method() === 'GET') {
                return Http::response($current);
            }
            $sent = $r->data();

            return Http::response(['id' => self::ASSISTANT]);
        });
        app(TelnyxConvaiService::class)->applyVoxraCallPolicy(self::ASSISTANT, $recording);
        $this->assertNotNull($sent);

        return $sent;
    }

    public function test_call_policy_sets_a_quick_silence_check_in_and_a_dead_line_backstop(): void
    {
        $b = $this->policyBody([
            'telephony_settings' => [
                'default_texml_app_id' => '123',
                'time_limit_secs' => 1800,
                'user_idle_reply_secs' => 10,
                'user_idle_timeout_secs' => null,
                'recording_settings' => ['enabled' => true, 'channels' => 'dual', 'format' => 'mp3'],
            ],
            'interruption_settings' => ['enable' => true, 'disable_greeting_interruption' => true],
        ]);

        $t = $b['telephony_settings'];
        $this->assertSame(TelnyxConvaiService::USER_IDLE_REPLY_SECS, $t['user_idle_reply_secs']);
        $this->assertGreaterThanOrEqual(4, $t['user_idle_reply_secs']);
        $this->assertLessThanOrEqual(5, $t['user_idle_reply_secs']);
        // Telnyx minimum is 10; well past two check-ins.
        $this->assertSame(TelnyxConvaiService::USER_IDLE_TIMEOUT_SECS, $t['user_idle_timeout_secs']);
        $this->assertGreaterThan(3 * $t['user_idle_reply_secs'], $t['user_idle_timeout_secs']);
        // Merged, not clobbered: other telephony settings and recording kept.
        $this->assertSame('123', $t['default_texml_app_id']);
        $this->assertSame(1800, $t['time_limit_secs']);
        $this->assertTrue($t['recording_settings']['enabled']);
        // The disclosure stays un-interruptible (voxragtm#83).
        $this->assertTrue($b['interruption_settings']['disable_greeting_interruption']);
    }

    public function test_call_policy_sends_idle_settings_even_with_no_current_telephony_settings(): void
    {
        $b = $this->policyBody([]);

        $this->assertSame(TelnyxConvaiService::USER_IDLE_REPLY_SECS, $b['telephony_settings']['user_idle_reply_secs']);
        $this->assertSame(TelnyxConvaiService::USER_IDLE_TIMEOUT_SECS, $b['telephony_settings']['user_idle_timeout_secs']);
        $this->assertArrayNotHasKey('recording_settings', $b['telephony_settings']);
    }

    public function test_prompt_re_asks_after_silence_and_closes_politely(): void
    {
        $p = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;

        $this->assertStringContainsString('## If the caller goes quiet', $p);
        $this->assertStringContainsString("Sorry, I didn't catch that — how can I help?", $p);
        $this->assertStringContainsString('After two check-ins', $p);
    }

    public function test_prompt_forbids_stage_directions_and_hangup_is_silent(): void
    {
        $p = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;

        $this->assertStringContainsString('## Speaking and ending the call', $p);
        $this->assertStringContainsString('Never narrate actions', $p);
        $this->assertStringContainsString('(End of call)', $p);
        $this->assertStringContainsString('The call has ended', $p);

        $agent = new AiAgent();
        $agent->telnyx_assistant_id = self::ASSISTANT;
        $agent->tools_enabled = [];
        $sent = null;
        Http::fake(function (Request $r) use (&$sent) {
            $sent = $r->data();

            return Http::response(['id' => self::ASSISTANT]);
        });
        app(TelnyxConvaiService::class)->syncReceptionAgentTools($agent);
        $hangup = collect($sent['tools'])->firstWhere('type', 'hangup');
        $this->assertStringContainsString('never announce that the call is ending', $hangup['hangup']['description']);
    }

    public function test_remembered_name_is_confirmed_before_use(): void
    {
        $p = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;

        $this->assertStringContainsString('## Returning callers and shared phones', $p);
        $this->assertStringContainsString('Am I speaking with <name>?', $p);
        $this->assertStringContainsString('confirmed_name', $p);

        $def = collect(ReceptionAgentToolDefinitions::list([]))->firstWhere('name', 'recall_caller');
        $this->assertArrayHasKey('confirmed_name', $def['properties']);
        $this->assertSame([], $def['required']);
        $this->assertStringContainsString('shared phone', $def['description']);
        $this->assertTrue(ReceptionAgentToolDefinitions::isDataTool('recall_caller'));
    }

    public function test_grounding_forbids_filling_gaps_and_offers_to_check(): void
    {
        $p = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;

        $this->assertStringContainsString('never fill a gap', $p);
        $this->assertStringContainsString("I'll check that with the team and let you know", $p);
    }

    public function test_existing_prompt_sections_are_kept_in_order(): void
    {
        $p = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;

        $abuse = strpos($p, '## Abusive callers and spam (voxragtm#84)');
        $urgent = strpos($p, '## Urgent calls and transfers (voxragtm#122)');
        $quiet = strpos($p, '## If the caller goes quiet');
        $disclosure = strpos($p, '## AI disclosure and call recording (voxragtm#83)');
        $this->assertNotFalse($abuse);
        $this->assertNotFalse($urgent);
        $this->assertLessThan($urgent, $abuse);
        $this->assertLessThan($quiet, $urgent);
        $this->assertLessThan($disclosure, $quiet);
        $this->assertStringEndsWith('{{recording_notice}}', trim($p));
    }
}
