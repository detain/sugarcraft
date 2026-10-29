<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Context\Rule;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\OutputStyleHostCommand;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Role;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\PinsEnglishLocaleTrait;

/**
 * The output-style slot end to end (C-CTX-4.e): the one-of-one field on
 * {@see RulesState}, the two style walks on {@see RuleLoader}, the prompt
 * splice that reads the slot, and the `/output-style` host command that drives
 * it — because each half can filter correctly on its own and still hand the
 * model a prompt with no style, or two styles, in it.
 *
 * The shape is the one {@see RulebookPromptRenderTest} established for the
 * pack family: synthetic `$HOME` under `sys_get_temp_dir()`, two real renders
 * of the same files whose byte delta IS the assertion, and every host-command
 * row read back through the same {@see RulesState} object the next prompt will
 * consult — so a command that echoed a happy line without moving the slot
 * would fail here rather than in review.
 *
 * WHY THE GOLDEN DOES NOT MOVE: same argument as the render twin's — the
 * styles live in a fixture home the golden's inputs never contain, and the
 * switch is the thing being asserted, not a constant to freeze. Each render
 * gets its OWN {@see Runtime} because the prompt is memoised per-Runtime
 * (§17.2); one instance asked to render two states would answer twice with
 * the first string and make every comparison in this file vacuous.
 */
final class OutputStyleTest extends TestCase
{
    use HomeSandboxTrait;
    use PinsEnglishLocaleTrait;

    private string $sandbox = '';

    private string $home = '';

    private string $stylesDir = '';

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/candy_code_outputstyle_' . uniqid('', true);
        $this->home = $this->sandbox . '/home';
        $this->stylesDir = $this->home . '/.candy-code/styles';
        $this->repo = $this->sandbox . '/repo';
        mkdir($this->stylesDir, 0o700, true);
        mkdir($this->repo, 0o755, true);
        $this->useHomeSandbox($this->home, create: false);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    // -- the slot -------------------------------------------------------------

    /**
     * The one-of-one law is a property of the field shape: a second selection
     * REPLACES the first (no check can be forgotten because there is nowhere
     * to stack), and clearing is {@see RulesState::clearStyle()} rather than a
     * magic name.
     */
    public function testTheStyleSlotHoldsOneSelectionReplacedRatherThanStacked(): void
    {
        $state = RulesState::new();
        self::assertNull($state->activeStyle(), 'a fresh session selects nothing');

        $state->setStyle('terse');
        self::assertSame('terse', $state->activeStyle());

        $state->setStyle('poetry');
        self::assertSame('poetry', $state->activeStyle(), 'the second selection replaced the first — never two riders');

        $state->clearStyle();
        self::assertNull($state->activeStyle());
    }

    /** parse-don't-validate, mirroring the pack half: junk never reaches the slot. */
    public function testABlankStyleNameIsRefusedByTheSlotItself(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RulesState::new()->setStyle('   ');
    }

    // -- the splice -----------------------------------------------------------

    /**
     * The centre of the step: the same files on disk, rendered with and
     * without the selection, and the difference is exactly the style's fence —
     * an unselected style contributes NOTHING, a selected one rides the same
     * `<user-rules>` fence and preamble as a pack, and clearing reverses it.
     */
    public function testAnUnselectedStyleLeavesNoFenceAndASelectedOneRidesThePackFence(): void
    {
        file_put_contents($this->stylesDir . '/poetry.md', "---\nname: Poetry\n---\nALWAYS REPLY IN HAIKU.\n");
        $packs = $this->home . '/.candy-code/rulebooks';
        mkdir($packs, 0o700, true);
        file_put_contents($packs . '/standing.md', "STANDING-PACK-BODY\n");

        $before = $this->renderStyleState(RulesState::new());
        self::assertStringNotContainsString('ALWAYS REPLY IN HAIKU.', $before, 'an unselected style is not in the prompt');
        self::assertStringContainsString('STANDING-PACK-BODY', $before);
        self::assertSame(1, substr_count($before, '<user-rules>'), 'one fence for the one enabled pack');

        $selected = RulesState::new();
        $selected->setStyle('poetry');
        $on = $this->renderStyleState($selected);
        self::assertStringContainsString('ALWAYS REPLY IN HAIKU.', $on, 'the selection moved the body into the prompt');
        self::assertSame(2, substr_count($on, '<user-rules>'), 'the style rides its own fence beside the pack');
        self::assertSame(2, substr_count($on, $this->preamble()), 'each fence keeps its own authority preamble');

        $selected->clearStyle();
        $after = $this->renderStyleState($selected);
        self::assertStringNotContainsString('ALWAYS REPLY IN HAIKU.', $after, 'off means out from the next render');
        self::assertSame(1, substr_count($after, '<user-rules>'));
    }

