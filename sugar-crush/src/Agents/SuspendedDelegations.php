<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Support\HookContextFiles;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Usage;

/**
 * Delegated `Task` runs, kept so they can be RESUMED rather than started over
 * (see {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}): a run that ended
 * without a report continues where it stopped, and since step 4.7-1 a run that
 * FINISHED is kept too, so a follow-up reaches the agent that did the work.
 *
 * BOUNDED TWICE, because every delegation now writes one file: by age
 * ({@see MAX_AGE_SECONDS}) and by count ({@see MAX_RUNS}, oldest evicted first),
 * both applied on each {@see save()}.
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
 *
 * ONE DIRECTORY PER EFFECTIVE UID, because the store sits in a temp directory
 * every user on the box shares. Under a single fixed name the first user to
 * suspend a run created it `0700` as theirs, and every other user's `save()`
 * was then refused by the ownership check for as long as that directory stood:
 * their suspended runs could never be resumed, and a local user could arrange
 * that on purpose with one `mkdir` (audit TMP-1). The uid suffix gives each
 * user a name nobody else's ordinary use contends for; the verification still
 * runs on that name, since another user can still PLANT it, and a planted one
 * is refused rather than written through.
 *
 * ONE CONVERSATION, TWO VIEWS (roadmap P-C1). A run's saved transcript is the
 * model's copy — the exact typed messages a resume replays; the run's
 * {@see \SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog} is the
 * human's, the one its child session is built from. The suspension records
 * that log's path, so a resumed run keeps writing the SAME file and the stored
 * session follows the conversation the model continues.
 *
 * THE RUN'S OWN LEDGER (roadmap 3.B-5, DCP §13.2 I). A run that prunes its
 * context does so on an ephemeral {@see ContextLedger} of its own; it is
 * saved beside the transcript its refs were fixed over, so a resume sends the
 * same pruned view rather than every output it had already let go. It is
 * stored as the ledger's own plain-array form ({@see ContextLedger::toArray()})
 * and rebuilt leniently, so the restorable classes above stay exactly the
 * transcript's — no ledger value object is ever handed to `unserialize()`.
 *
 * THE RUN'S OWN WORKTREE (roadmap 4.9). An `isolation: worktree` run that
 * left work in its git worktree keeps the tree, and the suspension names it
 * (by the {@see WorktreeManager} id it was created under), so a resume works
 * on in the same tree instead of starting a clean one beside it.
 */
final class SuspendedDelegations
{
    /** Resume ids are what {@see save()} mints: 16 lowercase hex digits — never a path. */
    public const ID_PATTERN = '/^[0-9a-f]{16}$/';

    /** A suspension nobody resumed within a week is swept on the next save. */
    public const MAX_AGE_SECONDS = 7 * 86400;

    /**
     * How many runs the store keeps; a save beyond it evicts the least
     * recently written. Step 4.7-1 saves EVERY run, so without a count cap a
     * busy week of delegations is a week of transcripts on disk.
     */
    public const MAX_RUNS = 200;

    /** The store directory's stem; {@see directoryFor()} appends whose it is. */
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
        return new self(self::directoryFor(\function_exists('posix_geteuid') ? \posix_geteuid() : null));
    }

    /**
     * {@see new()}'s directory for a given effective uid, or for a build with
     * no `posix_geteuid()`.
     *
     * The same naming as {@see \SugarCraft\Crush\Hooks\BuiltIn\AuditHook}'s
     * log directory, and a seam for the same reason: every build the suite runs
     * on has the posix lookup, so with it inline the `null` arm (a shared
     * `-noposix` scope, where only the mode and symlink checks hold) would be
     * reachable from no test at all.
     */
    private static function directoryFor(?int $uid): string
    {
        return sys_get_temp_dir() . '/' . self::DIR_NAME . '-' . ($uid === null ? 'noposix' : (string) $uid);
    }

    /**
     * Persist a suspended run, under $id when continuing an earlier suspension
     * (so one delegation keeps one id however many times it is resumed), else
     * under a fresh id.
     *
     * @param list<Message> $transcript
     * @param string|null $transcriptLog the run's human-readable log, which a
     *        resume keeps appending to (see the class doc); null for none
     * @param ContextLedger|null $ledger the run's ephemeral context ledger
     *        (see the class doc); null for a run that kept none
     * @param string|null $worktree the id of the worktree the run kept (see the
     *        class doc); null for a run that kept none
     *
     * @return string the resume id
     *
     * @throws \RuntimeException when the store directory is unsafe or unwritable
     */
    public function save(string $agent, array $transcript, int $resumes, ?string $id = null, ?string $transcriptLog = null, ?ContextLedger $ledger = null, ?string $worktree = null): string
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
            'transcriptLog' => $transcriptLog,
            'contextLedger' => $ledger?->toArray(),
            'worktree' => $worktree,
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
     * @return array{agent: string, transcript: list<Message>, resumes: int, transcriptLog: ?string, contextLedger: ?array<string, mixed>, worktree: ?string}|null
     *         null for an id that is malformed, unknown, expired or unreadable;
     *         `transcriptLog` is null for a run saved without one (or before P-C1);
     *         `contextLedger` is the run's ledger in {@see ContextLedger::toArray()}
     *         form, for {@see ContextLedger::fromArray()}, or null (none kept, or
     *         saved before roadmap 3.B-5); `worktree` the id of the worktree
     *         the run kept, or null
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
            'transcriptLog' => \is_string($data['transcriptLog'] ?? null) && $data['transcriptLog'] !== '' ? $data['transcriptLog'] : null,
            'contextLedger' => \is_array($data['contextLedger'] ?? null) ? $data['contextLedger'] : null,
            'worktree' => \is_string($data['worktree'] ?? null) && $data['worktree'] !== '' ? $data['worktree'] : null,
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

    /**
     * Drop expired runs, then the oldest beyond {@see MAX_RUNS} - 1, leaving
     * room for the run being saved.
     */
    private function sweep(string $dir): void
    {
        $kept = [];
        foreach (glob($dir . '/*.run') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false) {
                continue;
            }
            if (time() - $mtime > self::MAX_AGE_SECONDS) {
                @unlink($file);
                continue;
            }
            $kept[$file] = $mtime;
        }

        $excess = count($kept) - (self::MAX_RUNS - 1);
        if ($excess <= 0) {
            return;
        }

        asort($kept);
        foreach (array_slice(array_keys($kept), 0, $excess) as $file) {
            @unlink($file);
        }
    }
}
