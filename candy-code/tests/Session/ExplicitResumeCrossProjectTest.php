<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * ISS-026's residual half (rv6): an EXPLICIT `--resume <id>` of a session
 * recorded under another cwd reports the crossing on stderr, exactly like
 * the `--continue` ladder's fallback — the picker's answer told the user to
 * "pass --resume to pick deliberately", and the deliberate pick was then
 * silent about the very fact the answer warned of.
 *
 * The announcement itself lands on stderr (which PHPUnit does not capture, by
 * the {@see LatestResumableSessionCwdTest} doctrine), so the behaviour pins
 * run a real `openSession()` in a child process with fd 2 redirected to a
 * file, and the predicate behind it is pinned directly.
 */
final class ExplicitResumeCrossProjectTest extends TestCase
{
    private string $dir = '';
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-resume-picked-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/launch', 0700, true);
        mkdir($this->dir . '/other', 0700, true);
        $this->dbPath = $this->dir . '/session.db';
    }

    protected function tearDown(): void
    {
        // chr(42): the GlobDialect corpus harvests glob-shaped literals from
        // every file in the tree; spelling the wildcard by code keeps this
        // teardown out of the differential it derives.
        foreach (glob($this->dir . '/' . chr(42)) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/launch');
        @rmdir($this->dir . '/other');
        @rmdir($this->dir);
    }

    public function testARowRecordedUnderAnotherCwdAccusesItself(): void
    {
        self::assertSame('/other/project', self::predicate(['cwd' => '/other/project'], '/launch/project'));
    }

    public function testTheSameCwdAnUnknownRowAndAnUnknownLaunchNeverAccuse(): void
    {
        self::assertNull(self::predicate(['cwd' => '/launch/project'], '/launch/project'), 'the launch is where the session lives');
        self::assertNull(self::predicate([], '/launch/project'), 'a row with no recorded cwd is never accused (same rule as the continue ladder)');
        self::assertNull(self::predicate(['cwd' => ''], '/launch/project'), 'an empty recorded cwd is the same absence');
        self::assertNull(self::predicate(['cwd' => '/other/project'], ''), 'a launch with no getcwd cannot tell crossed from local');
    }

    public function testTheCrossedExplicitPickAnnouncesOnStderr(): void
    {
        $this->seedSession('a1b2c3d4e5f60718', (string) realpath($this->dir . '/other'));

        $stderr = $this->launchResumePick('a1b2c3d4e5f60718', $this->dir . '/launch');

        self::assertStringContainsString('--resume', $stderr);
        self::assertStringContainsString('another project', $stderr);
        self::assertStringContainsString(basename($this->dir . '/other'), $stderr, 'the notice must name the cwd the session came from');
        self::assertStringNotContainsString('--continue', $stderr, 'the pick must not borrow the ladder\'s sentence — it tells the user to do what they just did');
    }

    public function testALocalExplicitPickStaysSilent(): void
    {
        $this->seedSession('b2c3d4e5f6071829', (string) realpath($this->dir . '/launch'));

        $stderr = $this->launchResumePick('b2c3d4e5f6071829', $this->dir . '/launch');

        self::assertStringNotContainsString('another project', $stderr, 'a same-cwd resume must print nothing');
    }

    public function testTheAnnouncementIsWiredIntoTheExplicitArm(): void
    {
        // The child pins prove the arm prints; this proves a refactor cannot
        // quietly MOVE the print out of the arm that resolves an explicit
        // target (the picker's answer is the one promise being kept).
        $source = (string) file_get_contents((new \ReflectionClass(Bootstrap::class))->getFileName());

        self::assertSame(
            1,
            preg_match(
                '/self::findSession\(\$store, self::\$sessionLaunchTarget\);.*?crossProjectRowCwd\(.*?SESSION_RESUME_CROSS_PROJECT_PICKED_FORMAT/s',
                $source,
            ),
            'the explicit --resume arm no longer consults the crossed predicate and its format',
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function predicate(array $row, string $launchCwd): ?string
    {
        $method = new \ReflectionMethod(Bootstrap::class, 'crossProjectRowCwd');
        $method->setAccessible(true);

        return $method->invoke(null, $row, $launchCwd);
    }

    private function seedSession(string $id, string $cwd): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession($id, 'candy-code', 'test-model', null, null, $cwd, null);
        $store->saveTranscript($id, [Message::user('work in ' . $id)]);
    }

    /**
     * Run {@see Bootstrap::openSession()} for `--resume $id` with the process
     * started in $workdir, and answer what it wrote to fd 2.
     */
    private function launchResumePick(string $id, string $workdir): string
    {
        $script = $this->dir . '/launch.php';
        file_put_contents($script, implode("\n", [
            '<?php',
            'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';',
            'use SugarCraft\\Code\\Cli\\Bootstrap;',
            'use SugarCraft\\Code\\Session\\EnhancedSessionStore;',
            'Bootstrap::useSessionLaunch(false, true, $argv[1]);',
            '$open = Bootstrap::openSession(new EnhancedSessionStore($argv[2]));',
            'fwrite(STDOUT, (string) $open["id"]);',
        ]));
        $errFile = $this->dir . '/pick-stderr.txt';

        $process = proc_open(
            [PHP_BINARY, $script, $id, $this->dbPath],
            [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
            $pipes,
            $workdir,
        );
        self::assertIsResource($process, 'the pick child could not start');
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);

        self::assertSame(0, $status, 'pick child exited ' . $status . '; stderr: ' . (string) @file_get_contents($errFile));
        self::assertSame($id, $stdout, 'the child did not open the session it named');

        return (string) file_get_contents($errFile);
    }
}
