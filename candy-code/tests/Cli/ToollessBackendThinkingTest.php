<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-PRV-5.b: the title and auxiliary backends run without the thinking budget.
 *
 * Before this step `Bootstrap::toollessBackend()` handed the provider factory
 * the selected provider's config with `thinkingBudget` in it, so every
 * handful-of-words completion — session titles, `/btw`, goal judgements,
 * commit subjects — paid for extended-thinking tokens the answer never uses,
 * latency included. The fix switches the setting off in the builder itself
 * unless the caller opts out, and only {@see Bootstrap::summaryBackend()}
 * does: a compaction summary becomes permanent context, so its reasoning is
 * worth paying for.
 *
 * WHY `''` AND NOT `unset()`, pinned by tests 2 and 4: every thinking arm in
 * `ProviderFactory` resolves the setting as
 * `$config['thinkingBudget'] ?? userTierSetting('thinkingBudget')`, so
 * deleting the provider-BLOCK key would leave a user-tier `thinkingBudget`
 * riding the fallback straight into the titler's requests — the half the
 * design's root-cause sentence names is only one of the two feeds. The empty
 * string is a PRESENT key (it beats the fallback) and both parsers already
 * read it as off, block tier and user tier alike.
 *
 * The capture seam is the provider's own `anthropicThinkingBudget()` — the
 * raw "what was asked" answer that shapes the `thinking` arm — read off the
 * provider the REAL factory paths constructed. The mapping from that answer
 * to the wire (a null asks for no `thinking` key at all) is byte-pinned by
 * {@see \SugarCraft\Code\Tests\Providers\ThinkingCeilingTest}'s off-shape
 * tests; what was missing, and what this file pins, is that the tool-less
 * paths ARRIVE there with nothing configured, while the summary path does
 * not.
 */
final class ToollessBackendThinkingTest extends TestCase
{
    use HomeSandboxTrait;

    private ?string $sandboxHome = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot FIRST, then re-arm after the trait's ENV_KEYS loop — the
        // lane-cf contract.
        $dir = sys_get_temp_dir() . '/toolless-thinking-home-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->sandboxHome = $this->useHomeSandbox($dir);

        putenv('CANDY_CODE_PROVIDER');
        putenv('CANDY_CODE_MODEL');
        putenv('CANDY_CODE_TITLE_MODEL');
        putenv('CANDY_CODE_SUMMARY_MODEL');
        putenv('ANTHROPIC_API_KEY');
        $this->useHomeSandbox($this->sandboxHome);
    }

    protected function tearDown(): void
    {
        putenv('CANDY_CODE_PROVIDER');
        putenv('CANDY_CODE_MODEL');
        putenv('CANDY_CODE_TITLE_MODEL');
        putenv('CANDY_CODE_SUMMARY_MODEL');
        putenv('ANTHROPIC_API_KEY');
        $this->restoreHomeSandbox();
        if ($this->sandboxHome !== null && is_dir($this->sandboxHome)) {
            @rmdir($this->sandboxHome);
        }

        parent::tearDown();
    }

    public function testTheTitlerDropsTheProviderBlockThinkingBudget(): void
    {
        $this->armAnthropicBlockBudget(8192);

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertNull(
            $this->askedThinkingBudgetOn($backend),
            'a configured block thinkingBudget must not reach the session titler',
        );
    }

    public function testTheTitlerDropsTheUserTierThinkingBudget(): void
    {
        // The discriminating polarity of the ''-not-unset choice: the key is
        // absent from the block copy either way, and only a PRESENT off
        // spelling defeats the factory's user-tier fallback. Red under a bare
        // unset(): the 4096 rides the `??` right-hand side into the request.
        Bootstrap::writeUserConfig(['thinkingBudget' => 4096]);
        $this->armAnthropicSelection();

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertNull(
            $this->askedThinkingBudgetOn($backend),
            'a user-tier thinkingBudget must not reach the session titler through the fallback',
        );
    }

    public function testTheSummaryBackendKeepsTheProviderBlockThinkingBudget(): void
    {
        $this->armAnthropicBlockBudget(8192);

        $backend = Bootstrap::summaryBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame(
            8192,
            $this->askedThinkingBudgetOn($backend),
            'summaries become permanent context and keep the configured reasoning on purpose',
        );
    }

    public function testTheSummaryBackendKeepsTheUserTierThinkingBudget(): void
    {
        Bootstrap::writeUserConfig(['thinkingBudget' => 4096]);
        $this->armAnthropicSelection();

        $backend = Bootstrap::summaryBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame(
            4096,
            $this->askedThinkingBudgetOn($backend),
            'the user-tier setting must survive untouched on the keepThinking path',
        );
    }

    public function testTheBudgetedTitlerWouldHaveAskedForTheBlockNumber(): void
    {
        // Vacuity arm: proves `armAnthropicBlockBudget` actually feeds the
        // provider the factory constructs — the SAME number the titler test
        // asserts absent is asked for when nothing switches it off. Without
        // this row, a mis-keyed fixture would make the nulls unearnable.
        $this->armAnthropicBlockBudget(8192);

        $factory = new \SugarCraft\Code\Providers\ProviderFactory();
        $provider = $factory->create([
            'type' => 'anthropic',
            'apiKey' => 'sk-test',
            'model' => 'claude-sonnet-4-6',
            'thinkingBudget' => 8192,
        ]);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame(8192, $this->askedThinkingBudget($provider));
    }

    /**
     * Select the anthropic provider through the env tier and seed an apiKey,
     * exactly the shape {@see TitleBackendAuxiliaryModelTest} drives.
     */
    private function armAnthropicSelection(): void
    {
        putenv('CANDY_CODE_PROVIDER=anthropic');
        putenv('ANTHROPIC_API_KEY=sk-test');
    }

    /**
     * A minimal `providers.anthropic` block carrying ONLY the thinking knob —
     * type included, because an entry without one is not an override — on top
     * of the env selection.
     */
    private function armAnthropicBlockBudget(int $budget): void
    {
        $this->armAnthropicSelection();
        Bootstrap::writeUserConfig([
            'providers' => [
                'anthropic' => [
                    'type' => 'anthropic',
                    'thinkingBudget' => $budget,
                ],
            ],
        ]);
    }

    /**
     * The thinking budget the backend's provider would shape a request's
     * `thinking` arm from — read off the EngineBackend's private provider,
     * the same reflection idiom the wiring pins use.
     */
    private function askedThinkingBudgetOn(EngineBackend $backend): ?int
    {
        $provider = (new ReflectionProperty(EngineBackend::class, 'provider'))->getValue($backend);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);

        return $this->askedThinkingBudget($provider);
    }

    /**
     * `anthropicThinkingBudget()` is the raw "what was asked" answer the wire
     * table then judges; a no-effort request is the shape every tool-less
     * completion carries.
     */
    private function askedThinkingBudget(AnthropicProvider $provider): ?int
    {
        $request = new CompleteRequest(
            model: 'claude-haiku-4-5',
            messages: [new UserMessage('summarise this')],
        );

        return (new ReflectionMethod(AnthropicProvider::class, 'anthropicThinkingBudget'))
            ->invoke($provider, $request);
    }
}
