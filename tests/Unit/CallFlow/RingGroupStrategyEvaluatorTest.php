<?php

namespace Tests\Unit\CallFlow;

use App\Models\RingGroups;
use App\Models\RingGroupsDestinations;
use App\Services\CallFlow\CallFlowContext;
use App\Services\CallFlow\RingGroupStrategyEvaluator;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class RingGroupStrategyEvaluatorTest extends TestCase
{
    private RingGroupStrategyEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RingGroupStrategyEvaluator();
    }

    private function ctx(): CallFlowContext
    {
        return new CallFlowContext(
            domainUuid: '00000000-0000-0000-0000-000000000000',
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
        $g->domain_uuid = '00000000-0000-0000-0000-000000000000';
        $g->ring_group_name = 'Test';
        $g->ring_group_extension = '9000';
        $g->ring_group_strategy = $strategy;
        $g->ring_group_call_timeout = $timeout;
        $g->ring_group_timeout_app = '';
        $g->ring_group_timeout_data = '';
        $g->setRelation('destinations', new Collection($members));
        return $g;
    }

    public function test_simultaneous_emits_single_node_with_members(): void
    {
        $g = $this->group('simultaneous', [
            $this->member('201'),
            $this->member('202'),
        ]);
        $ctx = $this->ctx();

        // walker never called because no exit configured
        $node = $this->evaluator->expand($g, $ctx, fn ($opt) => throw new \AssertionError('should not recurse'));

        $this->assertSame('ring_group', $node->type);
        $this->assertSame('simultaneous', $node->metadata['strategy']);
        $this->assertCount(2, $node->metadata['members']);
        $this->assertSame([], $node->branches);
    }

    // NOTE: the sequential strategy resolves members against v_extensions,
    // so its test lives in tests/Integration/CallFlow (real database).

    public function test_random_strategy_attaches_warning(): void
    {
        $g = $this->group('random', [$this->member('201')]);
        $ctx = $this->ctx();

        $this->evaluator->expand($g, $ctx, fn ($opt) => throw new \AssertionError('not called'));

        $this->assertNotEmpty($ctx->warnings);
        $this->assertStringContainsString('nondeterministic', $ctx->warnings[0]);
    }

    public function test_external_sip_member(): void
    {
        $g = $this->group('sequence', [
            $this->member('sip:alice@example.com'),
        ]);
        $ctx = $this->ctx();

        $node = $this->evaluator->expand($g, $ctx, fn ($opt) => throw new \AssertionError('not called'));

        $first = $node->branches[0]->child;
        $this->assertSame('external', $first->metadata['target_kind']);
    }
}
