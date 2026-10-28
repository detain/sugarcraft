<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\InstructionsHostCommand;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-CTX-3.a: `/instructions [open <n|path>]`.
 *
 * Driven as a real submitted draft through `Chat::update(new KeyMsg(KeyType
 * ::Enter))` — the routing, the row and the exec-channel Cmd are all the
 * user-visible thing under test — plus the served half through
 * {@see InstructionsHostCommand} directly, which is what `serve` and the
 * headless host run. The listing rows are pinned against a temp project root
 * holding one AGENTS.md and a sandboxed home holding its personal file, the
 * two files this session's prompt actually reads; the numbers on the rows are
 * what `open <n>` resolves against, so the open tests read them as the list
 * printed them.
 *
 * The jail is the heart of it: an open names either a listed path, an index,
 * or the personal file (created empty first when absent). Everything else is
 * refused BY NAME, and a link standing where the personal file would be is
 * never followed — quick-add's write law, re-pinned here at the create-on-open
 * door. Edits reaching the model is InstructionDiffTracker's already-shipped
 * turn-boundary law (C-CTX-1.a); this lane adds no hook because the tracker
 * needs none, and that absence is the point of the arm's shape.
 */
final class InstructionsCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $rootDir = '';
    private string $rootAgents = '';
    private string $personalPath = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/cc-cmp-ctx3a-' . bin2hex(random_bytes(6));
        $this->rootDir = $this->sandbox . '/project';
        mkdir($this->rootDir, 0700, true);
        $this->rootAgents = $this->rootDir . '/AGENTS.md';
        file_put_contents($this->rootAgents, '# Project rules' . "\n");
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->personalPath = $this->sandbox . '/home/.candy-code/' . InstructionFileLoader::PERSONAL_FILENAME;
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    /** Submit $draft against a Chat rooted at the temp project. */
    private function submit(string $draft): array
    {
        $chat = new Chat(
            history: [],
            projectRoot: $this->rootDir,
            inputBuf: $draft,
            backend: new EchoBackend(),
        );

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    private function transcriptText(Chat $chat): string
    {
        $text = '';
        foreach ($chat->history as $message) {
            $text .= $message->content . "\n";
        }

        return $text;
    }

    private function writePersonalFile(string $body): void
    {
        mkdir(dirname($this->personalPath), 0700, true);
        file_put_contents($this->personalPath, $body);
    }

    public function testTheListingAnswersOneNumberedRowPerSessionInstructionFile(): void
    {
        $this->writePersonalFile('personal bytes' . "\n");

        [$next, $cmd] = $this->submit('/instructions');

        self::assertNull($cmd, 'the listing never arms a terminal hand-over');
        self::assertFalse($next->inFlight);
        $transcript = $this->transcriptText($next);
        self::assertStringContainsString('Instruction files:', $transcript);
        self::assertStringContainsString('1) ', $transcript, 'the root document is row 1 — the same order `open <n>` resolves against');
        self::assertStringContainsString($this->rootAgents, $transcript);
        self::assertStringContainsString('2) ', $transcript);
        self::assertStringContainsString($this->personalPath, $transcript);
        self::assertStringContainsString('(personal)', $transcript, 'the tier badge is /context\'s own vocabulary');
        self::assertLessThan(
            // assertLessThan(expected, actual): the personal row is the upper
            // bound, the root row must sit below it — project rows first.
            strpos($transcript, $this->personalPath),
            strpos($transcript, $this->rootAgents),
            'project rows come before personal rows, loader order',
        );

    }

    public function testOpenByIndexArmsTheEditorOnTheRowThatIndexShows(): void
    {
        [$next, $cmd] = $this->submit('/instructions open 1');

        self::assertNotNull($cmd, 'the terminal hand-back must ride the Cmd channel');
        self::assertFalse($next->inFlight);
        $request = $cmd();
        self::assertInstanceOf(ExecRequest::class, $request);
        self::assertStringEndsWith(escapeshellarg($this->rootAgents), (string) $request->command);
    }

    public function testOpenBySpelledPathArmsTheEditorOnTheListedFile(): void
    {
        [$next, $cmd] = $this->submit('/instructions open ' . $this->rootAgents);

        self::assertNotNull($cmd);
        $request = $cmd();
        self::assertStringEndsWith(escapeshellarg($this->rootAgents), (string) $request->command);
    }

    public function testAPathOutsideTheListIsRefusedByName(): void
    {
        $outside = $this->rootDir . '/scratchpad.md';
        file_put_contents($outside, 'not an instruction file' . "\n");

        [$next, $cmd] = $this->submit('/instructions open ' . $outside);

        self::assertNull($cmd, 'a real file the session does not read must never reach an editor');
        self::assertStringContainsString('Not opening `' . $outside . '`', $this->transcriptText($next));
    }

    public function testOpenWithoutPickAnswersUsage(): void
    {
        [$next, $cmd] = $this->submit('/instructions open');

        self::assertNull($cmd);
        self::assertStringContainsString('Usage: /instructions [open <n|path>]', $this->transcriptText($next));
    }

    public function testAnIndexOffTheEndAnswersLoudly(): void
    {
        [$next, $cmd] = $this->submit('/instructions open 9');

        self::assertNull($cmd);
        self::assertStringContainsString('No instruction file #9', $this->transcriptText($next));
    }

    public function testAnyOtherVerbKeepsTheRoutersUsageRow(): void
    {
        [$next, $cmd] = $this->submit('/instructions prune');

        self::assertNull($cmd);
        self::assertStringContainsString('Usage: /instructions', $this->transcriptText($next));
    }

    public function testThePersonalFileIsCreatedEmptyWhenOpenedBeforeItExists(): void
    {
        self::assertFalse(is_file($this->personalPath));

        [$next, $cmd] = $this->submit('/instructions open ' . $this->personalPath);

        self::assertNotNull($cmd, 'the personal instruction file is jailed in even before it exists');
        $request = $cmd();
        self::assertStringEndsWith(escapeshellarg($this->personalPath), (string) $request->command);
        self::assertFileExists($this->personalPath);
        self::assertSame(0, filesize($this->personalPath), 'created empty — the editor carries the first bytes, the command invents none');
        $mode = fileperms($this->personalPath) & 0777;
        self::assertSame(0600, $mode, 'the created file is the operator\'s own');
    }

    public function testALinkAtThePersonalNameIsNeverFollowed(): void
    {
        $victim = $this->rootDir . '/victim.md';
        file_put_contents($victim, 'do not write through me' . "\n");
        mkdir(dirname($this->personalPath), 0700, true);
        symlink($victim, $this->personalPath);

        [$next, $cmd] = $this->submit('/instructions open ' . $this->personalPath);

        self::assertNull($cmd, 'quick-add\'s link law holds at the create-on-open door too');
        self::assertStringContainsString('Not opening `' . $this->personalPath . '`', $this->transcriptText($next));
        self::assertStringNotContainsString('do not write through me', $this->transcriptText($next));
    }

    public function testTheServedFormListsRowsAndAnswersNeedsATerminalForOpen(): void
    {
        $this->writePersonalFile('served personal bytes' . "\n");

        $listed = (new InstructionsHostCommand())->run(
            CommandContext::new(root: $this->rootDir),
            '/instructions',
        );
        $listText = '';
        foreach ($listed->rows as $row) {
            $listText .= $row->content . "\n";
        }
        self::assertStringContainsString('1) ', $listText, 'the served listing rides the same rows the wire reads');
        self::assertStringContainsString($this->rootAgents, $listText);

        $opened = (new InstructionsHostCommand())->run(
            CommandContext::new(root: $this->rootDir),
            '/instructions open 1',
        );
        self::assertSame([], $opened->effects, 'a headless open arms nothing — there is no terminal to hand over');
        $served = '';
        foreach ($opened->rows as $row) {
            $served .= $row->content;
        }
        self::assertStringContainsString('needs an interactive terminal', $served);
    }

    public function testTheListingRowBuilderIsTheWireRowBuilder(): void
    {
        // The panel, the wire and the open-jailed list all come from
        // ContextSources::instructionRows(); this pins that identity against
        // the price result it transforms, so a fork between the two shapes is
        // caught here rather than by three surfaces disagreeing on screen.
        $files = [['path' => '/x/AGENTS.md', 'bytes' => 12, 'personal' => false]];
        $rows = \SugarCraft\Code\Host\ContextSources::instructionRows($files);

        self::assertSame([['path' => '/x/AGENTS.md', 'bytes' => 12, 'tier' => 'project']], $rows);
        self::assertNull(\SugarCraft\Code\Host\ContextSources::instructionRows(null));
    }
}
