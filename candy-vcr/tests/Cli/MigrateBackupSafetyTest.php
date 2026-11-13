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
 * The in-place migrate backup door (findings 017/059).
 *
 * The .bak is the artifact a user forgets on a shared runner and it
 * holds the FULL session — every keystroke and --env value. The pre-fix
 * `@copy()` landed it at the ambient umask (measured 0600 source ->
 * 0664 .bak), silently overwrote any existing backup, and happily wrote
 * through a planted symlink (CWE-59). These tests pin all three doors
 * plus byte fidelity of the copy itself.
 */
final class MigrateBackupSafetyTest extends TestCase
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

    public function testBackupLandsOwnerOnlyEvenUnderUmaskZero(): void
    {
        $input = $this->writeCassette('cv-safety-', 1);
        $backup = $input . '.bak';

        // Worst-case ambient policy: a 000 umask would mint a 0666 copy
        // if the door relied on it. The chmod-before-content contract must
        // pin 0600 regardless.
        $umask = umask(0);
        try {
            $exit = $this->migrate($input);
        } finally {
            umask($umask);
        }

        $this->assertSame(0, $exit['code']);
        $this->assertFileExists($backup);
        $this->assertSame(0600, fileperms($backup) & 0777, 'backup must be owner-only, not umask-luck');
    }

    public function testExistingBackupIsRefusedAndLeftIntact(): void
    {
        $input = $this->writeCassette('cv-safety-', 1);
        $backup = $input . '.bak';
        $preMigrationBytes = (string) file_get_contents($input);

        // The "second run" state: a previous backup already owns the name.
        // Pre-fix copy() destroyed it without a word.
        file_put_contents($backup, 'PREVIOUS-BACKUP');
        $this->paths[] = $backup;

        $result = $this->migrate($input);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('already exists', $result['stderr']);
        $this->assertStringContainsString('refusing to overwrite', $result['stderr']);
        $this->assertSame('PREVIOUS-BACKUP', file_get_contents($backup), 'the only prior copy must survive');
        $this->assertSame($preMigrationBytes, file_get_contents($input), 'refusal must have zero side effects');
    }

    public function testPlantedSymlinkAtBackupNameIsRefusedAndNotWrittenThrough(): void
    {
        $input = $this->writeCassette('cv-safety-', 1);
        $backup = $input . '.bak';
        $victim = $this->scratch('cv-victim-');
        file_put_contents($victim, 'VICTIM-CONTENT');
        $this->paths[] = $backup;
        symlink($victim, $backup);

        $result = $this->migrate($input);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('symlink', $result['stderr']);
        $this->assertSame('VICTIM-CONTENT', file_get_contents($victim), 'CWE-59: the link target must be untouched');
        $this->assertTrue(is_link($backup));
    }

    public function testDanglingSymlinkAtBackupNameIsRefused(): void
    {
        $input = $this->writeCassette('cv-safety-', 1);
        $backup = $input . '.bak';
        // A name that does NOT exist — symlink() to a missing target is
        // the arm where file_exists() lies and only is_link()/O_EXCL
        // catches the attack.
        $ghost = sys_get_temp_dir() . '/cv-safety-ghost-' . bin2hex(random_bytes(6));
        symlink($ghost, $backup);
        $this->paths[] = $backup;

        $result = $this->migrate($input);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('symlink', $result['stderr']);
    }

    public function testBackupCarriesTheExactPreMigrationBytes(): void
    {
        $input = $this->writeCassette('cv-safety-', 1);
        $backup = $input . '.bak';
        $preMigrationBytes = (string) file_get_contents($input);

        $result = $this->migrate($input);
        $this->paths[] = $backup;

        $this->assertSame(0, $result['code']);
        $this->assertSame($preMigrationBytes, file_get_contents($backup));
        // The input itself moved on — proof the copy is of the OLD state.
        $this->assertNotSame($preMigrationBytes, file_get_contents($input));
        $this->assertStringContainsString('"v":2', (string) file_get_contents($input));
        $this->assertStringContainsString('backed up original to', $result['stdout']);
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function migrate(string $inputPath): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $code = (new MigrateCommand())->run([$inputPath], $stdout, $stderr);
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
