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
 * voxragtm#122 (QA bloom.emergency): an urgent caller must have their name +
 * problem taken and the owner alerted BEFORE any transfer. The owner transfer
 * dials {{owner_transfer_to}}, which only alert_owner's webhook response fills
 * (store_fields_as_variables) — voxraweb only returns it after escalating.
 */
class VoxraAlertOwnerTest extends TestCase
{
    private const ASSISTANT = 'assistant-bloom';

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

    private function agent(array $toolsEnabled = []): AiAgent
    {
        $agent = new AiAgent();
        $agent->telnyx_assistant_id = self::ASSISTANT;
        $agent->tools_enabled = $toolsEnabled;

        return $agent;
    }

    /** @return array<string,mixed> the tools POSTed to Telnyx */
    private function syncedTools(AiAgent $agent): array
    {
        $sent = null;
        Http::fake(function (Request $r) use (&$sent) {
            if ($r->method() === 'POST') {
                $sent = $r->data();
            }

            return Http::response(['id' => self::ASSISTANT]);
        });
        app(TelnyxConvaiService::class)->syncReceptionAgentTools($agent);
        $this->assertNotNull($sent);

        return $sent['tools'];
    }

    public function test_alert_owner_needs_number_and_problem_and_is_a_voxraweb_data_tool(): void
    {
        $def = collect(ReceptionAgentToolDefinitions::list([]))->firstWhere('name', 'alert_owner');

        $this->assertNotNull($def);
        // Name asked for but not required: a caller who refuses it must still
        // get the owner alerted (voxragtm#84, QA bloom.abuse).
        $this->assertSame(['callback_number', 'problem'], $def['required']);
        $this->assertArrayHasKey('caller_declined_name', $def['properties']);
        $this->assertTrue(ReceptionAgentToolDefinitions::isDataTool('alert_owner'));
        $this->assertStringContainsString('BEFORE any transfer', $def['description']);
    }

    public function test_alert_owner_response_fills_the_transfer_target(): void
    {
        $tools = $this->syncedTools($this->agent());

        $alert = collect($tools)->first(fn ($t) => ($t['webhook']['name'] ?? null) === 'alert_owner');
        $this->assertNotNull($alert);
        $this->assertSame('https://voxra.test/api/agent/tool', $alert['webhook']['url']);
        $this->assertSame([['name' => 'owner_transfer_to', 'value_path' => 'transfer_to']], $alert['webhook']['store_fields_as_variables']);
        $this->assertSame(10000, $alert['timeout_ms']);
        $this->assertSame(['tool_name', 'callback_number', 'problem'], $alert['webhook']['body_parameters']['required']);

        // Other webhook tools carry no variable mapping.
        $capture = collect($tools)->first(fn ($t) => ($t['webhook']['name'] ?? null) === 'capture_lead');
        $this->assertArrayNotHasKey('store_fields_as_variables', $capture['webhook']);

        $transfer = collect($tools)->firstWhere('type', 'transfer');
        $this->assertSame('{{owner_transfer_to}}', $transfer['transfer']['targets'][0]['to']);
    }

    public function test_report_abuse_is_a_voxraweb_data_tool_and_the_prompt_puts_abuse_first(): void
    {
        $def = collect(ReceptionAgentToolDefinitions::list([]))->firstWhere('name', 'report_abuse');
        $this->assertNotNull($def);
        $this->assertTrue(ReceptionAgentToolDefinitions::isDataTool('report_abuse'));
        $this->assertSame([], $def['required']);
        $this->assertArrayHasKey('genuine_need', $def['properties']);

        $tools = $this->syncedTools($this->agent());
        $abuse = collect($tools)->first(fn ($t) => ($t['webhook']['name'] ?? null) === 'report_abuse');
        $this->assertSame('https://voxra.test/api/agent/tool', $abuse['webhook']['url']);
        $this->assertNotNull(collect($tools)->firstWhere('type', 'hangup'));

        $p = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;
        $this->assertStringContainsString("I'll need to end the call if the language continues", $p);
        $this->assertStringContainsString('Frustrated isn\'t abusive', $p);
        $this->assertLessThan(strpos($p, '## Urgent calls and transfers'), strpos($p, 'call report_abuse'));
        $this->assertStringContainsString('caller_declined_name true', $p);
    }

    public function test_transfer_falls_back_to_owner_mobile_when_alert_owner_is_disabled(): void
    {
        $tools = $this->syncedTools($this->agent(['alert_owner' => false]));

        $this->assertNull(collect($tools)->first(fn ($t) => ($t['webhook']['name'] ?? null) === 'alert_owner'));
        $transfer = collect($tools)->firstWhere('type', 'transfer');
        $this->assertSame('{{owner_mobile}}', $transfer['transfer']['targets'][0]['to']);
    }

    public function test_prompt_orders_alert_before_transfer_and_keeps_the_disclosure_section(): void
    {
        $p = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;

        $this->assertStringContainsString('{{urgent_definition}}', $p);
        $this->assertStringContainsString('Call alert_owner', $p);
        $this->assertLessThan(strpos($p, 'use the transfer tool'), strpos($p, 'Call alert_owner'));
        $this->assertStringContainsString('NHS 111', $p);
        $this->assertStringContainsString('## AI disclosure and call recording', $p);
        $this->assertStringContainsString('{{recording_notice}}', $p);
        $this->assertStringContainsString('lookup_business_info', $p);
    }

    public function test_call_policy_defaults_urgent_definition_and_keeps_notice_when_recording_unknown(): void
    {
        Http::fake([
            'api.telnyx.test/v2/ai/assistants/*' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['dynamic_variables' => ['recording_notice' => 'This call is not audio-recorded.']])
                : Http::response([]),
        ]);

        app(TelnyxConvaiService::class)->applyVoxraCallPolicy(self::ASSISTANT, null);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data()['dynamic_variables']['recording_notice'] === 'This call is not audio-recorded.'
            && $r->data()['dynamic_variables']['urgent_definition'] === TelnyxConvaiService::DEFAULT_URGENT_DEFINITION);
    }
}
