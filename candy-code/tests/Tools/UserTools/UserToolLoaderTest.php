<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\UserTools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\UserTools\UserToolLoader;
use SugarCraft\Code\Tools\UserTools\UserToolSpec;

/**
 * The user-tier tool loader (`C-TOOL-6.e`, plan_comparison.md).
 *
 * Every test runs against a sandboxed HOME ({@see HomeSandboxTrait}) — the
 * loader keys off {@see HomeDirectory::owned()}, so an unsandboxed suite
 * would read whatever the developer's real `~/.candy-code/tools` carries.
 * {@see testTheLoaderReadsTheSandboxedHomeNotSomeOtherDirectory()} is the
 * deliberate pin that the sandbox is real, per the design's one-test law.
 */
final class UserToolLoaderTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = $this->useHomeSandbox(sys_get_temp_dir() . '/c-tool-6-e-home-' . uniqid('', true));
        // The launch-notice store is process-global; KeychainTest's precedent
        // holds — bound it here so skip announcements cannot bleed.
        LaunchNotices::reset();
    }

    protected function tearDown(): void
    {
        LaunchNotices::reset();
        @chmod($this->home, 0o700);
        $this->restoreHomeSandbox();
        parent::tearDown();
    }

    public function testAValidFileLoadsAsASpec(): void
    {
        $path = $this->writeUserTool('fmt', ['argv' => ['fmt', '{{path}}']]);

        $loaded = UserToolLoader::load();

        self::assertSame([], $loaded['skips']);
        self::assertCount(1, $loaded['specs']);
        $spec = $loaded['specs'][0];
        self::assertInstanceOf(UserToolSpec::class, $spec);
        self::assertSame('fmt', $spec->name);
        self::assertSame(['fmt', '{{path}}'], $spec->argv);
        // The declared default when the file names no timeout.
        self::assertSame(60, $spec->timeoutSeconds);
        self::assertStringStartsWith($this->userToolDirectory(), $path);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusalShapes(): array
    {
        return [
            'not json' => ['{not json', 'bad_json'],
            'a list, not an object' => ['[]', 'bad_shape'],
            'an empty object' => ['{}', 'bad_shape'],
            'name not a slug' => [['name' => 'Bad Name', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls']], 'bad_name'],
            'name too long' => [['name' => 'a' . str_repeat('b', 48), 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls']], 'bad_name'],
            'empty description' => [['name' => 'ok', 'description' => '', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls']], 'bad_description'],
            'description over the bound' => [['name' => 'ok', 'description' => str_repeat('x', 1025), 'inputSchema' => ['type' => 'object'], 'argv' => ['ls']], 'bad_description'],
            'schema not an object type' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'array'], 'argv' => ['ls']], 'bad_schema'],
            'argv empty' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => []], 'bad_argv'],
            'argv with a number' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls', 5]], 'bad_argv'],
            'placeholder in the program word' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]], 'argv' => ['{{x}}', 'rest']], 'argv_placeholder'],
            'placeholder not declared' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls', '{{nope}}']], 'undeclared_placeholder'],
            'malformed placeholder' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls', 'a{{b']], 'undeclared_placeholder'],
            'timeout zero' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls'], 'timeout' => 0], 'bad_timeout'],
            'timeout past the bound' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls'], 'timeout' => 601], 'bad_timeout'],
            'timeout spelled as text' => [['name' => 'ok', 'description' => 'd', 'inputSchema' => ['type' => 'object'], 'argv' => ['ls'], 'timeout' => '60'], 'bad_timeout'],
        ];
    }

    /**
     * Each invalid shape is skipped with ITS OWN announcement naming the file —
     * the message is compared against {@see Lang::t()} of the expected key, so
     * every arm is also a catalogue-parity pin: the loader routes user-facing
     * text through Lang, never a raw literal.
     */
    public function testEveryInvalidShapeIsSkippedAndAnnounced(): void
    {
        foreach (self::refusalShapes() as $shape => [$payload, $class]) {
            $dir = $this->userToolDirectory();
            $path = $dir . '/' . $shape . '.json';
            self::assertFalse(is_file($path));
            file_put_contents($path, is_string($payload) ? $payload : (string) json_encode($payload));

            $loaded = UserToolLoader::load();

            self::assertSame([], $loaded['specs'], $shape . ' must not load');
            self::assertSame(
                [Lang::t('cli.usertools.skip.' . $class, ['file' => $path])],
                $loaded['skips'],
                $shape . ' must announce as ' . $class,
            );
            $this->cleanUserToolDirectory();
        }
    }

    /**
     * A name that validates is kept; 48 characters is the bound itself.
     */
    public function testTheNameBoundIsInclusiveAndTheTimeoutRideThrough(): void
    {
        $long = 'a' . str_repeat('b', 47);
        $this->writeUserTool('ride', [
            'name' => $long,
            'timeout' => 600,
            'argv' => ['wc', '-l', '{{path}}'],
            'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'extra' => ['type' => 'string']], 'required' => ['path', 'extra']],
        ]);

        $loaded = UserToolLoader::load();

        self::assertSame([], $loaded['skips']);
        self::assertSame($long, $loaded['specs'][0]->name);
        self::assertSame(600, $loaded['specs'][0]->timeoutSeconds);
        self::assertSame(['path', 'extra'], $loaded['specs'][0]->requiredProperties());
    }

    public function testAnUnreadableFileIsNamedAndSkipped(): void
    {
        $dir = $this->userToolDirectory();
        $path = $dir . '/secretish.json';
        file_put_contents($path, '{"name":"never","description":"d","inputSchema":{"type":"object"},"argv":["ls"]}');
        chmod($path, 0o000);

        $loaded = UserToolLoader::load();

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // A root suite reads any mode; the pin that matters (no crash, a
            // decided answer) holds in both polarities.
            self::assertSame([], $loaded['skips']);
            self::assertCount(1, $loaded['specs']);
        } else {
            self::assertSame([], $loaded['specs']);
            self::assertSame([Lang::t('cli.usertools.skip.unreadable', ['file' => $path])], $loaded['skips']);
        }
        chmod($path, 0o600);
    }

    public function testTheProjectTierIsNeverRead(): void
    {
        // A project-tier directory — the surface the ruling REFUSES.
        $projectRoot = sys_get_temp_dir() . '/c-tool-6-e-project-' . uniqid('', true);
        mkdir($projectRoot . '/' . UserToolLoader::DIRECTORY, 0o777, true);
        file_put_contents(
            $projectRoot . '/' . UserToolLoader::DIRECTORY . '/project-tool.json',
            (string) json_encode($this->userToolPayload(['name' => 'projtool'])),
        );

        self::assertSame([], UserToolLoader::load()['specs']);
        $names = array_map(static fn ($tool): string => $tool->name(), Bootstrap::userScriptTools($projectRoot));
        self::assertSame([], $names);

        // And a user-tier file loads under a DIFFERENT launch root (the memo
        // key carries it, like a second process), proving the root argument
        // selects nothing about where tools are read from — only HOME does.
        $this->writeUserTool('usertier');
        $otherRoot = sys_get_temp_dir() . '/c-tool-6-e-other-' . uniqid('', true);
        mkdir($otherRoot, 0o777, true);
        $names = array_map(static fn ($tool): string => $tool->name(), Bootstrap::userScriptTools($otherRoot));
        self::assertSame(['user__usertier'], $names);
    }

    public function testAnUnownedHomeYieldsNothing(): void
    {
        $this->writeUserTool('fmt');
        // The ownership law is HomeDirectory's: a world-writable home is
        // nobody's, so nothing is read from it (no crash, no notices).
        chmod($this->home, 0o777);

        $loaded = UserToolLoader::load();
        self::assertSame([], $loaded['specs']);
        self::assertSame([], $loaded['skips']);
        chmod($this->home, 0o700);
    }

    public function testADuplicateNameRefusesTheLaterFileAndNamesTheFirst(): void
    {
        $first = $this->writeUserTool('a-first.json', [], 'fmt');
        $second = $this->writeUserTool('b-second.json', [], 'fmt');

        $loaded = UserToolLoader::load();

        self::assertCount(1, $loaded['specs']);
        self::assertSame(
            [Lang::t('cli.usertools.skip.duplicate', ['file' => $second, 'name' => 'fmt', 'first' => $first])],
            $loaded['skips'],
        );
    }

    public function testTheRosterIsFrozenMidSessionAndAnnouncesOnce(): void
    {
        $this->writeUserTool('fmt');
        file_put_contents($this->home . '/' . UserToolLoader::DIRECTORY . '/broken-a.json', 'nope');
        $root = sys_get_temp_dir() . '/c-tool-6-e-root-' . uniqid('', true);
        mkdir($root, 0o777, true);

        $before = Bootstrap::userScriptTools($root);
        self::assertSame(['user__fmt'], array_map(static fn ($tool): string => $tool->name(), $before));
        $announced = LaunchNotices::rows();
        self::assertNotSame([], $announced, 'the skip must surface at launch');
        self::assertSame(1, $this->countUsertoolsRows($announced));

        // A file added mid-session (and another broken one) change NOTHING:
        // the roster is memoised per process, and skips are announced only by
        // the single derivation that read them.
        $this->writeUserTool('word');
        file_put_contents($this->home . '/' . UserToolLoader::DIRECTORY . '/broken-b.json', 'nope');
        $after = Bootstrap::userScriptTools($root);

        self::assertSame(['user__fmt'], array_map(static fn ($tool): string => $tool->name(), $after));
        self::assertSame($announced, LaunchNotices::rows());
    }

    /**
     * @param array<array-key, mixed> $rows
     */
    private function countUsertoolsRows(array $rows): int
    {
        // The rendered skip text (not the key) is what rows carry; compare on
        // the wording AFTER the file token, derived live from the catalogue.
        $probe = 'zz-' . uniqid('', true);
        $template = (string) Lang::t('cli.usertools.skip.bad_json', ['file' => $probe]);
        $tail = str_replace($probe, '', $template);
        $flat = json_encode($rows);
        self::assertIsString($flat);

        return substr_count($flat, $tail);
    }

    /**
     * The corpus guard's reachability evidence (DYNAMIC_TOOL_CLASSES): a
     * user-tier file reaches the launch roster through the SAME filtered
     * surface the MCP arm joins.
     */
    public function testTheLoadedToolsReachTheLaunchRoster(): void
    {
        $this->writeUserTool('fmt');
        $root = sys_get_temp_dir() . '/c-tool-6-e-reach-' . uniqid('', true);
        mkdir($root, 0o777, true);

        $names = array_map(static fn ($tool): string => $tool->name(), LaunchContext::unfilteredTools($root));

        self::assertContains('user__fmt', $names);
    }

    /**
     * The design's sandboxing pin: the loader reads the HOME the test owns,
     * not the real account home it stands in for.
     */
    public function testTheLoaderReadsTheSandboxedHomeNotSomeOtherDirectory(): void
    {
        $this->writeUserTool('fmt');
        self::assertSame($this->home, HomeDirectory::owned());

        $loaded = UserToolLoader::load();
        self::assertCount(1, $loaded['specs']);
        // owned() is the loader's only root and it answered with the sandbox;
        // the spec came from the file standing there, not the real home.
        self::assertFileExists($this->home . '/' . UserToolLoader::DIRECTORY . '/fmt.json');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function writeUserTool(string $nameOrFile, array $overrides = [], ?string $declaredName = null): string
    {
        $dir = $this->userToolDirectory();
        $file = str_ends_with($nameOrFile, '.json') ? $nameOrFile : $nameOrFile . '.json';
        $payload = $this->userToolPayload(array_merge(['name' => $declaredName ?? basename($file, '.json')], $overrides));
        $path = $dir . '/' . $file;
        file_put_contents($path, (string) json_encode($payload));

        return $path;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function userToolPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'fmt',
            'description' => 'format a thing',
            'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            'argv' => ['fmt', '{{path}}'],
        ], $overrides);
    }

    private function userToolDirectory(): string
    {
        $dir = $this->home . '/' . UserToolLoader::DIRECTORY;
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return $dir;
    }

    private function cleanUserToolDirectory(): void
    {
        foreach ((array) scandir($this->home . '/' . UserToolLoader::DIRECTORY) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($this->home . '/' . UserToolLoader::DIRECTORY . '/' . $entry)) {
                unlink($this->home . '/' . UserToolLoader::DIRECTORY . '/' . $entry);
            }
        }
    }
}
