<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Support\Version;

/**
 * The phar stamp reader (DST-1 lineage) and the 053 dev-stamp naming:
 * rolling builds must print their commit, not the frozen constant.
 *
 * @covers \SugarCraft\GitServer\Support\Version
 */
final class VersionTest extends TestCase
{
    private string $tmpDir;

    /** @var list<string> */
    private array $phars = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-version-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->phars as $phar) {
            @\unlink($phar);
        }
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Mint a throwaway archive carrying exactly $version in its metadata.
     * Hosts with phar.readonly=1 cannot create archives at all — the arms
     * that need one skip honestly instead of pinning nothing.
     */
    private function stampedPhar(string $version): ?string
    {
        $path = $this->tmpDir . '/probe-' . \bin2hex(\random_bytes(6)) . '.phar';
        try {
            $phar = new \Phar($path);
            $phar->setStub("<?php echo 'probe'; __HALT_COMPILER();");
            // Phars must ship at least one entry or the reader refuses the
            // manifest ('zero entries' is treated as corrupt), so the probe
            // carries a payload byte.
            $phar->addFromString('probe.txt', 'x');
            $phar->setMetadata(['version' => $version]);
            unset($phar); // the write lands on close
        } catch (\Throwable $e) {
            @\unlink($path);
            $this->markTestSkipped('phar creation unavailable on this host: ' . $e->getMessage());
        }
        $this->phars[] = $path;

        return $path;
    }

    public function testReleaseStampStillReadsBackAsTheBareTriple(): void
    {
        $phar = $this->stampedPhar(Version::TAG_PREFIX . 'v0.2.0');
        $this->assertNotNull($phar);

        $this->assertSame('0.2.0', Version::fromPharMetadata($phar));
        $this->assertSame('0.2.0', Version::resolve($phar, '0.1.0'));
        $this->assertSame('0.2.0', Version::displayLabel($phar, '0.1.0'), 'a release build names itself, no parenthetical');
    }

    public function testDevStampIsNamedNotDropped(): void
    {
        // 053's core: the phar-latest law stamps every rolling build
        // dev-<shortsha> precisely so the artifact can name its commit —
        // the old COMPARABLE-only reader threw that identity away and the
        // whole dev train printed the compiled-in constant.
        $phar = $this->stampedPhar('dev-abc1234');
        $this->assertNotNull($phar);

        $this->assertSame('dev-abc1234', Version::fromPharMetadata($phar));
        $label = Version::displayLabel($phar, '0.1.0');
        $this->assertSame('0.1.0 (dev-abc1234)', $label);
        $this->assertStringContainsString('dev-abc1234', $label, 'the smoke gate greps the RAW stamp out of the label');
    }

    public function testCommitlessDevFamilyWordsStayUnreadable(): void
    {
        // 'dev-build' (build-phar's emergency fallback) and 'dev-master'
        // classify as dev but name no commit; null keeps their runs on
        // the plain constant instead of rendering a fake precision.
        foreach (['dev-build', 'dev-master', 'dev-', 'dev'] as $stamp) {
            $phar = $this->stampedPhar($stamp);
            $this->assertNotNull($phar);
            $this->assertNull(Version::fromPharMetadata($phar), "the word {$stamp} names no commit");
            $this->assertSame('0.1.0', Version::displayLabel($phar, '0.1.0'));
        }
    }

    public function testJunkAndMissingArchivesAnswerNullNotThrow(): void
    {
        // The DST-1 promise: a corrupt or absent archive must not crash
        // --version. Type-garbage, missing key, and non-phar file all
        // take the silent-fallback leg.
        $notAPhar = $this->tmpDir . '/plain.txt';
        \file_put_contents($notAPhar, 'not a phar at all');

        $this->assertNull(Version::fromPharMetadata(''));
        $this->assertNull(Version::fromPharMetadata($this->tmpDir . '/absent.phar'));
        $this->assertNull(Version::fromPharMetadata($notAPhar));
        $this->assertSame('0.1.0', Version::resolve($notAPhar, '0.1.0'));

        $junk = $this->stampedPhar('v0.1.0.1');
        $this->assertNotNull($junk);
        $this->assertNull(Version::fromPharMetadata($junk), 'four segments order nobody: refused');
    }

    public function testTagPrefixAndVersionVariantsThatDoNotBelongStayNull(): void
    {
        $phar = $this->stampedPhar('candy-code/v0.9.0');
        $this->assertNotNull($phar);

        // A FOREIGN namespace stamp inside our archive is not readable as
        // OUR release identity once the foreign prefix defeats stripping.
        $this->assertNull(Version::fromPharMetadata($phar));
    }

    public function testIsDevIsTheLiteralPrefixRule(): void
    {
        // Mirror of candy-code's rule, literal and trim-free: the prefix
        // classifies; the DEV_STAMP grammar is what separates nameable
        // commits from words.
        $this->assertTrue(Version::isDev('dev-abc1234'));
        $this->assertTrue(Version::isDev('dev-master'), 'classification is grammar-blind by design');
        $this->assertTrue(Version::isDev('DEV-abc1234'), 'the rule lowercases before testing');
        $this->assertFalse(Version::isDev('0.1.0'));
        $this->assertFalse(Version::isDev('0.1.0 (dev-abc1234)'));
    }

    public function testDevStampGrammarNamesCommitsNotWords(): void
    {
        // The constant is used directly (not re-wrapped) so this pin
        // covers the EXACT pattern shipped, modifiers included.
        $this->assertSame(1, \preg_match(Version::DEV_STAMP, 'dev-abc1234'), 'the canonical 7-hex stamp');
        $this->assertSame(1, \preg_match(Version::DEV_STAMP, 'dev-abcd'), 'git\'s own 4-digit minimum');
        $this->assertSame(1, \preg_match(Version::DEV_STAMP, 'dev-' . \str_repeat('0', 40)), 'a full object id');
        $this->assertSame(1, \preg_match(Version::DEV_STAMP, 'dev-ABCDEF'), 'hex may arrive uppercase');
        $this->assertSame(0, \preg_match(Version::DEV_STAMP, 'dev-abc'), 'three digits is not an abbreviation');
        $this->assertSame(0, \preg_match(Version::DEV_STAMP, 'dev-master'));
        $this->assertSame(0, \preg_match(Version::DEV_STAMP, 'dev-build'));
    }

    public function testOutsideAnArchiveTheConstantSimplyPrints(): void
    {
        // The plain-checkout path (the overwhelmingly common one for
        // packagers reading the repo, not the phar): no parenthetical,
        // no dev classification of the constant itself.
        $this->assertSame('0.1.0', Version::displayLabel('', '0.1.0'));
        $this->assertSame('0.1.0', Version::displayLabel($this->tmpDir . '/absent.phar', '0.1.0'));
    }
}
