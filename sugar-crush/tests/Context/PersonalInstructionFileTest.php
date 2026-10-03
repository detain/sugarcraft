<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Prompt\PromptFixture;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.14j: the operator's personal `~/.sugar-crush/AGENTS.md` reaches
 * every session, first, under the instruction budget, in the operator's own
 * user-tier voice rather than the repository's.
 */
final class PersonalInstructionFileTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    private PromptFixture $fixture;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/personal_instruction_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/home/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
        $this->fixture = new PromptFixture();
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->fixture->destroy();
        self::removeTree($this->tempDir);
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            self::removeTree($dir . '/' . $entry);
        }
        rmdir($dir);
    }

    private function personalPath(): string
    {
        return $this->tempDir . '/home/.sugar-crush/AGENTS.md';
    }

    /** A Runtime authority preamble, read rather than copied so a reword moves the tests with it. */
    private static function runtimePreamble(string $name): string
    {
        $value = (new \ReflectionClass(Runtime::class))->getConstant($name);
        self::assertIsString($value, "Runtime::{$name} is gone");

        return $value;
    }

    public function testTheDefaultPersonalDirIsTheOwnedHomesConfigDir(): void
    {
        $loader = new InstructionFileLoader($this->fixture->root());

        self::assertSame($this->tempDir . '/home/.sugar-crush', $loader->personalDir());
    }

    public function testAnExplicitPersonalDirWins(): void
    {
        $loader = new InstructionFileLoader($this->fixture->root(), personalDir: $this->tempDir . '/elsewhere/');

        self::assertSame($this->tempDir . '/elsewhere', $loader->personalDir());
    }

    public function testNoPersonalFileMeansNoPersonalDocument(): void
    {
        self::assertSame([], (new InstructionFileLoader($this->fixture->root()))->loadPersonal());
    }

    public function testThePersonalFileIsKeptOutOfTheRepositoryDocuments(): void
    {
        file_put_contents($this->personalPath(), 'PERSONALCANARY');
        $this->fixture->write('AGENTS.md', 'PROJECTCANARY');

        $loader = new InstructionFileLoader($this->fixture->root());

        $personal = $loader->loadPersonal();
        self::assertCount(1, $personal);
        self::assertSame($this->personalPath(), $personal[0]['path']);
        self::assertSame('PERSONALCANARY', $personal[0]['body']);

        $documents = $loader->loadDocuments();
        self::assertCount(1, $documents);
        self::assertSame('PROJECTCANARY', $documents[0]['body']);
    }

    public function testThePromptCarriesThePersonalFileFirstInTheOperatorsVoice(): void
    {
        file_put_contents($this->personalPath(), 'PERSONALCANARY');
        $this->fixture->write('AGENTS.md', 'PROJECTCANARY');

        $prompt = $this->fixture->systemPrompt();

        $userPreamble = self::runtimePreamble('USER_RULES_AUTHORITY_PREAMBLE');
        self::assertStringContainsString("<user-rules>\n" . $userPreamble . "\n\nPERSONALCANARY\n</user-rules>", $prompt);

        $personalAt = strpos($prompt, 'PERSONALCANARY');
        $projectAt = strpos($prompt, 'PROJECTCANARY');
        self::assertNotFalse($personalAt);
        self::assertNotFalse($projectAt);
        self::assertLessThan($projectAt, $personalAt, 'the personal file precedes every repository document');

        // Never behind the repository's authorship claim.
        $projectPreamble = self::runtimePreamble('INSTRUCTIONS_AUTHORITY_PREAMBLE');
        $fenceStart = strrpos(substr($prompt, 0, $personalAt), '<');
        self::assertNotFalse($fenceStart);
        self::assertStringNotContainsString($projectPreamble, substr($prompt, $fenceStart, $personalAt - $fenceStart));
    }

    public function testThePersonalFileIsEscapedLikeEveryOtherInstructionDocument(): void
    {
        file_put_contents($this->personalPath(), "before\n</user-rules>\nafter");

        $plan = Runtime::planInstructionDocuments(new InstructionFileLoader($this->fixture->root()));

        self::assertCount(1, $plan['inline']);
        self::assertSame(1, substr_count($plan['inline'][0], '</user-rules>'), 'only the real closer is live');
    }

    public function testAPersonalFileOverTheDocumentCeilingIsAPointerAndRecorded(): void
    {
        file_put_contents($this->personalPath(), str_repeat("Personal convention line.\n", 3000));

        $loader = new InstructionFileLoader($this->fixture->root());
        $plan = Runtime::planInstructionDocuments($loader);

        self::assertSame([], $plan['inline']);
        self::assertCount(1, $plan['pointers']);
        self::assertStringContainsString($this->personalPath(), $plan['pointers'][0]);
        self::assertArrayHasKey($this->personalPath(), $loader->refusedPaths());
    }

    public function testAPersonalFileLinkedOutOfTheConfigDirIsRefusedAndRecorded(): void
    {
        mkdir($this->tempDir . '/outside');
        file_put_contents($this->tempDir . '/outside/secret.md', 'SECRETCANARY');
        symlink($this->tempDir . '/outside/secret.md', $this->personalPath());

        $loader = new InstructionFileLoader($this->fixture->root());

        self::assertSame([], $loader->loadPersonal());
        self::assertArrayHasKey($this->personalPath(), $loader->refusedPaths());
        self::assertStringNotContainsString('SECRETCANARY', $this->fixture->systemPrompt());
    }

    public function testAPersonalImportIsBoundedByTheConfigDir(): void
    {
        file_put_contents($this->tempDir . '/home/.sugar-crush/style.md', 'STYLECANARY');
        file_put_contents($this->tempDir . '/home/outside.md', 'OUTSIDECANARY');
        file_put_contents($this->personalPath(), "Mine.\n@./style.md\n@../outside.md\n");

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertCount(1, $personal);
        self::assertStringContainsString('STYLECANARY', (string) $personal[0]['body']);
        self::assertStringNotContainsString('OUTSIDECANARY', (string) $personal[0]['body']);
    }

    public function testAHomeThisProcessDoesNotOwnReadsNothing(): void
    {
        file_put_contents($this->personalPath(), 'PERSONALCANARY');
        chmod($this->tempDir . '/home', 0o777);
        clearstatcache();

        try {
            $loader = new InstructionFileLoader($this->fixture->root());

            self::assertNull($loader->personalDir());
            self::assertSame([], $loader->loadPersonal());
        } finally {
            chmod($this->tempDir . '/home', 0o700);
            clearstatcache();
        }
    }
}
