<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SugarCraft\Code\Backend\DumpsSystemPrompt;
use SugarCraft\Code\Commands\ContextCommand;
use SugarCraft\Code\Commands\ShareCommand;
use SugarCraft\Code\Host\ContextSources;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tools\PathJail;

/**
 * `/context` (and `/tokens`): where the next request's context window goes —
 * the system prompt per layer, the instruction documents it splices (by name
 * and size, roadmap VIS-2a), the tool schemas, the history, the largest
 * messages, the cache-hit share (roadmap 5.6) and the cache breaks (3.B-5). Read-only and local: it
 * measures in this process, sends nothing and calls no model. The report
 * itself is {@see ContextCommand}'s; this is its session half (roadmap O-2h).
 *
 * The measurement itself lives in {@see ContextSources::measure()} — the SAME
 * call `context.sources` answers from (WEB-3a), so the panel and the wire can
 * never fork it. The history figure is the driver's own token estimate
 * ({@see CommandContext::$contextTokens}) — the status bar's — so the two
 * surfaces cannot disagree. The prompt layers come from a backend that
 * assembles its own prompt ({@see \SugarCraft\Code\Backend\ReportsPromptSections});
 * the tool schemas from an engine backend's tool list. Either may be unknown,
 * and the report then says so instead of printing a zero.
 *
 * `/context --raw [path]` (GAP-PRM-15): the dump form. The panel is a readout
 * — labels and sizes; what reproducing a request actually needs is the exact
 * prompt string and the exact tool schemas, and a backend that assembles its
 * own prompt can name them ({@see DumpsSystemPrompt}). Two files are written
 * atomically at 0600: `<base>.json` (`{"prompt": …, "tools": […]}`) and
 * `<base>.txt` (the prompt body alone). TARGETS follow `/export`'s rules on
 * purpose — one shelf, one jail: no path lands the pair on
 * `~/.candy-code/exports/` under a `prompt-` name (the prefix keeps it apart
 * from the `transcript-` files beside it); a plain path is jailed to the
 * project root or that shelf, `~` expands, and the save-name palette fills
 * `date`/`time`/`job_timestamp` and nothing else, every other bracketed name
 * staying literal WITH a note in the reply, the same degrade-visibly law.
 * The reply carries paths and byte sizes only. WHY a file and never stdout:
 * the prompt body is thousands of cells of instruction text whose exact bytes
 * belong to the request, not the transcript — echoed, it would re-enter the
 * next request's history through its own tool-output row and be billed a
 * second time, which is the quietest way to inflate a session. A backend
 * without the capability is refused BY NAME, never answered with a dump
 * invented around it.
 */
final class ContextHostCommand implements HostCommand
{
    /** Default file name prefix — what keeps this shelf's prompt dumps apart from `/export`'s transcripts. */
    public const FILE_PREFIX = 'prompt-';

    /** Palette arms a dump can fill; every other {@see MediaStore::TOKENS} name stays literal. */
    private const FILLABLE_TOKENS = ['date', 'time', 'job_timestamp'];

    public function run(CommandContext $context, string $text): CommandResult
    {
        $words = CommandText::words($text);
        if ($words !== [] && strtolower($words[0]) === '--raw') {
            return $this->runRaw($text, $context, \array_slice($words, 1));
        }

        $breakdown = ContextSources::measure($context, self::instructionFiles($context->root));

        return CommandResult::reply($text, (new ContextCommand($breakdown))->report());
    }

