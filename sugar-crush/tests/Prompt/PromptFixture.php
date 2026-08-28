<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Prompt;

use DateTimeImmutable;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * A fully-controlled prompt-composition harness for tests.
 *
 * Builds a Runtime and an App over one private fixture repository — a real
 * directory under the system temp root — so a test can control EVERY context
 * half {@see Runtime::buildSystemPrompt()} reads: root instruction files
 * (written with {@see write()}), project memory (created lazily with
 * {@see memoryStore()}), the repo map (derived from composer.json manifests
 * written into the root) and skills ({@see addSkill()}). Nothing is mocked:
 * the assembled prompt comes from the real EnvironmentBlock, MemoryBlock,
 * RepoMapBlock, InstructionFileLoader and SkillMatcher chain, which is the
 * point — later phases assert on prompt content without each test re-deriving
 * the same temp-repo setup, and without a single host-dependent byte
 * (fixed cwd/date/platform via the P2.S1-injectable EnvironmentBlock).
 *
 * WHY THIS IS NOT IN tests/Support/: that directory is assigned wholesale to
 * another plan's in-flight lane, and tests/Support/DuplicatedTestHelperDriftTest.php
 * flags any helper byte-identical to one elsewhere in tests/. The fixture
 * therefore lives in its own directory (tests/Prompt/) and is implemented
 * distinctly rather than copied — the private-buildSystemPrompt invocation
 * below uses a scoped Closure where the other suites use ReflectionMethod.
 */
final class PromptFixture
{
    private string $root;

    private ?MemoryStore $store = null;

    /** @var list<Skill> */
    private array $skills = [];

    /**
     * @param ProviderInterface $provider Provider the fixture's App and Runtime
     *                                    use; defaults to the zero-arg
     *                                    {@see EchoProvider} so a plain
     *                                    `new PromptFixture()` is enough.
     * @param string            $model    Model name rendered in the environment
     *                                    block (and handed to App::new()).
     * @param ?DateTimeImmutable $now     Frozen timestamp for the environment
     *                                    block; defaults to a fixed date so the
     *                                    prompt is byte-deterministic.
     * @param ?string           $platform Injected platform string for the
     *                                    environment block (P2.S1), defaulting
     *                                    to 'linux' for the same reason.
     */
    public function __construct(
        private ProviderInterface $provider = new EchoProvider(),
        private string $model = 'test-model',
        private ?DateTimeImmutable $now = null,
        private ?string $platform = 'linux',
    ) {
        $this->root = sys_get_temp_dir() . '/crush_promptfix_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
        $this->now ??= new DateTimeImmutable('2026-01-15 12:00:00 UTC');
    }

    /** The fixture repository root, created (empty) at construction. */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Write a file inside the fixture repository, creating parent directories
     * as needed.
     *
     * This is the fixture's key wiring: instruction files, composer.json
     * manifests and source files all enter the prompt through here, so a
     * deleted body makes every migrated test go red (the deletion experiment).
     */
    public function write(string $relativePath, string $contents): self
    {
        $target = $this->root . '/' . ltrim($relativePath, '/');
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0o700, true);
        }
        file_put_contents($target, $contents);

        return $this;
    }

    /**
     * Write a composer.json-style manifest into the fixture repository.
     *
     * RepoMapBlock derives the repository map from these manifests only, so
     * writing one here is how a test controls what the map lists.
     */
    public function writeJson(string $relativePath, array $data): self
    {
        return $this->write($relativePath, (string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * The project memory store rooted inside the fixture repository, created
     * lazily on first call.
     *
     * The App built by {@see app()} carries the store only once this method
     * has been called, so a fixture that never asks for memory produces a
     * prompt with no memory block at all.
     */
    public function memoryStore(): MemoryStore
    {
        if ($this->store === null) {
            $this->store = new MemoryStore($this->memoryDir());
        }

        return $this->store;
    }

    /**
     * Register a skill the fixture's App enables AND lists as available.
     *
     * Both halves are wired because {@see Runtime::buildSystemPrompt()}
     * renders them separately: the full body via
     * {@see Skill::systemPromptContribution()} for `enabledSkills`, and the
     * level-1 name/description listing for `availableSkills`.
     */
    public function addSkill(Skill $skill): self
    {
        $this->skills[] = $skill;

        return $this;
    }

    /**
     * The App this fixture controls: rooted at the fixture repository, with an
     * instruction loader over it, plus the store and skills accumulated so
     * far.
     *
     * Immutable via App's own `with*()` chain, so a test can call e.g.
     * `->withMessages([...])` on the result without affecting later calls.
     */
    public function app(): App
    {
        $app = App::new($this->provider, $this->model)
            ->withRoot($this->root)
            ->withInstructionLoader(new InstructionFileLoader($this->root));

        if ($this->store !== null) {
            $app = $app->withMemoryStore($this->store);
        }

        if ($this->skills !== []) {
            $registry = new SkillRegistry();
            $registry->register($this->skills);
            $app = $app->withEnabledSkills($this->skills)->withAvailableSkills($registry);
        }

        return $app;
    }

    /**
     * Assemble the system prompt for `$app` (or the fixture's own default
     * App) through the REAL private {@see Runtime::buildSystemPrompt()}.
     *
     * Each call without an explicit `$runtime` gets a fresh Runtime injected
     * with a controlled EnvironmentBlock (fixture root, frozen date, fixed
     * platform), so prompts are byte-deterministic. Passing an explicit
     * Runtime — as the memoization tests do — leaves the snapshot lifetime
     * to that instance, which is the whole point of those tests.
     */
    public function systemPrompt(?App $app = null, ?Runtime $runtime = null): string
    {
        $app ??= $this->app();
        $runtime ??= new Runtime(
            $app->provider,
            new HookManager(new HookRegistry()),
            new EnvironmentBlock($this->root, $app->model, $this->now, $this->platform),
        );

        $build = \Closure::bind(
            static fn(Runtime $runtime, App $app): string => $runtime->buildSystemPrompt($app),
            null,
            Runtime::class,
        );

        return $build($runtime, $app);
    }

    /**
     * Remove the fixture repository from disk.
     *
     * Call from a consuming test's tearDown (or alongside the test's own
     * temp-dir tracking) so failed and passing runs clean up identically.
     */
    public function destroy(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * The directory MemoryStore requires to already exist and be writable —
     * the store's constructor throws rather than creating it.
     */
    private function memoryDir(): string
    {
        $dir = $this->root . '/.sugar-crush/memory';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }
}