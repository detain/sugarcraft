<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\LegacyMigration;
use SugarCraft\Code\Config\PolicySnapshot;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * The first-launch migration of the pre-rename home state. // @rename-keep
 *
 * Each case builds a temp HOME holding the OLD tree, runs // @rename-keep
 * {@see LegacyMigration::run()} against an in-memory stderr, and asserts the
 * exact contract the rename plan pins: whole-directory moves under the
 * ownership gate, a security state that reads identical after the move
 * (fingerprints, not vibes), refusal when the destination exists, refusal
 * under a held server lock WITH the actionable notice, and the env fold
 * where a name already set under the new prefix wins.
 */
final class LegacyMigrationTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    /** @var resource */
    private $stderr;

    /** @var list<string> env names this test set, cleared on tearDown */
    private array $touchedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/candy_code_legacy_' . uniqid((string) getmypid(), true);
        $this->useHomeSandbox($this->home);

        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $this->stderr = $stream;
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->touchedEnv = [];

        fclose($this->stderr);
        $this->restoreHomeSandbox();
        $this->purgeHomeTree();

        parent::tearDown();
    }

    public function testTheWholeHomeTreeMovesOntoTheNewName(): void
    {
        // The seeded tree is deliberately the OLD spelling. // @rename-keep
        $old = $this->home . '/.sugar-crush'; // @rename-keep
        $this->seedLegacyHome($old);

        LegacyMigration::run($this->stderr);

        self::assertFalse(is_dir($old), 'the legacy home directory must be gone after the move'); // @rename-keep
        $new = $this->home . '/.candy-code';
        self::assertSame('{"model":"probe"}', file_get_contents($new . '/config.json'));
        self::assertSame("hooks: {}\n", file_get_contents($new . '/hooks.yaml'));
        self::assertSame('{"pins":{"a":"b"}}', file_get_contents($new . '/mcp-trust.json'));
        self::assertSame('log bytes', file_get_contents($new . '/server/server.log'));
        self::assertSame('bytes', file_get_contents($new . '/cache/repomap.sqlite'));

        $perms = fileperms($new);
        self::assertIsInt($perms);
        self::assertSame(0o700, $perms & 0o777, 'rename() must carry the private mode, not a fresh umask-filtered one');

        $written = $this->takeStderr();
        self::assertStringContainsString('candy-code: moved ' . $old . ' -> ' . $new, $written); // @rename-keep
        self::assertStringNotContainsString('.config/sugarcraft-crush', $written, 'a second notice for an absent legacy dir is noise'); // @rename-keep
    }

    public function testTheMovedPolicySnapshotStaysFresh(): void
    {
        $old = $this->home . '/.sugar-crush'; // @rename-keep
        $this->seedLegacyHome($old);

        $record = [
            'version' => PolicySnapshot::VERSION,
            'userConfig' => PolicySnapshot::fingerprint($old . '/config.json'), // @rename-keep
            'userHooks' => PolicySnapshot::fingerprint($old . '/hooks.yaml'), // @rename-keep
            'trustedRoots' => [$this->home],
            'projectHooks' => [],
        ];
        PolicySnapshot::write(static fn (): string => $old, $record); // @rename-keep

        LegacyMigration::run($this->stderr);

        $new = $this->home . '/.candy-code';
        self::assertSame($record, PolicySnapshot::read(static fn (): string => $new));

        // The no-quarantine claim, measured: the recorded fingerprints still
        // match the moved bytes, so the launch-time comparison sees no change.
        self::assertSame($record['userConfig'], PolicySnapshot::fingerprint($new . '/config.json'));
        self::assertSame($record['userHooks'], PolicySnapshot::fingerprint($new . '/hooks.yaml'));
        self::assertSame('{"model":"probe"}', file_get_contents($new . '/config.json'));
    }

    public function testAnExistingCandyCodeDirBlocksTheMove(): void
    {
        $old = $this->home . '/.sugar-crush'; // @rename-keep
        $this->seedLegacyHome($old);
        $new = $this->home . '/.candy-code';
        mkdir($new, 0700, true);
        file_put_contents($new . '/config.json', '{"model":"mine"}');

        LegacyMigration::run($this->stderr);

        self::assertDirectoryExists($old); // @rename-keep
        self::assertSame('{"model":"mine"}', file_get_contents($new . '/config.json'), 'the move must never merge over live new-tree state');
        self::assertStringNotContainsString('moved ', $this->takeStderr());
    }

    public function testAHeldServerLockBlocksTheMoveAndSaysSo(): void
    {
        $old = $this->home . '/.sugar-crush'; // @rename-keep
        $this->seedLegacyHome($old);
        $lockPath = $old . '/server/server.lock'; // @rename-keep

        $holder = fopen($lockPath, 'c');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        try {
            LegacyMigration::run($this->stderr);

            self::assertDirectoryExists($old); // @rename-keep
            self::assertStringContainsString(
                'candy-code: a sugarcrush server is still running; quit it, then relaunch to migrate ~/.sugar-crush', // @rename-keep
                $this->takeStderr(),
            );
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    public function testLegacyEnvVarsFoldAndAPreSetNewNameWins(): void
    {
        putenv('SUGARCRUSH_LANEF_ONE=1'); // @rename-keep
        putenv('SUGARCRUSH_LANEF_TWO=old'); // @rename-keep
        putenv('CANDY_CODE_LANEF_TWO=new');
        $this->touchedEnv = ['SUGARCRUSH_LANEF_ONE', 'SUGARCRUSH_LANEF_TWO', 'CANDY_CODE_LANEF_TWO']; // @rename-keep

        LegacyMigration::run($this->stderr);

        self::assertSame('1', getenv('CANDY_CODE_LANEF_ONE'));
        self::assertSame('new', getenv('CANDY_CODE_LANEF_TWO'), 'a name the user already set under the new prefix must win');

        $written = $this->takeStderr();
        self::assertSame(1, substr_count($written, 'env SUGARCRUSH_LANEF_ONE is deprecated'), 'every folded var is reported exactly once'); // @rename-keep
        self::assertStringNotContainsString(
            'env SUGARCRUSH_LANEF_TWO is deprecated', // @rename-keep
            $written,
            'a shadowed old name was not imported, so it is not reported — the notice list is the import list',
        );
    }

    /**
     * Seed the pre-rename home layout; returns nothing — callers keep the // @rename-keep
     * old path they built themselves for the absence assertions.
     */
    private function seedLegacyHome(string $old): void
    {
        mkdir($old, 0700, true); // @rename-keep
        mkdir($old . '/server', 0700, true); // @rename-keep
        mkdir($old . '/cache', 0700, true); // @rename-keep
        file_put_contents($old . '/config.json', '{"model":"probe"}'); // @rename-keep
        file_put_contents($old . '/hooks.yaml', "hooks: {}\n"); // @rename-keep
        file_put_contents($old . '/mcp-trust.json', '{"pins":{"a":"b"}}'); // @rename-keep
        file_put_contents($old . '/server/server.log', 'log bytes'); // @rename-keep
        file_put_contents($old . '/cache/repomap.sqlite', 'bytes'); // @rename-keep
    }

    private function takeStderr(): string
    {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }

    private function purgeHomeTree(): void
    {
        if (!is_dir($this->home)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->home);
    }
}