    /**
     * Switching styles moves only the style's fence: the pack's framed bytes
     * sit at the same place before and after, so a selection can never
     * reshuffle the standing rules it rides beside. (The prefix is the splice
     * order; only the style block moves.)
     */
    public function testSwitchingFromOneStyleToAnotherLeavesEveryOtherFenceByteIdentical(): void
    {
        file_put_contents($this->stylesDir . '/alpha.md', "STYLE-ALPHA-BODY\n");
        file_put_contents($this->stylesDir . '/beta.md', "STYLE-BETA-BODY\n");
        $packs = $this->home . '/.candy-code/rulebooks';
        mkdir($packs, 0o700, true);
        file_put_contents($packs . '/anchor.md', "PACK-ANCHOR-BODY\n");

        $first = RulesState::new();
        $first->setStyle('alpha');
        $renderAlpha = $this->renderStyleState($first);
        self::assertStringContainsString('STYLE-ALPHA-BODY', $renderAlpha);
        self::assertStringNotContainsString('STYLE-BETA-BODY', $renderAlpha, 'the unselected second style contributes nothing');

        $first->setStyle('beta');
        $renderBeta = $this->renderStyleState($first);
        self::assertStringNotContainsString('STYLE-ALPHA-BODY', $renderBeta, 'the previous style is out — exactly one rides');
        self::assertStringContainsString('STYLE-BETA-BODY', $renderBeta);

        self::assertSame(2, substr_count($renderAlpha, '<user-rules>'));
        self::assertSame(2, substr_count($renderBeta, '<user-rules>'));
        self::assertSame(
            $this->fenceHolding($renderAlpha, 'PACK-ANCHOR-BODY'),
            $this->fenceHolding($renderBeta, 'PACK-ANCHOR-BODY'),
            'the pack fence is byte-identical across the switch',
        );
    }

    /**
     * The negative control every widened splice needs: an App with NO
     * RulesState at all — which is what every pre-style caller constructs —
     * must not ride any style, because {@see RuleLoader::load()}'s stateless
     * guard flips styles off rather than defaulting them on.
     */
    public function testAnAppWithNoRulesStateCarriesNoStyleIntoThePrompt(): void
    {
        file_put_contents($this->stylesDir . '/poetry.md', "ALWAYS REPLY IN HAIKU.\n");

        $app = App::new($this->provider(), 'gpt-4')->withRoot($this->repo);
        self::assertNull($app->rulesState, 'this App carries no session state at all');
        $unfiltered = $this->renderPrompt($app);

        self::assertStringNotContainsString('ALWAYS REPLY IN HAIKU.', $unfiltered, 'no state means no style, never all styles');
    }

    /**
     * Frontmatter wins over selection, both directions of the conjunction in
     * {@see RulesState::effectiveRule()}: a file that disabled itself stays
     * out of the prompt however it is selected, while the slot still records
     * the intent (the command says so out loud — see the host rows below).
     */
    public function testAStyleThatDisabledItselfIsNotPutOnThePromptBySelectingIt(): void
    {
        file_put_contents($this->stylesDir . '/ghost.md', "---\nname: Ghost\nenabled: false\n---\nGHOST-STYLE-BODY\n");

        $state = RulesState::new();
        $state->setStyle('ghost');
        self::assertSame('ghost', $state->activeStyle(), 'the slot records the selection');
        self::assertStringNotContainsString(
            'GHOST-STYLE-BODY',
            $this->renderStyleState($state),
            'and the frontmatter still decides the bytes',
        );
    }

