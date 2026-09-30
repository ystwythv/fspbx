<?php

namespace Tests\Feature;

use App\Services\FreeswitchEslService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * POST /internal/voxra/click-to-call (voxragtm#24): HMAC + freshness checks,
 * input validation, and the exact originate string sent to FreeSWITCH — the
 * owner is rung first, the caller only via the &bridge app on answer. ESL is
 * a partial mock: the real FreeswitchEslService::originate() formats the
 * command and only executeCommand() is stubbed. In-memory sqlite schema.
 */
class VoxraClickToCallTest extends TestCase
{
    private const URL = '/internal/voxra/click-to-call';
    private const SECRET = 'test-internal-secret';
    private const DOMAIN_UUID = '3f1c2b7a-9d4e-4c1a-8b2f-6e5d4c3b2a10';
    private const DOMAIN_NAME = 'acme.voxra.uk';
    private const CALL_UUID = '11111111-2222-4333-8444-555555555555';

    /** @var \Mockery\MockInterface&FreeswitchEslService */
    private $esl;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);
        config()->set('services.voxra_internal.secret', self::SECRET);
        Cache::flush();

        Schema::create('v_domains', function ($t) {
            $t->string('domain_uuid')->primary();
            $t->string('domain_name')->nullable();
            $t->string('domain_enabled')->nullable();
        });
        Schema::create('v_extensions', function ($t) {
            $t->string('extension_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('extension')->nullable();
        });
        Schema::create('v_destinations', function ($t) {
            $t->string('destination_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('destination_type')->nullable();
            $t->string('destination_number')->nullable();
        });

        DB::table('v_domains')->insert([
            'domain_uuid' => self::DOMAIN_UUID, 'domain_name' => self::DOMAIN_NAME, 'domain_enabled' => 'true',
        ]);
        DB::table('v_extensions')->insert([
            'extension_uuid' => 'e1e1e1e1-0000-4000-8000-000000000001',
            'domain_uuid' => self::DOMAIN_UUID,
            'extension' => '201',
        ]);
        DB::table('v_destinations')->insert([
            'destination_uuid' => 'd1d1d1d1-0000-4000-8000-000000000001',
            'domain_uuid' => self::DOMAIN_UUID,
            'destination_type' => 'inbound',
            'destination_number' => '+441162987910',
        ]);

        Str::createUuidsUsing(fn () => Uuid::fromString(self::CALL_UUID));

        $this->esl = Mockery::mock(FreeswitchEslService::class)->makePartial();
        $this->app->instance(FreeswitchEslService::class, $this->esl);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'domain_uuid' => self::DOMAIN_UUID,
            'owner' => '+447700900123',
            'owner_kind' => 'number',
            'caller' => '+447700900456',
            'cli' => '+441162987910',
            'ref' => 'esc-123',
            'ts' => time(),
        ], $overrides);
    }

    private function send(array $payload, ?string $secret = self::SECRET)
    {
        $headers = $secret === null ? [] : ['Signature' => hash_hmac('sha256', json_encode($payload), $secret)];

        return $this->postJson(self::URL, $payload, $headers);
    }

    public function test_missing_or_bad_signature_is_401(): void
    {
        $this->esl->shouldNotReceive('executeCommand');

        $this->send($this->payload(), null)->assertStatus(401);
        $this->send($this->payload(), 'wrong-secret')->assertStatus(401);
    }

    public function test_stale_ts_is_401(): void
    {
        $this->esl->shouldNotReceive('executeCommand');

        $this->send($this->payload(['ts' => time() - 301]))
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'stale']);
        $this->send($this->payload(['ts' => null]))->assertStatus(401);
    }

    public function test_bad_input_is_422(): void
    {
        $this->esl->shouldNotReceive('executeCommand');

        $bad = [
            ['domain_uuid' => 'not-a-uuid'],
            ['owner' => '07700900123'],
            ['owner' => '+4477'],
            ['owner_kind' => 'sip'],
            ['owner_kind' => 'extension', 'owner' => '1'],
            ['owner_kind' => 'extension', 'owner' => '+447700900123'],
            ['owner_kind' => 'extension', 'owner' => '999'],   // not in the domain
            ['caller' => '447700900456'],
            ['caller' => '+44 7700 900456'],
            ['cli' => 'anonymous'],
            ['ref' => ''],
            ['ref' => str_repeat('a', 65)],
            ['ref' => 'esc 123'],
            ['ref' => 'x,y'],
            ['caller' => '+441162987910'],                     // caller == cli
            ['owner' => '+441162987910', 'caller' => '+447700900999'], // owner is a hosted DID
        ];

        foreach ($bad as $override) {
            $this->send($this->payload($override))
                ->assertStatus(422)
                ->assertJsonPath('ok', false);
        }
    }

    public function test_unknown_domain_is_404(): void
    {
        $this->esl->shouldNotReceive('executeCommand');

        $this->send($this->payload(['domain_uuid' => '00000000-0000-4000-8000-000000000000']))
            ->assertStatus(404)
            ->assertExactJson(['ok' => false, 'error' => 'unknown domain']);
    }

    public function test_number_owner_rings_owner_first_then_bridges_caller(): void
    {
        $expected = 'bgapi originate {'
            . "origination_uuid='" . self::CALL_UUID . "',"
            . "origination_caller_id_number='+441162987910',"
            . "origination_caller_id_name='Voxra call-back',"
            . "effective_caller_id_number='+441162987910',"
            . "effective_caller_id_name='Voxra call-back',"
            . "outbound_caller_id_number='441162987910',"
            . "outbound_caller_id_name='Voxra call-back',"
            . "originate_timeout='30',"
            . "ignore_early_media='true',"
            . "hangup_after_bridge='true',"
            . "call_direction='outbound',"
            . "domain_uuid='" . self::DOMAIN_UUID . "',"
            . "domain_name='acme.voxra.uk',"
            . "voxra_click_to_call='true',"
            . "voxra_escalation_ref='esc-123',"
            . "group_confirm_key='exec',"
            . "group_confirm_file='lua lua/voxra_owner_confirm.lua',"
            . "group_confirm_cancel_timeout='1'"
            . '}loopback/+447700900123/acme.voxra.uk '
            . '&bridge({'
            . 'origination_caller_id_number=+441162987910,'
            . 'origination_caller_id_name=+441162987910,'
            . 'effective_caller_id_number=+441162987910,'
            . 'effective_caller_id_name=+441162987910,'
            . 'outbound_caller_id_number=441162987910,'
            . 'outbound_caller_id_name=+441162987910,'
            . 'ignore_early_media=false,'
            . 'bridge_early_media=true,'
            . 'originate_timeout=45,'
            . 'call_direction=outbound,'
            . 'domain_uuid=' . self::DOMAIN_UUID . ','
            . 'domain_name=acme.voxra.uk,'
            . 'voxra_click_to_call=true,'
            . 'voxra_escalation_ref=esc-123'
            . '}loopback/+447700900456/acme.voxra.uk) XML default';

        $this->esl->shouldReceive('executeCommand')->once()->with($expected)
            ->andReturn('+OK Job-UUID: 9a9a9a9a-0000-4000-8000-000000000000');

        $this->send($this->payload())
            ->assertOk()
            ->assertExactJson(['ok' => true, 'call_uuid' => self::CALL_UUID]);
    }

    public function test_extension_owner_rings_the_extension_without_confirm(): void
    {
        $captured = null;
        $this->esl->shouldReceive('executeCommand')->once()
            ->with(Mockery::on(function ($cmd) use (&$captured) {
                $captured = $cmd;

                return true;
            }))
            ->andReturn('+OK Job-UUID: 9a9a9a9a-0000-4000-8000-000000000000');

        $this->send($this->payload(['owner_kind' => 'extension', 'owner' => '201']))
            ->assertOk()
            ->assertJsonPath('call_uuid', self::CALL_UUID);

        $this->assertStringContainsString("}user/201@acme.voxra.uk &bridge({", $captured);
        $this->assertStringContainsString("origination_uuid='" . self::CALL_UUID . "'", $captured);
        $this->assertStringContainsString("origination_caller_id_number='+441162987910'", $captured);
        $this->assertStringContainsString("call_direction='local'", $captured);
        $this->assertStringContainsString('}loopback/+447700900456/acme.voxra.uk) XML default', $captured);
        $this->assertStringNotContainsString('group_confirm', $captured);
        // The owner is the only originate target; the caller only appears inside &bridge().
        $this->assertSame(1, substr_count($captured, '+447700900456'));
        $this->assertMatchesRegularExpression('/&bridge\(\{[^ ]*\+447700900456[^ ]*\)/', $captured);
    }

    public function test_duplicate_within_60s_does_not_ring_twice(): void
    {
        $this->esl->shouldReceive('executeCommand')->once()
            ->andReturn('+OK Job-UUID: 9a9a9a9a-0000-4000-8000-000000000000');

        $this->send($this->payload())
            ->assertOk()
            ->assertExactJson(['ok' => true, 'call_uuid' => self::CALL_UUID]);

        // Same domain + caller, different owner / ref: still a duplicate.
        $this->send($this->payload(['ref' => 'esc-124', 'owner_kind' => 'extension', 'owner' => '201']))
            ->assertOk()
            ->assertExactJson(['ok' => true, 'duplicate' => true, 'call_uuid' => self::CALL_UUID]);

        $this->travel(61)->seconds();
        $this->esl->shouldReceive('executeCommand')->once()
            ->andReturn('+OK Job-UUID: 9a9a9a9a-0000-4000-8000-000000000001');
        $this->send($this->payload())->assertOk()->assertJsonMissingPath('duplicate');
    }

    public function test_esl_failure_is_500_and_releases_the_dedupe_lock(): void
    {
        $this->esl->shouldReceive('executeCommand')->once()->andReturn(null);

        $this->send($this->payload())
            ->assertStatus(500)
            ->assertExactJson(['ok' => false, 'error' => 'originate failed']);

        $this->esl->shouldReceive('executeCommand')->once()->andReturn('-ERR no reply');
        $this->send($this->payload())->assertStatus(500);

        $this->esl->shouldReceive('executeCommand')->once()
            ->andReturn('+OK Job-UUID: 9a9a9a9a-0000-4000-8000-000000000000');
        $this->send($this->payload())->assertOk()->assertJsonPath('ok', true);
    }
}
