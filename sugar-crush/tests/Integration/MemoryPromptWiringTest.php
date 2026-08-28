<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Prompt\PromptFixture;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * The route crush_code.md Phase 5 item 9 had to build: MemoryStore -> App ->
 * Runtime::buildSystemPrompt() -> the model.
 *
 * Before this item the store was constructed by `Bootstrap::memoryStore()` and
 * reached `Chat` only, where `/memory` used it as a CRUD surface — so a note the
 * user deliberately recorded never reached the model. Every hop of the new route
 * is asserted here, because a route is only as wired as its weakest hop and the
 * previous state of this feature is exactly what "looks wired, never fires"
 * looks like.
 *
 * The backend-selection chain is cleared for every test
 * ({@see BackendSelectionEnvSandboxTrait}), because the tests that start at
 * {@see Bootstrap::backend()} assert on an {@see EngineBackend} and either
 * shell-out variable merely exported in the developer's shell selects a
 * `CommandBackend`, which carries no memory store at all. Measured before the
 * clearing was added: two failures here.
 */
final class MemoryPromptWiringTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    private string $dir;

    private MemoryStore $store;

    /** @var list<PromptFixture> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_mempromptwire_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->store = new MemoryStore($this->dir);
        $this->clearBackendSelectionEnv();
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }
        $this->fixtures = [];

        $this->restoreBackendSelectionEnv();

        // Restore write permission first, or rmrf() cannot unlink inside the
        // directory testAnUnusableMemoryDirectory... deliberately locked.
        $locked = $this->dir . '/readonly-home/.sugar-crush/memory';
        if (is_dir($locked)) {
            chmod($locked, 0o700);
        }

        self::rmrf($this->dir);
    }

    private static function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? self::rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }

    /** Drives one turn and returns the system prompt the provider was handed. */
    private function promptFor(App $app, PromptCapturingProvider $provider): string
    {
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        iterator_to_array($runtime->run($app));

        return $provider->lastSystemPrompt();
    }

    // -------------------------------------------------------------------------
    // App -> prompt
    // -------------------------------------------------------------------------

    public function testAProjectScopeNoteReachesTheSystemPrompt(): void
    {
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;
        $fixture->memoryStore()->add('Never commit a per-lib composer.lock.', MemoryScope::Project);

        $provider = new PromptCapturingProvider();
        $prompt = $this->promptFor(
            $fixture->app()->withMessages([new UserMessage('hi')]),
            $provider,
        );

        $this->assertStringContainsString('<project-memory>', $prompt);
        $this->assertStringContainsString('Never commit a per-lib composer.lock.', $prompt);
    }

    public function testAnAppWithoutAStoreGetsTheSamePromptItAlwaysDid(): void
    {
        $provider = new PromptCapturingProvider();
        $prompt = $this->promptFor(
            App::new($provider, 'test-model')->withMessages([new UserMessage('hi')]),
            $provider,
        );

        $this->assertStringNotContainsString('<project-memory>', $prompt);
    }

    public function testAnAppWithAnEmptyStoreAddsNothing(): void
    {
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;
        $fixture->memoryStore();

        $provider = new PromptCapturingProvider();
        $prompt = $this->promptFor(
            $fixture->app()->withMessages([new UserMessage('hi')]),
            $provider,
        );

        $this->assertStringNotContainsString('<project-memory>', $prompt);
    }

    public function testAUserScopeNoteDoesNotReachThePrompt(): void
    {
        // The deliberate boundary, asserted rather than only documented: user
        // memory follows the operator across every project, so leaking it into a
        // work repository's prompt has to be a separate decision.
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;
        $fixture->memoryStore()->add('my personal preference', MemoryScope::User);

        $provider = new PromptCapturingProvider();
        $prompt = $this->promptFor(
            $fixture->app()->withMessages([new UserMessage('hi')]),
            $provider,
        );

        $this->assertStringNotContainsString('my personal preference', $prompt);
        $this->assertStringNotContainsString('<project-memory>', $prompt);
    }

    public function testTheMemoryBlockSitsBeforeTheEnvironmentBlockInThePrompt(): void
    {
        // P3.S1 inverted this pin, deliberately, with the env block's move to
        // the END of the assembly (stable layers first, volatile <env> last —
        // prompt_expand.md §9.2): the memory block now precedes the
        // environment block instead of following it. An inverted assertion
        // still pins an order — a reorder that put <env> back ahead of the
        // memory block reds this.
        $this->store->add('a project convention', MemoryScope::Project);

        $provider = new PromptCapturingProvider();
        $prompt = $this->promptFor(
            App::new($provider, 'test-model')
                ->withMessages([new UserMessage('hi')])
                ->withMemoryStore($this->store),
            $provider,
        );

        $env = strpos($prompt, '<env>');
        $memory = strpos($prompt, '<project-memory>');

        $this->assertNotFalse($env);
        $this->assertNotFalse($memory);
        $this->assertLessThan(
            $env,
            $memory,
            'the memory block must precede the volatile environment block in the assembled prompt',
        );
    }

    /**
     * The snapshot contract. `buildSystemPrompt()` runs once per step of the
     * agentic loop, so a per-call capture would re-read and YAML-parse the whole
     * project memory directory up to `maxSteps` times per turn.
     */
    public function testTheMemoryDirectoryIsReadOncePerRuntimeNotOncePerStep(): void
    {
        $this->store->add('a project convention', MemoryScope::Project);

        $provider = new PromptCapturingProvider();
        $app = App::new($provider, 'test-model')
            ->withMessages([new UserMessage('hi')])
            ->withMemoryStore($this->store);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));

        iterator_to_array($runtime->run($app));
        $first = $provider->lastSystemPrompt();

        // A note added between steps must NOT retroactively join a turn already
        // in flight - that is what "snapshot, not live state" means.
        $this->store->add('added after the snapshot', MemoryScope::Project);
        iterator_to_array($runtime->run($app));

        $this->assertSame($first, $provider->lastSystemPrompt());
        $this->assertStringNotContainsString('added after the snapshot', $provider->lastSystemPrompt());
    }

    public function testAFreshRuntimeSeesTheNewNote(): void
    {
        // The other half of the snapshot claim: the memoization is per-Runtime,
        // so the next turn's Runtime does pick the note up. Without this, the
        // test above would also pass for a block that never re-read at all.
        $this->store->add('first note', MemoryScope::Project);

        $provider = new PromptCapturingProvider();
        $app = App::new($provider, 'test-model')
            ->withMessages([new UserMessage('hi')])
            ->withMemoryStore($this->store);

        $this->promptFor($app, $provider);
        $this->store->add('second note', MemoryScope::Project);

        $this->assertStringContainsString('second note', $this->promptFor($app, $provider));
    }

    // -------------------------------------------------------------------------
    // EngineBackend -> App
    // -------------------------------------------------------------------------

    public function testTheBackendForwardsItsStoreOntoTheAppItBuilds(): void
    {
        $this->store->add('backend-routed note', MemoryScope::Project);

        $provider = new PromptCapturingProvider();
        $backend = (new EngineBackend($provider, 'test-model'))->withMemoryStore($this->store);

        $backend->complete([]);

        $this->assertStringContainsString('backend-routed note', $provider->lastSystemPrompt());
    }

    /**
     * The positional-reconstruction trap. EngineBackend's `with*()` methods each
     * rebuild the whole object with positional arguments, so a new constructor
     * parameter that is not threaded through every one of them is silently
     * dropped by whichever call comes next — and the store would look wired while
     * being lost by an ordinary builder chain.
     */
    public function testEveryBuilderMethodPreservesTheMemoryStore(): void
    {
        $this->store->add('survives the chain', MemoryScope::Project);

        $provider = new PromptCapturingProvider();
        $backend = (new EngineBackend($provider, 'test-model'))
            ->withMemoryStore($this->store)
            ->withTools([])
            ->withSkills([])
            ->withSkillRegistry(new \SugarCraft\Crush\Skills\SkillRegistry())
            ->withInstructionLoader(new InstructionFileLoader($this->dir))
            ->withRoot($this->dir)
            ->withMaxSteps(4)
            ->withPermissionGate(new PermissionGate(PermissionMode::Default))
            ->withoutHooks();

        $backend->complete([]);

        $this->assertStringContainsString(
            'survives the chain',
            $provider->lastSystemPrompt(),
            'a with*() method that forgot to thread $this->memoryStore would drop it here',
        );
    }

    /**
     * EVERY builder method, with the list DERIVED rather than written down.
     *
     * The method set comes from reflection, so a builder added later is covered
     * the day it exists instead of the day someone remembers this test. And
     * because a reflected method cannot be invoked without an argument, the
     * `$steps` map is checked for completeness against that reflected set: a new
     * `with*()` reds this test with its own name until it is added here, which is
     * the only version of "every builder method" that stays true.
     *
     * An earlier draft of this test hard-coded nine of the twelve and still
     * called itself "every builder method" — `withHooks()` and
     * `withWorktreeRoot()` were uncovered. That is the claim-beyond-its-domain
     * failure this suite keeps finding, so the fix is structural, not another
     * two entries.
     */
    public function testEveryBuilderMethodPreservesTheMemoryStoreByReflectedSet(): void
    {
        $property = new \ReflectionProperty(EngineBackend::class, 'memoryStore');
        $base = (new EngineBackend(new PromptCapturingProvider(), 'test-model'))
            ->withMemoryStore($this->store);

        $steps = [
            'withTools' => fn(EngineBackend $b): EngineBackend => $b->withTools([]),
            'withSkills' => fn(EngineBackend $b): EngineBackend => $b->withSkills([]),
            'withSkillRegistry' => fn(EngineBackend $b): EngineBackend => $b->withSkillRegistry(
                new \SugarCraft\Crush\Skills\SkillRegistry(),
            ),
            'withInstructionLoader' => fn(EngineBackend $b): EngineBackend => $b->withInstructionLoader(
                new InstructionFileLoader($this->dir),
            ),
            'withHooks' => fn(EngineBackend $b): EngineBackend => $b->withHooks(
                new HookManager(new HookRegistry()),
            ),
            'withRoot' => fn(EngineBackend $b): EngineBackend => $b->withRoot($this->dir),
            'withWorktreeRoot' => fn(EngineBackend $b): EngineBackend => $b->withWorktreeRoot($this->dir),
            'withMaxSteps' => fn(EngineBackend $b): EngineBackend => $b->withMaxSteps(2),
            'withPermissionGate' => fn(EngineBackend $b): EngineBackend => $b->withPermissionGate(
                new PermissionGate(PermissionMode::Default),
            ),
            'withPermissionApprover' => fn(EngineBackend $b): EngineBackend => $b->withPermissionApprover(
                static fn(): bool => true,
            ),
            'withoutHooks' => fn(EngineBackend $b): EngineBackend => $b->withoutHooks(),
            // withMemoryStore() is the setter itself, so "preserves" is not a
            // meaningful question for it; it is exercised by every other case.
            'withMemoryStore' => fn(EngineBackend $b): EngineBackend => $b->withMemoryStore($this->store),
        ];

        $reflected = [];
        foreach ((new \ReflectionClass(EngineBackend::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== EngineBackend::class) {
                continue;
            }
            if (preg_match('/^with(out)?[A-Z]/', $method->getName()) !== 1) {
                continue;
            }
            $reflected[] = $method->getName();
        }

        sort($reflected);
        $covered = array_keys($steps);
        sort($covered);

        $this->assertSame(
            $reflected,
            $covered,
            'every with*()/without*() builder on EngineBackend must be exercised here; '
            . 'a new one has to be added to $steps rather than silently uncovered',
        );
        $this->assertNotEmpty($reflected, 'reflection found no builders, so this test proves nothing');

        foreach ($steps as $name => $step) {
            $this->assertSame(
                $this->store,
                $property->getValue($step($base)),
                $name . '() dropped the memory store',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Bootstrap -> EngineBackend: the last hop, and the one that makes the
    // feature reachable from a real `bin/sugarcrush` run rather than only from
    // a test constructing the backend by hand.
    // -------------------------------------------------------------------------

    /**
     * DOMAIN: `backend()`'s EchoProvider fallback arm ONLY.
     *
     * Named for what it reaches, because the old name ("a real bootstrap-built
     * backend") claimed the whole of `Bootstrap`. `HOME` points at a fresh
     * directory with nothing persisted, and the whole selection chain —
     * `$SUGARCRUSH_PROVIDER`, `$SUGARCRUSH_BACKEND_CMD` and
     * `$SUGARCRUSH_BACKEND_CMD_STREAM` — is cleared in `setUp()`, so `backend()`
     * neither delegates to `backendFor()` nor drops to a shell-out tier; it
     * builds the Echo engine itself. Deleting `backendFor()`'s `->withMemoryStore(...)` left this test —
     * and the other 100 in this file's neighbourhood — green.
     * {@see testTheProviderSelectedBackendPathCarriesAMemoryStoreToo()} covers
     * the arm every configured user actually takes.
     */
    public function testBackendsEchoFallbackArmCarriesAMemoryStore(): void
    {
        $home = $this->dir . '/home';
        mkdir($home, 0o700, true);
        $originalHome = (string) getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $backend = Bootstrap::backend($this->dir);

            $this->assertInstanceOf(EngineBackend::class, $backend);
            $store = (new \ReflectionProperty(EngineBackend::class, 'memoryStore'))->getValue($backend);
            $this->assertInstanceOf(
                MemoryStore::class,
                $store,
                'without this hop the block is unreachable from a real run',
            );
        } finally {
            $originalHome === '' ? putenv('HOME') : putenv('HOME=' . $originalHome);
        }
    }

    /**
     * The OTHER construction arm, and the one every configured run takes.
     *
     * `backend()` delegates to `backendFor()` whenever `$SUGARCRUSH_PROVIDER` is
     * set (tier 1) or a provider is persisted (tier 4) — the two shell-out
     * variables in between return their own backends and never reach
     * `backendFor()` at all. Cited by tier rather than by line number: the two
     * numbers that stood here (`Bootstrap:1132` / `:1157`) had already drifted
     * off the lines they named. And
     * `Chat::selectPaletteProvider()` calls `backendFor()` directly for the
     * Ctrl+P / `/model` hot-swap. So this arm is the default for anyone who has
     * ever picked a provider, and deleting its `->withMemoryStore(...)` was
     * measured green across 101 tests: the kill came only from the Echo arm.
     *
     * `custom` is the one built-in provider type `ProviderFactory` constructs
     * with no credential in the environment.
     */
    public function testTheProviderSelectedBackendPathCarriesAMemoryStoreToo(): void
    {
        $home = $this->dir . '/configured-home';
        mkdir($home, 0o700, true);
        $originalHome = (string) getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $backend = Bootstrap::backendFor('custom', $this->dir);

            $this->assertInstanceOf(EngineBackend::class, $backend);
            $this->assertInstanceOf(
                MemoryStore::class,
                (new \ReflectionProperty(EngineBackend::class, 'memoryStore'))->getValue($backend),
                'a configured provider must reach the model with the same memory the Echo fallback does',
            );
        } finally {
            $originalHome === '' ? putenv('HOME') : putenv('HOME=' . $originalHome);
        }
    }

    /**
     * A memory directory that cannot be opened must cost the feature, never the
     * launch. `MemoryStore`'s constructor throws for an unwritable path, and
     * `Bootstrap::backend()` is on the startup path.
     */
    public function testAnUnusableMemoryDirectoryDegradesToNoBlockRatherThanFailingLaunch(): void
    {
        $home = $this->dir . '/readonly-home';
        $memory = $home . '/.sugar-crush/memory';
        mkdir($memory, 0o700, true);
        // An existing but UNWRITABLE memory directory. Chosen over the other
        // failure shapes (a file where the directory belongs, a missing parent)
        // because those make Bootstrap::ensureDir() call mkdir() on a path that
        // exists, and the resulting "File exists" PHP warning is red under this
        // suite's failOnWarning - a warning about a pre-existing helper rather
        // than about the behaviour under test. Here ensureDir() sees is_dir() and
        // does nothing, and MemoryStore's own is_writable() check is what throws.
        chmod($memory, 0o500);

        // Asserted rather than skipped: the premise fails only when the suite
        // runs as root, where is_writable() is true for any directory, and a
        // silent skip would hide that this scenario stopped testing anything.
        self::assertFalse(
            is_writable($memory),
            'this scenario needs a genuinely unwritable directory; running the suite as root defeats it',
        );

        $originalHome = (string) getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $backend = Bootstrap::backend($this->dir);

            $this->assertInstanceOf(EngineBackend::class, $backend, 'the launch must still succeed');
            $this->assertNull(
                (new \ReflectionProperty(EngineBackend::class, 'memoryStore'))->getValue($backend),
                'and degrade to no memory block',
            );
        } finally {
            $originalHome === '' ? putenv('HOME') : putenv('HOME=' . $originalHome);
        }
    }

    public function testTheBlockClassIsTheOneTheRuntimeUses(): void
    {
        // Guards against a second copy of this logic appearing: the prompt text
        // must come from MemoryBlock's own render(), constants included.
        $this->store->add('a note', MemoryScope::Project);

        $provider = new PromptCapturingProvider();
        $prompt = $this->promptFor(
            App::new($provider, 'test-model')
                ->withMessages([new UserMessage('hi')])
                ->withMemoryStore($this->store),
            $provider,
        );

        $this->assertStringContainsString(MemoryBlock::capture($this->store)->render(), $prompt);
    }
}

/**
 * Records the system prompt of the last request it was handed, which is the only
 * way to observe {@see Runtime::buildSystemPrompt()} — it is private, and its
 * output leaves the object only inside a {@see CompleteRequest}.
 */
final class PromptCapturingProvider implements ProviderInterface
{
    private string $lastSystemPrompt = '';

    public function lastSystemPrompt(): string
    {
        return $this->lastSystemPrompt;
    }

    public function name(): string
    {
        return 'prompt-capturing';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return 100_000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $this->lastSystemPrompt = $request->systemPrompt ?? '';

        return new CompleteResponse(content: 'ok');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield $this->complete($request);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}
