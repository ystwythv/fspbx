<?php

namespace Tests\Integration\CallFlow;

use App\Models\RingGroups;
use App\Models\RingGroupsDestinations;
use App\Services\CallFlow\CallFlowContext;
use App\Services\CallFlow\RingGroupStrategyEvaluator;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\CdrIntegrationTestCase;

/**
 * The sequential ring-group strategy resolves each member against
 * v_extensions, so it needs a real database — moved here from the unit
 * suite. Also covers the resolved-extension path (label, resource_uuid,
 * target_kind) that a bare database never exercised.
 */
class RingGroupSequentialTest extends CdrIntegrationTestCase
{
    private const DOMAIN = '00000000-0000-0000-0000-000000000000';

    private RingGroupStrategyEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RingGroupStrategyEvaluator();
    }

    public function test_sequential_chains_members(): void
    {
        // 202 exists as a real extension; 201/203 stay unresolved
        $extensionUuid = (string) Str::uuid();
        DB::table('v_extensions')->insert([
            'extension_uuid' => $extensionUuid,
            'domain_uuid' => self::DOMAIN,
            'extension' => '202',
            'effective_caller_id_name' => 'Front Desk',
            'do_not_disturb' => 'false',
            'enabled' => 'true',
        ]);

        $g = $this->group('sequence', [
            $this->member('201'),
            $this->member('202'),
            $this->member('203'),
        ]);
        $ctx = $this->ctx();

        $node = $this->evaluator->expand($g, $ctx, fn ($opt) => throw new \AssertionError('no exit configured'));

        $this->assertSame('ring_group', $node->type);
        $this->assertCount(1, $node->branches);
        $this->assertSame('enter', $node->branches[0]->condition);

        // first member → second → third
        $first = $node->branches[0]->child;
        $this->assertSame('ring_group_member', $first->type);
        $this->assertSame('201', $first->extension);
        $this->assertSame('unknown', $first->metadata['target_kind']);
        $this->assertCount(1, $first->branches);
        $this->assertSame('member_next', $first->branches[0]->condition);

        // 202 resolves against v_extensions
        $second = $first->branches[0]->child;
        $this->assertSame('202', $second->extension);
        $this->assertSame('extension', $second->metadata['target_kind']);
        $this->assertSame($extensionUuid, $second->resource_uuid);
        $this->assertStringContainsString('Front Desk', $second->label);

        $third = $second->branches[0]->child;
        $this->assertSame('203', $third->extension);
        // no exit configured, so tail has no further branch
        $this->assertSame([], $third->branches);
    }

    private function ctx(): CallFlowContext
    {
        return new CallFlowContext(
            domainUuid: self::DOMAIN,
            domainName: 'test.example',
            at: new DateTimeImmutable('2026-04-23T10:00:00Z'),
            timezone: 'UTC',
        );
    }

    private function member(string $ext, int $delay = 0, int $timeout = 0): RingGroupsDestinations
    {
        // Bypass constructor to skip Session::get() in RingGroupsDestinations::__construct
        $m = (new \ReflectionClass(RingGroupsDestinations::class))->newInstanceWithoutConstructor();
        $m->destination_number = $ext;
        $m->destination_delay = $delay;
        $m->destination_timeout = $timeout;
        return $m;
    }

    private function group(string $strategy, array $members, int $timeout = 30): RingGroups
    {
        $g = new RingGroups();
        $g->ring_group_uuid = '00000000-0000-0000-0000-000000000001';
        $g->domain_uuid = self::DOMAIN;
        $g->ring_group_name = 'Test';
        $g->ring_group_extension = '9000';
        $g->ring_group_strategy = $strategy;
        $g->ring_group_call_timeout = $timeout;
        $g->ring_group_timeout_app = '';
        $g->ring_group_timeout_data = '';
        $g->setRelation('destinations', new Collection($members));
        return $g;
    }
}
