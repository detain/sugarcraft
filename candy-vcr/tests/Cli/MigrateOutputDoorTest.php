<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Cli\MigrateCommand;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * The migrate output doors (finding 058).
 *
 * Before this pair of doors, `migrate a.cas a.cas` destroyed the input
 * without the .bak the implicit in-place form is required to make, and
 * `migrate a.cas b.cas` silently clobbered an existing b.cas. The fix:
 * an output that realpath-folds onto the input is IN-PLACE whatever
 * spelling it wore, and an existing distinct output needs --force.
 */
final class MigrateOutputDoorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testExplicitSamePathIsFoldedIntoTheBackupDoor(): void
    {
        $input = $this->writeCassette('cv-door-', 1);
        $backup = $input . '.bak';
        $this->paths[] = $backup;

        $result = $this->migrate([$input, $input]);

        $this->assertSame(0, $result['code']);
        $this->assertFileExists($backup, 'the same-path spelling must pay the in-place backup tax');
        $this->assertStringContainsString('"v":1', (string) file_get_contents($backup), 'the backup holds the pre-migration state');
        $this->assertStringContainsString('"v":2', (string) file_get_contents($input));
        $this->assertStringContainsString('backed up original to', $result['stdout']);
    }

    public function testDotSegmentSpellingCannotDodgeTheSamePathDoor(): void
    {
        $input = $this->writeCassette('cv-door-', 1);
        $backup = $input . '.bak';
        $this->paths[] = $backup;

        // realpath('/tmp/x') === realpath('/tmp/./x'): the door must see
        // through the spelling so the backup cannot be dodged by `./`.
        $spelled = dirname($input) . '/./' . basename($input);
        $result = $this->migrate([$input, $spelled]);

        $this->assertSame(0, $result['code']);
        $this->assertFileExists($backup, './ spelling must fold onto the input, not clobber it');
    }

    public function testExistingDistinctOutputIsRefusedWithoutForce(): void
    {
        $input = $this->writeCassette('cv-door-', 1);
        $output = $this->scratch('cv-out-');
        file_put_contents($output, 'PRE-EXISTING');
        $preMigrationBytes = (string) file_get_contents($input);

        $result = $this->migrate([$input, $output]);

        $this->assertSame(2, $result['code']);
        $this->assertStringContainsString('already exists', $result['stderr']);
        $this->assertStringContainsString('--force', $result['stderr']);
        $this->assertSame('PRE-EXISTING', file_get_contents($output), 'refusal must have zero side effects');
        $this->assertSame($preMigrationBytes, file_get_contents($input));
        $this->assertFalse(file_exists($input . '.bak'), 'a refusal before the write made no backup');
    }

    public function testForceOverwritesDistinctOutputAndLeavesInputAlone(): void
    {
        $input = $this->writeCassette('cv-door-', 1);
        $output = $this->scratch('cv-out-');
        file_put_contents($output, 'PRE-EXISTING');
        $preMigrationBytes = (string) file_get_contents($input);

        $result = $this->migrate([$input, $output, '--force']);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('"v":2', (string) file_get_contents($output));
        $this->assertSame($preMigrationBytes, file_get_contents($input), 'explicit output is never an in-place migrate');
        $this->assertFalse(file_exists($input . '.bak'), 'backups belong to the in-place door only');
    }

    public function testFreshDistinctOutputNeedsNoForce(): void
    {
        $input = $this->writeCassette('cv-door-', 1);
        $output = dirname($input) . '/cv-fresh-' . bin2hex(random_bytes(6)) . '.cas';
        $this->paths[] = $output;
        $preMigrationBytes = (string) file_get_contents($input);

        $result = $this->migrate([$input, $output]);

        $this->assertSame(0, $result['code'], $result['stderr']);
        $this->assertStringContainsString('"v":2', (string) file_get_contents($output));
        $this->assertSame($preMigrationBytes, file_get_contents($input));
    }

    /**
     * @param list<string> $args
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function migrate(array $args): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $code = (new MigrateCommand())->run($args, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [
            'code' => $code,
            'stdout' => (string) stream_get_contents($stdout),
            'stderr' => (string) stream_get_contents($stderr),
        ];
    }

    private function writeCassette(string $prefix, int $version): string
    {
        $path = $this->scratch($prefix);
        $cassette = new Cassette(
            new CassetteHeader(
                version: $version,
                createdAt: '2026-05-07T10:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        );
        (new JsonlFormat())->write($cassette, $path);

        return $path;
    }

    private function scratch(string $prefix): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), $prefix);
        $this->paths[] = $path;

        return $path;
    }
}
