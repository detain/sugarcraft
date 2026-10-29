<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tests\Prompt\PromptFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.14j: the operator's personal `~/.candy-code/AGENTS.md` reaches
 * every session, first, under the instruction budget, in the operator's own
 * user-tier voice rather than the repository's. C-CTX-4.b adds the persona
 * files behind that head — `SOUL.md` and `USER.md` (OpenClaw names) — each
 * fenced, contained, ceilinged and priced by the very same gates, each an
 * absent-cheap stat when it is not there.
 */
final class PersonalInstructionFileTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    private PromptFixture $fixture;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/personal_instruction_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/home/.candy-code', 0o700, true);
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
        return $this->tempDir . '/home/.candy-code/AGENTS.md';
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

        self::assertSame($this->tempDir . '/home/.candy-code', $loader->personalDir());
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

    /**
     * Each inline instruction document's section is labelled by its own
     * fence, so the personal file's section says `<user-rules>` — the voice it
     * is framed in — and a repository document's says `<project-instructions>`.
     */
    public function testEachInstructionSectionIsLabelledByItsOwnFence(): void
    {
        file_put_contents($this->personalPath(), 'PERSONALCANARY');
        $this->fixture->write('AGENTS.md', 'PROJECTCANARY');

        $app = $this->fixture->app();
        $runtime = \Closure::bind(
            fn(\SugarCraft\Code\App\App $app): Runtime => $this->runtime($app),
            $this->fixture,
            \SugarCraft\Code\Tests\Prompt\PromptFixture::class,
        )($app);
        $sections = new \ReflectionMethod($runtime, 'systemPromptSections');

        $fences = [];
        foreach ($sections->invoke($runtime, $app) as $section) {
            foreach (['PERSONALCANARY', 'PROJECTCANARY'] as $canary) {
                if (str_contains($section->render(), $canary)) {
                    $fences[$canary] = $section->fence();
                }
            }
        }

        self::assertSame(
            ['PERSONALCANARY' => '<user-rules>', 'PROJECTCANARY' => '<project-instructions>'],
            $fences,
        );
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
        file_put_contents($this->tempDir . '/home/.candy-code/style.md', 'STYLECANARY');
        file_put_contents($this->tempDir . '/home/outside.md', 'OUTSIDECANARY');
        file_put_contents($this->personalPath(), "Mine.\n@./style.md\n@../outside.md\n");

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertCount(1, $personal);
        self::assertStringContainsString('STYLECANARY', (string) $personal[0]['body']);
        self::assertStringNotContainsString('OUTSIDECANARY', (string) $personal[0]['body']);
    }

    private function claudeFallbackPath(): string
    {
        return $this->tempDir . '/home/.claude/CLAUDE.md';
    }

    public function testTheClaudePersonalFileStandsInWhenOursIsAbsent(): void
    {
        mkdir($this->tempDir . '/home/.claude', 0o700, true);
        file_put_contents($this->claudeFallbackPath(), 'CLAUDECANARY');

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertCount(1, $personal);
        self::assertSame($this->claudeFallbackPath(), $personal[0]['path']);
        self::assertStringContainsString('CLAUDECANARY', (string) $personal[0]['body']);
    }

    public function testThePrimaryPersonalFileKeepsTheFallbackUnread(): void
    {
        mkdir($this->tempDir . '/home/.claude', 0o700, true);
        file_put_contents($this->claudeFallbackPath(), 'CLAUDECANARY');
        file_put_contents($this->personalPath(), 'PRIMARYCANARY');

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertCount(1, $personal);
        self::assertSame($this->personalPath(), $personal[0]['path']);
        self::assertStringNotContainsString('CLAUDECANARY', (string) $personal[0]['body']);
    }

    public function testAFallbackFileLinkedOutOfClaudeDirIsRefusedAndRecorded(): void
    {
        mkdir($this->tempDir . '/home/.claude', 0o700, true);
        mkdir($this->tempDir . '/outside');
        file_put_contents($this->tempDir . '/outside/secret.md', 'SECRETCANARY');
        symlink($this->tempDir . '/outside/secret.md', $this->claudeFallbackPath());

        $loader = new InstructionFileLoader($this->fixture->root());

        self::assertSame([], $loader->loadPersonal());
        self::assertArrayHasKey($this->claudeFallbackPath(), $loader->refusedPaths());
        self::assertStringNotContainsString('SECRETCANARY', $this->fixture->systemPrompt());
    }

    public function testAFallbackImportIsBoundedByTheClaudeDir(): void
    {
        mkdir($this->tempDir . '/home/.claude', 0o700, true);
        file_put_contents($this->tempDir . '/home/.claude/style.md', 'CLAUDESTYLE');
        file_put_contents($this->tempDir . '/home/.candy-code/sneak.md', 'CANDYCODESNEAK');
        file_put_contents($this->claudeFallbackPath(), "Mine.\n@./style.md\n@../.candy-code/sneak.md\n");

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertCount(1, $personal);
        self::assertStringContainsString('CLAUDESTYLE', (string) $personal[0]['body']);
        // The fallback's anchor is ITS directory: crossing into the neighbouring
        // .candy-code tree is exactly the escape the primary's own bound refuses.
        self::assertStringNotContainsString('CANDYCODESNEAK', (string) $personal[0]['body']);
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

    /**
     * INT-M2: a containment-refused primary must not silently close the
     * personal channel. The refusal still records its loud provenance line,
     * but `~/.claude/CLAUDE.md` then stands in exactly as it does when no
     * primary exists — the gate's job is refusing the escaped symlink, not
     * un-housing the operator's other personal file.
     */
    public function testARefusedPrimaryFallsThroughToTheClaudeFallback(): void
    {
        mkdir($this->tempDir . '/outside');
        file_put_contents($this->tempDir . '/outside/secret.md', 'SECRETCANARY');
        symlink($this->tempDir . '/outside/secret.md', $this->personalPath());
        mkdir($this->tempDir . '/home/.claude', 0o700, true);
        file_put_contents($this->claudeFallbackPath(), 'CLAUDECANARY');

        $loader = new InstructionFileLoader($this->fixture->root());
        $personal = $loader->loadPersonal();

        self::assertCount(1, $personal);
        self::assertSame($this->claudeFallbackPath(), $personal[0]['path']);
        self::assertStringContainsString('CLAUDECANARY', (string) $personal[0]['body']);
        self::assertStringNotContainsString('SECRETCANARY', (string) $personal[0]['body']);
        self::assertArrayHasKey($this->personalPath(), $loader->refusedPaths());
    }

    /**
     * INT-M2 polarity: when BOTH personal candidates escape their own
     * directories, both refusals are recorded and the personal channel still
     * yields nothing — the fall-through inherits the fallback's own gate, it
     * does not widen it.
     */
    public function testBothPersonalCandidatesLinkedOutRecordBothRefusals(): void
    {
        mkdir($this->tempDir . '/outside');
        file_put_contents($this->tempDir . '/outside/secret.md', 'SECRETCANARY');
        symlink($this->tempDir . '/outside/secret.md', $this->personalPath());
        mkdir($this->tempDir . '/home/.claude', 0o700, true);
        symlink($this->tempDir . '/outside/secret.md', $this->claudeFallbackPath());

        $loader = new InstructionFileLoader($this->fixture->root());

        self::assertSame([], $loader->loadPersonal());
        self::assertArrayHasKey($this->personalPath(), $loader->refusedPaths());
        self::assertArrayHasKey($this->claudeFallbackPath(), $loader->refusedPaths());
    }

    // ── C-CTX-4.b: the persona files ──────────────────────────────────

    private function soulPath(): string
    {
        return $this->tempDir . '/home/.candy-code/SOUL.md';
    }

    private function userPath(): string
    {
        return $this->tempDir . '/home/.candy-code/USER.md';
    }

    public function testThePersonaFilesLoadBehindTheHeadInOrder(): void
    {
        file_put_contents($this->personalPath(), 'HEADCANARY');
        file_put_contents($this->soulPath(), 'SOULCANARY');
        file_put_contents($this->userPath(), 'USERCANARY');

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertSame(
            [$this->personalPath(), $this->soulPath(), $this->userPath()],
            array_column($personal, 'path'),
            'the head leads and the personas ride in const order',
        );
        self::assertSame(
            ['HEADCANARY', 'SOULCANARY', 'USERCANARY'],
            array_column($personal, 'body'),
        );
    }

    /**
     * The absent-file law for the persona tier: a candidate that is not a
     * file costs one stat and adds nothing — the personal list with only the
     * head written is byte-for-byte the single-candidate list that existed
     * before the personas joined it.
     */
    public function testAbsentPersonaFilesCostNothing(): void
    {
        file_put_contents($this->personalPath(), 'HEADCANARY');

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertSame(
            [['path' => $this->personalPath(), 'body' => 'HEADCANARY', 'bytes' => 10, 'imports' => []]],
            $personal,
        );
    }

    /**
     * Every personal document is fenced in the operator's own voice under the
     * shared instruction budget, head first and personas behind it — the
     * framing AGENTS.md always had, per file, never a merged blob.
     */
    public function testThePromptCarriesEveryPersonalFileFencedInTheOperatorsVoice(): void
    {
        file_put_contents($this->personalPath(), 'HEADCANARY');
        file_put_contents($this->soulPath(), 'SOULCANARY');
        file_put_contents($this->userPath(), 'USERCANARY');
        $this->fixture->write('AGENTS.md', 'PROJECTCANARY');

        $plan = Runtime::planInstructionDocuments(new InstructionFileLoader($this->fixture->root()));

        $seen = [];
        foreach ($plan['inline'] as $section) {
            foreach (['HEADCANARY', 'SOULCANARY', 'USERCANARY', 'PROJECTCANARY'] as $canary) {
                if (str_contains($section, $canary)) {
                    $seen[] = $canary;
                }
            }
        }

        self::assertSame(
            ['HEADCANARY', 'SOULCANARY', 'USERCANARY', 'PROJECTCANARY'],
            $seen,
            'the three personal documents lead the inline list in their own order, the repository behind them',
        );

        $preamble = self::runtimePreamble('USER_RULES_AUTHORITY_PREAMBLE');
        $project = self::runtimePreamble('INSTRUCTIONS_AUTHORITY_PREAMBLE');
        foreach (['HEADCANARY', 'SOULCANARY', 'USERCANARY'] as $canary) {
            self::assertStringContainsString(
                "<user-rules>\n" . $preamble . "\n\n" . $canary . "\n</user-rules>",
                $plan['inline'][array_search($canary, $seen, true)],
                "{$canary} rides the operator's voice, not the repository's",
            );
        }
        self::assertStringNotContainsString($project, $plan['inline'][0]);
    }

    /**
     * C-CTX-4.b containment: a persona symlinked out of the personal directory
     * is refused AND RECORDED like the head's own escape — and read sideways,
     * the INT-M2 law says one escaped candidate never un-houses the others:
     * the head and the surviving persona keep their channel open.
     */
    public function testAPersonaLinkedOutOfThePersonalDirIsRefusedAndTheOthersSurvive(): void
    {
        file_put_contents($this->personalPath(), 'HEADCANARY');
        mkdir($this->tempDir . '/outside');
        file_put_contents($this->tempDir . '/outside/secret.md', 'SECRETCANARY');
        symlink($this->tempDir . '/outside/secret.md', $this->soulPath());
        file_put_contents($this->userPath(), 'USERCANARY');

        $loader = new InstructionFileLoader($this->fixture->root());
        $personal = $loader->loadPersonal();

        self::assertSame(
            [$this->personalPath(), $this->userPath()],
            array_column($personal, 'path'),
        );
        self::assertArrayHasKey($this->soulPath(), $loader->refusedPaths());
        self::assertStringContainsString('resolves outside', $loader->refusedPaths()[$this->soulPath()]);
        self::assertStringNotContainsString('SECRETCANARY', $this->fixture->systemPrompt());
    }

    /**
     * The `~/.claude` stand-in answers for the HEAD slot only: with no
     * AGENTS.md, the fallback leads and the persona files still ride behind
     * it, each still contained by the personal directory, the fallback by its
     * own.
     */
    public function testTheClaudeStandInTakesTheHeadSlotBehindWhichPersonasRide(): void
    {
        mkdir($this->tempDir . '/home/.claude', 0o700, true);
        file_put_contents($this->claudeFallbackPath(), 'CLAUDECANARY');
        file_put_contents($this->soulPath(), 'SOULCANARY');

        $personal = (new InstructionFileLoader($this->fixture->root()))->loadPersonal();

        self::assertSame(
            [$this->claudeFallbackPath(), $this->soulPath()],
            array_column($personal, 'path'),
        );
        self::assertStringNotContainsString('CLAUDECANARY', (string) $personal[1]['body']);
    }

    /**
     * The ceiling law is the head's law per file: an oversized persona
     * becomes its own null-body pointer entry — it does not silence the head
     * or the other persona.
     */
    public function testAPersonaOverTheDocumentCeilingIsAPointerEntryAlone(): void
    {
        file_put_contents($this->personalPath(), 'HEADCANARY');
        file_put_contents($this->userPath(), str_repeat("Persona convention line.\n", 3000));

        $loader = new InstructionFileLoader($this->fixture->root());
        $personal = $loader->loadPersonal();

        self::assertSame([$this->personalPath(), $this->userPath()], array_column($personal, 'path'));
        self::assertSame('HEADCANARY', $personal[0]['body']);
        self::assertNull($personal[1]['body']);
        self::assertGreaterThan(0, $personal[1]['bytes']);

        $plan = Runtime::planInstructionDocuments($loader);
        self::assertStringContainsString($this->userPath(), implode("\n", $plan['pointers']));
    }

    /**
     * The Context-panel truth (C-CTX-4.b): {@see \SugarCraft\Code\Host\ContextSources::priceInstructionFiles()}
     * prices every personal document as its OWN personal-tier row, in loader
     * order, beside the repository rows — no per-file special case lives in
     * that loop, so the persona files join the panel by the same door the head
     * always used.
     */
    public function testEachExistingPersonaIsItsOwnPersonalTierPriceRow(): void
    {
        file_put_contents($this->personalPath(), 'HEADCANARY');
        file_put_contents($this->soulPath(), 'SOULCANARY');
        file_put_contents($this->userPath(), 'USERCANARY');
        $this->fixture->write('CLAUDE.md', "Repo rules.\n");

        $priced = \SugarCraft\Code\Host\ContextSources::priceInstructionFiles($this->fixture->root());

        self::assertNotNull($priced, 'the pricing seam refuses to answer for a readable root');
        $personalRows = array_values(array_filter($priced['files'], static fn(array $r): bool => $r['personal']));
        self::assertSame(
            [$this->personalPath(), $this->soulPath(), $this->userPath()],
            array_column($personalRows, 'path'),
        );
        foreach ($personalRows as $row) {
            self::assertGreaterThan(0, $row['bytes'], 'a priced row carries the document size');
        }
        $repoRows = array_values(array_filter($priced['files'], static fn(array $r): bool => !$r['personal']));
        self::assertNotEmpty($repoRows, 'the repository document keeps its own unpersonal row');
    }
}
