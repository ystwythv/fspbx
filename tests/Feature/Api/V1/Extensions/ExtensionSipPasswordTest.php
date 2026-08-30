<?php

namespace Tests\Feature\Api\V1\Extensions;

use App\Http\Middleware\AuthorizeUser;
use App\Models\DeviceLines;
use App\Models\Domain;
use App\Models\ExtensionAdvSettings;
use App\Models\Extensions;
use App\Models\User;
use App\Models\Voicemails;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V1 extension API: partner FMC provisioning needs to SET the SIP password
 * (iqportal's Voxra Admin "reset SIP password" and the Voxra Complete
 * reseller flow) and read it back exactly once via ?include=sip_credentials.
 * Runs against an in-memory sqlite schema; the permission middleware is
 * bypassed (its own tests cover it) while auth:sanctum + the bearer check
 * stay in the stack.
 */
class ExtensionSipPasswordTest extends TestCase
{
    private const DOMAIN = '11111111-1111-1111-1111-111111111111';
    private const EXT = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);
        config()->set('cache.default', 'array');

        Event::fake([\App\Events\ExtensionUpdated::class]);
        $this->createSchema();

        // Postgres fills these UUID primary keys with a column default; sqlite can't.
        foreach ([
            Extensions::class           => 'extension_uuid',
            Voicemails::class           => 'voicemail_uuid',
            ExtensionAdvSettings::class => 'setting_uuid',
        ] as $model => $pk) {
            $model::creating(function ($m) use ($pk) {
                if (empty($m->{$pk})) {
                    $m->{$pk} = (string) Str::uuid();
                }
            });
        }

        Domain::unguarded(fn () => Domain::query()->insert([
            'domain_uuid' => self::DOMAIN,
            'domain_name' => 'acme.voxra.uk',
            'domain_enabled' => 'true',
        ]));

        $this->withoutMiddleware([AuthorizeUser::class]);
        // helpers.php isSuperAdmin() iterates the session user groups from model hooks
        Session::put('user.groups', []);
        Session::put('permissions', []);
        $user = new User();
        $user->setRawAttributes(['user_uuid' => 'user-uuid-1', 'domain_uuid' => self::DOMAIN, 'username' => 'api']);
        Sanctum::actingAs($user);
    }

    private function createSchema(): void
    {
        Schema::create('v_domains', function ($t) {
            $t->string('domain_uuid')->primary();
            $t->string('domain_name')->nullable();
            $t->string('domain_enabled')->nullable();
            $t->string('domain_description')->nullable();
        });
        Schema::create('v_extensions', function ($t) {
            foreach ([
                'domain_uuid', 'extension', 'number_alias', 'password', 'accountcode', 'user_context',
                'effective_caller_id_name', 'effective_caller_id_number', 'outbound_caller_id_name',
                'outbound_caller_id_number', 'emergency_caller_id_name', 'emergency_caller_id_number',
                'directory_first_name', 'directory_last_name', 'directory_visible', 'directory_exten_visible',
                'max_registrations', 'limit_max', 'limit_destination', 'toll_allow', 'call_group', 'hold_music',
                'cidr', 'sip_force_contact', 'sip_force_expires', 'sip_bypass_media', 'mwi_account',
                'absolute_codec_string', 'dial_string', 'force_ping', 'auth_acl', 'call_timeout', 'enabled',
                'do_not_disturb', 'ring_target', 'user_record', 'call_screen_enabled', 'description',
                'forward_all_enabled', 'forward_all_destination', 'forward_busy_enabled', 'forward_busy_destination',
                'forward_no_answer_enabled', 'forward_no_answer_destination', 'forward_user_not_registered_enabled',
                'forward_user_not_registered_destination', 'follow_me_uuid', 'follow_me_enabled', 'insert_date',
                'insert_user', 'update_date', 'update_user',
            ] as $col) {
                $t->string($col)->nullable();
            }
            $t->string('extension_uuid')->primary();
        });
        Schema::create('extension_advanced_settings', function ($t) {
            $t->string('setting_uuid')->primary();
            $t->string('extension_uuid')->nullable();
            $t->string('suspended')->nullable();
        });
        Schema::create('mobile_app_users', function ($t) {
            $t->string('mobile_app_user_uuid')->primary();
            $t->string('extension_uuid')->nullable();
            $t->string('org_id')->nullable();
            $t->string('conn_id')->nullable();
            $t->string('user_id')->nullable();
            $t->string('status')->nullable();
            $t->string('exclude_from_stale_report')->nullable();
        });
        Schema::create('v_voicemails', function ($t) {
            $t->string('voicemail_uuid')->primary();
            foreach ([
                'domain_uuid', 'voicemail_id', 'voicemail_password', 'voicemail_mail_to', 'voicemail_sms_to',
                'voicemail_enabled', 'voicemail_transcription_enabled', 'voicemail_recording_instructions',
                'voicemail_file', 'voicemail_local_after_email', 'voicemail_tutorial', 'voicemail_description',
                'greeting_id', 'insert_date', 'insert_user', 'update_date', 'update_user',
            ] as $col) {
                $t->string($col)->nullable();
            }
        });
        Schema::create('v_device_lines', function ($t) {
            $t->string('device_line_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('device_uuid')->nullable();
            $t->string('auth_id')->nullable();
            $t->string('password')->nullable();
            $t->string('line_number')->nullable();
            $t->string('insert_date')->nullable();
            $t->string('insert_user')->nullable();
            $t->string('update_date')->nullable();
            $t->string('update_user')->nullable();
        });
        Schema::create('v_domain_settings', function ($t) {
            $t->string('domain_setting_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('domain_setting_category')->nullable();
            $t->string('domain_setting_subcategory')->nullable();
            $t->string('domain_setting_value')->nullable();
            $t->string('domain_setting_enabled')->nullable();
        });
        Schema::create('v_default_settings', function ($t) {
            $t->string('default_setting_uuid')->primary();
            $t->string('default_setting_category')->nullable();
            $t->string('default_setting_subcategory')->nullable();
            $t->string('default_setting_value')->nullable();
        });
        Schema::create('users_adv_fields', function ($t) {
            $t->string('user_uuid')->primary();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
        });
        // Every table UniqueExtension scans for number collisions.
        foreach ([
            'v_ring_groups'         => ['ring_group_uuid', 'ring_group_extension'],
            'v_call_center_queues'  => ['call_center_queue_uuid', 'queue_extension'],
            'v_fax'                 => ['fax_uuid', 'fax_extension'],
            'v_ivr_menus'           => ['ivr_menu_uuid', 'ivr_menu_extension'],
            'v_call_flows'          => ['call_flow_uuid', 'call_flow_extension'],
            'v_conference_centers'  => ['conference_center_uuid', 'conference_center_extension'],
            'v_conferences'         => ['conference_uuid', 'conference_extension'],
            'business_hours'        => ['uuid', 'extension'],
        ] as $table => [$pk, $col]) {
            Schema::create($table, function ($t) use ($pk, $col) {
                $t->string($pk)->primary();
                $t->string('domain_uuid')->nullable();
                $t->string($col)->nullable();
            });
        }
    }

    private function base(): string
    {
        return '/api/v1/domains/' . self::DOMAIN . '/extensions';
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer test-token', 'Accept' => 'application/json'];
    }

    private function storePayload(array $extra = []): array
    {
        return array_merge([
            'extension'            => '201',
            'directory_first_name' => 'Acme',
            'directory_last_name'  => 'Mobile',
            'voicemail_enabled'    => true,
            'ring_target'          => 'fmc',
        ], $extra);
    }

    // ---- store ---------------------------------------------------------

    public function test_store_uses_supplied_password_and_echoes_it_only_with_include(): void
    {
        $pw = 'Xk9pQ2mV7bR4tL8nW3zYab';

        $res = $this->postJson($this->base() . '?include=sip_credentials', $this->storePayload(['password' => $pw]), $this->headers());
        $res->assertStatus(201)
            ->assertJsonPath('extension', '201')
            ->assertJsonPath('ring_target', 'fmc')
            ->assertJsonPath('sip_credentials.username', '201')
            ->assertJsonPath('sip_credentials.password', $pw)
            ->assertJsonPath('sip_credentials.realm', 'acme.voxra.uk');
        $this->assertArrayNotHasKey('password', $res->json());

        $ext = Extensions::where('domain_uuid', self::DOMAIN)->where('extension', '201')->first();
        $this->assertNotNull($ext);
        $this->assertSame($pw, $ext->getRawOriginal('password'));
        $this->assertSame('acme.voxra.uk', $ext->user_context);
    }

    public function test_store_without_include_never_exposes_the_password(): void
    {
        $res = $this->postJson($this->base(), $this->storePayload(['password' => 'Xk9pQ2mV7bR4tL8nW3zY']), $this->headers());
        $res->assertStatus(201);
        $json = $res->json();
        $this->assertArrayNotHasKey('sip_credentials', $json);
        $this->assertArrayNotHasKey('password', $json);
    }

    public function test_store_generates_a_password_when_none_supplied(): void
    {
        $res = $this->postJson($this->base() . '?include=sip_credentials', $this->storePayload(), $this->headers());
        $res->assertStatus(201);
        $generated = $res->json('sip_credentials.password');
        $this->assertIsString($generated);
        $this->assertGreaterThanOrEqual(12, strlen($generated));
        $this->assertSame($generated, Extensions::where('extension', '201')->first()->getRawOriginal('password'));
    }

    public function test_store_rejects_non_alphanumeric_short_or_long_password(): void
    {
        foreach (['short', 'has-a-dash-and-symbols!', str_repeat('a', 65)] as $bad) {
            $res = $this->postJson($this->base(), $this->storePayload(['password' => $bad]), $this->headers());
            // App\Exceptions\Handler renders ValidationException as 400 invalid_request_error
            $res->assertStatus(400)->assertJsonPath('error.type', 'invalid_request_error');
            $this->assertStringContainsString('password', $res->getContent());
        }
        $this->assertSame(0, Extensions::count());
    }

    // ---- update --------------------------------------------------------

    private function seedExtension(string $password = 'OldPassword12345'): void
    {
        Extensions::query()->insert([
            'extension_uuid'       => self::EXT,
            'domain_uuid'          => self::DOMAIN,
            'extension'            => '202',
            'password'             => $password,
            'user_context'         => 'acme.voxra.uk',
            'directory_first_name' => 'Acme',
            'enabled'              => 'true',
            'ring_target'          => 'both',
        ]);
        foreach (['dl-1', 'dl-2'] as $uuid) {
            DeviceLines::query()->insert([
                'device_line_uuid' => $uuid,
                'domain_uuid'      => self::DOMAIN,
                'device_uuid'      => 'dev-' . $uuid,
                'auth_id'          => '202',
                'password'         => $password,
                'line_number'      => '1',
            ]);
        }
        // a device line for a different extension must be left alone
        DeviceLines::query()->insert([
            'device_line_uuid' => 'dl-other',
            'domain_uuid'      => self::DOMAIN,
            'device_uuid'      => 'dev-other',
            'auth_id'          => '203',
            'password'         => 'OtherPassword123',
            'line_number'      => '1',
        ]);
    }

    public function test_update_sets_password_mirrors_device_lines_and_echoes_with_include(): void
    {
        $this->seedExtension();
        $pw = 'NewPassword987654321';

        $res = $this->patchJson($this->base() . '/' . self::EXT . '?include=sip_credentials', [
            'password'                  => $pw,
            'ring_target'               => 'fmc',
            'outbound_caller_id_number' => '441225800810',
            'outbound_caller_id_name'   => 'Acme Plumbing',
            'call_timeout'              => '20',
        ], $this->headers());

        $res->assertStatus(200)
            ->assertJsonPath('sip_credentials.username', '202')
            ->assertJsonPath('sip_credentials.password', $pw)
            ->assertJsonPath('sip_credentials.realm', 'acme.voxra.uk')
            ->assertJsonPath('ring_target', 'fmc')
            ->assertJsonPath('outbound_caller_id_number_e164', '441225800810');
        $this->assertArrayNotHasKey('password', $res->json());

        $ext = Extensions::find(self::EXT);
        $this->assertSame($pw, $ext->getRawOriginal('password'));
        $this->assertSame('Acme Plumbing', $ext->outbound_caller_id_name);
        $this->assertSame('20', (string) $ext->call_timeout);

        $this->assertSame($pw, DeviceLines::find('dl-1')->password);
        $this->assertSame($pw, DeviceLines::find('dl-2')->password);
        $this->assertSame('OtherPassword123', DeviceLines::find('dl-other')->password);
    }

    public function test_update_without_password_leaves_credentials_untouched(): void
    {
        $this->seedExtension('OldPassword12345');

        $this->patchJson($this->base() . '/' . self::EXT, ['description' => 'renamed'], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('description', 'renamed');

        $this->assertSame('OldPassword12345', Extensions::find(self::EXT)->getRawOriginal('password'));
        $this->assertSame('OldPassword12345', DeviceLines::find('dl-1')->password);
    }

    public function test_update_rejects_invalid_password(): void
    {
        $this->seedExtension('OldPassword12345');

        $res = $this->patchJson($this->base() . '/' . self::EXT, ['password' => 'bad pass!'], $this->headers());
        $res->assertStatus(400)->assertJsonPath('error.type', 'invalid_request_error');
        $this->assertStringContainsString('password', $res->getContent());

        $this->assertSame('OldPassword12345', Extensions::find(self::EXT)->getRawOriginal('password'));
    }

    // ---- read paths never leak ------------------------------------------

    public function test_show_and_index_never_expose_password_even_with_include(): void
    {
        $this->seedExtension('OldPassword12345');

        $show = $this->getJson($this->base() . '/' . self::EXT . '?include=sip_credentials', $this->headers());
        $show->assertStatus(200);
        $this->assertArrayNotHasKey('sip_credentials', $show->json());
        $this->assertArrayNotHasKey('password', $show->json());
        $this->assertStringNotContainsString('OldPassword12345', $show->getContent());

        $index = $this->getJson($this->base() . '?include=sip_credentials', $this->headers());
        $index->assertStatus(200);
        $this->assertStringNotContainsString('OldPassword12345', $index->getContent());
    }
}
