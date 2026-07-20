<?php

namespace Database\Factories;

use App\Models\CDR;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * v_xml_cdr rows for integration tests (issue #12). Defaults describe an
 * answered inbound call; states mirror the CallStatus taxonomy the API's
 * status filter and stats use.
 */
class CDRFactory extends Factory
{
    protected $model = CDR::class;

    public function definition(): array
    {
        $start = time() - 3600;

        return [
            'xml_cdr_uuid' => (string) Str::uuid(),
            'domain_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'caller_id_name' => 'Test Caller',
            'caller_id_number' => '01970623111',
            'destination_number' => '2001',
            'caller_destination' => '2001',
            'start_epoch' => $start,
            'answer_epoch' => $start + 5,
            'end_epoch' => $start + 65,
            'duration' => 65,
            'billsec' => 60,
            'hangup_cause' => 'NORMAL_CLEARING',
            'voicemail_message' => false,
            'missed_call' => false,
            'leg' => 'a',
        ];
    }

    public function answered(): static
    {
        return $this->state(fn () => []);
    }

    public function missed(): static
    {
        return $this->state(fn () => [
            'answer_epoch' => 0,
            'billsec' => 0,
            'missed_call' => true,
        ]);
    }

    public function voicemail(): static
    {
        return $this->state(fn () => [
            'voicemail_message' => true,
            'missed_call' => true,
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn () => [
            'answer_epoch' => 0,
            'billsec' => 0,
            'missed_call' => true,
            'cc_cancel_reason' => 'BREAK_OUT',
            'cc_cause' => 'cancel',
        ]);
    }

    public function busy(): static
    {
        return $this->state(fn () => [
            'answer_epoch' => 0,
            'billsec' => 0,
            'hangup_cause' => 'USER_BUSY',
        ]);
    }

    public function noAnswer(): static
    {
        return $this->state(fn () => [
            'answer_epoch' => 0,
            'billsec' => 0,
            'hangup_cause' => 'NO_ANSWER',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'answer_epoch' => null,
            'billsec' => 0,
            'hangup_cause' => 'CALL_REJECTED',
        ]);
    }

    public function outbound(string $destination = '443300577577'): static
    {
        return $this->state(fn () => [
            'direction' => 'outbound',
            'destination_number' => $destination,
            'caller_destination' => $destination,
        ]);
    }

    public function withMos(float $mos): static
    {
        return $this->state(fn () => ['rtp_audio_in_mos' => $mos]);
    }

    public function inDomain(string $domainUuid): static
    {
        return $this->state(fn () => ['domain_uuid' => $domainUuid]);
    }

    public function startedAt(int $epoch, int $durationSec = 65): static
    {
        return $this->state(fn (array $attrs) => [
            'start_epoch' => $epoch,
            'answer_epoch' => empty($attrs['answer_epoch']) ? $attrs['answer_epoch'] : $epoch + 5,
            'end_epoch' => $epoch + $durationSec,
            'duration' => $durationSec,
        ]);
    }
}
