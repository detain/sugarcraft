<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context\Compaction;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\Compaction\StepSummarizer;

final class StepSummarizerModelOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(StepSummarizer::ENV_SUMMARY_MODEL);
    }

    public function testConstNamesTheDocumentedVariable(): void
    {
        $this->assertSame('CANDY_CODE_SUMMARY_MODEL', StepSummarizer::ENV_SUMMARY_MODEL);
    }

    public function testOverrideIsTrimmedAndBlankIsNull(): void
    {
        putenv(StepSummarizer::ENV_SUMMARY_MODEL . '=  cheap-model ');
        $this->assertSame('cheap-model', StepSummarizer::modelOverride());
        putenv(StepSummarizer::ENV_SUMMARY_MODEL . '=   ');
        $this->assertNull(StepSummarizer::modelOverride());
        putenv(StepSummarizer::ENV_SUMMARY_MODEL);
        $this->assertNull(StepSummarizer::modelOverride());
    }
}
