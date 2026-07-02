<?php

namespace App\Services;

use App\Models\DefaultSettings;
use App\Models\DomainSettings;

class RecordingWebhookConfigService
{
    const CATEGORY = 'recording_webhook';

    protected array $subcategories = [
        'enabled',
        'url',
        'secret',
        'url_ttl',
        'directions',
    ];

    /**
     * Effective settings for a domain: per-key domain override on top of defaults.
     * Returns null unless enabled with a usable url + secret.
     */
    public function getSettingsForDomain(?string $domainUuid): ?array
    {
        $settings = $this->getDefaultSettings();

        if ($domainUuid) {
            $settings = array_merge($settings, $this->getDomainSettings($domainUuid));
        }

        return $this->normalize($settings);
    }

    /**
     * Map of domain_uuid => effective settings for every domain that has the
     * webhook explicitly enabled at domain level.
     */
    public function getEnabledDomainConfigs(): array
    {
        $defaults = $this->getDefaultSettings();

        $rows = DomainSettings::query()
            ->where('domain_setting_category', self::CATEGORY)
            ->whereIn('domain_setting_subcategory', $this->subcategories)
            ->where('domain_setting_enabled', true)
            ->get([
                'domain_uuid',
                'domain_setting_subcategory',
                'domain_setting_value',
            ]);

        $configs = [];

        foreach ($rows->groupBy('domain_uuid') as $domainUuid => $groupedRows) {
            $flat = $defaults;

            foreach ($groupedRows as $row) {
                if ($row->domain_setting_value !== null && $row->domain_setting_value !== '') {
                    $flat[$row->domain_setting_subcategory] = $row->domain_setting_value;
                }
            }

            if ($normalized = $this->normalize($flat)) {
                $configs[$domainUuid] = $normalized;
            }
        }

        return $configs;
    }

    protected function normalize(array $settings): ?array
    {
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $url = trim($settings['url'] ?? '');
        $secret = trim($settings['secret'] ?? '');

        if (!$enabled || $url === '' || $secret === '') {
            return null;
        }

        $directions = array_values(array_filter(array_map(
            'trim',
            explode(',', strtolower($settings['directions'] ?? 'inbound,outbound,local'))
        )));

        return [
            'url' => $url,
            'secret' => $secret,
            'url_ttl' => max(60, (int) ($settings['url_ttl'] ?? 3600)),
            'directions' => $directions ?: ['inbound', 'outbound', 'local'],
        ];
    }

    protected function getDomainSettings(string $domainUuid): array
    {
        return DomainSettings::query()
            ->where('domain_uuid', $domainUuid)
            ->where('domain_setting_category', self::CATEGORY)
            ->whereIn('domain_setting_subcategory', $this->subcategories)
            ->where('domain_setting_enabled', true)
            ->pluck('domain_setting_value', 'domain_setting_subcategory')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->toArray();
    }

    protected function getDefaultSettings(): array
    {
        return DefaultSettings::query()
            ->where('default_setting_category', self::CATEGORY)
            ->whereIn('default_setting_subcategory', $this->subcategories)
            ->where('default_setting_enabled', true)
            ->pluck('default_setting_value', 'default_setting_subcategory')
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->toArray();
    }
}
