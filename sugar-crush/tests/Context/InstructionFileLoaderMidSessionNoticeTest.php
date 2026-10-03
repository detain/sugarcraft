<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * Audit R1's last residual: a NESTED instruction file `loadForPath()` refuses
 * or defers mid-session — a `src/CLAUDE.md` linked out of the checkout, one
 * over the 60 KiB document ceiling — reached the model as a pointer or as
 * nothing, and the user was never told. The launch notice cannot cover it:
 * the file is only reached when a tool touches a path under it.
 *
 * Each refusal is now one UI-only transcript row through the mid-session
 * notice seam ({@see RuntimeNoticeSink}), said once per session.
 */
final class InstructionFileLoaderMidSessionNoticeTest extends TestCase
{
    private string $sandbox;

    private string $repo;

    private string|false $errorLog = false;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-r1-midsession-' . bin2hex(random_bytes(6));
        $this->repo = $this->sandbox . '/repo';
        mkdir($this->repo . '/src', 0700, true);
        mkdir($this->sandbox . '/outside', 0700, true);
        file_put_contents($this->sandbox . '/outside/shared.md', "# shared rules\n");
        file_put_contents($this->repo . '/src/x.php', "<?php\n");

        // RuntimeNoticeSink::warn() also writes the row to the error log;
        // keep that off the test runner's stderr.
        $this->errorLog = ini_get('error_log');
        ini_set('error_log', $this->sandbox . '/error.log');

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        ini_set('error_log', $this->errorLog === false ? '' : $this->errorLog);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->sandbox);
    }

    public function testANestedInstructionFileLinkedOutOfTheCheckoutIsAnnounced(): void
    {
        symlink($this->sandbox . '/outside/shared.md', $this->repo . '/src/CLAUDE.md');
        $loader = new InstructionFileLoader($this->repo);

        self::assertNull($loader->loadForPath($this->repo . '/src/x.php'), 'fixture: the link is refused');

        self::assertSame(
            [sprintf(
                InstructionFileLoader::MID_SESSION_REFUSAL_NOTICE_FORMAT,
                'src/CLAUDE.md',
                'src/x.php',
                'resolves outside the checkout (' . realpath($this->repo) . ')',
            )],
            RuntimeNoticeSink::drain(),
        );
    }

    public function testAnOversizedNestedInstructionFileIsAnnounced(): void
    {
        file_put_contents($this->repo . '/src/CLAUDE.md', str_repeat('x', InstructionFileLoader::MAX_DOCUMENT_BYTES + 1));
        $loader = new InstructionFileLoader($this->repo);

        $content = $loader->loadForPath($this->repo . '/src/x.php');

        self::assertStringContainsString('src/CLAUDE.md', (string) $content, 'fixture: the model gets a pointer');
        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices);
        self::assertStringStartsWith('Instruction file src/CLAUDE.md was left out', $notices[0]);
        self::assertStringContainsString('over the 61,440-byte instruction-document ceiling', $notices[0]);
    }

    /** Every later touch under the same file says nothing more. */
    public function testEachRefusalIsAnnouncedOnce(): void
    {
        symlink($this->sandbox . '/outside/shared.md', $this->repo . '/src/CLAUDE.md');
        file_put_contents($this->repo . '/src/y.php', "<?php\n");
        $loader = new InstructionFileLoader($this->repo);

        $loader->loadForPath($this->repo . '/src/x.php');
        RuntimeNoticeSink::drain();
        $loader->loadForPath($this->repo . '/src/y.php');
        $loader->loadForPath($this->repo . '/src/x.php');

        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * The touched path's OWN refusal is about the file the agent opened, not
     * about an instruction file: reading something outside the checkout is
     * not news.
     */
    public function testTouchingAPathOutsideTheCheckoutSaysNothing(): void
    {
        $loader = new InstructionFileLoader($this->repo);

        self::assertNull($loader->loadForPath($this->sandbox . '/outside/shared.md'));

        self::assertNotSame([], $loader->refusedPaths(), 'fixture: the touch was recorded as refused');
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /** A nested file that loads is not a refusal and raises nothing. */
    public function testALoadedNestedFileSaysNothing(): void
    {
        file_put_contents($this->repo . '/src/CLAUDE.md', "# src rules\n");

        self::assertStringContainsString('# src rules', (string) (new InstructionFileLoader($this->repo))->loadForPath($this->repo . '/src/x.php'));
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * A forked tool child's announcement comes back with its other session
     * marks, so the next forked call does not say it again.
     */
    public function testTheAnnouncedMarkCrossesAForkedToolChildsSessionState(): void
    {
        symlink($this->sandbox . '/outside/shared.md', $this->repo . '/src/CLAUDE.md');
        $child = new Read(instructionLoader: new InstructionFileLoader($this->repo));
        $parentLoader = new InstructionFileLoader($this->repo);
        $parent = new Read(instructionLoader: $parentLoader);

        $child->execute(['file_path' => $this->repo . '/src/x.php']);
        self::assertCount(1, RuntimeNoticeSink::drain(), 'fixture: the child announced it');
        $parent->mergeSessionState(json_decode(json_encode($child->exportSessionState()), true));

        $parentLoader->loadForPath($this->repo . '/src/x.php');

        self::assertSame([], RuntimeNoticeSink::drain());
    }
}
