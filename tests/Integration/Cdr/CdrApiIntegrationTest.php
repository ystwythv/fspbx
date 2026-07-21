<?php

namespace Tests\Integration\Cdr;

use App\Models\CDR;
use Tests\Integration\CdrIntegrationTestCase;

/**
 * End-to-end CDR API tests with DB fixtures (issue #12): domain scoping,
 * status filters, window validation, cursor pagination, stats
 * correctness, CSV export and the tenant token roundtrip.
 */
class CdrApiIntegrationTest extends CdrIntegrationTestCase
{
    public function test_tenant_token_cannot_read_another_tenants_cdrs(): void
    {
        $domainA = $this->makeDomain();
        $domainB = $this->makeDomain();
        $userA = $this->makeUser($domainA, ['cdr_api_read']);
        $tokenA = $this->mintToken($userA, $domainA);

        CDR::factory()->inDomain($domainB)->create();

        $response = $this->get(
            "/api/v1/domains/{$domainB}/cdr/calls?" . $this->isoWindowAroundNow(),
            $this->bearer($tokenA)
        );

        $response->assertStatus(403);
    }

    public function test_tenant_list_returns_only_own_domain(): void
    {
        $domainA = $this->makeDomain();
        $domainB = $this->makeDomain();
        $user = $this->makeUser($domainA, ['cdr_api_read']);
        $token = $this->mintToken($user, $domainA);

        $mine = CDR::factory()->inDomain($domainA)->create();
        CDR::factory()->inDomain($domainB)->create();

        $response = $this->get(
            "/api/v1/domains/{$domainA}/cdr/calls?" . $this->isoWindowAroundNow(),
            $this->bearer($token)
        );

        $response->assertStatus(200);
        $uuids = array_column($response->json('data'), 'xml_cdr_uuid');
        $this->assertSame([(string) $mine->xml_cdr_uuid], $uuids);
    }

    public function test_status_filters_return_the_right_subsets(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read']);
        $token = $this->mintToken($user, $domain);

        $expected = [
            'answered' => CDR::factory()->inDomain($domain)->answered()->create(),
            'missed' => CDR::factory()->inDomain($domain)->missed()->create(),
            'voicemail' => CDR::factory()->inDomain($domain)->voicemail()->create(),
            'abandoned' => CDR::factory()->inDomain($domain)->abandoned()->create(),
            'busy' => CDR::factory()->inDomain($domain)->busy()->create(),
            'no_answer' => CDR::factory()->inDomain($domain)->noAnswer()->create(),
            'failed' => CDR::factory()->inDomain($domain)->failed()->create(),
        ];

        foreach ($expected as $status => $cdr) {
            $response = $this->get(
                "/api/v1/domains/{$domain}/cdr/calls?" . $this->isoWindowAroundNow() . "&status={$status}",
                $this->bearer($token)
            );

            $response->assertStatus(200);
            $uuids = array_column($response->json('data'), 'xml_cdr_uuid');
            $this->assertSame(
                [(string) $cdr->xml_cdr_uuid],
                $uuids,
                "status={$status} returned the wrong rows"
            );
            $this->assertSame($status, $response->json('data.0.status'));
        }
    }

    public function test_window_validation(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read']);
        $token = $this->mintToken($user, $domain);

        $base = "/api/v1/domains/{$domain}/cdr/calls";

        // > 30 day window
        $this->get(
            $base . '?date_from=' . gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 86400)
                . '&date_to=' . gmdate('Y-m-d\TH:i:s\Z'),
            $this->bearer($token)
        )->assertStatus(422)->assertJsonPath('error.code', 'window_too_large');

        // date_from beyond the 90 day retention
        $this->get(
            $base . '?date_from=' . gmdate('Y-m-d\TH:i:s\Z', time() - 100 * 86400)
                . '&date_to=' . gmdate('Y-m-d\TH:i:s\Z', time() - 80 * 86400),
            $this->bearer($token)
        )->assertStatus(422)->assertJsonPath('error.code', 'window_too_old');

        // inverted range
        $this->get(
            $base . '?date_from=' . gmdate('Y-m-d\TH:i:s\Z')
                . '&date_to=' . gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
            $this->bearer($token)
        )->assertStatus(422);

