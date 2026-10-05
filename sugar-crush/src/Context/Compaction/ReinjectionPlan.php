<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Compaction;

use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Context\Utf8Scrub;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * What the first request after a compaction re-injects (roadmap 2.6): the
 * files the agent was working on, re-read from disk, and the bodies of the
 * skills it had loaded — carried on that step's `<turn-context>` row
 * ({@see TurnContextBlock::withReinjection()}), with a fresh git snapshot
 * beside it.
 *
 * WHY. A compaction replaces the rows that held a file's contents or a
 * skill's instructions with a summary that keeps, at best, their names. The
 * very next step then re-reads the files it was editing and re-loads the
 * skill it was following — or, worse, works from what the summary says they
 * contain. Claude Code's answer, which this mirrors (crush_report §4.4 "What
 * survives compaction"): re-read up to five of the files read or edited,
 * most recent first, each up to 5,000 tokens, and re-inject invoked skill
 * bodies at up to 5,000 tokens each and 25,000 in all, oldest dropped first.
 * One more bound is this harness's own: the whole re-injection stays within
 * {@see MAX_WINDOW_PERCENT} of the context window, so a small-window model is
 * never handed back what the compaction just took away.
 *
 * WHEN — STATELESS, OFF THE HISTORY. A compaction is recognised by what it
 * leaves in the conversation the engine is handed: the `[summary] ` rows a
 * host compaction writes ({@see StateSummaryTemplate::ROW_PREFIX}) and the
 * harness's active step-summary block in the turn's ledger
 * ({@see \SugarCraft\Crush\Context\Pruning\CompressionBlock}, roadmap 2.4-1;
 * also `/compress`, the person's — never the model's own `Compress` call,
 * which shrank its context on purpose). Their hash is the compaction
 * {@see cycleOf() cycle}; the row that re-injects stamps it in its
 * {@see MARKER} line, and a cycle the newest stamp already names is done.
 * So there is no flag to carry across the fork, a resumed session re-injects
 * once if it was compacted and never re-injected, and the host's compaction
 * code path — which must do no file I/O on the render loop — writes nothing
 * for it: the files are read here, in the turn's own process.
 *
 * WHAT — FROM THE HISTORY TOO. Files: the Read/Edit/Write calls still in the
 * conversation, most recent first, then the "Files modified" and "Files read"
 * lists of the newest state row a host compaction wrote (derived from the
 * tool rows it condensed, {@see FilesTouched}). Skills: the Skill calls
 * still in the conversation ({@see SkillTool::invokedIn()}), then the roster
 * the turn-context rows carry ({@see TurnContextBlock::invokedSkillsIn()}),
 * which outlives the calls it was built from. The plan: the newest of those
 * files that is a plan-mode plan — a `.md` directly in
 * {@see \SugarCraft\Crush\Permissions\PermissionGate::PLANS_DIR} (roadmap
 * 5.7-1) — is restored as its own `<plan>` part after the files, outside
 * their five, and is the first thing the budget pays for: a plan is what a
 * plan-mode turn was written to keep, and the reason it lives on disk at all
 * is that a compaction would fold it away in the transcript.
 *
 * SAFETY. Only files inside the project root are re-read (a path outside it
 * was reached through a tool call the person approved; re-reading it silently
 * is not that), never a binary file, and nothing larger than the per-file cap
 * is inlined — it is named as a referenced file instead. Skill bodies are
 * loaded through the session's own {@see SkillTool}, so a skill that is no
 * longer model-invocable is not re-injected. Every payload byte is passed
 * through {@see PromptFence::escape()}.
 */
