<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Lang;

/**
 * ISS-036's drain half: a built-in a command file actually replaced is
 * announced to the launch transcript as ONE aggregate row, beside the row
 * that refuses an untrusted shadow.
 *
 * WHY THIS WAS A DEFECT AND NOT A GAP: the loader's own comment promised
 * "the seam Bootstrap drains at launch" while {@code CommandLoader::overrides()}
 * had no reader in `src/` at all — the sentence lived on stderr behind
 * {@see \SugarCraft\Code\Commands\CommandLoader::DEBUG_REFUSALS_ENV}, so the
 * launch notice the ISS-036 message claims ("every override is reported") was
 * true only of the refusal. An allowed shadow — the operator's own
 * `~/.candy-code/commands/compact.md`, or a trusted checkout's — replaced
 * `/compact` with nothing printed anywhere the operator would look.
 *
 * BOTH CHANNELS, ONE CHILD LAUNCH, the shape
 * {@see BootstrapLaunchNoticeRoutingTest} established and for its reasons:
 * only a real launch measures which call sites reach the seam.
 */
final class CommandOverrideLaunchNoticeTest extends TestCase
{
    private string $tmpDir = '';
    private string $home = '';
    private string $configDir = '';
    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/crush_override_notice_' . uniqid('', true);
        $this->home = $this->tmpDir . '/home';
        $this->configDir = $this->home . '/.candy-code';
        $this->projectRoot = $this->tmpDir . '/repo';

        mkdir($this->configDir, 0o700, true);
        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    /**
     * The aggregate row the drain must produce for one accepted shadow of
     * the given built-in by a file at $path in tier $tier.
     */
    private function expectedRow(string $name, string $tier, string $path): string
    {
        $sentence = Lang::t('cmd.loader.builtin-overridden', [
            'name' => $name,
            'tier' => $tier,
            'path' => $path,
        ]);

        return sprintf(Bootstrap::COMMAND_OVERRIDE_NOTICE_FORMAT, 1, '', $sentence);
    }

    /**
     * @return array{0: string, 1: list<string>} [stderr, transcript notice rows]
     */
    private function launch(): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tmpDir . '/launch.php';
        $errFile = $this->tmpDir . '/launch-stderr.txt';
        $outFile = $this->tmpDir . '/launch-stdout.txt';

        file_put_contents(
            $script,
            "<?php\nrequire " . var_export($autoload, true) . ";\n"
            . '\\SugarCraft\\Code\\Cli\\Bootstrap::chat(' . var_export($this->projectRoot, true) . ");\n"
            . "echo json_encode(\\SugarCraft\\Code\\Cli\\Bootstrap::launchNotices());\n",
        );

        // `default` for the same reason BootstrapLaunchNoticeRoutingTest pins
        // it: under the headless bypass default an orthogonal unavoidable
        // notice would ride into the exact-count assertion below.
        exec(sprintf(
            'HOME=%s CANDY_CODE_PERMISSION_MODE=default timeout -s KILL 20 %s %s >%s 2>%s',
            escapeshellarg($this->home),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ));

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        self::assertNotSame('', $stdout, "child launch produced no stdout; stderr was:\n" . (string) @file_get_contents($errFile));

        return [
            is_file($errFile) ? (string) file_get_contents($errFile) : '',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public function testAnAcceptedUserTierOverrideReachesBothChannelsAsOneAggregateRow(): void
    {
        self::assertFalse(CommandRegistry::isControlPlane('compact'), 'fixture: /compact must not be reserved');
        $path = $this->configDir . '/commands';
        mkdir($path, 0o700, true);
        file_put_contents($path . '/compact.md', "---\ndescription: mine\n---\nbody\n");
        $real = (string) realpath($path . '/compact.md');

        [$stderr, $notices] = $this->launch();

        $row = $this->expectedRow('compact', 'user', $real);
        self::assertContains($row, $notices, 'the override aggregate did not reach the transcript');
        self::assertSame(
            1,
            count(array_filter($notices, static fn (string $n): bool => str_contains($n, 'replaced by a command file'))),
            'the accepted override must be ONE aggregate row, whatever the wording around it',
        );
        self::assertStringContainsString($row, $stderr, 'the aggregate stopped reaching stderr beside the transcript');
    }

    public function testAnUntrustedProjectShadowIsRefusedWithoutAnOverrideRow(): void
    {
        // The drain must announce only what survived: a PROJECT-tier shadow
        // on an untrusted root is taken back by loadAll(), so reporting it as
        // an override would advertise a replacement that did not happen.
        $path = $this->projectRoot . '/' . LayeredSettings::dir() . '/commands';
        mkdir($path, 0o700, true);
        file_put_contents($path . '/compact.md', "---\ndescription: repo's\n---\nbody\n");

        [, $notices] = $this->launch();

        self::assertSame(
            [],
            array_values(array_filter($notices, static fn (string $n): bool => str_contains($n, 'replaced by a command file'))),
            'a refused shadow must not be announced as an override',
        );
        self::assertNotSame(
            [],
            array_values(array_filter($notices, static fn (string $n): bool => str_contains($n, 'compact.md'))),
            'fixture: the refusal itself must still reach the transcript, or this case proves nothing',
        );
    }
}