        // missing dates
        $this->get($base, $this->bearer($token))
            ->assertStatus(422)->assertJsonPath('error.code', 'parameter_missing');
    }

    public function test_cursor_pagination_with_start_epoch_ties(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read']);
        $token = $this->mintToken($user, $domain);

        $tiedEpoch = time() - 1800;
        $created = CDR::factory()->inDomain($domain)->count(3)->create([
            'start_epoch' => $tiedEpoch,
        ]);

        $base = "/api/v1/domains/{$domain}/cdr/calls?" . $this->isoWindowAroundNow() . '&limit=2';

        $page1 = $this->get($base, $this->bearer($token));
        $page1->assertStatus(200)->assertJsonPath('has_more', true);
        $firstPage = array_column($page1->json('data'), 'xml_cdr_uuid');
        $this->assertCount(2, $firstPage);

        $cursor = end($firstPage);
        $page2 = $this->get($base . '&starting_after=' . $cursor, $this->bearer($token));
        $page2->assertStatus(200)->assertJsonPath('has_more', false);
        $secondPage = array_column($page2->json('data'), 'xml_cdr_uuid');
        $this->assertCount(1, $secondPage);

        $all = array_merge($firstPage, $secondPage);
        $this->assertCount(3, array_unique($all), 'pagination dropped or duplicated rows on tied start_epoch');
        $this->assertEqualsCanonicalizing(
            $created->pluck('xml_cdr_uuid')->map(fn ($u) => (string) $u)->all(),
            $all
        );
    }

    public function test_stats_summary_matches_known_dataset(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read']);
        $token = $this->mintToken($user, $domain);

        CDR::factory()->inDomain($domain)->answered()->create(['billsec' => 60, 'duration' => 65]);
        CDR::factory()->inDomain($domain)->answered()->create(['billsec' => 30, 'duration' => 35]);
        CDR::factory()->inDomain($domain)->missed()->create();
        CDR::factory()->inDomain($domain)->voicemail()->create(['billsec' => 20]);
        CDR::factory()->inDomain($domain)->busy()->create();
        CDR::factory()->inDomain($domain)->noAnswer()->create();
        CDR::factory()->inDomain($domain)->failed()->create();

        $response = $this->get(
            "/api/v1/domains/{$domain}/cdr/stats/summary?" . $this->isoWindowAroundNow(),
            $this->bearer($token)
        );

        $response->assertStatus(200);
        $totals = $response->json('totals');
        $this->assertNotNull($totals, 'summary payload missing totals');

        $this->assertSame(7, $totals['calls']);
        $this->assertSame(2, $totals['answered']);
        $this->assertSame(1, $totals['missed']);
        $this->assertSame(1, $totals['voicemail']);
        $this->assertSame(1, $totals['busy']);
        $this->assertSame(1, $totals['no_answer']);
        $this->assertSame(1, $totals['failed']);
    }

    public function test_csv_export_rows_and_truncation_marker(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read']);
        $token = $this->mintToken($user, $domain);

        CDR::factory()->inDomain($domain)->count(3)->create();

        // full export
        $response = $this->get(
            "/api/v1/domains/{$domain}/cdr/calls.csv?" . $this->isoWindowAroundNow(),
            $this->bearer($token)
        );
        $response->assertStatus(200);
        $lines = array_filter(explode("\n", trim($response->streamedContent())));
        $this->assertStringStartsWith('xml_cdr_uuid,domain_uuid,direction,status', $lines[array_key_first($lines)]);
        $this->assertCount(4, $lines); // header + 3 rows

        // truncated export
        config(['cdr.csv_max_rows' => 2]);
        $truncated = $this->get(
            "/api/v1/domains/{$domain}/cdr/calls.csv?" . $this->isoWindowAroundNow(),
            $this->bearer($token)
        );
        $this->assertStringContainsString('Export truncated at 2 rows', $truncated->streamedContent());
    }

    public function test_tenant_token_create_use_revoke_roundtrip(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read', 'api_token_self_manage']);
        $adminToken = $this->mintToken($user, $domain);

        // create
        $create = $this->postJson(
            "/api/v1/domains/{$domain}/api-tokens",
            ['name' => 'roundtrip'],
            $this->bearer($adminToken)
        );
        $create->assertStatus(201);
        $plainText = $create->json('token');
        $tokenId = $create->json('id');
        $this->assertNotEmpty($plainText);
        $this->assertSame($domain, $create->json('domain_uuid'));

        // use
        $this->get(
            "/api/v1/domains/{$domain}/cdr/calls?" . $this->isoWindowAroundNow(),
            $this->bearer($plainText)
        )->assertStatus(200);

        // revoke
        $this->delete(
            "/api/v1/domains/{$domain}/api-tokens/{$tokenId}",
            [],
            $this->bearer($adminToken)
        )->assertStatus(200);

        // revoked token no longer authenticates
        $this->get(
            "/api/v1/domains/{$domain}/cdr/calls?" . $this->isoWindowAroundNow(),
            $this->bearer($plainText)
        )->assertStatus(401);
    }

    public function test_stats_rate_limit_returns_429_not_500(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read']);
        $token = $this->mintToken($user, $domain);

        $url = "/api/v1/domains/{$domain}/cdr/stats/summary?" . $this->isoWindowAroundNow();

        // cdr-stats bucket is 30/min per token; the 31st must be a clean 429
        // (a Handler catch-all used to turn it into an opaque 500)
        $status = null;
        for ($i = 1; $i <= 31; $i++) {
            $response = $this->get($url, $this->bearer($token));
            $status = $response->status();
            if ($status !== 200) {
                break;
            }
        }

        $this->assertSame(429, $status, "expected 429 after exceeding the limit, got {$status} on request {$i}");
        $response->assertHeader('Retry-After');
        $this->assertSame('rate_limit_error', $response->json('error.type'));
    }

    public function test_min_mos_filter(): void
    {
        $domain = $this->makeDomain();
        $user = $this->makeUser($domain, ['cdr_api_read']);
        $token = $this->mintToken($user, $domain);

        $good = CDR::factory()->inDomain($domain)->withMos(4.4)->create();
        CDR::factory()->inDomain($domain)->withMos(2.1)->create();
        CDR::factory()->inDomain($domain)->create(); // no MOS

        $response = $this->get(
            "/api/v1/domains/{$domain}/cdr/calls?" . $this->isoWindowAroundNow() . '&min_mos=4.0',
            $this->bearer($token)
        );

        $response->assertStatus(200);
        $this->assertSame(
            [(string) $good->xml_cdr_uuid],
            array_column($response->json('data'), 'xml_cdr_uuid')
        );
    }
}