    /**
     * The orthogonality law, pinned at the seam the splice reads: the disabled
     * pack set is NEVER consulted for a style — naming a style in
     * `disabledRules` neither lists nor silences it — while the same name
     * selected through the slot rides.
     */
    public function testADisabledSetNameMatchingAStyleNeitherMutesItNorSelectsIt(): void
    {
        file_put_contents($this->stylesDir . '/poetry.md', "ALWAYS REPLY IN HAIKU.\n");

        $shadowed = RulesState::new(['poetry']);
        self::assertStringNotContainsString(
            'ALWAYS REPLY IN HAIKU.',
            $this->renderStyleState($shadowed),
            'a disabled entry naming a style selects nothing',
        );

        $shadowed->setStyle('poetry');
        self::assertStringContainsString(
            'ALWAYS REPLY IN HAIKU.',
            $this->renderStyleState($shadowed),
            'and the same entry cannot silence the selected style',
        );

        $walked = (new RuleLoader($this->repo))->loadUserStyles();
        self::assertCount(1, $walked, 'the walk hands the listing the file the toggle set cannot see');
        self::assertTrue($walked[0]->style, 'carrying the style bit the splice branches on');
        self::assertSame($walked[0], $shadowed->effectiveRule($walked[0]), 'effectiveRule returns a selected style rule untouched by the disabled set');
    }

    // -- the walks --------------------------------------------------------------

    /**
     * The listing reads the walks, not `load()`, because `load()` emits
     * ENABLED rules only — so the walks must return every style file, self-
     * disabled included, while `load()` under a selection emits exactly the
     * selected one. A walk that filtered like `load()` would make the command
     * able to offer only the already-on style.
     */
    public function testTheWalksListEveryStyleFileWhileTheSpliceLoadEmitsOnlyTheSelectedOne(): void
    {
        file_put_contents($this->stylesDir . '/one.md', "ONE-BODY\n");
        file_put_contents($this->stylesDir . '/two.md', "TWO-BODY\n");
        file_put_contents($this->stylesDir . '/ghost.md', "---\nenabled: false\n---\nGHOST-BODY\n");

        $walked = (new RuleLoader($this->repo))->loadUserStyles();
        self::assertCount(3, $walked, 'the walk keeps the self-disabled file for the listing');
        $byKey = [];
        foreach ($walked as $rule) {
            $byKey[$rule->key] = $rule;
        }
        self::assertFalse($byKey['ghost']->enabled, 'with its frontmatter bit intact');

        $state = RulesState::new();
        $state->setStyle('two');
        $loaded = (new RuleLoader($this->repo, rulesState: $state))->load();
        $styleKeys = [];
        foreach ($loaded as $rule) {
            if ($rule->style) {
                $styleKeys[] = $rule->key;
            }
        }
        self::assertSame(['two'], $styleKeys, 'load emits exactly the selected style');
    }

    /** A project-tier style is walked by its own call and listed as project source. */
    public function testTheProjectStylesDirectoryIsAWalkOfItsOwn(): void
    {
        $project = $this->repo . '/.candy-code/styles';
        mkdir($project, 0o755, true);
        file_put_contents($project . '/ship.md', "PROJECT-STYLE-BODY\n");

        self::assertSame([], (new RuleLoader($this->repo))->loadUserStyles(), 'the user walk never sees the project directory');
        $projectStyles = (new RuleLoader($this->repo))->loadProjectStyles();
        self::assertCount(1, $projectStyles);
        self::assertSame('ship', $projectStyles[0]->key);
        self::assertSame('project', $projectStyles[0]->tier, 'tier says project, so the splice frames it with the repository voice guard');
    }

    // -- registration -----------------------------------------------------------

    public function testTheCommandIsRegisteredWithBothSurfaces(): void
    {
        $rows = array_values(array_filter(
            BuiltInCommands::all(),
            static fn ($row): bool => $row->spec->name === 'output-style',
        ));

        self::assertCount(1, $rows);
        self::assertSame('handleOutputStyleCommand', $rows[0]->handler);
        self::assertSame(CommandArguments::Text, $rows[0]->arguments, 'the raw tail reaches the host command verbatim');
        self::assertSame(OutputStyleHostCommand::class, $rows[0]->hostCommand);
        self::assertSame('[name|off]', $rows[0]->spec->argumentHint);
    }

    // -- the host command ---------------------------------------------------------

