<?php

namespace Tests\Integration\Voxra;

use App\Services\Voxra\VoxraMediaPurgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\CdrIntegrationTestCase;

/**
 * voxra:purge-media (voxragtm#83) applies the 10-day audio retention to
 * Voxra customers only. The PBX also carries other customers (iqmobile.uk,
 * tel.et, reseller domains) and the lon1/eu1.voxra.uk WhatsApp Business
 * Calling realms, whose calls are IQ Mobile's and are recorded under
 * recordings/iqmobile.uk/. Their recordings, files and voicemail must be left
 * exactly as they are.
 */
class VoxraMediaPurgeScopeTest extends CdrIntegrationTestCase
{
    private string $root;
    private string $rec;
    private string $vm;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('TRUNCATE v_voicemails, v_voicemail_messages');
        config([
            'services.voxra.retention_extra_domains' => '',
            'services.voxra.retention_pbx_domains' => '',
        ]);
        $this->root = sys_get_temp_dir() . '/voxra-purge-' . Str::random(8);
        $this->rec = $this->root . '/recordings';
        $this->vm = $this->root . '/voicemail';
        mkdir($this->rec, 0777, true);
        mkdir($this->vm, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function svc(): VoxraMediaPurgeService
    {
        return (new VoxraMediaPurgeService(null))->withRoots($this->rec, $this->vm);
    }

    private function domain(string $name, ?string $description): string
    {
        $uuid = $this->makeDomain($name);
        DB::table('v_domains')->where('domain_uuid', $uuid)->update(['domain_description' => $description]);

        return $uuid;
    }

    /** A CDR with a recording file on disk under recordings/<dir>/archive/…. */
    private function cdr(string $domainUuid, string $dir, int $daysAgo): string
    {
        $uuid = (string) Str::uuid();
        $path = $this->rec . '/' . $dir . '/archive/2026/Sep/01';
        @mkdir($path, 0777, true);
        touch($path . '/' . $uuid . '.wav', now()->subDays($daysAgo)->getTimestamp());
        DB::table('v_xml_cdr')->insert([
            'xml_cdr_uuid' => $uuid,
            'domain_uuid' => $domainUuid,
            'start_stamp' => now()->subDays($daysAgo),
            'record_path' => $path,
            'record_name' => $uuid . '.wav',
        ]);

        return $uuid;
    }

    private function fileOf(string $cdr, string $dir): string
    {
        return $this->rec . '/' . $dir . '/archive/2026/Sep/01/' . $cdr . '.wav';
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

    private function names(array $domains): array
    {
        $names = array_map(fn ($d) => $d->domain_name, $domains);
        sort($names);

        return $names;
    }

    public function test_only_voxra_tenant_domains_are_purged(): void
    {
        $voxra = $this->domain('acme.voxra.uk', 'voxra-tenant:t-1');
        $wa = $this->domain('lon1.voxra.uk', 'WhatsApp Business Calling digest realm for this node. Managed by ansible whatsapp-sip.yml.');
        $iqmobile = $this->domain('iqmobile.uk', 'iqmobile');
        $telet = $this->domain('tel.et', null);
        $reseller = $this->domain('ry-abc.voxra.uk', 'reseller:88 Greenhouse Partners Limited');

        $voxraOld = $this->cdr($voxra, 'acme.voxra.uk', 11);
        $voxraNew = $this->cdr($voxra, 'acme.voxra.uk', 9);
        // WhatsApp calling: CDR on lon1.voxra.uk, file under iqmobile.uk/.
        $waOld = $this->cdr($wa, 'iqmobile.uk', 11);
        $iqOld = $this->cdr($iqmobile, 'iqmobile.uk', 400);
        $telOld = $this->cdr($telet, 'tel.et', 400);
        $resOld = $this->cdr($reseller, 'ry-abc.voxra.uk', 400);
        $vmVoxra = $this->voicemail($voxra, 11);
        $vmIq = $this->voicemail($iqmobile, 400);
        $vmWa = $this->voicemail($wa, 400);

        $svc = $this->svc();
        $this->assertSame(['acme.voxra.uk'], $this->names($svc->domains()));

        $dry = $svc->purge(['scope' => 'age', 'days' => 10, 'dry_run' => true, 'telnyx' => false]);
        $this->assertSame(1, $dry['counts']['pbx_recordings']);
        $this->assertSame(['acme.voxra.uk'], array_keys($dry['by_domain']), 'per-domain counts cover Voxra tenants only');
        $this->assertTrue($this->recordingKept($voxraOld), 'dry run deletes nothing');
        $this->assertFileExists($this->fileOf($voxraOld, 'acme.voxra.uk'));

        $res = $svc->purge(['scope' => 'age', 'days' => 10, 'telnyx' => false]);

        $this->assertSame(0, $res['counts']['errors']);
        $this->assertFalse($this->recordingKept($voxraOld));
        $this->assertFileDoesNotExist($this->fileOf($voxraOld, 'acme.voxra.uk'));
        $this->assertTrue($this->recordingKept($voxraNew), 'inside the 10 days');
        $this->assertFileExists($this->fileOf($voxraNew, 'acme.voxra.uk'));

        $this->assertTrue($this->recordingKept($waOld), 'lon1.voxra.uk WhatsApp CDR untouched');
        $this->assertFileExists($this->fileOf($waOld, 'iqmobile.uk'));
        $this->assertTrue($this->recordingKept($iqOld), 'iqmobile.uk untouched');
        $this->assertFileExists($this->fileOf($iqOld, 'iqmobile.uk'));
        $this->assertTrue($this->recordingKept($telOld), 'tel.et untouched');
        $this->assertFileExists($this->fileOf($telOld, 'tel.et'));
        $this->assertTrue($this->recordingKept($resOld), 'reseller domain untouched');
        $this->assertFileExists($this->fileOf($resOld, 'ry-abc.voxra.uk'));

        $this->assertFalse(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmVoxra)->exists());
        $this->assertTrue(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmIq)->exists(), 'iqmobile.uk voicemail untouched');
        $this->assertTrue(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmWa)->exists(), 'lon1.voxra.uk voicemail untouched');
    }

    public function test_whatsapp_realm_and_reseller_domains_refused_even_if_configured(): void
    {
        $this->domain('acme.voxra.uk', 'voxra-tenant:t-1');
        $this->domain('lon1.voxra.uk', 'WhatsApp Business Calling digest realm for this node. Managed by ansible whatsapp-sip.yml.');
        $this->domain('eu1.voxra.uk', 'WhatsApp Business Calling digest realm for this node. Managed by ansible whatsapp-sip.yml.');
        $this->domain('ry-abc.voxra.uk', 'reseller:88 Greenhouse Partners Limited');
        $this->domain('demo.voxra.uk', 'Voxra demo line');
        config(['services.voxra.retention_extra_domains' => 'lon1.voxra.uk,eu1.voxra.uk,ry-abc.voxra.uk,demo.voxra.uk']);

        $this->assertSame(['acme.voxra.uk', 'demo.voxra.uk'], $this->names($this->svc()->domains()));
    }

    public function test_voxra_cdr_with_file_in_non_voxra_directory_is_skipped_not_an_error(): void
    {
        $voxra = $this->domain('acme.voxra.uk', 'voxra-tenant:t-1');
        $this->domain('bravo.voxra.uk', 'voxra-tenant:t-2');
        $this->domain('iqmobile.uk', 'iqmobile');

        $foreign = $this->cdr($voxra, 'iqmobile.uk', 11);
        // Filed under another Voxra tenant's directory, no non-Voxra CDR: fine.
        $crossVoxra = $this->cdr($voxra, 'bravo.voxra.uk', 11);

        $res = $this->svc()->purge(['scope' => 'age', 'days' => 10, 'telnyx' => false]);

        $this->assertSame(0, $res['counts']['errors'], 'nightly run stays green');
        $this->assertSame(1, $res['counts']['pbx_recordings_skipped_non_voxra_dir']);
        $this->assertTrue($this->recordingKept($foreign));
        $this->assertFileExists($this->fileOf($foreign, 'iqmobile.uk'), 'never delete under a non-Voxra directory');
        $this->assertFalse($this->recordingKept($crossVoxra));
        $this->assertFileDoesNotExist($this->fileOf($crossVoxra, 'bravo.voxra.uk'));
    }

    public function test_file_shared_with_a_non_voxra_cdr_is_kept(): void
    {
        $voxra = $this->domain('acme.voxra.uk', 'voxra-tenant:t-1');
        $this->domain('bravo.voxra.uk', 'voxra-tenant:t-2');
        $iq = $this->domain('iqmobile.uk', 'iqmobile');

        // Voxra CDR pointing into another Voxra tenant's tree, but an
        // IQ Mobile CDR references the same file: leave it.
        $shared = $this->cdr($voxra, 'bravo.voxra.uk', 11);
        DB::table('v_xml_cdr')->insert([
            'xml_cdr_uuid' => (string) Str::uuid(),
            'domain_uuid' => $iq,
            'start_stamp' => now()->subDays(11),
            'record_path' => $this->rec . '/bravo.voxra.uk/archive/2026/Sep/01',
            'record_name' => $shared . '.wav',
        ]);

        $res = $this->svc()->purge(['scope' => 'age', 'days' => 10, 'telnyx' => false]);

        $this->assertSame(0, $res['counts']['errors']);
        $this->assertTrue($this->recordingKept($shared));
        $this->assertFileExists($this->fileOf($shared, 'bravo.voxra.uk'), 'orphan sweep also leaves it');
    }

    public function test_pbx_only_domain_keeps_its_own_90_days(): void
    {
        // voxragtm#132: iqmobile.uk joins the sweep at 90 days, never 10.
        config(['services.voxra.retention_pbx_domains' => 'iqmobile.uk', 'services.voxra.retention_pbx_days' => 90]);
        $voxra = $this->domain('acme.voxra.uk', 'voxra-tenant:t-1');
        $wa = $this->domain('lon1.voxra.uk', 'WhatsApp Business Calling digest realm for this node. Managed by ansible whatsapp-sip.yml.');
        $iqmobile = $this->domain('iqmobile.uk', 'iqmobile');
        $telet = $this->domain('tel.et', null);

        $voxraOld = $this->cdr($voxra, 'acme.voxra.uk', 11);
        // WhatsApp call (IQ Mobile's), 11 days old: past Voxra's 10, inside IQ Mobile's 90.
        $waRecent = $this->cdr($wa, 'iqmobile.uk', 11);
        $iqOld = $this->cdr($iqmobile, 'iqmobile.uk', 100);
        $iqRecent = $this->cdr($iqmobile, 'iqmobile.uk', 50);
        $telOld = $this->cdr($telet, 'tel.et', 400);
        $vmIqOld = $this->voicemail($iqmobile, 100);
        $vmIqRecent = $this->voicemail($iqmobile, 50);

        $res = $this->svc()->purge(['scope' => 'age', 'days' => 10, 'telnyx' => false]);

        $this->assertSame(0, $res['counts']['errors']);
        $this->assertSame(2, $res['counts']['domains'], 'acme.voxra.uk + iqmobile.uk; never lon1.voxra.uk');
        $this->assertFalse($this->recordingKept($voxraOld));
        $this->assertFalse($this->recordingKept($iqOld), 'iqmobile.uk past 90 days');
        $this->assertTrue($this->recordingKept($iqRecent), 'iqmobile.uk inside 90 days, though past 10');
        $this->assertFileExists($this->fileOf($iqRecent, 'iqmobile.uk'));
        $this->assertTrue($this->recordingKept($waRecent), 'lon1.voxra.uk WhatsApp CDR untouched');
        $this->assertFileExists($this->fileOf($waRecent, 'iqmobile.uk'), 'not swept at the Voxra 10 days');
        $this->assertTrue($this->recordingKept($telOld), 'tel.et untouched');
        $this->assertFalse(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmIqOld)->exists());
        $this->assertTrue(DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $vmIqRecent)->exists());
    }
}
