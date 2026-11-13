<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\ResumePlan;
use SugarCraft\Code\Lang;

/**
 * Finding #21: a resumed session re-applies its stored model and provider when
 * both are still configured, warns and falls back otherwise, and never moves
 * the working directory.
 */
final class ResumePlanTest extends TestCase
{
    private const CONFIGURED = [
        'sglang' => ['qwen-coder', 'qwen-small'],
        'anthropic' => ['claude-sonnet-4-6', 'claude-haiku-5-5'],
    ];

    public function testAStoredProviderAndModelThatAreStillConfiguredAreReapplied(): void
    {
        $plan = ResumePlan::for(
            ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'],
            self::CONFIGURED,
            'sglang',
            'qwen-coder',
            '/work',
        );

        self::assertSame('anthropic', $plan->provider);
        self::assertSame('claude-haiku-5-5', $plan->model);
        self::assertSame([], $plan->notices);
    }

    public function testAVanishedProviderFallsBackToTheDefaultAndWarns(): void
    {
        $plan = ResumePlan::for(
            ['provider' => 'retired', 'model' => 'old-model'],
            self::CONFIGURED,
            'sglang',
            'qwen-coder',
            '/work',
        );

        self::assertSame('sglang', $plan->provider);
        self::assertSame('qwen-coder', $plan->model);
        self::assertCount(2, $plan->notices, 'the provider and the model that rode with it each warn');
    }

    public function testAModelTheProviderNoLongerOffersFallsBackToItsConfiguredModel(): void
    {
        $plan = ResumePlan::for(
            ['provider' => 'anthropic', 'model' => 'claude-retired'],
            self::CONFIGURED,
            'sglang',
            'qwen-coder',
            '/work',
        );

        self::assertSame('anthropic', $plan->provider);
        self::assertSame('claude-sonnet-4-6', $plan->model);
        self::assertCount(1, $plan->notices);
    }

    public function testAWorkingDirectoryMismatchWarnsAndChangesNothing(): void
    {
        $plan = ResumePlan::for(
            ['provider' => 'sglang', 'model' => 'qwen-coder', 'cwd' => '/old/project'],
            self::CONFIGURED,
            'sglang',
            'qwen-coder',
            '/new/project',
        );

        self::assertSame('sglang', $plan->provider);
        self::assertSame('qwen-coder', $plan->model);
        self::assertSame(
            Lang::t('host.session.resume.cwd_differs', ['stored' => '/old/project', 'current' => '/new/project']),
            $plan->notices[0],
        );
    }

    public function testAMatchingWorkingDirectoryIsSilent(): void
    {
        $plan = ResumePlan::for(
            ['cwd' => '/work/'],
            self::CONFIGURED,
            'sglang',
            'qwen-coder',
            '/work',
        );

        self::assertSame([], $plan->notices);
    }

    public function testAnEmptyRowKeepsTheCurrentDefaults(): void
    {
        $plan = ResumePlan::for([], self::CONFIGURED, 'sglang', 'qwen-coder', null);

        self::assertSame('sglang', $plan->provider);
        self::assertSame('qwen-coder', $plan->model);
        self::assertSame([], $plan->notices);
    }
}
