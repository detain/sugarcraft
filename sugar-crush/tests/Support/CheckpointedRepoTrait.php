<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Workspace\GitRunner;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * A git repository with a session whose checkpoints carry real workspace
 * snapshots, for the `/rewind --files|--both`, `/undo`, `/redo` and `/diff`
 * suites (item 3.A-2). Checkpoints are written the way a turn writes them —
 * the pre-turn transcript, the prompt as the draft, then the snapshot — and
 * the files are edited between them the way a turn would.
 */
trait CheckpointedRepoTrait
{
    use HomeSandboxTrait;

    private string $tmp;

    private string $repo;

    private EnhancedSessionStore $store;

    private string|false $previousErrorLog = false;

    private function setUpCheckpointedRepo(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_undo_' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/db', 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        WorkspaceCheckpointer::forgetDisabled();
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        RuntimeNoticeSink::reset();
        // A refused capture announces itself through RuntimeNoticeSink::warn(),
        // whose error_log() copy would otherwise land in the runner's output.
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->tmp . '/error.log');

        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo, 0o700, true);
        $this->gitAt('init', '-q');
        file_put_contents($this->repo . '/file.txt', "base\n");
        $this->gitAt('add', '.');
        $this->gitAt('commit', '-q', '-m', 'base');

        $this->store = new EnhancedSessionStore($this->tmp . '/db/session.db');
        $this->store->createSession('undo-session', 'echo', 'echo');
    }

    private function tearDownCheckpointedRepo(): void
    {
        WorkspaceCheckpointer::forgetDisabled();
        EnhancedSessionStore::forgetAnnouncedSnapshots();
        RuntimeNoticeSink::reset();
        $this->previousErrorLog === false ? ini_restore('error_log') : ini_set('error_log', $this->previousErrorLog);
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    /**
     * Save the checkpoint a turn sending $prompt after $transcript would
     * save, with a snapshot of the files as they are now.
     *
     * @param list<array{role: string, content: string}> $transcript
     */
    private function turnCheckpoint(array $transcript, string $prompt): int
    {
        $index = $this->store->saveCheckpoint('undo-session', [
            'messages' => $transcript,
            'messagesPrecedePrompt' => true,
            'inputBuf' => $prompt,
        ]);
        $workspace = $this->store->captureWorkspace('undo-session', $index, $this->repo);
        self::assertSame('captured', $workspace['status'], (string) ($workspace['reason'] ?? ''));

        return $index;
    }

    /**
     * @param list<Message> $history
     */
    private function chatAt(string $input, array $history, bool $readOnly = false): Chat
    {
        return new Chat(
            history: $history,
            inputBuf: $input,
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 'undo-session',
            currentSessionName: 'named',
            projectRoot: $this->repo,
            readOnlySession: $readOnly,
        );
    }

    /**
     * Send $command from a window showing $history — $previous's, when given
     * — and return the window after it ran. A fresh window per command, as a
     * relaunch would be: the redo stack and the snapshots live in the store
     * and the repository, not on the Chat.
     *
     * @param list<Message> $history
     */
    private function send(string $command, Chat|array $previous = []): Chat
    {
        $history = $previous instanceof Chat ? $previous->history : $previous;
        [$next] = $this->chatAt($command, $history)->update(new KeyMsg(KeyType::Enter, ''));

        return $next;
    }

    private static function reply(Chat $chat): string
    {
        return $chat->history[\count($chat->history) - 1]->content;
    }

    /**
     * The transcript without the UI-only command rows.
     *
     * @return list<string>
     */
    private static function conversation(Chat $chat): array
    {
        $rows = [];
        foreach ($chat->history as $message) {
            if (!$message->uiOnly) {
                $rows[] = $message->content;
            }
        }

        return $rows;
    }

    private function file(string $name): ?string
    {
        $path = $this->repo . '/' . $name;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    private function gitAt(string ...$args): string
    {
        $command = 'git -c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false -C ' . escapeshellarg($this->repo);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));

        return implode("\n", $out);
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
