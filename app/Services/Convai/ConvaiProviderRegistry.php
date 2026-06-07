<?php

namespace App\Services\Convai;

use RuntimeException;

class ConvaiProviderRegistry
{
    public const DEFAULT = 'elevenlabs';

    /** @var array<string, class-string<ConvaiProviderInterface>> */
    private const PROVIDERS = [
        'elevenlabs' => ElevenLabsConvaiProvider::class,
        'telnyx'     => TelnyxConvaiProvider::class,
    ];

    public function resolve(?string $name): ConvaiProviderInterface
    {
        $name = $name ?: self::DEFAULT;

        if (!isset(self::PROVIDERS[$name])) {
            throw new RuntimeException("Unknown conversational AI provider: {$name}");
        }

        return app(self::PROVIDERS[$name]);
    }

    /**
     * Providers available for new agents, for the UI.
     * A provider is offered only when its API key is configured.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function available(): array
    {
        $options = [];

        if ((string) config('services.elevenlabs.api_key', '') !== '') {
            $options[] = ['value' => 'elevenlabs', 'label' => 'ElevenLabs'];
        }
        if ((string) config('services.telnyx.api_key', '') !== '') {
            $options[] = ['value' => 'telnyx', 'label' => 'Telnyx'];
        }

        return $options;
    }
}
