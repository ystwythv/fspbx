<?php

namespace Tests\Unit\Recordings;

use App\Services\RecordingArchiveService;
use App\Services\S3StorageConfigService;
use PHPUnit\Framework\TestCase;

class RecordingArchiveObjectKeyTest extends TestCase
{
    private function service(): RecordingArchiveService
    {
        return new RecordingArchiveService(new S3StorageConfigService());
    }

    private function rec(): object
    {
        return (object) [
            'domain_name' => 'acme.example',
            'start_stamp' => '2026-09-04 09:00:12',
            'direction' => 'inbound',
            'caller_id_number' => '+447911123456',
            'caller_destination' => '01234 567890',
        ];
    }

    public function test_custom_bucket_key_layout_omits_domain(): void
    {
        $key = $this->service()->buildObjectKey($this->rec(), ['type' => 'custom'], '/tmp/x.mp3', 'UTC');

        $this->assertSame('recordings/2026/09/04/090012_inbound_+447911123456_01234_567890.mp3', $key);
    }

    public function test_default_bucket_key_layout_is_prefixed_by_domain(): void
    {
        $key = $this->service()->buildObjectKey($this->rec(), ['type' => 'default'], '/tmp/x.mp3', 'UTC');

        $this->assertStringStartsWith('acme.example/2026/09/04/', $key);
    }

    public function test_key_uses_domain_timezone(): void
    {
        $key = $this->service()->buildObjectKey($this->rec(), ['type' => 'custom'], '/tmp/x.mp3', 'Europe/London');

        $this->assertStringContainsString('/100012_inbound_', $key);
    }

    public function test_sanitize_replaces_unsafe_characters(): void
    {
        $this->assertSame('unknown', $this->service()->sanitizePathSegment(''));
        $this->assertSame('a_b', $this->service()->sanitizePathSegment('a/b'));
    }
}
