<?php

namespace Tests\Unit\Voxra;

use App\Services\Voxra\VoxraDisclosure;
use App\Services\Voxra\VoxraMediaPurgeService;
use PHPUnit\Framework\TestCase;

/** voxragtm#83: every Voxra assistant greeting discloses AI (and recording). */
class VoxraDisclosureTest extends TestCase
{
    public function test_default_greeting_discloses_ai_and_recording(): void
    {
        $g = VoxraDisclosure::defaultGreeting('Nutty Squirrel', true);

        $this->assertSame("Hi, thanks for calling Nutty Squirrel. Just so you know, I'm an AI assistant and this call is being recorded. How can I help?", $g);
    }

    public function test_recording_off_mentions_ai_only(): void
    {
        $g = VoxraDisclosure::defaultGreeting('Bloom', false);

        $this->assertTrue(VoxraDisclosure::mentionsAi($g));
        $this->assertFalse(VoxraDisclosure::mentionsRecording($g));
    }

    public function test_greeting_without_disclosure_gets_it_before_the_question(): void
    {
        $g = VoxraDisclosure::ensure('Good morning, Bloom Hair. What can I do for you?', 'Bloom', true);

        $this->assertSame("Good morning, Bloom Hair. Just so you know, I'm an AI assistant and this call is being recorded. What can I do for you?", $g);
    }

    public function test_greeting_that_already_discloses_is_kept(): void
    {
        $in = "Hi, you've reached Bloom — I'm their AI assistant and calls are recorded. How can I help?";

        $this->assertSame($in, VoxraDisclosure::ensure($in, 'Bloom', true));
    }

    public function test_ai_only_greeting_still_gets_recording_when_on(): void
    {
        $g = VoxraDisclosure::ensure("Hi, I'm Bloom's AI assistant. How can I help?", 'Bloom', true);

        $this->assertTrue(VoxraDisclosure::mentionsRecording($g));
    }

    public function test_long_greeting_is_trimmed_not_the_disclosure(): void
    {
        $g = VoxraDisclosure::ensure(str_repeat('Hello ', 200), 'Bloom', true);

        $this->assertLessThanOrEqual(VoxraDisclosure::MAX_GREETING, mb_strlen($g));
        $this->assertStringEndsWith("I'm an AI assistant and this call is being recorded.", $g);
    }

    public function test_ordinary_words_are_not_mistaken_for_ai(): void
    {
        $this->assertFalse(VoxraDisclosure::mentionsAi('Said the aid to the maid'));
        $this->assertTrue(VoxraDisclosure::mentionsAi("I'm an A.I. assistant"));
    }

    public function test_number_variants_cover_stored_forms(): void
    {
        $v = VoxraMediaPurgeService::numberVariants(['+447700900123']);
        sort($v, SORT_STRING);

        $this->assertSame(['+447700900123', '07700900123', '447700900123'], $v);
        $this->assertSame([], VoxraMediaPurgeService::numberVariants(['']));
    }
}
