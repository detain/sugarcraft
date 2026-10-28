<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-CTX-3.b: a single-line draft starting `#` (and not `##`) is a quick-add
 * offer, not a turn. The confirm row that submit() raises is answered by the
 * key dispatch: Enter appends `- <note>` to the personal instruction file
 * `~/.candy-code/AGENTS.md` (created 0600 when absent, containment-checked,
 * a planted symlink refused), Esc restores the draft exactly, and any other
 * keystroke cancels the offer and lands in that restored draft.
 *
 * Driven through the real `update()` entry with HOME sandboxed, so what the
 * tests pin is the file the instruction loader would read back on the next
 * session — and, per C-CTX-1.a, the one the diff tracker carries to the
 * model from the DISK. Nothing here may start a model turn: a quick-add
 * costs nothing to offer, and the Esc path must prove it lost nothing.
 */
final class InstructionQuickAddTest extends TestCase
{
    use HomeSandboxTrait;

    private string $project = '';

    private string $home = '';

    private string $personalPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $scratch = sys_get_temp_dir() . '/cc_ctx3b_' . bin2hex(random_bytes(4));
        $this->project = $scratch . '/proj';
        $this->home = $scratch . '/home';
        mkdir($this->project, 0o700, true);
        $this->useHomeSandbox($this->home);
        $this->personalPath = $this->home . '/.candy-code/AGENTS.md';
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->project);
        $this->removeTree($this->home);

        parent::tearDown();
    }

    public function testAHashLineBecomesAConfirmRowAndNotATurn(): void
    {
        $backend = new QuickAddRecordingBackend();
        $chat = $this->typeInto($this->quickAddChat($backend), '# remember to run tests');

        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'offering a note starts no work');
        $this->assertNotNull($this->pendingOf($pending), 'the confirm is pending');
        $this->assertSame('', $pending->inputBuf, 'the box is consumed');
        $this->assertSame(0, $backend->calls, 'and no model is asked');

        $rows = $pending->history;
        $draftRow = $rows[array_key_last($rows) - 1];
        $this->assertSame(Role::User, $draftRow->role, 'the draft keeps its place in the transcript');
        $this->assertTrue($draftRow->uiOnly, 'but never joins a later prompt — the note rides the file instead');
        $this->assertSame('# remember to run tests', $draftRow->content);

        $confirm = $this->lastRow($pending);
        $this->assertSame(Role::Assistant, $confirm->role);
        $this->assertTrue($confirm->uiOnly, 'a prompt-back row is not model context');
        $this->assertStringContainsString('remember to run tests', $confirm->content);
        $this->assertStringContainsString('Esc', $confirm->content, 'the row states its own two answers');

        $this->assertFileDoesNotExist($this->personalPath, 'Enter on the draft only ASKS');
    }

    public function testEnterOnTheConfirmAppendsTheNoteAndCreatesTheFilePrivateToTheUser(): void
    {
        $backend = new QuickAddRecordingBackend();
        [$pending] = $this->typeInto($this->quickAddChat($backend), '# remember to run tests')
            ->update(new KeyMsg(KeyType::Enter, ''));

        [$done, $cmd] = $pending->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertNull($this->pendingOf($done), 'one answer, then the confirm is gone');
        $this->assertSame(0, $backend->calls, 'appending still starts no turn');

        clearstatcache();
        $this->assertFileExists($this->personalPath);
        $this->assertSame("- remember to run tests\n", file_get_contents($this->personalPath));
        $this->assertSame(0o600, fileperms($this->personalPath) & 0o777, 'a fresh instruction file is owner-private');
        $this->assertSame(0o700, fileperms(dirname($this->personalPath)) & 0o777);

        $row = $this->lastRow($done);
        $this->assertTrue($row->uiOnly);
        $this->assertStringContainsString('personal instructions', $row->content);
        $this->assertStringContainsString('remember to run tests', $row->content);
    }

    public function testASecondNoteAppendsAndKeepsTheUsersOwnBytesAndMode(): void
    {
        mkdir($this->home . '/.candy-code', 0o700);
        $before = "# My rules\n- keep it simple\n";
        file_put_contents($this->personalPath, $before);
        chmod($this->personalPath, 0o644);

        [$asked] = $this->typeInto($this->quickAddChat(new QuickAddRecordingBackend()), '# second note')
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($this->pendingOf($asked));
        $asked->update(new KeyMsg(KeyType::Enter, ''));

        clearstatcache();
        $this->assertSame($before . "- second note\n", file_get_contents($this->personalPath), 'append, never rewrite');
        $this->assertSame(0o644, fileperms($this->personalPath) & 0o777, 'the file was the user’s; its mode stays theirs');
    }

    public function testEscapeRestoresTheDraftExactlyAndWritesNothing(): void
    {
        [$pending] = $this->typeInto($this->quickAddChat(new QuickAddRecordingBackend()), '# remember to run tests')
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($this->pendingOf($pending));

        [$back, $cmd] = $pending->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertNull($cmd);
        $this->assertNull($this->pendingOf($back), 'the offer is cancelled');
        $this->assertSame('# remember to run tests', $back->inputBuf, 'the draft comes back byte for byte');
        $this->assertFileDoesNotExist($this->personalPath, 'and nothing was written');
        $this->assertDirectoryDoesNotExist($this->home . '/.candy-code', 'not even the directory');
    }

    public function testAnyOtherKeyCancelsAndLandsInTheRestoredDraft(): void
    {
        [$pending] = $this->typeInto($this->quickAddChat(new QuickAddRecordingBackend()), '# remember to run tests')
            ->update(new KeyMsg(KeyType::Enter, ''));

        [$after] = $pending->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertNull($this->pendingOf($after));
        $this->assertSame('# remember to run testsx', $after->inputBuf, 'the keystroke the user meant is not lost to the confirm');
        $this->assertFileDoesNotExist($this->personalPath);
    }

    public function testAMultiLineDraftIsNeverCaptured(): void
    {
        $chat = new Chat(backend: new QuickAddRecordingBackend(), projectRoot: $this->project, inputBuf: "# heading\nbody line");

        [$after] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($this->pendingOf($after), 'pasted markdown is a prompt, not an offer');
        $this->assertFileDoesNotExist($this->personalPath);
        $this->assertStringContainsString("# heading\nbody line", $this->modelVisiblePrompt($after));
    }

    public function testADoubleHashHeadingIsMarkdownNotAnOffer(): void
    {
        $chat = $this->typeInto(new Chat(backend: new QuickAddRecordingBackend(), projectRoot: $this->project), '## Release notes');

        [$after] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($this->pendingOf($after));
        $this->assertFileDoesNotExist($this->personalPath);
        $this->assertStringContainsString('## Release notes', $this->modelVisiblePrompt($after));
    }

    public function testABareHashPromptsAsBefore(): void
    {
        $chat = $this->typeInto(new Chat(backend: new QuickAddRecordingBackend(), projectRoot: $this->project), '#');

        [$after] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($this->pendingOf($after), 'a bare `#` offers nothing');
        $this->assertFileDoesNotExist($this->personalPath);
    }

    public function testADraftWhileATurnRunsQueuesAsPromptAndIsNotCaptured(): void
    {
        $chat = new Chat(
            backend: new QuickAddRecordingBackend(),
            projectRoot: $this->project,
            inFlight: true,
            inputBuf: '# mid-turn note',
        );

        [$after] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($this->pendingOf($after), 'the mid-turn arm sees it first');
        $this->assertFileDoesNotExist($this->personalPath, 'no confirm can be armed behind a running turn');
    }

    public function testASymlinkWearingTheFileNameIsRefusedNeverFollowed(): void
    {
        mkdir($this->home . '/.candy-code', 0o700);
        $outside = $this->project . '/victim.md';
        symlink($outside, $this->personalPath);

        [$pending] = $this->typeInto($this->quickAddChat(new QuickAddRecordingBackend()), '# sneaky note')
            ->update(new KeyMsg(KeyType::Enter, ''));
        [$after, $cmd] = $pending->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertNull($this->pendingOf($after));
        $this->assertFileDoesNotExist($outside, 'the link target was never opened');
        $this->assertStringContainsString('Could not write', $this->lastRow($after)->content);
        $this->assertTrue($this->lastRow($after)->uiOnly);
    }

    public function testADirectoryLinkOutOfHomeIsRefused(): void
    {
        $outside = $this->project . '/elsewhere';
        mkdir($outside, 0o700);
        symlink($outside, $this->home . '/.candy-code');

        [$pending] = $this->typeInto($this->quickAddChat(new QuickAddRecordingBackend()), '# escaped note')
            ->update(new KeyMsg(KeyType::Enter, ''));
        [$after, $cmd] = $pending->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertNull($this->pendingOf($after));
        $this->assertFileDoesNotExist($outside . '/AGENTS.md', 'nothing lands outside the owned home');
        $this->assertStringContainsString('Could not write', $this->lastRow($after)->content);
    }

    private function quickAddChat(Backend $backend): Chat
    {
        return new Chat(backend: $backend, projectRoot: $this->project);
    }

    private function typeInto(Chat $chat, string $text): Chat
    {
        foreach (mb_str_split($text) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat;
    }

    /** @return array{note: string, draft: string}|null */
    private function pendingOf(Chat $chat): ?array
    {
        return (new \ReflectionProperty(Chat::class, 'pendingQuickAdd'))->getValue($chat);
    }

    private function lastRow(Chat $chat): Message
    {
        return $chat->history[array_key_last($chat->history)];
    }

    /**
     * The draft as the MODEL will see it: the newest user row that is not
     * uiOnly. A quick-add row must never be it.
     */
    private function modelVisiblePrompt(Chat $chat): string
    {
        foreach (array_reverse($chat->history) as $row) {
            if ($row->role === Role::User && !$row->uiOnly) {
                return $row->content;
            }
        }

        return '';
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}

final class QuickAddRecordingBackend implements Backend
{
    public int $calls = 0;

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant('ok');
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant('ok'));
    }
}
