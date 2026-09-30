<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Models\AiAgent;
use App\Services\ReceptionAgent\ReceptionAgentToolDefinitions;
use App\Services\TelnyxConvaiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * voxragtm#140: a Voxra tenant's receptionist gets only the reception tool
 * set; the *9 in-call summon tools (complete_and_exit 404s "session not
 * found" on a reception call, get_weather, park_call, ...) stay with non-Voxra
 * summon assistants. In-memory sqlite for the v_domains tag lookup.
 */
class VoxraReceptionToolAllowlistTest extends TestCase
{
    private const VOXRA_DOMAIN = '11111111-1111-1111-1111-111111111111';
    private const PBX_DOMAIN = '22222222-2222-2222-2222-222222222222';

    private const RECEPTION_WEBHOOK_TOOLS = [
        'alert_owner', 'capture_lead', 'check_availability', 'book_appointment',
        'cancel_appointment', 'reschedule_appointment',
        'recall_caller', 'remember_about_caller', 'remember', 'recall_business',
        'report_abuse', 'record_summary', 'lookup_business_info', 'search_memory',
        'send_payment_link',
    ];

    private const SUMMON_ONLY_TOOLS = [
        'lookup_user', 'transfer_call', 'announced_transfer', 'park_call',
        'bring_back', 'three_way_add', 'take_notes', 'email_reminder',
        'complete_and_exit', 'get_time_in_city', 'get_weather',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.telnyx.api_key' => 'test-key',
            'services.telnyx.base_url' => 'https://api.telnyx.test',
            'app.url' => 'https://lon1.voxra.test',
            'services.voxra.app_url' => 'https://voxra.test',
            'services.voxra.agent_tool_secret' => 'tool-secret',
        ]);

        Schema::create('v_domains', function ($t) {
            $t->string('domain_uuid')->primary();
            $t->string('domain_name')->nullable();
            $t->string('domain_description')->nullable();
        });
        DB::table('v_domains')->insert([
            ['domain_uuid' => self::VOXRA_DOMAIN, 'domain_name' => 'bloom-hair.voxra.uk', 'domain_description' => 'voxra-tenant:485c8590'],
            ['domain_uuid' => self::PBX_DOMAIN, 'domain_name' => 'iqmobile.uk', 'domain_description' => 'iqmobile'],
        ]);
    }

    private function agent(string $domainUuid, array $toolsEnabled = []): AiAgent
    {
        $agent = new AiAgent();
        $agent->domain_uuid = $domainUuid;
        $agent->mode = AiAgent::MODE_RECEPTION;
        $agent->telnyx_assistant_id = 'assistant-1';
        $agent->tools_enabled = $toolsEnabled;

        return $agent;
    }

    /** @return array<int,array<string,mixed>> the tools POSTed to Telnyx */
    private function syncedTools(AiAgent $agent): array
    {
        $sent = null;
        Http::fake(function (Request $r) use (&$sent) {
            $sent = $r->data();

            return Http::response(['id' => 'assistant-1']);
        });
        app(TelnyxConvaiService::class)->syncReceptionAgentTools($agent);
        $this->assertNotNull($sent);

        return $sent['tools'];
    }

    /** @return array<int,string> webhook names, then native tool types */
    private function names(array $tools): array
    {
        return array_map(fn ($t) => $t['type'] === 'webhook' ? $t['webhook']['name'] : $t['type'], $tools);
    }

    public function test_voxra_receptionist_gets_exactly_the_reception_set(): void
    {
        $tools = $this->syncedTools($this->agent(self::VOXRA_DOMAIN));

        $this->assertSame(
            array_merge(self::RECEPTION_WEBHOOK_TOOLS, ['transfer', 'hangup']),
            $this->names($tools)
        );
        // Every reception webhook tool is served by voxraweb, not the PBX.
        foreach ($tools as $t) {
            if ($t['type'] === 'webhook') {
                $this->assertSame('https://voxra.test/api/agent/tool', $t['webhook']['url'], $t['webhook']['name']);
            }
        }
        // Owner transfer still gated on alert_owner (voxragtm#122).
        $transfer = collect($tools)->firstWhere('type', 'transfer');
        $this->assertSame('{{owner_transfer_to}}', $transfer['transfer']['targets'][0]['to']);
    }

    public function test_tools_enabled_cannot_re_enable_a_summon_tool_for_a_voxra_receptionist(): void
    {
        $tools = $this->syncedTools($this->agent(self::VOXRA_DOMAIN, ['complete_and_exit' => true, 'get_weather' => true]));

        $this->assertEmpty(array_intersect(self::SUMMON_ONLY_TOOLS, $this->names($tools)));
    }

    public function test_summon_agent_on_a_non_voxra_domain_keeps_its_full_set(): void
    {
        $names = $this->names($this->syncedTools($this->agent(self::PBX_DOMAIN)));

        foreach (array_merge(self::SUMMON_ONLY_TOOLS, self::RECEPTION_WEBHOOK_TOOLS, ['transfer', 'hangup']) as $tool) {
            $this->assertContains($tool, $names);
        }
        $this->assertSame(
            array_column(ReceptionAgentToolDefinitions::list([]), 'name'),
            array_values(array_filter($names, fn ($n) => !in_array($n, ['transfer', 'hangup'], true)))
        );
    }

    public function test_allowlist_matches_the_voxraweb_data_tools(): void
    {
        $this->assertSame(self::RECEPTION_WEBHOOK_TOOLS, ReceptionAgentToolDefinitions::VOXRA_RECEPTION_TOOLS);
        $this->assertEqualsCanonicalizing(ReceptionAgentToolDefinitions::DATA_TOOLS, ReceptionAgentToolDefinitions::VOXRA_RECEPTION_TOOLS);
        // Every allowlisted name is a real tool definition.
        $defined = array_column(ReceptionAgentToolDefinitions::list([]), 'name');
        $this->assertEmpty(array_diff(ReceptionAgentToolDefinitions::VOXRA_RECEPTION_TOOLS, $defined));
    }

    /**
     * voxragtm#175: the booking tools speak a filler line and get 8s instead
     * of Telnyx's ~5s default (a slow calendar was timing out silently), and
     * cancel/reschedule are voxraweb data tools with the agreed contract.
     */
    public function test_booking_tools_have_a_filler_line_and_an_8s_timeout(): void
    {
        $tools = collect($this->syncedTools($this->agent(self::VOXRA_DOMAIN)))
            ->filter(fn ($t) => $t['type'] === 'webhook')
            ->keyBy(fn ($t) => $t['webhook']['name']);

        foreach (['check_availability', 'book_appointment', 'cancel_appointment', 'reschedule_appointment'] as $name) {
            $this->assertTrue($tools->has($name), $name);
            $t = $tools[$name];
            $this->assertSame(8000, $t['timeout_ms'], $name);
            $this->assertArrayNotHasKey('timeout_ms', $t['webhook'], $name);
            $this->assertCount(1, $t['webhook']['messages'], $name);
            $this->assertSame('request_response_delayed', $t['webhook']['messages'][0]['type'], $name);
            $this->assertNotSame('', trim($t['webhook']['messages'][0]['content']), $name);
            // Served by voxraweb with the tenant/caller context headers.
            $this->assertSame('https://voxra.test/api/agent/tool', $t['webhook']['url'], $name);
            $headers = array_column($t['webhook']['headers'], 'value', 'name');
            $this->assertSame('Bearer tool-secret', $headers['Authorization'], $name);
            $this->assertSame('{{domain_uuid}}', $headers['X-Voxra-Domain-Uuid'], $name);
            $this->assertSame('{{conversation_id}}', $headers['X-Voxra-Conversation-Id'], $name);
            $this->assertSame('{{caller_number}}', $headers['X-Voxra-Caller-Number'], $name);
            // Webhook-independent fallbacks (voxragtm#141).
            $this->assertSame('{{voxra_domain_uuid}}', $headers['X-Voxra-Sip-Domain-Uuid'], $name);
            $this->assertSame('{{telnyx_agent_target}}', $headers['X-Voxra-Agent-Target'], $name);
            $this->assertSame('{{telnyx_end_user_target}}', $headers['X-Voxra-End-User'], $name);
            $this->assertTrue(ReceptionAgentToolDefinitions::isDataTool($name), $name);
        }

        // alert_owner keeps its own 10s.
        $this->assertSame(10000, $tools['alert_owner']['timeout_ms']);
        // Tools without a filler get no messages / timeout.
        $this->assertArrayNotHasKey('messages', $tools['capture_lead']['webhook']);
        $this->assertArrayNotHasKey('timeout_ms', $tools['capture_lead']);
    }

    public function test_cancel_and_reschedule_match_the_voxraweb_contract(): void
    {
        $defs = collect(ReceptionAgentToolDefinitions::list([]))->keyBy('name');

        $cancel = $defs['cancel_appointment'];
        $this->assertSame(['appointment_ref', 'starts_at', 'reason'], array_keys($cancel['properties']));
        $this->assertSame([], $cancel['required']);
        $this->assertStringContainsString('Confirm which appointment', $cancel['description']);

        $move = $defs['reschedule_appointment'];
        $this->assertSame(['appointment_ref', 'starts_at', 'new_starts_at'], array_keys($move['properties']));
        $this->assertSame(['new_starts_at'], $move['required']);
        $this->assertStringContainsString('check_availability', $move['description']);
        foreach (array_merge($cancel['properties'], $move['properties']) as $prop) {
            $this->assertSame('string', $prop['type']);
        }

        // Rendered for Telnyx: tool_name enum + the required new time.
        $tools = collect($this->syncedTools($this->agent(self::VOXRA_DOMAIN)))
            ->filter(fn ($t) => $t['type'] === 'webhook')
            ->keyBy(fn ($t) => $t['webhook']['name']);
        $body = $tools['reschedule_appointment']['webhook']['body_parameters'];
        $this->assertSame(['reschedule_appointment'], $body['properties']['tool_name']['enum']);
        $this->assertSame(['tool_name', 'new_starts_at'], $body['required']);
        $this->assertSame(['tool_name'], $tools['cancel_appointment']['webhook']['body_parameters']['required']);
    }

    public function test_check_availability_only_promises_date_phrasing_voxraweb_parses(): void
    {
        $def = collect(ReceptionAgentToolDefinitions::list([]))->firstWhere('name', 'check_availability');
        $desc = $def['properties']['date']['description'];

        foreach (['today', 'tomorrow', 'next Tuesday', 'this Tuesday', 'YYYY-MM-DD'] as $phrase) {
            $this->assertStringContainsString($phrase, $desc);
        }
    }

    public function test_check_availability_takes_an_optional_service_for_booking_systems(): void
    {
        $def = collect(ReceptionAgentToolDefinitions::list([]))->firstWhere('name', 'check_availability');
        $this->assertArrayHasKey('service', $def['properties']);
        $this->assertSame('string', $def['properties']['service']['type']);
        $this->assertSame(['date'], $def['required']);
    }

    public function test_reception_prompt_and_tool_descriptions_never_mention_a_removed_tool(): void
    {
        $texts = [ProvisionTenantController::RECEPTION_SYSTEM_PROMPT];
        foreach (ReceptionAgentToolDefinitions::forAgent($this->agent(self::VOXRA_DOMAIN)) as $t) {
            $texts[] = $t['description'];
            $texts[] = json_encode($t['properties']);
        }
        $all = implode("\n", $texts);

        foreach (self::SUMMON_ONLY_TOOLS as $tool) {
            $this->assertStringNotContainsString($tool, $all);
        }
    }
}
