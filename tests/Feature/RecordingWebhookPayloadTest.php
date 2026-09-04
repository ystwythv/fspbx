<?php

namespace Tests\Feature;

use App\Jobs\SendRecordingWebhook;
use App\Models\CDR;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\RecordingWebhookDelivery;
use App\Services\CallRecordingUrlService;
use App\Services\RecordingWebhookConfigService;
use Tests\TestCase;

/**
 * recording.available / recording.archived payload contract (#107):
 * the storage block lets a receiver that owns the bucket keep a durable
 * pointer instead of a time-limited URL.
 */
class RecordingWebhookPayloadTest extends TestCase
{
    private function cdr(string $recordPath, string $recordName): CDR
    {
        $cdr = new CDR();
        $cdr->setRawAttributes([
            'xml_cdr_uuid' => 'cdr-uuid-1',
            'domain_uuid' => 'dom-uuid-1',
            'direction' => 'inbound',
            'caller_id_name' => 'Dave',
            'caller_id_number' => '+447911123456',
            'caller_destination' => '01234567890',
            'destination_number' => '01234567890',
            'start_stamp' => '2026-09-04 09:00:12',
            'end_stamp' => '2026-09-04 09:03:00',
            'duration' => 168,
            'billsec' => 160,
            'hangup_cause' => 'NORMAL_CLEARING',
            'record_path' => $recordPath,
            'record_name' => $recordName,
        ]);

        $domain = new Domain();
        $domain->setRawAttributes(['domain_uuid' => 'dom-uuid-1', 'domain_name' => 'acme.example']);
        $cdr->setRelation('domain', $domain);

        $ext = new Extensions();
        $ext->setRawAttributes(['extension' => '201', 'effective_caller_id_name' => 'Front Desk']);
        $cdr->setRelation('extension', $ext);

        return $cdr;
    }

    private function delivery(string $event): RecordingWebhookDelivery
    {
        $delivery = new RecordingWebhookDelivery();
        $delivery->setRawAttributes(['uuid' => 'del-uuid-1', 'event' => $event]);

        return $delivery;
    }

    public function test_available_payload_for_local_recording(): void
    {
        $urls = [
            'audio_url' => 'https://pbx.example/cdrs/cdr-uuid-1/stream?signature=abc',
            'download_url' => 'https://pbx.example/cdrs/cdr-uuid-1/download?signature=abc',
            'filename' => 'cdr-uuid-1.wav',
            'storage' => ['type' => 'local'],
        ];

        $payload = (new SendRecordingWebhook('del-uuid-1'))->buildPayload(
            RecordingWebhookConfigService::EVENT_AVAILABLE,
            $this->delivery('recording.available'),
            $this->cdr('/var/lib/freeswitch/recordings/acme.example/archive/2026/Sep/04', 'cdr-uuid-1.wav'),
            $urls,
            3600
        );

        $this->assertSame('recording.available', $payload['event']);
        $this->assertSame('cdr-uuid-1', $payload['cdr_uuid']);
        $this->assertSame('acme.example', $payload['domain']);
        $this->assertSame('201', $payload['extension']);
        $this->assertSame('Front Desk', $payload['extension_name']);
        $this->assertSame(160, $payload['billsec']);
        $this->assertSame('wav', $payload['recording']['format']);
        $this->assertSame(['type' => 'local'], $payload['recording']['storage']);
        $this->assertSame($urls['audio_url'], $payload['recording']['url']);
    }

    public function test_archived_payload_carries_s3_storage_block_without_credentials(): void
    {
        $settings = [
            'key' => 'AKIA-SECRET',
            'secret' => 'shh',
            'bucket' => 'customer-bucket',
            'region' => 'eu-west-2',
            'type' => 'custom',
        ];
        $key = 'recordings/2026/09/04/090012_inbound_+447911123456_01234567890.mp3';

        $urls = [
            'audio_url' => 'https://customer-bucket.s3.eu-west-2.amazonaws.com/' . $key . '?X-Amz-Signature=1',
            'download_url' => 'https://customer-bucket.s3.eu-west-2.amazonaws.com/' . $key . '?X-Amz-Signature=2',
            'filename' => basename($key),
            'storage' => CallRecordingUrlService::s3Storage($settings, $key),
        ];

        $payload = (new SendRecordingWebhook('del-uuid-1'))->buildPayload(
            RecordingWebhookConfigService::EVENT_ARCHIVED,
            $this->delivery('recording.archived'),
            $this->cdr('S3', $key),
            $urls,
            3600
        );

        $this->assertSame('recording.archived', $payload['event']);
        $this->assertSame('mp3', $payload['recording']['format']);
        $this->assertSame([
            'type' => 's3',
            'bucket' => 'customer-bucket',
            'key' => $key,
            'endpoint' => 'https://s3.eu-west-2.amazonaws.com',
            'region' => 'eu-west-2',
        ], $payload['recording']['storage']);

        $json = json_encode($payload);
        $this->assertStringNotContainsString('AKIA-SECRET', $json);
        $this->assertStringNotContainsString('shh', $json);
    }

    public function test_custom_endpoint_is_reported_verbatim(): void
    {
        $storage = CallRecordingUrlService::s3Storage([
            'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'region' => 'auto',
            'endpoint' => 'https://abc123.r2.cloudflarestorage.com', 'type' => 'custom',
        ], 'recordings/x.mp3');

        $this->assertSame('https://abc123.r2.cloudflarestorage.com', $storage['endpoint']);
        $this->assertSame('auto', $storage['region']);
    }
}
