<?php

namespace Tests\Integration\Voxra;

use App\Services\Voxra\VoxraMediaPurgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\CdrIntegrationTestCase;

/**
 * voxra:purge-media (voxragtm#83) applies the 10-day audio retention to
 * Voxra domains only. The PBX also carries other customers (iqmobile.uk,
 * tel.et, reseller domains) whose recordings and voicemail must be left
 * exactly as they are, including files filed under their directories.
 */
class VoxraMediaPurgeScopeTest extends CdrIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('TRUNCATE v_voicemails, v_voicemail_messages');
        config([
            'services.voxra.retention_extra_domains' => 'lon1.voxra.uk',
            'services.voxra.retention_pbx_domains' => '',
        ]);
    }

    private function domain(string $name, ?string $description): string
    {
        $uuid = $this->makeDomain($name);
        DB::table('v_domains')->where('domain_uuid', $uuid)->update(['domain_description' => $description]);

        return $uuid;
    }

    private function cdr(string $domainUuid, string $dir, int $daysAgo): string
    {
        $uuid = (string) Str::uuid();
        DB::table('v_xml_cdr')->insert([
            'xml_cdr_uuid' => $uuid,
            'domain_uuid' => $domainUuid,
            'start_stamp' => now()->subDays($daysAgo),
            'record_path' => VoxraMediaPurgeService::RECORDINGS_ROOT . '/' . $dir . '/archive/2026/Sep/01',
            'record_name' => $uuid . '.wav',
        ]);

        return $uuid;
    }

    private function voicemail(string $domainUuid, int $daysAgo): string
    {
        $box = (string) Str::uuid();
        DB::table('v_voicemails')->insert(['voicemail_uuid' => $box, 'domain_uuid' => $domainUuid, 'voicemail_id' => '100']);
        $msg = (string) Str::uuid();
        DB::table('v_voicemail_messages')->insert([
            'voicemail_message_uuid' => $msg,
            'domain_uuid' => $domainUuid,
            'voicemail_uuid' => $box,
            'created_epoch' => now()->subDays($daysAgo)->getTimestamp(),
        ]);

        return $msg;
    }

    private function recordingKept(string $cdr): bool
    {
        return DB::table('v_xml_cdr')->where('xml_cdr_uuid', $cdr)->value('record_name') !== null;
    }

    public function test_only_voxra_domains_are_purged(): void
    {
        $voxra = $this->domain('acme.voxra.uk', 'voxra-tenant:t-1');
        $platform = $this->domain('lon1.voxra.uk', 'WhatsApp Business Calling digest realm');
        $iqmobile = $this->domain('iqmobile.uk', 'IQ Mobile');
        $telet = $this->domain('tel.et', null);

        $voxraOld = $this->cdr($voxra, 'acme.voxra.uk', 11);
        $voxraNew = $this->cdr($voxra, 'acme.voxra.uk', 9);
        $platformOld = $this->cdr($platform, 'lon1.voxra.uk', 11);
        // Voxra call recorded under a non-Voxra customer's directory: left alone.
        $platformForeign = $this->cdr($platform, 'iqmobile.uk', 11);
        $iqOld = $this->cdr($iqmobile, 'iqmobile.uk', 400);
        $telOld = $this->cdr($telet, 'tel.et', 400);
        $vmVoxra = $this->voicemail($voxra, 11);
        $vmIq = $this->voicemail($iqmobile, 400);

        $svc = new VoxraMediaPurgeService(null);
        $names = array_map(fn ($d) => $d->domain_name, $svc->domains());
        sort($names);
        $this->assertSame(['acme.voxra.uk', 'lon1.voxra.uk'], $names);

        $dry = $svc->purge(['scope' => 'age', 'days' => 10, 'dry_run' => true, 'telnyx' => false]);
        $this->assertSame(2, $dry['counts']['pbx_recordings']);
        $keys = array_keys($dry['by_domain']);
        sort($keys);
        $this->assertSame(['acme.voxra.uk', 'lon1.voxra.uk'], $keys, 'per-domain counts cover Voxra domains only');
        $this->assertTrue($this->recordingKept($voxraOld), 'dry run deletes nothing');

        $res = $svc->purge(['scope' => 'age', 'days' => 10, 'telnyx' => false]);

        $this->assertSame(0, $res['counts']['errors']);
        $this->assertSame(1, $res['counts']['pbx_recordings_skipped_non_voxra_dir']);
        $this->assertFalse($this->recordingKept($voxraOld));
        $this->assertFalse($this->recordingKept($platformOld));
        $this->assertTrue($this->recordingKept($voxraNew), 'inside the 10 days');
        $this->assertTrue($this->recordingKept($platformForeign), 'file in a non-Voxra directory');
        $this->assertTrue($this->recordingKept($iqOld), 'iqmobile.uk untouched');
        $this->assertTrue($this->recordingKept($telOld), 'tel.et untouched');
        $this->assertFalse(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmVoxra)->exists());
        $this->assertTrue(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmIq)->exists(), 'iqmobile.uk voicemail untouched');
    }

    public function test_pbx_only_domain_keeps_its_own_90_days(): void
    {
        // voxragtm#132: iqmobile.uk joins the sweep at 90 days, never 10.
        config(['services.voxra.retention_pbx_domains' => 'iqmobile.uk', 'services.voxra.retention_pbx_days' => 90]);
        $voxra = $this->domain('acme.voxra.uk', 'voxra-tenant:t-1');
        $platform = $this->domain('lon1.voxra.uk', 'WhatsApp Business Calling digest realm');
        $iqmobile = $this->domain('iqmobile.uk', 'IQ Mobile');
        $telet = $this->domain('tel.et', null);

        $voxraOld = $this->cdr($voxra, 'acme.voxra.uk', 11);
        $platformForeign = $this->cdr($platform, 'iqmobile.uk', 11);
        $iqOld = $this->cdr($iqmobile, 'iqmobile.uk', 100);
        $iqRecent = $this->cdr($iqmobile, 'iqmobile.uk', 50);
        $telOld = $this->cdr($telet, 'tel.et', 400);
        $vmIqOld = $this->voicemail($iqmobile, 100);
        $vmIqRecent = $this->voicemail($iqmobile, 50);

        $res = (new VoxraMediaPurgeService(null))->purge(['scope' => 'age', 'days' => 10, 'telnyx' => false]);

        $this->assertSame(0, $res['counts']['errors']);
        $this->assertSame(3, $res['counts']['domains']);
        $this->assertFalse($this->recordingKept($voxraOld));
        $this->assertFalse($this->recordingKept($iqOld), 'iqmobile.uk past 90 days');
        $this->assertTrue($this->recordingKept($iqRecent), 'iqmobile.uk inside 90 days, though past 10');
        $this->assertTrue($this->recordingKept($platformForeign), 'Voxra file in iqmobile.uk directory still left');
        $this->assertTrue($this->recordingKept($telOld), 'tel.et untouched');
        $this->assertFalse(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmIqOld)->exists());
        $this->assertTrue(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmIqRecent)->exists());
    }
}
