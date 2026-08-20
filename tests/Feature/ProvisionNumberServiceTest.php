<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\Destinations;
use App\Models\Domain;
use App\Services\ProvisionNumberService;
use App\Services\TelnyxNumberService;
use Mockery;
use Tests\TestCase;

/**
 * ProvisionNumberService guards (voxragtm#23 review fixes): re-provision must
 * never order a second paid DID, owner_mobile is normalised to E.164, and
 * ring-first refuses numbers hosted on this PBX (PSTN loop). DB lookups are
 * mocked so these run without a Postgres test database.
 */
class ProvisionNumberServiceTest extends TestCase
{
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

    public function test_order_and_route_short_circuits_when_reception_destination_exists(): void
    {
        config()->set('services.voxra.provision_order_number', true);
        config()->set('services.voxra.number_connection_id', 'conn_1');

        $existing = new Destinations();
        $existing->setRawAttributes(['destination_number' => '+441134960123']);

        // Any Telnyx use (search/order) would resolve the service — forbid it.
        $this->app->bind(TelnyxNumberService::class, function () {
            throw new \RuntimeException('Telnyx must not be called when a reception destination already exists');
        });

        $svc = Mockery::mock(ProvisionNumberService::class)->makePartial();
        $svc->shouldReceive('findReceptionDestination')->once()->andReturn($existing);

        $number = $svc->orderAndRoute($this->domain(), $this->agent(), 'rg_approved');

        $this->assertSame('+441134960123', $number);
    }

    /** @dataProvider ownerMobileProvider */
    public function test_owner_mobile_normalisation(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, ProvisionNumberService::normaliseOwnerMobile($raw));
    }

    public static function ownerMobileProvider(): array
    {
        return [
            'gb national'             => ['07700 900123', '+447700900123'],
            'gb national no spaces'   => ['07700900123', '+447700900123'],
            'e164 with spaces'        => ['+44 7700 900 123', '+447700900123'],
            'international 00 prefix' => ['00447700900123', '+447700900123'],
            'already e164'            => ['+15551234567', '+15551234567'],
            'punctuation stripped'    => ['(07700) 900-123', '+447700900123'],
            'not a number'            => ['ring me maybe', null],
            'too short'               => ['+12', null],
            'too long'                => ['+4477009001234567890', null],
            'empty'                   => ['', null],
            'null'                    => [null, null],
        ];
    }

    public function test_ring_first_refused_when_mobile_is_hosted_on_this_pbx(): void
    {
        $svc = Mockery::mock(ProvisionNumberService::class)->makePartial();
        $svc->shouldReceive('isHostedNumber')->once()->with('+447700900123')->andReturn(true);

        $this->assertNull($svc->resolveRingFirstMobile($this->domain(), true, '07700 900123'));
    }

    public function test_ring_first_allows_a_mobile_not_hosted_here(): void
    {
        $svc = Mockery::mock(ProvisionNumberService::class)->makePartial();
        $svc->shouldReceive('isHostedNumber')->once()->with('+447700900123')->andReturn(false);

        $this->assertSame('+447700900123', $svc->resolveRingFirstMobile($this->domain(), true, '07700 900123'));
    }

    public function test_ring_first_off_or_unusable_mobile_resolves_to_agent_only(): void
    {
        $svc = Mockery::mock(ProvisionNumberService::class)->makePartial();
        $svc->shouldReceive('isHostedNumber')->never();

        $this->assertNull($svc->resolveRingFirstMobile($this->domain(), false, '07700 900123'));
        $this->assertNull($svc->resolveRingFirstMobile($this->domain(), true, 'not-a-number'));
        $this->assertNull($svc->resolveRingFirstMobile($this->domain(), true, null));
    }

    public function test_ring_first_bridge_carries_answer_confirmation(): void
    {
        $actions = (new ProvisionNumberService())
            ->ringFirstActions($this->domain(), $this->agent(), '+447700900123');

        $bridge = collect($actions)->firstWhere('destination_app', 'bridge');
        $this->assertNotNull($bridge);
        // Press-1-to-accept so mobile voicemail can't swallow the call.
        $this->assertStringContainsString('group_confirm_key=1', $bridge['destination_data']);
        $this->assertStringContainsString('group_confirm_file=ivr/ivr-accept_reject_voicemail.wav', $bridge['destination_data']);
        $this->assertStringContainsString('group_confirm_cancel_timeout=1', $bridge['destination_data']);
        $this->assertStringContainsString('loopback/+447700900123/acme.voxra.uk', $bridge['destination_data']);

        // Unconfirmed/unanswered falls through to the agent transfer.
        $last = end($actions);
        $this->assertSame('transfer', $last['destination_app']);
        $this->assertSame('9250 XML acme.voxra.uk', $last['destination_data']);
    }
}
