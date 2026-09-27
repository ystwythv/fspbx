<?php

namespace Tests\Unit\Voxra;

use App\Models\AiAgent;
use App\Services\ProvisionLineService;
use PHPUnit\Framework\TestCase;

/**
 * The AI-agent allocator never hands out the Voxra Line extension 9260, even
 * though it sits inside the 9250–9299 agent range (voxragtm#110).
 */
class AgentExtensionAllocatorTest extends TestCase
{
    public function test_first_free_is_9250_on_an_empty_domain(): void
    {
        $this->assertSame('9250', AiAgent::firstFreeAgentExtension([]));
    }

    public function test_skips_used_numbers_and_the_line_extension(): void
    {
        $used = array_map('strval', range(9250, 9259));
        $this->assertSame('9261', AiAgent::firstFreeAgentExtension($used));
        $this->assertContains(ProvisionLineService::LINE_EXTENSION, AiAgent::RESERVED_AGENT_EXTENSIONS);
    }

    public function test_accepts_int_and_string_used_values(): void
    {
        $this->assertSame('9252', AiAgent::firstFreeAgentExtension([9250, '9251']));
    }

    public function test_never_returns_9260_even_when_everything_else_is_taken(): void
    {
        $used = array_values(array_filter(range(9250, 9299), fn ($n) => $n !== 9260));
        $this->assertNull(AiAgent::firstFreeAgentExtension($used));
    }
}