    /**
     * The `--raw` arm: dump the next request's prompt and tool schemas to a
     * JSON file and a plain-text sibling, then reply paths and sizes.
     *
     * @param list<string> $args words after `--raw` — at most one named path
     */
    private function runRaw(string $text, CommandContext $context, array $args): CommandResult
    {
        if (\count($args) > 1) {
            return CommandResult::errorReply($text, Lang::t('cmd.context.raw.bad-args'));
        }

        $backend = $context->backend;
        if (!$backend instanceof DumpsSystemPrompt) {
            // Named refusal: the user learns WHICH door is missing, and no
            // bytes are invented around it.
            return CommandResult::errorReply($text, Lang::t('cmd.context.raw.unsupported', [
                'backend' => $backend === null ? 'none' : (new \ReflectionObject($backend))->getShortName(),
            ]));
        }

        $literalTokens = [];
        $jsonWritten = false;

        try {
            $dump = $backend->dumpSystemPrompt();
            $json = \json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                // A schema carrying bytes json_encode cannot represent: refuse
                // loudly, never write a half-encoded file.
                throw new RuntimeException(Lang::t('cmd.context.raw.encode-failed'));
            }

            $base = $this->resolveBase($context, $args[0] ?? null, $literalTokens);
            $jsonTarget = $base . '.json';
            $txtTarget = $base . '.txt';
            AtomicFileWriter::write($jsonTarget, $json, 0600);
            $jsonWritten = true;
            AtomicFileWriter::write($txtTarget, $dump['prompt'], 0600);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if ($jsonWritten && !\is_file($txtTarget)) {
                // The JSON half landed before the failure; name the orphan
                // instead of letting the reply imply nothing was written.
                $message .= "\n" . Lang::t('cmd.context.raw.json-residue', ['json' => $jsonTarget]);
            }
            return CommandResult::errorReply($text, $message);
        }

        $reply = Lang::t('cmd.context.raw.dumped', [
            'json' => $jsonTarget,
            'jsonBytes' => (string) \strlen($json),
            'txt' => $txtTarget,
            'txtBytes' => (string) \strlen($dump['prompt']),
        ]);
        if ($literalTokens !== []) {
            $reply .= "\n" . Lang::t('cmd.context.raw.unfilled', [
                'tokens' => implode(', ', array_map(
                    static fn (string $name): string => '[' . $name . ']',
                    $literalTokens,
                )),
            ]);
        }