final readonly class ReinjectionPlan
{
    /** Files re-read at most, most recently touched first (Claude Code's five). */
    public const MAX_FILES = 5;

    /** A file over this many estimated tokens is referenced, not inlined. */
    public const MAX_FILE_TOKENS = 5_000;

    /** One skill body is clipped at this many estimated tokens. */
    public const MAX_SKILL_TOKENS = 5_000;

    /** All skill bodies together; the oldest are dropped first. */
    public const MAX_SKILLS_TOKENS = 25_000;

    /** The whole re-injection never exceeds this share of the context window. */
    public const MAX_WINDOW_PERCENT = 10;

    /**
     * The first words of a rendered re-injection, followed by its cycle —
     * what {@see lastCycleIn()} reads back. Public so the turn-context row can
     * tell its own head from the re-injected payload after it.
     */
    public const MARKER = 'Restored after context compaction';

    /** Bytes read from a file at most before it is judged by its token count. */
    private const MAX_FILE_BYTES = 262_144;

    /** Paths the newest host state row lists under these headings, in this order. */
    private const STATE_FILE_HEADINGS = ['Files modified', 'Files read'];

    /**
     * @param string                                  $cycle  the compaction this plan answers
     * @param list<string>                            $files  most recently touched first
     * @param list<array{name: string, args: string}> $skills most recently loaded first
     */
    private function __construct(
        public string $cycle,
        public array $files = [],
        public array $skills = [],
    ) {
    }

    /**
     * The plan the next request owes, or null when no compaction is pending
     * re-injection: none happened, or the newest stamped row already
     * answered this one.
     *
     * @param iterable<mixed> $messages the turn's typed conversation
     */
    public static function pendingIn(iterable $messages, ?ContextLedger $ledger): ?self
    {
        $messages = \is_array($messages) ? $messages : iterator_to_array($messages, false);
        $cycle = self::cycleOf($messages, $ledger);
        if ($cycle === '' || self::lastCycleIn($messages) === $cycle) {
            return null;
        }

        return new self($cycle, self::filesIn($messages), self::skillsIn($messages));
    }

    /**
     * The compaction state $messages and $ledger show, as a short hash, or ''
     * when nothing in them was compacted.
     *
     * @param iterable<mixed> $messages
     */
    public static function cycleOf(iterable $messages, ?ContextLedger $ledger): string
    {
        $parts = [];
        foreach ($messages as $message) {
            $content = self::contentOf($message);
            if ($content !== null && str_starts_with($content, StateSummaryTemplate::ROW_PREFIX)) {
                $parts[] = $content;
            }
        }

        $block = $ledger?->activeBlock();
        if ($block !== null && !$block->isRange() && $block->by !== PruneAuthor::Model) {
            $parts[] = 'b' . $block->id . "\0" . $block->summary;
        }

        return $parts === [] ? '' : substr(hash('xxh128', implode("\0\0", $parts)), 0, 12);
    }

    /**
     * The cycle the newest re-injecting turn-context row in $messages names,
     * or null when none does. Only a row's FIRST marker line counts: the
     * header renders ahead of the files, so a re-injected file that quotes
     * the line is never read as the stamp.
     *
     * @param iterable<mixed> $messages
     */
    public static function lastCycleIn(iterable $messages): ?string
    {
        $last = null;
        foreach ($messages as $message) {
            if (!TurnContextBlock::isTurnContext($message)) {
                continue;
            }
            if (preg_match('/^' . preg_quote(self::MARKER, '/') . ' \(cycle ([0-9a-f]+)\)/m', (string) self::contentOf($message), $m) === 1) {
                $last = $m[1];
            }
        }

        return $last;
    }

    /**
     * The files $messages show the agent reading or writing, most recent
     * first: the Read/Edit/Write calls still in the conversation (a call
     * whose result was an error touched nothing), then the newest host state
     * row's "Files modified" and "Files read" lists, each newest first.
     *
     * @param iterable<mixed> $messages
     * @return list<string>
     */
    public static function filesIn(iterable $messages): array
    {
        $tools = [...FilesTouched::MODIFY_TOOLS, ...FilesTouched::READ_TOOLS];
        /** @var array<string, string> $calls callId => path, in call order */
        $calls = [];
        /** @var array<string, true> $failed */
        $failed = [];
        $stateRows = [];

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls() ?? [] as $call) {
                    if (!$call instanceof ToolCall || !\in_array($call->name(), $tools, true)) {
                        continue;
                    }
                    $path = $call->arguments()[FilesTouched::PATH_ARGUMENT] ?? null;
                    if (\is_string($path) && trim($path) !== '') {
                        unset($calls[$call->id()]);
                        $calls[$call->id()] = trim($path);
                    }
                }
            } elseif ($message instanceof ToolResultMessage && $message->isError()) {
                $failed[$message->toolCallId()] = true;
            }

            $content = self::contentOf($message);
            if ($content !== null && StateSummaryTemplate::isStateRow($content)) {
                $stateRows[] = $content;
            }
        }

        $files = [];
        foreach (array_reverse($calls, true) as $id => $path) {
            if (!isset($failed[(string) $id])) {
                $files[] = $path;
            }
        }

        $state = StateSummaryTemplate::latestIn($stateRows);
        foreach (self::STATE_FILE_HEADINGS as $heading) {
            $listed = [];
            foreach (preg_split('/\R/u', $state?->section($heading) ?? '') ?: [] as $line) {
                $item = trim(preg_replace('/^\s*[-*]\s*/u', '', $line) ?? $line);
                if ($item !== '' && strtolower($item) !== StateSummaryTemplate::EMPTY) {
                    $listed[] = $item;
                }
            }
            // The lists keep first-seen order: the newest is last.
            array_push($files, ...array_reverse($listed));
        }

        return array_values(array_unique($files));
    }

    /**
     * The skills $messages show loaded, most recent first: the Skill calls
     * still in the conversation, then the turn-context roster for the ones
     * whose calls a compaction hid (their arguments are not on the roster,
     * so they re-load without them).
     *
     * @param iterable<mixed> $messages
     * @return list<array{name: string, args: string}>
     */
    public static function skillsIn(iterable $messages): array
    {
        $messages = \is_array($messages) ? $messages : iterator_to_array($messages, false);
        $skills = [];
        foreach (SkillTool::invokedIn($messages) as $skill) {
            $skills[$skill['name']] = $skill;
        }
        foreach (TurnContextBlock::invokedSkillsIn($messages) as $name) {
            $skills[$name] ??= ['name' => $name, 'args' => ''];
        }

        return array_values($skills);
    }

    /**
     * The roster for the turn-context row: every skill name {@see skillsIn()}
     * finds, most recent first.
     *
     * @param iterable<mixed> $messages
     * @return list<string>
     */
    public static function skillNamesIn(iterable $messages): array
    {
        return array_map(static fn (array $skill): string => $skill['name'], self::skillsIn($messages));
    }

    /** @return list<string> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<array{name: string, args: string}> */
    public function skills(): array
    {
        return $this->skills;
    }

    public function isEmpty(): bool
    {
        return $this->files === [] && $this->skills === [];
    }

    /**
     * The re-injection's text for the turn-context row — its {@see MARKER}
     * line, the skill bodies, the files, then the plan; with nothing to restore, the
     * marker line alone, which still answers the cycle. Reads the files and loads the skills NOW: call it only where
     * I/O is allowed (the engine's turn, never a render loop).
     *
     * @param ?string                    $root   the project root; null takes the process directory
     * @param iterable<mixed>            $tools  the turn's tools, where the session's {@see SkillTool} is found
     * @param int                        $window the context window in tokens; 0 when unknown
     */
    public function render(?string $root, iterable $tools, int $window = 0): string
    {
        $budget = self::MAX_FILES * self::MAX_FILE_TOKENS + self::MAX_SKILLS_TOKENS;
        if ($window > 0) {
            $budget = min($budget, intdiv($window * self::MAX_WINDOW_PERCENT, 100));
        }

        [$planPath, $filePaths] = $this->splitPlan($root);
        [$plan, $planReferenced] = $planPath === null ? [[], []] : $this->renderFiles($root, $budget, [$planPath], 'plan');
        foreach ($plan as $part) {
            $budget -= TokenEstimate::ofText($part);
        }
        [$skills, $budget] = $this->renderSkills($tools, $budget);
        [$files, $referenced] = $this->renderFiles($root, $budget, $filePaths, 'file');

        $parts = [...$skills, ...$files];
        if ($referenced !== []) {
            $parts[] = sprintf(
                'Referenced files (over %d tokens, not restored; Read them if you need them): %s',
                self::MAX_FILE_TOKENS,
                PromptFence::escape(implode(', ', $referenced)),
            );
        }
        array_push($parts, ...$plan);
        if ($planReferenced !== []) {
            $parts[] = sprintf(
                'Your plan (over %d tokens, not restored; Read it before you go on): %s',
                self::MAX_FILE_TOKENS,
                PromptFence::escape($planReferenced[0]),
            );
        }
        // Stamped even with nothing to restore: the cycle is answered either
        // way, so the next step neither looks again nor re-polls git for it.
        if ($parts === []) {
            return sprintf('%s (cycle %s): nothing from before it needed restoring.', self::MARKER, $this->cycle);
        }

        return sprintf(
            '%s (cycle %s): the skills you had loaded and the files you were working on%s, re-read from disk now. '
            . 'They are current; read a file again only for a part not shown here.',
            self::MARKER,
            $this->cycle,
            $planPath === null ? '' : ', with your plan',
        ) . "\n\n" . implode("\n\n", $parts);
    }

    /**
     * @param iterable<mixed> $tools
     * @return array{0: list<string>, 1: int} the rendered bodies and the budget left
     */
    private function renderSkills(iterable $tools, int $budget): array
    {
        $skillTool = null;
        foreach ($tools as $tool) {
            if ($tool instanceof SkillTool) {
                $skillTool = $tool;
                break;
            }
        }
        if ($skillTool === null || $this->skills === []) {
            return [[], $budget];
        }

        $rendered = [];
        $spent = 0;
        foreach ($this->skills as $skill) {
            try {
                $result = $skillTool->execute(['name' => $skill['name'], 'args' => $skill['args']]);
            } catch (\Throwable) {
                continue;
            }
            if ($result->isError()) {
                continue;
            }
            $body = self::clipped(Utf8Scrub::clean($result->content()), self::MAX_SKILL_TOKENS);
            $tokens = TokenEstimate::ofText($body);
            // Oldest dropped first: the list is newest first, so the first
            // body that no longer fits ends it.
            if ($spent + $tokens > min(self::MAX_SKILLS_TOKENS, $budget)) {
                break;
            }
            $spent += $tokens;
            $rendered[] = sprintf('<skill name="%s">', self::attribute($skill['name'])) . "\n"
                . PromptFence::escape($body) . "\n</skill>";
        }

        return [$rendered, $budget - $spent];
    }

    /**
     * The newest of {@see files()} that is a plan-mode plan, and the rest in
     * their order. A plan is a `.md` that resolves directly into
     * {@see \SugarCraft\Crush\Permissions\PermissionGate::PLANS_DIR} under
     * the project root — the one write plan mode allows. Older plans stay
     * ordinary files.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function splitPlan(?string $root): array
    {
        $base = $root ?? (getcwd() ?: '.');
        $realRoot = realpath($base);
        $plansDir = $realRoot === false ? false : realpath($realRoot . '/' . \SugarCraft\Crush\Permissions\PermissionGate::PLANS_DIR);
        if ($realRoot === false || $plansDir === false || !ContainedPath::below($plansDir, $realRoot)) {
            return [null, $this->files];
        }

        $plan = null;
        $files = [];
        foreach ($this->files as $path) {
            $real = $plan === null ? realpath(str_starts_with($path, '/') ? $path : $base . '/' . $path) : false;
            if ($real !== false && \dirname($real) === $plansDir && str_ends_with($real, '.md')) {
                $plan = $path;

                continue;
            }
            $files[] = $path;
        }

        return [$plan, $files];
    }

    /**
     * @param list<string> $paths most recently touched first
     * @param string       $tag   the element each inlined file is wrapped in
     * @return array{0: list<string>, 1: list<string>} the inlined files and the referenced paths
     */
    private function renderFiles(?string $root, int $budget, array $paths, string $tag): array
    {
        $base = $root ?? (getcwd() ?: '.');
        $realRoot = realpath($base);
        if ($realRoot === false) {
            return [[], []];
        }

        $inlined = [];
        $referenced = [];
        foreach ($paths as $path) {
            if (\count($inlined) + \count($referenced) >= self::MAX_FILES) {
                break;
            }
            $real = realpath(str_starts_with($path, '/') ? $path : $base . '/' . $path);
            // Gone, not a file, or outside the project: not re-read.
            if ($real === false || !is_file($real) || !is_readable($real) || !ContainedPath::below($real, $realRoot)) {
                continue;
            }

            $size = filesize($real);
            $content = $size !== false && $size <= self::MAX_FILE_BYTES ? @file_get_contents($real) : null;
            if ($content === false || ($content !== null && str_contains($content, "\0"))) {
                continue; // unreadable now, or binary
            }
            $content = $content === null ? null : Utf8Scrub::clean($content);
            $tokens = $content === null ? PHP_INT_MAX : TokenEstimate::ofText($content);
            if ($tokens > self::MAX_FILE_TOKENS || $tokens > $budget) {
                $referenced[] = $path;

                continue;
            }

            $budget -= $tokens;
            $inlined[] = sprintf('<%s path="%s">', $tag, self::attribute($path)) . "\n"
                . PromptFence::escape($content) . "\n</{$tag}>";
        }

        return [$inlined, $referenced];
    }

    /** $text cut to about $maxTokens, marked where it was cut. */
    private static function clipped(string $text, int $maxTokens): string
    {
        $tokens = TokenEstimate::ofText($text);
        if ($tokens <= $maxTokens) {
            return $text;
        }

        $length = mb_strlen($text);
        $keep = max(0, intdiv($length * $maxTokens, $tokens) - 64);

        return mb_substr($text, 0, $keep) . "\n…[clipped; call the Skill tool for the full body]";
    }

    /** $value made safe inside a double-quoted attribute. */
    private static function attribute(string $value): string
    {
        return str_replace(['"', "\n", "\r"], ['&quot;', ' ', ' '], PromptFence::escape($value));
    }

    /** A message's text, or null for a value that is not a message. */
    private static function contentOf(mixed $message): ?string
    {
        if ($message instanceof \SugarCraft\Crush\Messages\Message) {
            return $message->content();
        }

        return $message instanceof \SugarCraft\Crush\Message ? $message->content : null;
    }
}
