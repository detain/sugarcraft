<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Support\HookContextFiles;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Usage;

/**
 * Delegated `Task` runs that ended without a report, kept so they can be
 * RESUMED rather than started over (see
 * {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}).
 *
 * ON DISK, NOT IN MEMORY, because nothing in memory survives: every TUI turn
 * runs in a forked completion child, and parallel `Task` calls are forked
 * again below that, so the process that suspends a run is gone before the
 * model's next turn asks to resume it.
 *
 * The transcript is `serialize()`d rather than JSON-encoded because an
 * assistant step's tool calls are {@see ToolCall} objects with private state —
 * `json_encode()` turns them into `{}` and the resumed model would read results
 * for calls it can no longer see. Reading back is restricted to exactly the
 * classes a transcript is built from, and anything else (a foreign class, a
 * truncated file) is an unknown id, never a half-restored conversation.
 *
 * Transcripts hold whatever the sub-agent read, so the directory is the
 * owner-only, re-verified one {@see HookContextFiles::verifiedDirectory()}
 * accepts, and each file is written `0600` and renamed into place.
 */
final class SuspendedDelegations
{
    /** Resume ids are what {@see save()} mints: 16 lowercase hex digits — never a path. */
    public const ID_PATTERN = '/^[0-9a-f]{16}$/';

    /** A suspension nobody resumed within a week is swept on the next save. */
    public const MAX_AGE_SECONDS = 7 * 86400;

    private const DIR_NAME = 'sugarcrush-suspended-delegations';

    private const RESTORABLE_CLASSES = [
        AssistantMessage::class,
        SystemMessage::class,
        ToolResultMessage::class,
        UserMessage::class,
        ToolCall::class,
        \SugarCraft\Crush\ToolCall::class,
        Usage::class,
        Attachment::class,
        AttachmentType::class,
    ];

    public function __construct(
        private readonly string $dir,
    ) {}

    /**
     * The per-user store under the system temp directory.
     */
    public static function new(): self
    {
        return new self(sys_get_temp_dir() . '/' . self::DIR_NAME);
    }

    /**
     * Persist a suspended run, under $id when continuing an earlier suspension
     * (so one delegation keeps one id however many times it is resumed), else
     * under a fresh id.
     *
     * @param list<Message> $transcript
     *
     * @return string the resume id
     *
     * @throws \RuntimeException when the store directory is unsafe or unwritable
     */
    public function save(string $agent, array $transcript, int $resumes, ?string $id = null): string
    {
        $dir = HookContextFiles::verifiedDirectory($this->dir);
        $this->sweep($dir);

        if ($id === null || preg_match(self::ID_PATTERN, $id) !== 1) {
            $id = bin2hex(random_bytes(8));
        }

        $payload = serialize([
            'agent' => $agent,
            'transcript' => array_values($transcript),
            'resumes' => $resumes,
            'savedAt' => time(),
        ]);

        $temp = @tempnam($dir, 'suspending-');
        if ($temp === false) {
            throw new \RuntimeException("cannot create a file in {$dir}");
        }
        @chmod($temp, 0o600);
        if (@file_put_contents($temp, $payload) !== \strlen($payload) || !@rename($temp, $this->path($dir, $id))) {
            @unlink($temp);

            throw new \RuntimeException("cannot write the suspended run into {$dir}");
        }

        return $id;
    }

    /**
     * @return array{agent: string, transcript: list<Message>, resumes: int}|null
     *         null for an id that is malformed, unknown, expired or unreadable
     */
    public function load(string $id): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        try {
            $dir = HookContextFiles::verifiedDirectory($this->dir);
        } catch (\RuntimeException) {
            return null;
        }

        $raw = @file_get_contents($this->path($dir, $id));
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => self::RESTORABLE_CLASSES]);
        if (!\is_array($data)
            || !\is_string($data['agent'] ?? null)
            || !\is_int($data['resumes'] ?? null)
            || !\is_int($data['savedAt'] ?? null)
            || time() - $data['savedAt'] > self::MAX_AGE_SECONDS
            || !\is_array($data['transcript'] ?? null)
            || $data['transcript'] === []
        ) {
            return null;
        }

        foreach ($data['transcript'] as $message) {
            if (!$message instanceof Message) {
                return null;
            }
        }

        return [
            'agent' => $data['agent'],
            'transcript' => array_values($data['transcript']),
            'resumes' => $data['resumes'],
        ];
    }

    public function forget(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return;
        }

        try {
            @unlink($this->path(HookContextFiles::verifiedDirectory($this->dir), $id));
        } catch (\RuntimeException) {
        }
    }

    private function path(string $dir, string $id): string
    {
        return $dir . '/' . $id . '.run';
    }

    private function sweep(string $dir): void
    {
        foreach (glob($dir . '/*.run') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && time() - $mtime > self::MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }
}
