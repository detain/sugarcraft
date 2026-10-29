<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Protocol\TrustAsks;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;

/**
 * `context.sources` (WEB-3a): the `/context` panel's data as structure —
 * the instruction documents the prompt splices, the memory notes of both
 * stores, the skills inventory, the declared MCP servers, the paths the
 * loader refused, and the token breakdown. Read-only, nothing dialed,
 * nothing run: every section is a read of state the host already holds
 * ({@see \SugarCraft\Code\Host\ContextSources}).
 *
 * WHY A SESSION CALL. The breakdown is per-session — history, pruning
 * ledger and cache breaks belong to the host, and its calibrated history
 * estimate lives there, not in a recomputable global. A client wanting the
 * workspace halves without a session can read `memory.list`, the skills
 * prompt listing and `candy-code mcp list` surfaces it already has.
 *
 * WEB-3b joined the measurement with the trust prompt: the same answer that
 * reports a pending project-trust verdict is where that verdict is DISCOVERED
 * over the wire, so the first sighting mints that target's prompt once per
 * (root, target) ({@see TrustAsks}) — the discovery is a notification raised
 * by a read, the same posture as the launch notices the CLI prints while
 * answering any question (C-SEC-4 added the same discovery to
 * `session.subscribe`, so prompts reach a watcher without the panel having to
 * mount first); answering still takes a write, `context.trust`, under the
 * Approve scope. C-SEC-5.b widened the answer from the one MCP verdict to the
 * whole 5.a launch census: every pending target (`.mcp.json`, a drifted pin
 * re-approval, hooks, command shell forms, the project settings layer) mints
 * its own row of the `trusts` list, and a grant persists through the census's
 * own writer door, `Bootstrap::grantProjectTrust()`.
 */