        return CommandResult::reply($text, $reply);
    }

    /**
     * The stem both files hang off, decided under `/export`'s target rules:
     * no name → the exports shelf under a `prompt-<session>-<UTC stamp>`
     * default; a named path → `~` expanded, palette arms filled, then jailed
     * to the project root or the `~/.candy-code/` shelf — a miss on both is
     * the outside-root refusal, so a typo or a `../` cannot drop a prompt
     * somewhere unexpected. A directory receives the default name inside it;
     * a trailing `.json`/`.txt` is read as the stem, never duplicated.
     *
     * @param list<string> $literalTokens out: palette names left in the name
     *
     * @throws RuntimeException When no safe destination can be named.
     */
    private function resolveBase(CommandContext $context, ?string $path, array &$literalTokens): string
    {
        if ($path === null) {
            return $this->exportsDirectory() . '/' . $this->defaultFileName($context);
        }

        $path = $this->expandHome($this->expandTokens($path, $literalTokens));
        $root = $context->projectRoot();
        $target = $root === '' ? null : PathJail::resolveForCreate($root, $path);
        $target ??= $this->homeShelfTarget($path);
        if ($target === null) {
            throw new RuntimeException(Lang::t('cmd.context.raw.outside-root', [
                'path' => $path,
                'root' => $root === '' ? '' : " ({$root})",
                'exports' => ShareCommand::EXPORTS_SUBDIR,
            ]));
        }

        if (is_dir($target)) {
            return rtrim($target, '/') . '/' . $this->defaultFileName($context);
        }

        // The stem takes whichever suffix the user spelled, or none; both
        // files always exist, so a name is never allowed to BE one of them.
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        return \in_array($extension, ['json', 'txt'], true)
            ? substr($target, 0, -(\strlen($extension) + 1))
            : $target;
    }

    /**
     * @throws RuntimeException When the home directory is not this user's own.
     */
    private function exportsDirectory(): string
    {
        // owned(), not path(): path() falls back to the world-writable temp
        // directory, and an assembled prompt is the last thing to leave there
        // unasked — the same reasoning /export's shelf carries.
        $home = HomeDirectory::owned();
        if ($home === null) {
            throw new RuntimeException(Lang::t('cmd.context.raw.no-home'));
        }

        return rtrim($home, '/') . '/' . ShareCommand::EXPORTS_SUBDIR;
    }

    private function defaultFileName(CommandContext $context): string
    {
        $sessionId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $context->currentSessionId() ?? '') ?: 'session';
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

        return self::FILE_PREFIX . "{$sessionId}-{$stamp}";
    }

    /**
     * A positional path that misses the project jail is still welcome on the
     * tool's own shelf (`~/.candy-code/`), jailed through the home root and
     * then prefix-checked so only the shelf subtree passes — `/export`'s rule,
     * kept identical so one mental model covers both writers.
     */
    private function homeShelfTarget(string $path): ?string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            return null;
        }
        $shelf = rtrim($home, '/') . '/.candy-code';
        $resolved = PathJail::resolveForCreate($home, $path);
        if ($resolved === null) {
            return null;
        }

        return $resolved === $shelf || str_starts_with($resolved, $shelf . '/')
            ? $resolved
            : null;
    }

    /**
     * Expand a leading `~` to the owned home; other paths pass untouched —
     * and when no owned home can be named the spelling stays literal and the
     * jail rules decide, refusing there is the honest answer.
     */
    private function expandHome(string $path): string
    {
        if ($path !== '~' && !str_starts_with($path, '~/')) {
            return $path;
        }
        $home = HomeDirectory::owned();
        if ($home === null) {
            return $path;
        }

        return $path === '~' ? $home : rtrim($home, '/') . '/' . substr($path, 2);
    }

    /**
     * Fill the palette arms a dump can answer, in MediaStore's own formats;
     * every other bracketed {@see MediaStore::TOKENS} name stays literal and
     * comes back listed so a name typed against a stale media pattern degrades
     * visibly — a prompt carries no seed, no sampler, no image.
     *
     * @param list<string> $literalTokens out: palette names left in the name
     */
    private function expandTokens(string $path, array &$literalTokens): string
    {
        $expanded = preg_replace_callback(
            '/\[([a-z_]+)\]/',
            static function (array $m) use (&$literalTokens): string {
                if (!\in_array($m[1], MediaStore::TOKENS, true)) {
                    return $m[0];
                }
                if (\in_array($m[1], self::FILLABLE_TOKENS, true)) {
                    return match ($m[1]) {
                        'date' => date('Y-m-d'),
                        'time' => date('H-i-s'),
                        default => date('YmdHis'),
                    };
                }
                if (!\in_array($m[1], $literalTokens, true)) {
                    $literalTokens[] = $m[1];
                }

                return $m[0];
            },
            $path,
        ) ?? $path;

        // Jail integrity is structural, not checked: the capture is [a-z_]+
        // and MediaStore's own formats carry no '/', '.' or control bytes, so
        // expansion can never smuggle a path separator past resolveForCreate().

        return $expanded;
    }

    /**
     * The instruction documents this session's prompt splices, as names and
     * sizes only (roadmap VIS-2a): {@see \SugarCraft\Code\Cli\Bootstrap::instructionLoader()}
     * is the memoized instance the system prompt itself is built from, so the
     * panel reads the loader's own record — {@see \SugarCraft\Code\Context\InstructionFileLoader::loadDocuments()}
     * for the repository-shipped files, {@see \SugarCraft\Code\Context\InstructionFileLoader::loadPersonal()}
     * for the operator's own (kept apart there precisely because provenance
     * frames it differently), and {@see \SugarCraft\Code\Context\InstructionFileLoader::refusedPaths()}
     * for what was not read and why. A loader that cannot be built leaves the
     * figure null and the report says "not measured" — the same unknown-honesty
     * rule every other part of the panel follows.
     *
     * @return array{files: list<array{path: string, bytes: int, personal: bool}>, refused: list<array{path: string, reason: string}>}|null
     */
    private static function instructionFiles(?string $root): ?array
    {
        return ContextSources::priceInstructionFiles($root);
    }
}