    /**
     * The listing: no styles is the empty note, never an error; with files it
     * is a heading, one row per file with its state and source, and the footer
     * law. Rows are flattened exactly like the command's own output channel.
     */
    public function testTheListingOfNoStylesIsTheEmptyNoteNotAFailure(): void
    {
        $empty = (new OutputStyleHostCommand())->run($this->styleContext(RulesState::new(), $this->sandbox . '/nodir'), '');
        self::assertNull($empty->error, 'an empty styles world is the normal case, not a failure');
        self::assertNull($empty->errorCode);
        self::assertStringContainsString('No output styles found.', $this->hostRowText($empty));
    }

    public function testTheListingShowsEveryStyleWithItsStateAndSource(): void
    {
        file_put_contents($this->stylesDir . '/poetry.md', "---\nname: Poetry\n---\nHAIKU\n");
        file_put_contents($this->stylesDir . '/ghost.md', "---\nenabled: false\n---\nGHOST\n");
        $project = $this->repo . '/.candy-code/styles';
        mkdir($project, 0o755, true);
        file_put_contents($project . '/ship.md', "SHIP\n");

        $state = RulesState::new();
        $state->setStyle('poetry');
        $listed = (new OutputStyleHostCommand())->run($this->styleContext($state), '');
        $text = $this->hostRowText($listed);
        self::assertStringContainsString('Output styles (session only — nothing here is written to config):', $text);
        self::assertStringContainsString('poetry', $text);
        self::assertStringContainsString('active', $text);
        self::assertStringContainsString('off (frontmatter)', $text, 'the self-disabled file says its own no, not the slot\'s');
        self::assertStringContainsString('ship', $text);
        self::assertStringContainsString('project', $text);
        self::assertStringContainsString('/output-style <name> selects one style', $text, 'the footer restates the one-of-one law');

        $rows = $listed->rows;
        self::assertSame(Role::Assistant, $rows[count($rows) - 1]->role, 'a listing is a reply, not a notice');
    }

    /**
     * The full select / replace-with-note / refuse / clear conversation with
     * the reserved word, through the SAME state object the next prompt will
     * read — and the two refusals exit non-zero so they land as system
     * notices rather than as assistant replies.
     */
    public function testSelectingOffAndRefusalsAllMoveThroughTheOneSharedSlot(): void
    {
        file_put_contents($this->stylesDir . '/poetry.md', "USER-STYLE-BODY\n");

        $state = RulesState::new();
        $command = new OutputStyleHostCommand();

        $nothing = $command->run($this->styleContext($state), '/output-style off');
        self::assertNull($nothing->error);
        self::assertStringContainsString('No output style was active — nothing to clear.', $this->hostRowText($nothing));

        $set = $command->run($this->styleContext($state), '/output-style poetry');
        self::assertNull($set->error);
        self::assertStringContainsString('Style poetry: ACTIVE for this session.', $this->hostRowText($set));
        self::assertSame('poetry', $state->activeStyle(), 'the row and the slot moved together');

        $again = $command->run($this->styleContext($state), '/output-style poetry');
        self::assertStringContainsString('ACTIVE for this session', $this->hostRowText($again), 're-selecting is a no-op lawfully');

        $typo = $command->run($this->styleContext($state), '/output-style poetree');
        self::assertStringContainsString('Unknown output style: poetree', $this->hostRowText($typo));
        self::assertStringContainsString('Available styles: poetry', $this->hostRowText($typo));
        self::assertSame(Role::System, $typo->rows[count($typo->rows) - 1]->role, 'a refusal is a notice, not a reply');
        self::assertSame('poetry', $state->activeStyle(), 'the refusal changed nothing');

        $arity = $command->run($this->styleContext($state), '/output-style poetry now');
        self::assertSame(Role::System, $arity->rows[count($arity->rows) - 1]->role, 'the arity refusal is a notice too');
        self::assertStringContainsString('takes one style name or `off`; read it as `poetry now`', $this->hostRowText($arity));

        $cleared = $command->run($this->styleContext($state), '/output-style off');
        self::assertStringContainsString('No output style is active now', $this->hostRowText($cleared));
        self::assertNull($state->activeStyle());
    }

