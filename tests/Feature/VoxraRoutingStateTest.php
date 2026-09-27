<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Services\TelnyxConvaiService;
use App\Services\Voxra\VoxraRoutingState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The per-domain Voxra routing state (voxragtm#162) and what reads it
 * outside the provision request: the Telnyx tool sync drops
 * transfer_to_owner on Line+AI (voxragtm#163 — it would re-ring the mobile
 * that just didn't answer). In-memory sqlite, VoxraReceptionToolAllowlistTest
 * style.
 */
class VoxraRoutingStateTest extends TestCase
{
    private const DOMAIN = '11111111-1111-1111-1111-111111111111';

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
            'domain_uuid' => self::DOMAIN, 'domain_name' => 'acme.voxra.uk', 'domain_description' => 'voxra-tenant:abc',
        ]);
        Schema::create('v_domain_settings', function ($t) {
            $t->string('domain_setting_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('app_uuid')->nullable();
            $t->string('domain_setting_category')->nullable();
            $t->string('domain_setting_subcategory')->nullable();
            $t->string('domain_setting_name')->nullable();
            $t->text('domain_setting_value')->nullable();
            $t->string('domain_setting_order')->nullable();
            $t->string('domain_setting_enabled')->nullable();
            $t->string('domain_setting_description')->nullable();
        });
    }

    public function test_state_round_trips_and_is_one_row_per_domain(): void
    {
        $this->assertSame([], VoxraRoutingState::load(self::DOMAIN));
        $this->assertNull(VoxraRoutingState::mode(self::DOMAIN));

        VoxraRoutingState::save(self::DOMAIN, ['mode' => VoxraRoutingState::MODE_PRO, 'owner_mobile' => '+447700900123']);
        VoxraRoutingState::save(self::DOMAIN, ['mode' => VoxraRoutingState::MODE_LINE_AI, 'owner_mobile' => '+447700900123', 'ring_first_timeout' => 25]);

        $this->assertSame(1, DB::table('v_domain_settings')->count());
        $this->assertSame(
            ['mode' => 'line_ai', 'owner_mobile' => '+447700900123', 'ring_first_timeout' => 25],
            VoxraRoutingState::load(self::DOMAIN),
        );
        $this->assertTrue(VoxraRoutingState::isLineAi(self::DOMAIN));
    }

    public function test_unreadable_state_is_empty_not_an_error(): void
    {
        Schema::drop('v_domain_settings');

        $this->assertSame([], VoxraRoutingState::load(self::DOMAIN));
        $this->assertFalse(VoxraRoutingState::isLineAi(self::DOMAIN));
        $this->assertSame([], VoxraRoutingState::load(null));
    }

    /** @return array<int,string> webhook names, then native tool types */
    private function syncedToolNames(): array
    {
        $agent = new AiAgent();
        $agent->domain_uuid = self::DOMAIN;
        $agent->mode = AiAgent::MODE_RECEPTION;
        $agent->telnyx_assistant_id = 'assistant-1';
        $agent->tools_enabled = [];

        $sent = null;
        Http::fake(function (Request $r) use (&$sent) {
            $sent = $r->data();

            return Http::response(['id' => 'assistant-1']);
        });
        app(TelnyxConvaiService::class)->syncReceptionAgentTools($agent);

        return array_map(fn ($t) => $t['type'] === 'webhook' ? $t['webhook']['name'] : $t['type'], $sent['tools']);
    }

    public function test_line_ai_agent_has_no_owner_transfer(): void
    {
        VoxraRoutingState::save(self::DOMAIN, ['mode' => VoxraRoutingState::MODE_LINE_AI]);

        $names = $this->syncedToolNames();
        $this->assertNotContains('transfer', $names);
        // the rest of the receptionist is intact
        $this->assertContains('alert_owner', $names);
        $this->assertContains('book_appointment', $names);
        $this->assertContains('hangup', $names);
    }

    public function test_pro_agent_keeps_owner_transfer(): void
    {
        VoxraRoutingState::save(self::DOMAIN, ['mode' => VoxraRoutingState::MODE_PRO]);
        $this->assertContains('transfer', $this->syncedToolNames());

        // and so does a tenant provisioned before the state existed
        DB::table('v_domain_settings')->delete();
        $this->assertContains('transfer', $this->syncedToolNames());
    }
}
