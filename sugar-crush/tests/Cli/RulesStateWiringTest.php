<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * The boot path that makes ONE rulebook toggle set serve TWO owners, pinned from
 * `Bootstrap::chat()` outward.
 *
 * ## The door this closes
 *
 * `Chat` is the only writer of the session's {@see RulesState} (the `/rules`
 * command is handled there) and `EngineBackend` is the only reader (it builds each
 * turn's `App`, and the rules splice subtracts whatever the App carries). They are
 * not in a call chain with each other, so `Bootstrap::chat()` creates the instance
 * ONCE and hands it to both. Hand-writing two instances instead — the shape the
 * boot path degrades to if either half of that hand-out is deleted — is not a crash
 * and not a wrong value. It is a `/rules` reply that says `OFF for this session`,
 * a transcript that agrees, a listing that agrees, and a prompt that goes on
 * carrying the pack the operator just switched off, for the rest of the session.
 *
 * Nothing in the suite could see that before this file. Both halves were unit-tested
 * at their own end (`Chat` answered correctly; `EngineBackend` forwarded whatever it
 * was given) and the render seam was pinned from a hand-built App, which is exactly
 * the class of gap this audit keeps finding: a subsystem green at both ends and a
 * no-op in production. The unit tests that build their own `Chat` pass with or
 * without the boot wiring, because they never route through it.
 *
 * The render seam itself — does a toggled pack leave the bytes — is pinned from a
 * hand-built App at
 * {@see \SugarCraft\Crush\Tests\Context\RulebookPromptRenderTest::testTheSamePackRendersIntoThePromptWhenOnAndOutOfItWhenToggledOff()};
 * this file does not re-argue it, it argues that the LAUNCHED pair feeds that seam
 * one shared set.
 *
 * ## Why the assertion is object identity AND a byte change, not either alone
 *
 * `assertSame` on the two accessors proves the two owners hold one instance but says
 * nothing about whether anything READS it — a backend that stores the set and never
 * carries it onto the turn's App would pass on identity alone. The render proves the
 * read but not the sharing — a hand-passed set renders identically. Only together do
 * they describe the wiring: the SAME object, whose mutation by the shell's own
 * keystroke path, changes the bytes the launched backend would send.
 *
 * ## Why ONE launch, and the hazard recorded next door
 *
 * {@see AgentManagerWiringTest}'s class docblock records that the suite has two
 * load-sensitive forked-completion tests, and that adding ANY `Bootstrap::chat()`
 * launching file to the run destabilised them when it was measured on an otherwise
 * untouched checkout. This file pays for exactly ONE launch for the whole class, the
 * same frugality that file argues for, and its existence is therefore itself a
 * measurement of that recorded hazard rather than an assumption about it.
 *
     * `Bootstrap::chat()` is the seam this reads the wiring off, because it is the one
     * place the pair is created together: `Bootstrap::app()` — the entry `bin/sugarcrush`
     * actually calls — builds its shell by calling `chat()`, so every shell the binary
     * ever hosts, wrapped in `Program` or not, comes through the lines pinned here. The
     * narrower seam is also the quieter one: `app()` additionally constructs the session
     * stores and the worktree machinery around the same call, and none of that is what
     * this file is about.
 *
 * The prompt bytes are NOT observable through the launched backend's own provider:
 * with the backend-selection chain cleared, `Bootstrap::backend()` degrades to an
 * {@see EchoProvider}, which answers with prose and never receives the system prompt
 * at all. So the byte half renders the state the LAUNCHED backend carries through
 * {@see Runtime::buildSystemPrompt()} — the same splice `Runtime::run()` uses — on a
 * fresh Runtime per call, because the prompt is memoised per-Runtime (§17.2) and two
 * renders off one instance would return one string twice and make the comparison
 * vacuous.
 */
final class RulesStateWiringTest extends TestCase
{
    // Used for its CHAIN constant only, exactly as
    // {@see AgentManagerWiringTest} uses it: the clear/restore helpers are instance
    // methods and this fixture is class-level, so the loop is spelled out below and
    // only the LIST is shared.
    use BackendSelectionEnvSandboxTrait;

    /** The pack both user directories would answer to; see {@see seedRulebook()}. */
    private const PACK = 'focus';

    /** A body no other fixture in this suite emits, so presence is unambiguous. */
    private const SENTINEL = 'WIRING-SENTINEL-9B4C the pack is riding in the prompt';

    private static string $tempDir = '';
    private static string $repo = '';
    private static string $originalHome = '';
    private static mixed $originalServerHome = null;

    /** @var array<string, string|false> */
    private static array $originalBackendEnv = [];

    private static ?Chat $shell = null;

    /**
     * ONE launch for the whole class, against a synthetic `$HOME` holding exactly one
     * rule pack and a backend-selection chain cleared so the launch lands on the
     * ENGINE path.
     *
     * HOME is redirected for the class rather than per test because `Bootstrap`
     * resolves `~/.sugar-crush` off it, and a developer's real rule packs would ride
     * into the render comparisons below. BOTH spellings of HOME are set — `putenv()`
     * alone leaves the skill trees and the session stores reading the real home; see
     * {@see \SugarCraft\Crush\Tests\Support\HomeSandboxTrait}, which a class-level
     * fixture cannot use because its helpers are instance methods.
     *
     * The chain is cleared for the same window: a shell-out backend builds no prompt
     * at all, so a run with `$SUGARCRUSH_BACKEND_CMD` merely exported in the
     * developer's shell would have nothing to forward and this file would be asserting
     * the `CommandBackend` caveat instead of the engine wiring.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$tempDir = sys_get_temp_dir() . '/sugarcrush_rules_wiring_' . uniqid('', true);
        self::$repo = self::$tempDir . '/repo';
        mkdir(self::$tempDir . '/home', 0700, true);
        mkdir(self::$repo, 0755, true);

        self::$originalHome = getenv('HOME') ?: '';
        self::$originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . self::$tempDir . '/home');
        $_SERVER['HOME'] = self::$tempDir . '/home';

        foreach (self::CHAIN as $var) {
            self::$originalBackendEnv[$var] = getenv($var);
            putenv($var);
        }

        self::seedRulebook();

        self::$shell = Bootstrap::chat(self::$repo);
    }

    public static function tearDownAfterClass(): void
    {
        self::$shell = null;

        if (self::$originalHome !== '') {
            putenv('HOME=' . self::$originalHome);
        } else {
            putenv('HOME');
        }

        if (self::$originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = self::$originalServerHome;
        }

        foreach (self::$originalBackendEnv as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }
        self::$originalBackendEnv = [];

        self::wipeTree(self::$tempDir);

        parent::tearDownAfterClass();
    }

    /**
     * The headline claim: the shell the boot path built and the backend that shell
     * carries hold the SAME {@see RulesState} object, not two equal ones.
     *
     * Fails if `rulesState: $rulesState` is deleted from `Bootstrap::chat()`'s `new
     * Chat(...)` (the shell then falls back to a set of its own — the `?? RulesState::new()`
     * default in `Chat`'s constructor — while the backend keeps the one the injector
     * built, so the two pointers differ) and if the `instanceof EngineBackend`
     * carry-out is deleted (the backend's slot stays null against a non-null accessor).
     * Equality would not be enough here: two instances with the same contents are
     * precisely the bug.
     */
    public function testTheLaunchedShellAndItsBackendHoldOneAndTheSameRulesState(): void
    {
        $chat = self::$shell;
        self::assertInstanceOf(Chat::class, $chat, 'the class fixture must have launched');

        $backend = $chat->backend();
        self::assertInstanceOf(
            EngineBackend::class,
            $backend,
            'with the backend chain cleared the launch must land on the engine path, or there is no '
                . 'prompt for the toggle set to reach',
        );
        self::assertSame($chat->rulesState(), $backend->rulesState());
    }

    /**
     * The read half: a toggle typed into the LAUNCHED shell takes the pack out of the
     * prompt the launched backend would build, and typing it again puts it back.
     *
     * This is the assertion that survives a wiring which shares the object but never
     * consults it. The typed path is the real one — `Chat::update()` on Enter, exactly
     * what the event loop calls — because the point of the pin is the boot path, and a
     * test that called `RulesCommand` directly would pass against a `Chat` built by
     * hand.
     *
     * Both polarities, because a set that is read once in one direction is
     * indistinguishable from a set that is never re-read: an implementation that
     * rendered the first prompt and memoised the answer forever would pass a
     * one-direction test, and §17.2's memoisation lives one level below this seam
     * where it could plausibly be done wrong.
     */
    public function testAToggleTypedIntoTheLaunchedShellMovesThePackOutOfThePromptItBuilds(): void
    {
        $chat = self::$shell;
        self::assertInstanceOf(Chat::class, $chat);
        $backend = $chat->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);

        $state = $backend->rulesState();
        self::assertInstanceOf(RulesState::class, $state, 'the boot injector must have filled the slot');

        self::assertSame(
            self::renderFor($state),
            self::renderFor($chat->rulesState()),
            'before anything is typed, the two owners render the same prompt',
        );
        self::assertStringContainsString(self::SENTINEL, $this->promptOf($backend));

        $off = $this->typeIntoShell($chat, '/rules ' . self::PACK);
        self::assertStringContainsString('Pack ' . self::PACK . ': OFF for this session.', $off);
        self::assertSame(
            $state,
            $backend->rulesState(),
            'the toggle must mutate the set the backend holds, not swap the object out from under it',
        );
        self::assertSame([self::PACK], $state->disabled());
        self::assertStringNotContainsString(
            self::SENTINEL,
            $this->promptOf($backend),
            'the pack the shell just switched off must be out of the bytes the backend builds',
        );

        $on = $this->typeIntoShell($chat, '/rules ' . self::PACK);
        self::assertStringContainsString('Pack ' . self::PACK . ': ON for this session.', $on);
        self::assertSame([], $state->disabled());
        self::assertStringContainsString(self::SENTINEL, $this->promptOf($backend));
    }

    /**
     * The set the launched backend carries is not merely consulted at construction:
     * `complete()` re-reads it onto every turn's App, so a state that exists but is
     * copied once at launch and frozen cannot pass this.
     *
     * Asserted against the live source rather than by running a turn: a full turn
     * needs a provider that reports the prompt it was handed, and the offline
     * {@see EchoProvider} this launch resolves to never sees the system prompt at all.
     * The carry's BEHAVIOUR is pinned from a hand-built backend at
     * {@see \SugarCraft\Crush\Tests\Commands\RulesCommandTest::testTheToggledSetReachesTheSystemPromptThroughAPerTurnCarry()};
     * what that test cannot see is whether the production boot path ever fills the
     * slot it reads, which is what this file is for.
     */
    public function testTheBackendReadsItsToggleSetPerTurnRatherThanFreezingItAtLaunch(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Backend/EngineBackend.php');

        self::assertMatchesRegularExpression(
            '/\$app = App::new\(.*?->withRulesState\(\$this->rulesState\).*?->withMessages\(/s',
            $source,
            'EngineBackend::complete() must carry $this->rulesState onto the App it builds per turn, or the '
                . 'set the boot path injected is read once and frozen for the session',
        );
    }

    // -- helpers --------------------------------------------------------------

    /**
     * One pack in the user tier, so there is something for a toggle to remove.
     *
     * The choice of `rulebooks/` over `rules/` is arbitrary and said as much: both
     * directories are walked by {@see \SugarCraft\Crush\Context\RuleLoader::loadUserRules()}
     * and {@see \SugarCraft\Crush\Context\RuleLoader::loadUserRulebooks()}, both are
     * rendered behind the same fence, and each half already has its own render pin
     * next door. One directory is seeded because the test needs a pack, not because
     * the directory matters; seeding both would only add a second file to keep track
     * of, and the collision case between them belongs to
     * {@see \SugarCraft\Crush\Tests\Context\RuleLoaderTest::testTheSameStemInBothUserDirectoriesStaysTwoPacksToggledByOneName()}
     * and to {@see \SugarCraft\Crush\Tests\Commands\RulesCommandTest::testTogglingANameTwoPacksShareDisclosesThatBothMoved()},
     * not here.
     */
    private static function seedRulebook(): void
    {
        $dir = self::$tempDir . '/home/.sugar-crush/rulebooks';
        mkdir($dir, 0700, true);
        file_put_contents(
            $dir . '/' . self::PACK . '.md',
            "---\nname: Focus\n---\n\n" . self::SENTINEL . "\n",
        );
    }

    /** The prompt the launched backend would build from the state it carries now. */
    private function promptOf(EngineBackend $backend): string
    {
        return self::renderFor($backend->rulesState());
    }

    /**
     * One render per call on a FRESH Runtime: the system prompt is memoised per-Runtime
     * (§17.2), so two states rendered through one instance would return the first
     * string twice and make every comparison in this file vacuous.
     */
    private static function renderFor(?RulesState $state): string
    {
        $app = App::new(new EchoProvider(), 'echo')
            ->withRoot(self::$repo)
            ->withRulesState($state);

        $runtime = new Runtime(new EchoProvider(), new HookManager(new HookRegistry()));
        $build = new ReflectionMethod($runtime, 'buildSystemPrompt');
        $build->setAccessible(true);

        return (string) $build->invoke($runtime, $app);
    }

    /**
     * Drive a typed line through the real `Chat::update()` dispatch — the same
     * reflected `withInputBuf` seam {@see AgentManagerWiringTest} uses, because the
     * input buffer is private and the alternative is a keystroke-per-character loop
     * that tests nothing the dispatch does not already test.
     */
    private function typeIntoShell(Chat $chat, string $command): string
    {
        $withInputBuf = new ReflectionMethod($chat, 'withInputBuf');
        $withInputBuf->setAccessible(true);
        $withBuf = $withInputBuf->invoke($chat, $command);

        [$next, ] = $withBuf->update(new KeyMsg(KeyType::Enter, ''));
        self::assertInstanceOf(Chat::class, $next);

        return $next->history[array_key_last($next->history)]->content;
    }

    private static function wipeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
