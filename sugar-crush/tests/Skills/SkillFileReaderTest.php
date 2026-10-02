<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillFileReader;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit 15d-27: every read of a skill's files is bounded.
 *
 * Before {@see SkillFileReader}, the manifest stage read a whole `SKILL.md` to
 * find its frontmatter, the body stage read it whole again, and the asset and
 * eager (`Skill::fromFile()`) reads had no cap either — a repository's 50 MB
 * `SKILL.md` was loaded into memory at every launch. The oversized files here
 * are SPARSE (`ftruncate()` past the written head), so a test costs no disk,
 * and the memory assertions use `memory_reset_peak_usage()` so the peak they
 * read is this read's and not some earlier test's.
 */
final class SkillFileReaderTest extends TestCase
{
    use HomeSandboxTrait;
    use TemporaryDirectoryTrait;

    private const HUGE_BYTES = 50 * 1024 * 1024;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-skill-read-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
    }

    public function testReadReturnsAFileUnderTheCeilingWhole(): void
    {
        $path = $this->tempDir . '/small.md';
        file_put_contents($path, "---\ndescription: x\n---\nBody.\n");

        $this->assertSame("---\ndescription: x\n---\nBody.\n", SkillFileReader::read($path, 'SKILL.md'));
    }

    public function testReadAcceptsAFileOfExactlyTheCeiling(): void
    {
        $path = $this->tempDir . '/exact.md';
        file_put_contents($path, str_repeat('a', SkillFileReader::MAX_FILE_BYTES));

        $this->assertSame(SkillFileReader::MAX_FILE_BYTES, strlen(SkillFileReader::read($path, 'SKILL.md')));
    }

    public function testReadRefusesAFileOverTheCeilingWithoutLoadingIt(): void
    {
        $path = $this->sparseSkill($this->tempDir . '/huge', self::HUGE_BYTES) . '/SKILL.md';

        $before = $this->resetPeak();
        $caught = null;
        try {
            SkillFileReader::read($path, 'SKILL.md');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'a 50 MB file must be refused');
        $this->assertStringContainsString('SKILL.md is 52,428,800 bytes, over the 1,048,576-byte skill-file ceiling', $caught->getMessage());
        $this->assertStringContainsString($path, $caught->getMessage());
        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before, 'the refusal must come from the stat, not from reading the file');
    }

    public function testReadOfAMissingFileIsARuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read skill asset: ');

        SkillFileReader::read($this->tempDir . '/nope', 'skill asset');
    }

    public function testHeadReadsOnlyTheFrontmatterWindowAndSaysTheFileGoesOn(): void
    {
        $path = $this->tempDir . '/long.md';
        file_put_contents($path, "---\ndescription: x\n---\n" . str_repeat('b', 500_000));

        [$head, $truncated] = SkillFileReader::head($path, 'SKILL.md');

        $this->assertSame(SkillFileReader::MAX_FRONTMATTER_BYTES, strlen($head));
        $this->assertTrue($truncated);
        $this->assertStringStartsWith("---\ndescription: x\n---\n", $head);
    }

    public function testHeadOfASmallFileIsTheWholeFile(): void
    {
        $path = $this->tempDir . '/short.md';
        file_put_contents($path, "---\ndescription: x\n---\nBody.");

        $this->assertSame(["---\ndescription: x\n---\nBody.", false], SkillFileReader::head($path, 'SKILL.md'));
    }

    /**
     * The finding's suggested test, at the layer a launch enters through: a
     * project skill whose `SKILL.md` is 50 MB costs the launch nothing like its
     * size, is not listed (its body could never be read, so offering it would
     * promise the model something the Skill tool must refuse), and is reported
     * through skipped() — the launch notice — with the reason.
     */
    public function testAFiftyMegabyteProjectSkillIsRefusedAndReportedWithoutBeingRead(): void
    {
        $projectRoot = $this->tempDir . '/project';
        $file = $this->sparseSkill($projectRoot . '/.sugar-crush/skills/huge', self::HUGE_BYTES) . '/SKILL.md';
        $this->writeSkill($projectRoot . '/.sugar-crush/skills/fine', 'A normal skill');

        $registry = new SkillRegistry();
        $loader = new SkillLoader(reportSkips: false);

        $before = $this->resetPeak();
        (new SkillManager($loader, $registry))->loadAll($projectRoot);
        $peak = memory_get_peak_usage() - $before;

        $this->assertLessThan(8 * 1024 * 1024, $peak, 'peak memory must stay far below the 50 MB file');
        $this->assertNull($registry->get('huge'));
        $this->assertNotNull($registry->get('fine'), 'a sibling skill still loads');

        $key = array_key_exists($file, $loader->skipped()) ? $file : (string) realpath($file);
        $this->assertArrayHasKey($key, $loader->skipped());
        $this->assertStringContainsString('over the 1,048,576-byte skill-file ceiling', $loader->skipped()[$key]);
    }

    /**
     * The same file in a FOREIGN tree, which goes through the eager
     * `Skill::fromFile()` read rather than the manifest one.
     */
    public function testAFiftyMegabyteImportedSkillIsRefusedAndReportedWithoutBeingRead(): void
    {
        $projectRoot = $this->tempDir . '/project-foreign';
        $file = $this->sparseSkill($projectRoot . '/.claude/skills/huge', self::HUGE_BYTES) . '/SKILL.md';

        $registry = new SkillRegistry();
        $loader = new SkillLoader(reportSkips: false);

        $before = $this->resetPeak();
        (new SkillManager($loader, $registry))->loadAll($projectRoot);

        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertNull($registry->get('huge'));
        $this->assertArrayHasKey($file, $loader->skipped());
        $this->assertStringContainsString('over the 1,048,576-byte skill-file ceiling', $loader->skipped()[$file]);
    }

    /**
     * Under the whole-file ceiling the manifest still reads only the head: a
     * 900 KB skill lists with its frontmatter, and the read's peak stays near
     * the frontmatter window rather than the file.
     */
    public function testTheManifestStageReadsOnlyTheHeadOfALargeSkill(): void
    {
        $dir = $this->tempDir . '/big';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\ndescription: A big skill\n---\n" . str_repeat("line of body text\n", 50_000));
        $this->assertGreaterThan(800_000, filesize($dir . '/SKILL.md'));

        $before = $this->resetPeak();
        $manifest = (new SkillLoader(reportSkips: false))->loadSkillManifest($dir);

        $this->assertSame('A big skill', $manifest['description']);
        $this->assertLessThan(400_000, memory_get_peak_usage() - $before, 'the manifest stage must not read the body');
    }

    public function testAFrontmatterBlockThatDoesNotCloseInsideTheWindowIsRefused(): void
    {
        $dir = $this->tempDir . '/unclosed';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\ndescription: x\n" . str_repeat("k: v\n", 20_000) . "---\nBody.\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('frontmatter does not close within its first 65,536 bytes');

        (new SkillLoader(reportSkips: false))->loadSkillManifest($dir);
    }

    public function testTheBodyStageRefusesAFileOverTheCeiling(): void
    {
        $file = $this->sparseSkill($this->tempDir . '/body', SkillFileReader::MAX_FILE_BYTES + 1) . '/SKILL.md';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('over the 1,048,576-byte skill-file ceiling');

        (new SkillLoader(reportSkips: false))->loadSkillBody($file);
    }

    public function testTheEagerReadRefusesAFileOverTheCeiling(): void
    {
        $file = $this->sparseSkill($this->tempDir . '/eager', SkillFileReader::MAX_FILE_BYTES + 1) . '/SKILL.md';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('skill file is 1,048,577 bytes, over the 1,048,576-byte skill-file ceiling');

        Skill::fromFile($file);
    }

    public function testTheAssetStageRefusesAFileOverTheCeiling(): void
    {
        $skillDir = $this->tempDir . '/with-asset';
        $this->writeSkill($skillDir, 'Has an asset');
        mkdir($skillDir . '/references', 0777, true);
        $handle = fopen($skillDir . '/references/big.txt', 'wb');
        self::assertIsResource($handle);
        ftruncate($handle, SkillFileReader::MAX_FILE_BYTES + 1);
        fclose($handle);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('skill asset is 1,048,577 bytes, over the 1,048,576-byte skill-file ceiling');

        (new SkillLoader(reportSkips: false))->loadSkillAsset($skillDir . '/SKILL.md', 'references/big.txt');
    }

    public function testAnAssetUnderTheCeilingIsReturnedWhole(): void
    {
        $skillDir = $this->tempDir . '/with-small-asset';
        $this->writeSkill($skillDir, 'Has an asset');
        mkdir($skillDir . '/scripts', 0777, true);
        file_put_contents($skillDir . '/scripts/run.sh', "#!/bin/sh\necho hi\n");

        $this->assertSame(
            "#!/bin/sh\necho hi\n",
            (new SkillLoader(reportSkips: false))->loadSkillAsset($skillDir . '/SKILL.md', 'scripts/run.sh'),
        );
    }

    /**
     * A skill directory whose SKILL.md has a real frontmatter head and is then
     * extended, sparse, to $bytes.
     */
    private function sparseSkill(string $dir, int $bytes): string
    {
        mkdir($dir, 0777, true);
        $handle = fopen($dir . '/SKILL.md', 'wb');
        self::assertIsResource($handle);
        fwrite($handle, "---\ndescription: Enormous\n---\nBody.\n");
        ftruncate($handle, $bytes);
        fclose($handle);

        return $dir;
    }

    private function writeSkill(string $dir, string $description): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/SKILL.md', "---\ndescription: {$description}\n---\n\nBody.\n");
    }

    /** Start a fresh peak window and return the usage it starts from. */
    private function resetPeak(): int
    {
        memory_reset_peak_usage();

        return memory_get_usage();
    }
}