final class ContextMethods
{
    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('context.sources', Scope::Read, 'What the session\'s context is built from: instruction files, memory notes, skills, declared MCP servers, refused paths, the token breakdown, and this root\'s pending project-trust prompts.', self::sources(...)));
        $registry->add(MethodSpec::new('context.trust', Scope::Approve, 'Answer one project-trust prompt (permission.asked with kind "trust") by its askId: grant or reject; a user-tier remember writes the answering prompt\'s target list (trustedProjectMcp for an mcp or mcp-pin row, the matching trustedProject* key for the others) in your own config, a project-tier one is refused.', self::trust(...)));
        $registry->add(MethodSpec::new('context.instructionWrite', Scope::Write, 'Write one instruction document the session already reads — replace it by spelling content, or append raw bytes by spelling append (exactly one). The jail is the listing itself: path must equal a context.sources instructionFiles row, or "personal" for your own ~/.candy-code/AGENTS.md. A replace whose expectBytes no longer matches the file on disk refuses instruction_changed; a document past the loader\'s size cap refuses instruction_too_large; a path that now resolves through a symlink outside its jail refuses instruction_symlink. A landed write announces context.changed (reason "instructions") so the panel re-measures.', self::instructionWrite(...), true));
    }

    /** @return array<string, mixed> */
    private static function sources(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        $answer = $host->contextSources();
        $root = $host->workspace()->root;

        // C-SEC-5.b: the 5.a launch census replaces the single MCP verdict.
        // Every pending target mints (once) and rides the answer as its own
        // row; settled rows restate their truth so a session grant that
        // expires with the process, or a rejection that must not nag, still
        // shows on the panel.
        TrustAsks::discoverPending($root, static function (array $record) use ($call, $sessionId): void {
            $call->server->broadcast(EventEnvelope::server(EventType::PERMISSION_ASKED, ['sessionId' => $sessionId] + $record));
        });
        $answer['trusts'] = TrustAsks::records($root);

        return $answer;
    }

    /** @return array<string, mixed> */
    private static function trust(CallContext $call, Params $params): array
    {
        // C-SEC-4: the sessionId routes the answer to the process that minted
        // the prompt (a gateway's workspace host holds its own TrustAsks
        // statics) and roots it: settle() refuses a prompt from any other
        // root, so a misroute can never answer a different project's question.
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        $root = $host->workspace()->root;
        $askId = $params->string('askId', 16);
        $decision = $params->enum('decision', ['grant', 'reject']);
        $remember = $params->has('remember') ? $params->enum('remember', ['session', 'user', 'project']) : null;

        // C-SEC-5.b: the answering session's own settings writer is the door
        // non-MCP grants land in (the SettingsMethods precedent — the same
        // service the live settings surface uses); settle() falls back to the
        // launch's writer when a workspace carries none, and the MCP door
        // never consults it.
        $service = $host->workspace()->service(SettingsWriter::class);
        $record = TrustAsks::settle($askId, $decision, $remember, $root, $service instanceof SettingsWriter ? $service : null);

        return [
            'askId' => $record['askId'],
            'status' => $record['status'],
            'persisted' => $record['persisted'],
        ];
    }

    /**
     * `context.instructionWrite` (C-CTX-3.c): the write half of the panel.
     * The jail is the read answer — a spelled path must equal an
     * `instructionFiles` row the loader itself listed (equality, not a prefix
     * walk, because the listing already carries the loader's containment), and
     * the personal file is spelled through the sentinel the browser can
     * resolve even when it does not exist yet, mirroring the TUI's
     * `/instructions`-open laws ({@see \SugarCraft\Code\Cli\Chat}). Containment
     * is re-checked at write time through the one routed compare
     * ({@see ContainedPath}) so a listing that raced into a symlink cannot
     * write outside its tier's boundary. The size cap is the loader's own
     * constant — writing bytes it would later refuse to read back would only
     * teach the session a silent truncation.
     *
     * @return array{path: string, bytes: int, mode: string}
     */
    private static function instructionWrite(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);

        $hasContent = $params->has('content');
        $hasAppend = $params->has('append');
        if ($hasContent === $hasAppend) {
            throw RpcError::invalidParams('spell exactly one of content or append', 'instruction_mode');
        }
        $payload = $params->string($hasContent ? 'content' : 'append', InstructionFileLoader::MAX_DOCUMENT_BYTES);

        $root = $host->workspace()->root;
        $home = HomeDirectory::owned();
        $spelled = $params->string('path', 4096);
        $boundaries = $home === null ? [$root] : [$root, $home];

        if ($spelled === self::PERSONAL_PATH) {
            $target = self::personalWriteTarget($home);
            $isPersonal = true;
        } else {
            $target = $spelled;
            $isPersonal = false;
            if (is_link($target)) {
                // Checked before the listing: the brief names this refusal,
                // and a loader re-scan would otherwise re-classify it as the
                // row vanishing from the list.
                throw RpcError::of(ErrorCode::Forbidden, 'that instruction path resolves through a symlink and will not be written through', 'instruction_symlink');
            }
            $listed = $host->contextSources()['instructionFiles'] ?? [];
            $known = false;
            foreach ($listed as $row) {
                if (($row['path'] ?? null) === $spelled) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                throw RpcError::of(
                    ErrorCode::NotFound,
                    'instruction documents this session may write are the ones context.sources lists (or "personal")',
                    'instruction_not_listed'
                );
            }
            if (!is_file($target)) {
                throw RpcError::of(ErrorCode::NotFound, 'that instruction document is no longer a listed read — spell a path from a fresh context.sources answer (or "personal")', 'instruction_not_listed');
            }
            if (!self::held($target, $boundaries)) {
                // The listing was true when read; a path whose realpath has
                // since escaped (a swapped directory symlink) is no longer in
                // the jail the read answer described.
                throw RpcError::of(ErrorCode::Forbidden, 'that instruction path no longer resolves inside its jail', 'instruction_symlink');
            }
        }

        // Round-1 fix (MAJOR-1): the sentinel resolves WITHOUT creating (see
        // personalWriteTarget). The latch below therefore still measures an
        // absent primary as zero, so a panel save of the ~/.claude/CLAUDE.md
        // stand-in row refuses `instruction_changed` and costs NOTHING —
        // an eager 0-byte create before this point made that save refuse AND
        // mask the stand-in (loadPersonal takes the primary whenever it
        // exists), evicting the operator's personal instructions while landing
        // nothing. The empty-file create rides the success path below, whose
        // $isPersonal ? 0o600 mode arm mints the first write.
        $currentBytes = is_file($target) ? (int) filesize($target) : 0;
        if ($params->has('expectBytes') && $params->int('expectBytes', 0, 0) !== $currentBytes) {
            throw RpcError::of(ErrorCode::Conflict, 'the file on disk now holds a different byte count than the panel measured', 'instruction_changed');
        }
        $resulting = $hasAppend ? $currentBytes + strlen($payload) : strlen($payload);
        if ($resulting > InstructionFileLoader::MAX_DOCUMENT_BYTES) {
            throw RpcError::invalidParams('an instruction document may not grow past ' . InstructionFileLoader::MAX_DOCUMENT_BYTES . ' bytes', 'instruction_too_large');
        }

        try {
            if ($hasAppend && $currentBytes > 0) {
                // Mode-preserving append under a lock — the Chat.php twin's shape.
                if (file_put_contents($target, $payload, FILE_APPEND | LOCK_EX) === false) {
                    throw new \RuntimeException('append failed');
                }
            } else {
                $mode = $currentBytes > 0
                    ? ((int) (fileperms($target) & 0o777))
                    : ($isPersonal ? 0o600 : null);
                AtomicFileWriter::write($target, $payload, $mode);
            }
        } catch (\Throwable) {
            throw RpcError::of(ErrorCode::Conflict, 'the instruction document could not be written', 'instruction_write_failed');
        }

        // The pre-write filesize() above primed PHP's stat cache and the
        // append branch (file_put_contents) does not clear it; without this
        // the reply would promise the old byte count.
        \clearstatcache(true, $target);
        $bytes = is_file($target) ? (int) filesize($target) : 0;
        $host->announce(SessionEvent::new(SessionEvent::CONTEXT_CHANGED, ['reason' => 'instructions'], $sessionId));

        return ['path' => $target, 'bytes' => $bytes, 'mode' => $hasAppend ? 'append' : 'replace'];
    }

    /**
     * The personal file the sentinel writes, resolved under the same laws as
     * the TUI's open-arm: owned home, private directory, no symlink. The file
     * itself is deliberately NOT created here — a resolve that mints would
     * leave a refused write an empty primary masking the {@see
     * InstructionFileLoader::loadPersonal()} stand-in (round-1 MAJOR-1). The
     * 0600 first-write happens in the success path below, where the absent
     * file latches zero and the $isPersonal mode arm mints it.
     */
    private static function personalWriteTarget(?string $home): string
    {
        if ($home === null) {
            throw RpcError::of(ErrorCode::Forbidden, 'no owned home directory is available for the personal instruction file', 'instruction_home_unavailable');
        }
        $dir = rtrim($home, '/') . '/.candy-code';
        $unavailable = static fn (): RpcError => RpcError::of(ErrorCode::Forbidden, 'the personal instruction directory could not be settled inside the owned home', 'instruction_home_unavailable');

        if (is_link($dir)) {
            throw $unavailable();
        }
        if (!is_dir($dir)) {
            try {
                PrivateDir::ensure($dir, 'personal instruction directory');
            } catch (\Throwable) {
                throw $unavailable();
            }
        }
        $resolvedDir = realpath($dir);
        if ($resolvedDir === false || !self::held($resolvedDir, [$home])) {
            throw $unavailable();
        }
        $target = $resolvedDir . '/' . InstructionFileLoader::PERSONAL_FILENAME;
        if (is_link($target)) {
            throw RpcError::of(ErrorCode::Forbidden, 'the personal instruction file is a symlink and will not be written through', 'instruction_symlink');
        }

        return $target;
    }

    /** The sentinel spelling of the personal file (its absolute path may not exist yet). */
    public const PERSONAL_PATH = 'personal';

    /**
     * Whether $path resolves inside ANY boundary — the one routed
     * {@see ContainedPath::within} compare this class carries (C-CTX-3.c).
     *
     * @param list<string> $boundaries
     */
    private static function held(string $path, array $boundaries): bool
    {
        foreach ($boundaries as $boundary) {
            if (ContainedPath::within($path, $boundary)) {
                return true;
            }
        }

        return false;
    }
}
