<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Services\TelnyxConvaiService;
use App\Services\Voxra\VoxraMediaPurgeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Call recording, consent & retention (voxragtm#83): the reception greeting
 * always discloses, the recording switch reaches Telnyx without clobbering the
 * assistant's other telephony settings, and the Telnyx purge only touches our
 * assistants' old conversations/recordings (and nothing on a dry run).
 */
class VoxraRecordingPolicyTest extends TestCase
{
    private const OURS = 'assistant-ours';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.api_key' => 'test-key', 'services.telnyx.base_url' => 'https://api.telnyx.test']);
    }

    public function test_supplied_greeting_gets_the_disclosure(): void
    {
        $g = ProvisionTenantController::resolveGreeting('Hi, Acme Plumbing, how can I help?', null, 'Acme', true);

        $this->assertStringContainsString("I'm an AI assistant", $g);
        $this->assertStringContainsString('recorded', $g);
    }

    public function test_old_generic_greeting_is_replaced_when_none_supplied(): void
    {
        $g = ProvisionTenantController::resolveGreeting(null, 'Hi, how can I help with this call?', 'Acme', null);

        $this->assertSame("Hi, thanks for calling Acme. Just so you know, I'm an AI assistant and calls may be recorded. How can I help?", $g);
    }

    public function test_disclosing_greeting_is_kept_when_none_supplied(): void
    {
        $current = "Hi, Acme here — I'm an AI assistant and calls may be recorded. How can I help?";

        $this->assertSame($current, ProvisionTenantController::resolveGreeting(null, $current, 'Acme', null));
    }

    public function test_reception_prompt_has_the_disclosure_section(): void
    {
        $inputs = app(ProvisionTenantController::class)->receptionAgentInputs('Acme', true);

        $this->assertStringContainsString('## AI disclosure and call recording', $inputs['system_prompt']);
        $this->assertStringContainsString('{{recording_notice}}', $inputs['system_prompt']);
        $this->assertStringContainsString('Never', $inputs['system_prompt']);
    }

    public function test_call_policy_merges_recording_switch_into_current_settings(): void
    {
        Http::fake([
            'api.telnyx.test/v2/ai/assistants/*' => function (Request $r) {
                if ($r->method() === 'GET') {
                    return Http::response([
                        'id' => self::OURS,
                        'telephony_settings' => [
                            'default_texml_app_id' => '123',
                            'time_limit_secs' => 1800,
                            'recording_settings' => ['enabled' => true, 'channels' => 'dual', 'format' => 'mp3'],
                        ],
                        'interruption_settings' => ['enable' => true, 'disable_greeting_interruption' => false],
                        'dynamic_variables' => null,
                    ]);
                }

                return Http::response(['id' => self::OURS]);
            },
        ]);

        app(TelnyxConvaiService::class)->applyVoxraCallPolicy(self::OURS, false);

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST') {
                return false;
            }
            $b = $r->data();

            return $b['telephony_settings']['default_texml_app_id'] === '123'
                && $b['telephony_settings']['time_limit_secs'] === 1800
                && $b['telephony_settings']['recording_settings']['enabled'] === false
                && $b['telephony_settings']['recording_settings']['channels'] === 'dual'
                && $b['interruption_settings']['enable'] === true
                && $b['interruption_settings']['disable_greeting_interruption'] === true
                && str_contains($b['dynamic_variables']['recording_notice'], 'not audio-recorded');
        });
    }

    public function test_call_policy_leaves_recording_alone_when_unknown(): void
    {
        Http::fake([
            'api.telnyx.test/v2/ai/assistants/*' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['telephony_settings' => ['recording_settings' => ['enabled' => true]]])
                : Http::response([]),
        ]);

        app(TelnyxConvaiService::class)->applyVoxraCallPolicy(self::OURS, null);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data()['telephony_settings']['recording_settings']['enabled'] === true);
    }

    private function fakeTelnyxStore(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();
            if ($r->method() === 'GET' && str_contains($url, '/v2/ai/conversations')) {
                return Http::response(['data' => [
                    ['id' => 'conv-old', 'metadata' => ['assistant_id' => self::OURS, 'call_leg_id' => 'leg-1']],
                ]]);
            }
            if ($r->method() === 'GET' && str_contains($url, '/v2/recordings')) {
                if (str_contains(urldecode($url), 'filter[call_leg_id]=leg-1')) {
                    return Http::response(['data' => [['id' => 'rec-conv', 'to' => 'agent@' . self::OURS . '.sip.telnyx.com']], 'meta' => ['total_pages' => 1]]);
                }

                return Http::response(['data' => [
                    ['id' => 'rec-ours', 'to' => 'agent@' . self::OURS . '.sip.telnyx.com'],
                    ['id' => 'rec-other', 'to' => 'agent@assistant-someone-else.sip.telnyx.com'],
                    ['id' => 'rec-number', 'to' => '+447457401955'],
                ], 'meta' => ['total_pages' => 1]]);
            }

            return Http::response([], 200);
        });
    }

    public function test_telnyx_purge_dry_run_deletes_nothing(): void
    {
        $this->fakeTelnyxStore();
        $svc = new VoxraMediaPurgeService(app(TelnyxConvaiService::class));

        [$convs, $recs] = $svc->purgeTelnyx([self::OURS], Carbon::now()->subDays(10), [], true, 100);

        $this->assertSame(1, $convs);
        $this->assertSame(2, $recs); // rec-conv via the conversation + rec-ours by target
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_telnyx_purge_deletes_only_our_assistants_media(): void
    {
        $this->fakeTelnyxStore();
        $svc = new VoxraMediaPurgeService(app(TelnyxConvaiService::class));

        $svc->purgeTelnyx([self::OURS], Carbon::now()->subDays(10), [], false, 100);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v2/ai/conversations/conv-old'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v2/recordings/rec-conv'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v2/recordings/rec-ours'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'rec-other'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'rec-number'));
    }

    public function test_telnyx_age_filter_is_sent(): void
    {
        $this->fakeTelnyxStore();
        $svc = new VoxraMediaPurgeService(app(TelnyxConvaiService::class));
        Carbon::setTestNow('2026-12-31 12:00:00');

        $svc->purgeTelnyx([self::OURS], Carbon::now()->subDays(10), [], true, 100);

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'created_at=lt.2026-12-21T12:00:00Z')
            && str_contains(urldecode($r->url()), 'metadata->assistant_id=eq.' . self::OURS));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filter[created_at][lte]=2026-12-21T12:00:00Z'));
        Carbon::setTestNow();
    }

    public function test_audio_retention_defaults_to_ten_days(): void
    {
        // Privacy policy: call audio kept 10 days (confirmed 2026-09-25).
        $this->assertSame(10, (int) config('services.voxra.recording_retention_days'));

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'voxra:purge-media'));
        $this->assertCount(1, $events);
        $this->assertStringContainsString('--days=10', (string) $events->first()->command);
    }
}