    /**
     * A self-disabled file CAN be selected as far as the slot goes, and the
     * row must say the file still wins — a bare ACTIVE would promise prompt
     * bytes that do not ride.
     */
    public function testSelectingASelfDisabledStyleSaysSoInsteadOfLying(): void
    {
        file_put_contents($this->stylesDir . '/ghost.md', "---\nenabled: false\n---\nGHOST\n");

        $state = RulesState::new();
        $result = (new OutputStyleHostCommand())->run($this->styleContext($state), '/output-style ghost');
        $text = $this->hostRowText($result);
        self::assertStringContainsString('Style ghost: ACTIVE for this session.', $text);
        self::assertStringContainsString(
            'Its own frontmatter says enabled: false',
            $text,
            'the row names the frontmatter veto instead of silently pretending bytes rode',
        );
        self::assertSame('ghost', $state->activeStyle());
    }

    /**
     * Served parity, end to end: the command's selection is the slot the
     * RENDER reads — two files sharing a name ride together (the matches note
     * says so), and one `/output-style off` takes both back out.
     */
    public function testTheSelectedNameRidesBothDirectoriesItMatchesAndOffClearsThemTogether(): void
    {
        file_put_contents($this->stylesDir . '/poetry.md', "STYLE-USER-BODY\n");
        $project = $this->repo . '/.candy-code/styles';
        mkdir($project, 0o755, true);
        file_put_contents($project . '/poetry.md', "STYLE-PROJECT-BODY\n");

        $state = RulesState::new();
        $result = (new OutputStyleHostCommand())->run($this->styleContext($state), '/output-style poetry');
        self::assertStringContainsString(
            'this name matches 2 style files — all of them ride',
            $this->hostRowText($result),
        );

        $prompt = $this->renderStyleState($state);
        self::assertStringContainsString('STYLE-USER-BODY', $prompt);
        self::assertStringContainsString('STYLE-PROJECT-BODY', $prompt);
        self::assertSame(1, substr_count($prompt, '<user-rules>'), 'one selection rides BOTH files it matched — the user twin in the user fence, the project twin in its own tier fence');

        $command = new OutputStyleHostCommand();
        $command->run($this->styleContext($state), '/output-style off');
        self::assertSame(0, substr_count($this->renderStyleState($state), 'STYLE-'), 'off clears the slot both files rode');
    }

    // -- harness -------------------------------------------------------------------

    private function styleContext(RulesState $state, ?string $root = null): CommandContext
    {
        return CommandContext::new(
            root: $root ?? $this->repo,
            readOnly: false,
            rulesState: $state,
        );
    }

    /**
     * Every host row's text, flattened the way the drivers show them — so a
     * needle pinned here is pinned against the bytes an operator reads.
     */
    private function hostRowText(object $result): string
    {
        return implode("\n", array_map(
            static fn ($row): string => (string) (is_object($row) ? ($row->content ?? '') : (string) $row),
            $result->rows ?? [],
        ));
    }

    private function provider(): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        return $provider;
    }

    /**
     * One render of a state that may carry a style selection; the prompt is
     * memoised per-Runtime, so every call builds its own.
     */
    private function renderStyleState(RulesState $state): string
    {
        return $this->renderPrompt(
            App::new($this->provider(), 'gpt-4')
                ->withRoot($this->repo)
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

    /**
     * The whole `<user-rules>` fence carrying a marker, found by literal byte
     * search (never a regex: a pattern literal with a star is glob-shaped and
     * would drift the GlobDialect corpus by existing).
     */
    private function fenceHolding(string $prompt, string $marker): string
    {
        $open = "<user-rules>\n";
        $close = "\n</user-rules>";
        $offset = 0;
        while (($start = strpos($prompt, $open, $offset)) !== false) {
            $end = strpos($prompt, $close, $start);
            self::assertNotFalse($end, 'an unclosed user-rules fence in a rendered prompt');
            $block = substr($prompt, $start, $end + strlen($close) - $start);
            if (str_contains($block, $marker)) {
                return $block;
            }
            $offset = $end + strlen($close);
        }

        self::fail('no user-rules fence carries ' . $marker);
    }

    private function preamble(): string
    {
        $preamble = (new ReflectionClass(Runtime::class))->getConstant('USER_RULES_AUTHORITY_PREAMBLE');
        self::assertIsString($preamble, 'Runtime::USER_RULES_AUTHORITY_PREAMBLE must exist as a string constant');

        return $preamble;
    }
}
