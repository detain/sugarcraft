<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The two live renders that prove a `/rules` toggle changes the PROMPT, not merely
 * the object graph.
 *
 * Requirement 9's shape: build the system prompt twice for the SAME pack directory
 * — once with the pack on, once with it toggled off — and compare. Every assertion
 * below is about bytes that reach the model, so a loader that filters correctly but
 * a splice that ignores the filter (or the reverse) cannot pass.
 *
 * WHY THE GOLDEN DOES NOT MOVE. Nothing here touches
 * `tests/fixtures/prompt/golden-system-prompt.txt`, and nothing here needs it to:
 * the packs live in a synthetic `$HOME` under `sys_get_temp_dir()` that the golden's
 * fixture home never contains, so the committed bytes are produced by an input set
 * this file does not modify. That is also why the proof is two renders rather than a
 * third golden — a fixture would have to be regenerated to carry a toggle, and the
 * toggle is the thing being asserted, not a constant to freeze.
 *
 * Each render gets its OWN {@see Runtime}: the prompt is memoised per-Runtime (a
 * §17.2 invariant), so two calls on one instance would return the same string for
 * opposite states and the comparison would be vacuous.
 */
final class RulebookPromptRenderTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $home = '';

    private string $packsDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/sugarcrush_rulesrender_' . uniqid('', true);
        $this->home = $this->sandbox . '/home';
        $this->packsDir = $this->home . '/.sugar-crush/rulebooks';
        mkdir($this->packsDir, 0o700, true);
        $this->useHomeSandbox($this->home, create: false);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    /**
     * The centre of the step: the same pack on disk, rendered with and without the
     * session toggle, produces DIFFERENT bytes, and the difference is exactly that
     * pack's fence.
     *
     * The byte counts are asserted rather than only "not equal", because a delta of
     * one character would also be not-equal and would mean the fence geometry had
     * collapsed into something else.
     */
    public function testTheSamePackRendersIntoThePromptWhenOnAndOutOfItWhenToggledOff(): void
    {
        file_put_contents($this->packsDir . '/poetry.md', "---\nname: Poetry\n---\nALWAYS REPLY IN HAIKU.\n");
        $on = $this->render(RulesState::new());

        $off = RulesState::new();
        $off->toggle('poetry');
        $renderOff = $this->render($off);

        self::assertStringContainsString('ALWAYS REPLY IN HAIKU.', $on, 'the pack body is in the ON prompt');
        self::assertStringNotContainsString('ALWAYS REPLY IN HAIKU.', $renderOff, 'and absent from the OFF prompt');
        self::assertNotSame($on, $renderOff, 'the two renders differ at all');

        self::assertSame(1, substr_count($on, '<user-rules>'), 'exactly one fence for exactly one enabled pack');
        self::assertSame(1, substr_count($on, $this->preamble()), 'and exactly one authority preamble with it');
        self::assertSame(0, substr_count($renderOff, '<user-rules>'), 'a toggled-off pack leaves no fence behind');
        self::assertSame(0, substr_count($renderOff, $this->preamble()));

        // The toggle removes a WHOLE framed block, so the delta is the fence, the
        // preamble, the body and their newlines - not a stray byte that would also
        // satisfy assertNotSame(). Derived, not hand-copied, so it cannot rot.
        self::assertGreaterThan(
            strlen($this->preamble()),
            strlen($on) - strlen($renderOff),
            'the delta is at least the size of the framed block, so the geometry survived',
        );
    }

    /**
     * Two enabled packs, two fences — each rule is framed on its own, so the
     * "exactly once per enabled pack" of the done-when is a count of TWO here. A
     * splice that wrapped the tier in one fence would red the first assertion; one
     * that emitted a fence per tier regardless of pack count would red the second.
     */
    public function testEveryEnabledPackGetsItsOwnFenceAndPreamble(): void
    {
        file_put_contents($this->packsDir . '/one.md', "FIRST-PACK-BODY\n");
        file_put_contents($this->packsDir . '/two.md', "SECOND-PACK-BODY\n");
        $state = RulesState::new();

        self::assertSame(2, substr_count($this->render($state), '<user-rules>'));

        $state->toggle('two');
        $oneOff = $this->render($state);

        self::assertSame(1, substr_count($oneOff, '<user-rules>'), 'one enabled pack, one fence');
        self::assertSame(1, substr_count($oneOff, $this->preamble()));
        self::assertStringContainsString('FIRST-PACK-BODY', $oneOff);
        self::assertStringNotContainsString('SECOND-PACK-BODY', $oneOff);
    }

    /**
     * A `rules/` pack toggles through the same command and the same set as a
     * `rulebooks/` pack — the two user directories are one tier behind one fence,
     * which is what requirement 2's no-new-tier ruling has to mean in the bytes.
     */
    public function testAPackInTheRulesDirectoryTogglesThroughTheSameRenderPath(): void
    {
        $rules = $this->home . '/.sugar-crush/rules';
        mkdir($rules, 0o700, true);
        file_put_contents($rules . '/standing.md', "STANDING-RULE-BODY\n");
        file_put_contents($this->packsDir . '/book.md', "RULEBOOK-BODY\n");

        $both = $this->render(RulesState::new());
        self::assertSame(2, substr_count($both, '<user-rules>'));

        $state = RulesState::new(['standing']);
        $oneOff = $this->render($state);

        self::assertStringNotContainsString('STANDING-RULE-BODY', $oneOff);
        self::assertStringContainsString('RULEBOOK-BODY', $oneOff);
        self::assertSame(1, substr_count($oneOff, '<user-rules>'));
    }

    /**
     * The tier boundary in the prompt: a project file is framed by the project
     * fence, and toggling a name that only the repository uses subtracts nothing
     * from the rendered bytes.
     *
     * This is the render-side half of the scope pinned in
     * `RulesCommandTest::testAProjectRuleIsNotToggleableAndSaysSoAsAnError()` — the
     * command refuses the name, and here the prompt proves the same thing at the
     * byte level rather than only in a message.
     */
    public function testASessionToggleNeverSubtractsAProjectAuthoredRuleFromThePrompt(): void
    {
        $project = $this->sandbox . '/repo/.sugar-crush/rules';
        mkdir($project, 0o755, true);
        file_put_contents($project . '/terse.md', "PROJECT-TERSE-BODY\n");
        file_put_contents($this->packsDir . '/terse.md', "USER-TERSE-BODY\n");

        $state = RulesState::new();
        self::assertStringContainsString('PROJECT-TERSE-BODY', $this->render($state, $this->sandbox . '/repo'));

        $state->toggle('terse');
        $toggled = $this->render($state, $this->sandbox . '/repo');

        self::assertStringContainsString('PROJECT-TERSE-BODY', $toggled, 'the repository layer is untouched by the session set');
        self::assertStringNotContainsString('USER-TERSE-BODY', $toggled, 'the identically named user pack is the one that left');
    }

    /**
     * A `null` state on the App renders EXACTLY the bytes it rendered before this
     * step existed — the negative control for the whole change, and the reason every
     * existing prompt test is expected to be unmoved.
     */
    public function testAnAppWithNoRulesStateRendersTheUnfilteredPrompt(): void
    {
        file_put_contents($this->packsDir . '/poetry.md', "ALWAYS REPLY IN HAIKU.\n");

        $state = RulesState::new(['poetry']);
        $filtered = $this->render($state);

        $app = App::new($this->provider(), 'gpt-4')->withRoot($this->sandbox . '/repo');
        self::assertNull($app->rulesState, 'this App carries no toggle set at all');
        $unfiltered = $this->renderPrompt($app);

        self::assertStringContainsString('ALWAYS REPLY IN HAIKU.', $unfiltered);
        self::assertStringNotContainsString('ALWAYS REPLY IN HAIKU.', $filtered);
    }

    /**
     * The loader and the prompt must agree about which packs are on, because
     * `RulesCommand` builds its listing from the loader rather than from a second
     * reading of the prompt. Asserted against the real splice rather than argued in
     * a doc-block: a pack the loader returns but the splice drops (an empty body, a
     * non-user tier) must not appear as a toggleable `on` row.
     */
    public function testAPackWithNoBodyIsListedButContributesNoFence(): void
    {
        file_put_contents($this->packsDir . '/blank.md', "---\nname: Blank\n---\n\n");

        $loaderPacks = (new RuleLoader($this->sandbox . '/repo'))->loadUserRulebooks();
        self::assertCount(1, $loaderPacks, 'the loader hands the empty pack to the listing');
        self::assertSame('', trim($loaderPacks[0]->body));

        $rendered = $this->render(RulesState::new());
        self::assertSame(0, substr_count($rendered, '<user-rules>'), 'and the splice frames nothing for it');
    }

    // -- helpers --------------------------------------------------------------

    private function provider(): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        return $provider;
    }

    private function render(?RulesState $state, ?string $root = null): string
    {
        return $this->renderPrompt(
            App::new($this->provider(), 'gpt-4')
                ->withRoot($root ?? $this->sandbox . '/repo')
                ->withRulesState($state),
        );
    }

    /**
     * One render per call, on a fresh Runtime — the prompt is memoised per-Runtime
     * (§17.2), so reusing an instance across two states would return one string
     * twice and make every comparison in this file vacuous.
     */
    private function renderPrompt(App $app): string
    {
        $runtime = new Runtime($this->provider(), new HookManager(new HookRegistry()));
        $method = new ReflectionMethod($runtime, 'buildSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($runtime, $app);
    }

    private function preamble(): string
    {
        $preamble = (new ReflectionClass(Runtime::class))->getConstant('USER_RULES_AUTHORITY_PREAMBLE');
        self::assertIsString($preamble, 'Runtime::USER_RULES_AUTHORITY_PREAMBLE must exist as a string constant');

        return $preamble;
    }
}
