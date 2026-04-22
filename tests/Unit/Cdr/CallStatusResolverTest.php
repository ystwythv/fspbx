<?php

namespace Tests\Unit\Cdr;

use App\Enums\Cdr\CallStatus;
use App\Services\Cdr\CallStatusResolver;
use Tests\TestCase;
use stdClass;

class CallStatusResolverTest extends TestCase
{
    private CallStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CallStatusResolver();
    }

    public function test_voicemail_takes_precedence(): void
    {
        $cdr = $this->cdr([
            'voicemail_message' => true,
            'missed_call' => true,
            'hangup_cause' => 'NORMAL_CLEARING',
        ]);

        $this->assertSame(CallStatus::Voicemail, $this->resolver->resolve($cdr));
    }

    public function test_abandoned_requires_break_out_cancel(): void
    {
        $cdr = $this->cdr([
            'missed_call' => true,
            'hangup_cause' => 'NORMAL_CLEARING',
            'cc_cancel_reason' => 'BREAK_OUT',
            'cc_cause' => 'cancel',
        ]);

        $this->assertSame(CallStatus::Abandoned, $this->resolver->resolve($cdr));
    }

    public function test_missed_call_without_abandon_signals_is_missed(): void
    {
        $cdr = $this->cdr([
            'missed_call' => true,
            'hangup_cause' => 'NORMAL_CLEARING',
        ]);

        $this->assertSame(CallStatus::Missed, $this->resolver->resolve($cdr));
    }

    public function test_user_busy_is_busy(): void
    {
        $cdr = $this->cdr([
            'hangup_cause' => 'USER_BUSY',
        ]);

        $this->assertSame(CallStatus::Busy, $this->resolver->resolve($cdr));
    }

    public function test_no_answer_hangup_causes_map_to_no_answer(): void
    {
        foreach (['NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT'] as $cause) {
            $cdr = $this->cdr(['hangup_cause' => $cause]);
            $this->assertSame(
                CallStatus::NoAnswer,
                $this->resolver->resolve($cdr),
                "Expected NoAnswer for hangup_cause={$cause}"
            );
        }
    }

    public function test_answered_call_is_answered(): void
    {
        $cdr = $this->cdr([
            'answer_epoch' => 1712048047,
            'hangup_cause' => 'NORMAL_CLEARING',
        ]);

        $this->assertSame(CallStatus::Answered, $this->resolver->resolve($cdr));
    }

    public function test_unanswered_with_unknown_cause_is_failed(): void
    {
        $cdr = $this->cdr([
            'answer_epoch' => 0,
            'hangup_cause' => 'RECOVERY_ON_TIMER_EXPIRE',
        ]);

        $this->assertSame(CallStatus::Failed, $this->resolver->resolve($cdr));
    }

    private function cdr(array $fields): stdClass
    {
        $defaults = [
            'voicemail_message' => false,
            'missed_call' => false,
            'hangup_cause' => null,
            'cc_cancel_reason' => null,
            'cc_cause' => null,
            'answer_epoch' => 0,
        ];

        $row = new stdClass();
        foreach (array_merge($defaults, $fields) as $k => $v) {
            $row->{$k} = $v;
        }
        return $row;
    }
}
