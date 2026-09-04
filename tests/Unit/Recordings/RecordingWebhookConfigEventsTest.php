<?php

namespace Tests\Unit\Recordings;

use App\Services\RecordingWebhookConfigService;
use PHPUnit\Framework\TestCase;

class RecordingWebhookConfigEventsTest extends TestCase
{
    private function normalize(array $settings): ?array
    {
        $svc = new RecordingWebhookConfigService();
        $m = new \ReflectionMethod($svc, 'normalize');
        $m->setAccessible(true);

        return $m->invoke($svc, $settings);
    }

    private function base(array $extra = []): array
    {
        return array_merge(['enabled' => 'true', 'url' => 'https://r.example/hook', 'secret' => 'abc'], $extra);
    }

    public function test_events_default_to_available_only(): void
    {
        $this->assertSame(['recording.available'], $this->normalize($this->base())['events']);
    }

    public function test_events_accept_archived_and_ignore_unknown(): void
    {
        $cfg = $this->normalize($this->base(['events' => ' Recording.Available, recording.archived ,bogus']));

        $this->assertSame(['recording.available', 'recording.archived'], $cfg['events']);
    }

    public function test_archived_only_is_allowed(): void
    {
        $cfg = $this->normalize($this->base(['events' => 'recording.archived']));

        $this->assertSame(['recording.archived'], $cfg['events']);
    }

    public function test_all_unknown_falls_back_to_available(): void
    {
        $cfg = $this->normalize($this->base(['events' => 'nope']));

        $this->assertSame(['recording.available'], $cfg['events']);
    }
}
