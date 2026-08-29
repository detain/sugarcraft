<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use DateTimeImmutable;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;

/**
 * Renders the execution environment for injection into the system prompt.
 *
 * WHAT IS A SNAPSHOT AND WHAT IS NOT — the distinction this docblock used to get
 * backwards. {@see capture()} freezes exactly three things: the working
 * directory, the model name and the timestamp. Everything else
 * {@see render()} emits is read AT RENDER TIME: `PHP_OS_FAMILY`, `php_uname()`,
 * `PHP_VERSION` (constants, so stable anyway) and — the one that matters — the
 * git snapshot, which shells out to `git branch`/`status`/`log`/`diff` on every
 * `render()` call. `buildSystemPrompt()` runs once per step of the agentic loop,
 * so those FIVE subprocesses run per step (one `branch`, one `status`, one
 * `log`, and one `diff` for each of the staged and unstaged views), and the
 * block's git section reflects the repository as the agent has already changed
 * it rather than as it was at session start. FIVE is the count WHEN THE PROCESS
 * HELPERS EXIST AND THE DIFF IS EMITTED: four of them go through `proc_open`
 * and one through `shell_exec`, and a build where either is in
 * `disable_functions` runs fewer — see {@see gitStatusSnapshot()} for what it
 * emits instead, and why a hard failure there would have taken the whole
 * session down rather than one line. When the caller has suppressed the diff
 * via {@see withWriteSinceLastRender()} the count is THREE — the two `diff`
 * calls are the expensive half, which is the point of the suppression (see the
 * WHAT IT COSTS IN PROMPT CACHE paragraph below).
 *
 * WHAT THAT COSTS, measured rather than estimated, and PAIRED — each before/
 * after figure taken on the SAME tree, because the first draft of this note
 * compared an after-figure from one repository against a before-figure from
 * another and reported a 4.5x that was really 14x:
 *
 *  - This monorepo checkout, 6,969 tracked files, lightly dirty:
 *    **23.9 ms/render** for the three commands the block had before, **36.3 ms**
 *    with both diff sections. ~1.5x.
 *  - A 291-tracked-file repository with all 291 modified — a 124 KB working
 *    diff: **7.6 ms** before, **106.7 ms** after. ~14x.
 *
 * The two ratios differ because the two halves scale on DIFFERENT axes, which is
 * the actual finding: the old block's cost tracked the TRACKED-FILE COUNT (what
 * `status --porcelain` must stat), while the added cost tracks the SIZE OF THE
 * DIFF. A big clean repo pays almost nothing extra; a small repo with everything
 * rewritten pays the most. THAT SECOND AXIS HAS NO CEILING, and an earlier
 * revision of this note claimed one: it called ~107 ms "the absolute worst
 * case", which was the worst case OF THE TWO FIXTURES ABOVE and not of the
 * mechanism — a figure whose domain was two directories, written as a property
 * of the code. Pushed on purpose since: a 45.9 MB working diff (40 files, 400k
 * changed lines each way) renders in **399 ms**, of which `git diff` itself is
 * 373 ms. The block still comes out 9,013 B and peak memory is still 4.0 MB, so
 * the cap and the bounded drain hold under it; what does not hold is any fixed
 * millisecond figure. If it ever needs cutting the lever is named on
 * {@see gitDiffSection()}: the diff is DRAINED in full so the truncation marker
 * can state the real byte total, instead of being cut short by a SIGPIPE that
 * would leave the total unknowable. Reverse that trade there, and expect the
 * marker to lose its total.
 *
 * WHAT IT COSTS IN PROMPT CACHE — the larger of the two costs, and absent from a
 * note that claimed to state the cost. The three-command block's bytes moved
 * only when the set of modified PATHS moved; a diff body moves when ANY BYTE of
 * any tracked file moves. MEASURED, two successive edits to the SAME file with
 * `$now` pinned so the date line cannot account for it: the three-command block
 * rendered BYTE-IDENTICAL both times (327 B, no differing byte at all), this one
 * rendered 598 B then 615 B and first differs at byte **524**. Those four
 * figures are of that one two-edit fixture, not of this repository. WHERE THE
 * BLOCK SITS DECIDES HOW FAR THAT DIFFERING BYTE REACHES: on
 * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} it is now the LAST
 * layer — P3.S1 moved it there, so a step that touched one file re-prefills
 * only the `<env>` tail, and the repo map, the instruction documents, the
 * memory block and the skill listing that PRECEDE it keep their cache hit — and
 * on {@see \SugarCraft\Crush\Agents\Agent::systemPrompt()} it is still the TAIL
 * of the system message with the entire conversation behind it, so the move
 * bounded the blast radius on the Runtime path and left the Agent path where it
 * was. `tests/Providers/PromptStabilityTest` exists because "the cache hit
 * survives only as far as the first byte that differs", which is what makes
 * this a bill rather than a theory.
 *
 * THE LEVER, NOW PULLED — that bill used to end by naming a fix this class
 * never took: emit the diff only on the step AFTER a write tool actually ran,
 * "which needs a signal this class does not receive today". It receives it now,
 * as {@see withWriteSinceLastRender()}, and the default keeps the old
 * behaviour: a bare {@see capture()} or constructor emits both diff sections,
 * so every pre-existing caller and a fresh Runtime's first prompt render
 * exactly as they always did — the golden system prompt keeps its diff. The
 * caller flips the signal: after a step in which a write tool ran it derives
 * `withWriteSinceLastRender(true)` and the next prompt shows the working diff;
 * after a step in which none ran it derives `withWriteSinceLastRender(false)`
 * and the next prompt skips BOTH diff subprocesses and sections, leaving
 * branch, status and log in place. That removes the bill for the common case —
 * two consecutive no-write steps rendering byte-different prompts for a diff
 * the model has already seen — and the runtime cost with it: the two `diff`
 * calls are the expensive half of the five (373 ms of the 399 ms worst case
 * measured above was `git diff` itself).
 *
 * CROSS-TURN SEMANTICS, STATED — the signal lives on the block, the block
 * lives on the Runtime, and {@see \SugarCraft\Crush\Backend\EngineBackend::complete()}
 * builds a FRESH Runtime per user turn. A write on the LAST step of a turn is
 * therefore not lost: the next turn's first prompt starts in the default emit
 * state and shows the diff of that write, which is exactly the state the new
 * turn should open on. The honest asymmetry is the quiet case: a fresh turn
 * whose predecessor ended without a write still shows the diff ONCE, because
 * the default cannot tell "first step of a turn" from "step after a write",
 * and showing beats hiding — showing a diff twice costs bytes, hiding one
 * saves the bytes but also withholds the state the new turn opens on, and the
 * second loss is the worse one. Refining the first prompt of a
 * turn to start suppressed after a quiet turn belongs to the caller that wires
 * this signal into the engine loop, and that caller does not exist yet; the
 * signal existing with a truthful default is what this step ships, and the
 * wiring step decides whether a quiet turn earns a quiet opening.
 *
 * The live-rather-than-frozen choice is deliberate — a model reading a stale
 * `git status` after its own edits is worse than either cost — and it is pinned
 * by
 * `tests/Providers/PromptStabilityTest::testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()`.
 * It is also STATED, in the prompt, at the head of the git section: see
 * {@see GIT_STATE_CAVEAT}, which is the inverse of the "snapshot at
 * conversation start — may be outdated" caption upstream ships, because
 * upstream's would be false of this class.
 * See {@see MemoryBlock}, which builds an argument on exactly this block's
 * position in the prompt.
 *
 * The full line set is enumerated on {@see render()}, which is the method that
 * emits it — one list, in one place, so the two cannot drift apart again.
 */
final readonly class EnvironmentBlock
{
    /**
     * Bounded process capture and the one truncation wording, reused rather
     * than respelled.
     *
     * Both live under `Tools\Concerns` because tool results were the first
     * thing here that needed them; neither touches `$this` state and
     * {@see \SugarCraft\Crush\Commands\CommandSpec} already uses
     * `TruncatesOutput` from outside `Tools`, so the namespace is where they
     * were born rather than a statement about who may use them. Property-free,
     * which is what lets a `readonly` class use them at all.
     *
     * The pairing is the whole reason a truthful cap is possible here:
     * {@see CapturesProcessOutput::runCaptured()} drains the pipe to
     * completion while RETAINING only $maxBytes, and returns the exact count of
     * what it discarded, so {@see TruncatesOutput::truncateOutput()} can name
     * the real size of the diff rather than the size of the part that fit.
     */
    use CapturesProcessOutput;
    use TruncatesOutput;

    /**
     * Retained bytes per diff section, before the truncation marker's reserve.
     *
     * MEASURED, on this monorepo, which is the fair worst case the plan item
     * asks for. NAME THE DOMAIN FIRST, because the sizing sample is NEITHER of
     * the two commands this class runs: `git diff HEAD~10 HEAD` is a COMMIT
     * RANGE, here **425,603 B across 8,391 lines** (`HEAD~3 HEAD`: 164,340 B /
     * 3,118 lines), and it stands in for a working tree that has been rewritten
     * that heavily rather than for today's. What carries over to the commands
     * that ARE run is the MEAN — ~51 B per diff line, counting hunk headers and
     * unchanged context — and that was checked against the real thing rather
     * than assumed: this checkout's actual `git diff` is **70,452 B over 1,398
     * lines = 50.4 B/line**, against 50.7 for the HEAD~10 range. At the ~4
     * B/token rule of thumb the range figure is ~106k tokens of diff; emitted on
     * EVERY step of the agentic loop it does not merely inflate the prompt, it
     * evicts the conversation. So the cap is the feature and the diff is the
     * sample.
     *
     * 8 KiB is ~160 diff lines AT THAT MEASURED MEAN — a figure whose domain is
     * this repository's own diffs, not a property of diffs in general; a repo of
     * minified bundles gets far fewer lines for the same bytes.
     *
     * Sized BETWEEN its two neighbours on purpose. {@see MemoryBlock::MAX_BYTES}
     * is 4096 for twelve curated notes, and {@see TruncatesOutput}'s tool
     * default is 65536; a diff needs more than a note list (one hunk with
     * context is already ~10 lines) and less than a tool result, because a tool
     * result is text the model ASKED for whereas this block is emitted
     * unconditionally on every step whether it helps or not.
     *
     * THE AXIS THAT LADDER RANKS ON, named because a third context block now
     * exists and the count "two neighbours" would otherwise silently go stale.
     * {@see RepoMapBlock} joined this directory and the system prompt after
     * this argument was written, and its {@see RepoMapBlock::MAX_SECTION_BYTES}
     * is 8192 — the same figure as this one, which reads at a glance like a
     * third rung landing on top of this one. It is not a rung at all: the
     * ladder above ranks content whose size GROWS WITH USE, where the cap is
     * the feature and what renders under it is a sample. Notes accumulate as
     * the user writes them; a diff grows as the agent edits. A repository's
     * shape does neither — it is fixed for the session and does not respond to
     * anything the agent does — so `RepoMapBlock` sizes itself to the largest
     * real workspace measured instead, and the coincidence of 8192 is a
     * coincidence. This constant's two neighbours on the growth axis are still
     * exactly the two named above.
     *
     * What a third block DOES change is the total, which no single constant
     * here bounds: the unconditional prompt now costs up to this block's
     * 24,576 B of capped fields (below), plus `MemoryBlock`'s 4,096, plus
     * `RepoMapBlock`'s 2 x 8,192 — 45,056 B, exactly 44 KiB, of capped block
     * text if every bound were struck at once. In practice `RepoMapBlock`'s
     * two sections are near mutually exclusive (see its own docblock), so a
     * real ceiling is 36,864 B, exactly 36 KiB. The arithmetic was right the
     * first time and the UNITS were not: those two totals were written "about
     * 45 KiB" and "closer to 37 KiB", which are the byte counts divided by
     * 1000 while the same paragraph spells 8192 and 4096 as KiB correctly.
     *
     * DOMAIN OF THE BOUND: per SECTION. {@see render()} emits two independently
     * capped sections (staged, unstaged), so the block's diff contribution is
     * bounded by 2 * this, plus the two label lines. Independent rather than a
     * shared budget so a large staged diff cannot starve the unstaged one, which
     * is the section holding the edits the agent itself just made.
     */
    public const DIFF_MAX_BYTES = 8192;

    /**
     * Retained bytes for the `--porcelain` status and the recent-log lines, each.
     *
     * NOT part of P8.10, and here because P8.10's cap was measurably defeated by
     * its neighbour. MEASURED while sizing {@see DIFF_MAX_BYTES}, on a 291-file
     * working tree: `git status --porcelain` alone was **9,791 B over 291 lines**
     * (~34 B per changed path) and the finished `<env>` block came to 18,390 B
     * with a correctly-capped 8 KiB diff inside it. `--porcelain` also lists
     * UNTRACKED paths, so one unignored `node_modules` or `vendor` makes the
     * field arbitrarily large — and unlike the diff it was never bounded at all,
     * in bytes or in memory.
     *
     * 4 KiB is ~120 changed paths AT THAT MEASURED 34 B/line — a mean over this
     * repository's own porcelain output, not a constant of git. Half
     * {@see DIFF_MAX_BYTES} because a path list is a summary and the diff is the
     * evidence; the same reasoning that puts a diff below a tool result puts a
     * name list below a diff.
     *
     * `log --oneline -5` is bounded to five LINES by its own flag, which is a
     * bound in the wrong dimension: a commit subject has no length limit, so
     * five lines is not five short lines. It gets the same byte cap rather than
     * an argument about how long a subject usually is.
     *
     * THE WHOLE BLOCK, therefore: 2 * {@see DIFF_MAX_BYTES} + 2 * this = 24,576 B
     * of capped FIELD text, plus the branch line, the seven fixed lines, the
     * four field labels, the 100-byte {@see GIT_STATE_CAVEAT} caption and the
     * `<env>` fence. Each field's truncation marker is
     * reserved INSIDE its own cap by
     * {@see TruncatesOutput::truncateMerged()} rather than added on top, so the
     * 24,576 is a true ceiling on the fields and only the fixed part sits
     * outside it — under 25 KiB (25,600 B) however dirty the tree is. That is a
     * CLAIM ABOUT THIS ARITHMETIC and it is pinned rather than asserted:
     * {@see \SugarCraft\Crush\Tests\Context\EnvironmentBlockTest::testTheWholeGitSectionStaysBoundedHoweverDirtyTheTreeIs()}
     * derives the same 25,600 from the two constants, so a change to either cap
     * moves the test with the code instead of leaving a stale number here.
     * MEASURED against it on a fixture with ALL FOUR capped fields clipped at
     * once — 60 rewritten tracked files (30 staged, 30 not), 60 untracked ones
     * and five 1,500-byte commit subjects — the block came to **21,804 B**, i.e.
     * 3,796 B of headroom under the derived ceiling. That figure is of THAT
     * fixture — it moves with the fixture's own directory and file names, which
     * this description does not pin — so the ceiling is the claim and the
     * absolute is only an illustration. (The SAME fixture rendered 21,702 B
     * against master, MEASURED by rebuilding it once and rendering it twice
     * with the same script, changing only which `EnvironmentBlock.php` was
     * loaded. The delta is +102 B — the 100-byte caption plus its blank line —
     * and being fixed-part text it is the same +102 B on any fixture, which is
     * the part of this parenthesis that reproduces.)
     * `branch --show-current` is deliberately left uncapped — a ref
     * name is bounded by the filesystem's own 255-byte name limit, and its empty
     * value is MEANINGFUL (a detached HEAD reports empty and exits 0), so
     * routing it through a helper that reports exit codes would turn a real
     * state into an error report.
     */
    public const SUMMARY_MAX_BYTES = 4096;

    /**
     * `?` (0x3F) — what an invalid UTF-8 byte sequence in the git output is
     * replaced with before the block leaves {@see render()}.
     *
     * WHY A ONE-BYTE SUBSTITUTE AND NOT U+FFFD. Every cap on this class is
     * counted in BYTES, and U+FFFD costs three of them, so scrubbing with it
     * could grow a section past the cap the section was just clipped to — a
     * bound defeated by the repair applied after it. `?` cannot: mbstring emits
     * one substitute per invalid SEQUENCE and every invalid sequence is at least
     * one byte, so the scrub's output is never longer than its input. MEASURED
     * over six shapes (a lone `\xe9`, a truncated 2-of-3 and 2-of-4 sequence,
     * three bare continuation bytes, `\xff\xfe\xfd` between ASCII, and a lead
     * byte followed by a printable): in/out byte lengths 4/4, 2/1, 2/1, 3/3,
     * 7/7, 2/2 — never longer, and `mb_check_encoding()` true on all six.
     *
     * It also makes the COUNT exact, which is why the marker can state one.
     * 0x3F is never a continuation byte, so a `?` already present in the input
     * is never consumed as part of an invalid sequence and passes through
     * untouched (`"\xc3("` scrubs to `"?("`, the `(` surviving). The number of
     * substitutions is therefore exactly the increase in `?` count, with no
     * second pass over the text.
     */
    private const UTF8_SUBSTITUTE = 0x3F;

    /**
     * The one reason string for "the process helper this needs is disabled".
     *
     * Its own constant because {@see gitField()} and {@see gitDiffSection()}
     * must say the same thing — a model that learns one wording for
     * "unavailable" should not have to learn a second.
     */
    private const NO_PROCESS_REASON = 'unavailable (proc_open is disabled on this build)';

    /**
     * The honest caption for what the git section below it is — and is not.
     *
     * Upstream both label this block a snapshot: crush heads it
     * `Git status (snapshot at conversation start - may be outdated):`
     * (prompt_expand.md §5.5) and Claude Code says *"this status is a snapshot
     * in time, and will not update during the conversation"* (§4.4). Copied
     * here that label would be FALSE, and falsifiably so:
     * {@see gitStatusSnapshot()} is called from {@see render()}, so
     * `branch`/`status`/`log` — and the two diffs when they are not suppressed
     * — are re-run on EVERY render, and `Runtime::buildSystemPrompt()` renders
     * once per step of the agentic loop. MEASURED through that production path
     * rather than through a bare block: two `buildSystemPrompt()` calls on ONE
     * memoized Runtime, with a tracked edit and a new untracked file written
     * between them, differed — the second prompt was **206 B** longer, the
     * first difference landing inside the `Status:` field, and only the second
     * named the new file. NO ABSOLUTE LENGTHS ARE RECORDED HERE, and the two
     * that used to be (with a first-difference offset beside them) are dropped
     * rather than corrected: the fixture repo's path is interpolated into the
     * prompt, so all three move with the temp directory's name and no two runs
     * on different hosts agree. The DELTA and the field the difference lands in
     * are the parts that reproduce; they are a property of the fixture's shape
     * — one tracked edit plus one new untracked file — not of the host.
     * Upstream can say "snapshot"
     * because crush builds its prompt ONCE at coordinator construction
     * (§5.5); on the Runtime path this class is re-rendered per step, which is
     * the whole difference — and the caption keeps the word "snapshot" so the
     * sentence a reader already carries from upstream is the one it displaces,
     * rather than a claim it merely sits beside.
     *
     * WHY THE CAPTION IS SCOPED TO THE GIT STATE and not to the block. The
     * block is genuinely MIXED: {@see capture()} freezes the cwd, the model
     * name and the timestamp, and only the git section is polled. A caption
     * claiming the whole `<env>` block is live would be the same kind of false
     * label in the other direction, so this one says "this git state" and is
     * emitted inside the git section only.
     *
     * WHY THE CAPTION MAKES NO PER-STEP CLAIM AT ALL, THOUGH ONE RENDERER
     * WOULD SUPPORT ONE. This constant used to carry a second sentence — "The
     * main agent loop rebuilds this prompt, and re-reads the state, on every
     * step." — on the theory that NAMING the actor scoped the claim to the
     * path that has steps. It does not: the subject of that sentence is *this
     * prompt*, and it was emitted unconditionally, including into prompts the
     * main agent loop never touches.
     *
     * There are TWO renderers of this block and their cadences differ. "Re-read
     * on every step" is true only where a step exists to re-read on, and that
     * is the Runtime path alone: `EngineBackend::complete()` builds one Runtime
     * per turn and loops `Runtime::run()` over it, so `buildSystemPrompt()` —
     * and this render with it — runs once per step. The OTHER renderer of this
     * block, {@see \SugarCraft\Crush\Agents\Agent::systemPrompt()}, is called
     * once per run by every one of its call sites — `AgentManager` (before,
     * not inside, its transient-failure retry), `App`'s skill fork,
     * `WorkflowEngine`'s five stage builders and `ProcessExecutor` — each
     * building a single `CompleteRequest` with no agentic loop behind it. On
     * those paths the block is rendered exactly ONCE for the whole run, so a
     * flat per-step claim IS a false caption handed to a subagent, which is the
     * same defect as copying upstream's, only pointing the other way — and it
     * was reaching them: the sentence rendered into `golden-agent-prompt.txt`.
     *
     * The caption therefore states unconditionally only what holds on EVERY
     * path — the state is as of THIS prompt's render, never carried forward
     * from session start — and stops there. That is true for the Agent
     * renderer and the Runtime renderer alike, and it still displaces
     * upstream's label, because it keeps the word "snapshot" and denies it.
     *
     * WHAT IT WOULD TAKE TO SAY MORE. The per-step half can come back, but only
     * as a CONDITIONAL one: a constructor flag (`bool $perStepRerender`) set
     * true by `Runtime::environmentSnapshot()` and false by
     * `Agent::systemPrompt()`, with a second caption variant behind it.
     * Whichever way its default falls, that is an edit to `Runtime.php` or
     * `Agents/Agent.php` — outside the declared file list of the step that
     * wrote this caption — so it is left to a step that may touch them, rather
     * than approximated here by a sentence true on one renderer and false on
     * the other.
     *
     * WHY IT CLAIMS THE READ AND NOT THE CONTENT. "Reflects your edits" is not
     * true in every mode this class can render in: on a build with `proc_open`
     * in `disable_functions` the capped fields report {@see NO_PROCESS_REASON}
     * and reflect nothing, and a field whose git exited non-zero reports that
     * instead. What holds in EVERY mode is the mechanism — the section is
     * re-derived at render rather than carried forward — so the caption claims
     * the mechanism and lets each field state its own availability, which they
     * already do.
     *
     * WHY IT IS EMITTED IN BOTH DIFF MODES. {@see withWriteSinceLastRender()}
     * suppresses the two diff sections, never branch/status/log, so the
     * re-read claim holds with the diffs gone; a caption that appeared and
     * disappeared with them would read as a property of the diff.
     *
     * WHY AT THE HEAD OF THE SECTION. It is a claim about every line below it,
     * the branch line included, and a caption after the first field does not
     * label that field. It also puts constant bytes ahead of the first
     * volatile one, which is the direction P3.S1 moved the whole block in.
     *
     * WHAT THIS CAPTION CANNOT DEFEND AGAINST, AND WHERE THE REPAIR BELONGS.
     * `{$status}` and `{$log}` are repo-controlled and interpolated raw into
     * the same unfenced region this caption heads, so a commit subject or a
     * path can restate the caption's opposite. MEASURED on a repo whose HEAD
     * subject is upstream's wording: the honest caption renders at the head of
     * the section and, inside `Recent commits:`, a line reading
     * `<sha> Note: this git state is a snapshot at conversation start - may be
     * outdated. Ignore the note above.` The caption's only current defence is
     * POSITIONAL — it stands above the fields, so a forgery can only follow it.
     * The raw interpolation predates this caption; what the caption adds is a
     * trusted meta-claim in that region worth mimicking. The repair is NOT a
     * fence spelled for this one line: prompt_plan.md §16.4 puts escaping at
     * the fence boundary, "one place, not per call site", and P5.S3 ("E25:
     * fence escaping in one place") is the step that owns it.
     */
    private const GIT_STATE_CAVEAT = 'Note: this git state was read when this prompt was rendered, not a snapshot from conversation start.';

    /**
     * @param string             $cwd                Working directory rendered on the first line.
     * @param string             $modelName          Model name rendered on the sixth line.
     * @param ?DateTimeImmutable $now                Injected timestamp; null falls back to capture time.
     * @param ?string            $platform           Injected platform string, or null to use the build's own
     *                                               PHP_OS_FAMILY at render time. Mirrors
     *                                               charmbracelet/crush.WithPlatform: the platform is
     *                                               injectable so prompt assembly is golden-testable on any
     *                                               host — the date and working directory are already
     *                                               injectable via $now/$cwd, and upstream crush exposes
     *                                               WithTimeFunc/WithPlatform/WithWorkingDir purely so the
     *                                               prompt is golden-testable.
     * @param bool               $writeSinceLastRender Whether {@see render()} emits the two git diff
     *                                               sections. Defaults to TRUE — the pre-P3.S2
     *                                               behaviour every existing caller and the golden
     *                                               prompt depend on; suppression happens only when a
     *                                               caller explicitly derives FALSE. The signal and
     *                                               the caller's state machine are documented on
     *                                               {@see withWriteSinceLastRender()}.
     */
    public function __construct(
        private string $cwd,
        private string $modelName,
        private ?DateTimeImmutable $now = null,
        private ?string $platform = null,
        private bool $writeSinceLastRender = true,
    ) {}

    /** Returns the captured working directory. */
    public function cwd(): string
    {
        return $this->cwd;
    }

    /** Returns the captured model name. */
    public function modelName(): string
    {
        return $this->modelName;
    }

    /** Returns the captured timestamp, or null if none was provided. */
    public function now(): ?DateTimeImmutable
    {
        return $this->now;
    }

    /** Returns the injected platform string, or null when the build's own PHP_OS_FAMILY is used at render time. */
    public function platform(): ?string
    {
        return $this->platform;
    }

    /**
     * Returns whether {@see render()} will emit the two git diff sections.
     *
     * TRUE means "a write tool ran since the last render — or nobody has said
     * anything either way", which is the default: a bare {@see capture()} and a
     * fresh Runtime's first prompt both emit, exactly as they always did.
     */
    public function writeSinceLastRender(): bool
    {
        return $this->writeSinceLastRender;
    }

    /**
     * Returns a copy that shows (TRUE) or withholds (FALSE) the two git diff
     * sections on its next render.
     *
     * The signal the class docblock named as missing is now this: the caller —
     * the engine loop that observes tool results between prompt builds — flips
     * it per step. After a step in which a write tool ran, derive TRUE and the
     * next prompt shows the working diff, which is what the model must see to
     * continue; after a step in which none ran, derive FALSE and the next
     * prompt skips BOTH diff subprocesses and sections, leaving branch, status
     * and log in place. That is the cache lever: two consecutive no-write steps
     * would otherwise render byte-different prompts for a diff the model has
     * already seen.
     *
     * The default is TRUE and stays TRUE until a caller says otherwise — the
     * suppression must be explicit, because the golden prompt and every
     * existing caller construct the block bare and depend on the diff being
     * there. Cross-turn behaviour follows from the default: a fresh Runtime
     * (EngineBackend::complete() builds one per user turn) starts in the emit
     * state, so a write on the LAST step of a turn shows its diff on the next
     * turn's first prompt — which is the state the new turn should open on.
     *
     * Immutable: the source block is untouched, so a caller holding one block
     * can derive both polarities for two different steps.
     */
    public function withWriteSinceLastRender(bool $writeSinceLastRender): self
    {
        return new self($this->cwd, $this->modelName, $this->now, $this->platform, $writeSinceLastRender);
    }

    /**
     * Factory that captures the current working directory and model name with a
     * fresh timestamp.
     *
     * Those three values, and only those three, are frozen here. The git section
     * {@see render()} appends is polled on every render — see the class docblock
     * for why that is deliberate and what it costs.
     *
     * @see W1.B3b for the production wiring that calls this once per Chat session.
     */
    public static function capture(string $cwd, string $modelName): self
    {
        return new self($cwd, $modelName, new DateTimeImmutable());
    }

    /**
     * Renders the environment block as an XML-flavoured string for embedding in prompts.
     *
     * Seven lines, in this order: cwd, git-repository flag, platform, OS version,
     * PHP version, model name, current date. When the cwd is a git repository, a
     * git section is appended — polled here, on every call, not frozen at capture
     * time — and it opens with {@see GIT_STATE_CAVEAT}, the caption that tells
     * the model the state is as of this render rather than a snapshot from
     * conversation start, followed by branch, --porcelain status, recent log,
     * staged diff and unstaged diff. The two diff sections are conditional:
     * they render only when
     * {@see withWriteSinceLastRender()} says a write happened (or says nothing
     * — the default emits, which is the pre-P3.S2 behaviour). Every field of
     * that section except the branch name is size-capped;
     * see {@see DIFF_MAX_BYTES} and {@see SUMMARY_MAX_BYTES} for the bounds and
     * for why the branch is the one exception.
     *
     * There is deliberately no "additional working directories" line, although
     * crush_code.md Phase 5 item 10a asks for one. See the inline note at the
     * OS-version line below for the full reason; the short version is that this
     * application has no multi-root concept for such a line to describe.
     */
    public function render(): string
    {
        $lines = [
            'Working directory: ' . $this->cwd,
            'Is directory a git repo: ' . ($this->isGitRepo() ? 'Yes' : 'No'),
            'Platform: ' . ($this->platform ?? strtolower(PHP_OS_FAMILY)),
            // Distinct from `Platform:` above, which is PHP_OS_FAMILY - a
            // four-value bucket ("Linux"/"Darwin"/"Windows"/"BSD") that answers
            // "which family of syscalls" and nothing about the release. This
            // line adds the version, which is what a model needs to know
            // whether a flag exists: `sed -i ''` vs `sed -i`, GNU vs BSD
            // coreutils, a kernel new enough for a given /proc entry.
            //
            // The value is self-labelling on purpose. php_uname('r') alone would
            // read as "OS version: 23.5.0" on macOS, which is the DARWIN
            // version, not the macOS product version anyone means by "macOS
            // 14.5" - a number next to a label that does not own it. Prefixing
            // php_uname('s') makes the pair name its own domain ("Darwin
            // 23.5.0", "Linux 6.8.0-137-generic", "Windows NT 10.0") and
            // matches the reference pattern item 10a asks to match.
            //
            // Unguarded by function_exists() as a considered choice, not an
            // oversight: gitStatusSnapshot() below reaches the shell FIVE times
            // in this same render path — ONCE through shell_exec (the branch
            // name) and FOUR times through proc_open, which is the count after
            // the capped fields moved to {@see CapturesProcessOutput}; an
            // earlier revision of this comment still said "shell_exec() three
            // times", which had been true of the three-command version and of
            // nothing since. Both of those are far more commonly disabled than
            // php_uname, so a guard here would protect the wrong end of the same
            // method — and unlike here, the ends that matter ARE guarded now,
            // because an unguarded call to a disabled function is an Error, not
            // a false return.
            'OS version: ' . php_uname('s') . ' ' . php_uname('r'),
            'PHP version: ' . PHP_VERSION,
            'Model: ' . $this->modelName,
            'Current date: ' . ($this->now ?? new DateTimeImmutable())->format('Y-m-d'),
        ];

        if ($this->isGitRepo()) {
            $lines[] = '';
            $lines[] = $this->gitStatusSnapshot();
        }

        return "<env>\n" . $this->utf8Safe(implode("\n", $lines)) . "\n</env>";
    }

    /**
     * Guarantee the block is valid UTF-8, announcing it when it was not.
     *
     * WHY THIS EXISTS: without it, ONE latin-1 text file in the working tree
     * takes down EVERY step of the session, not the git section. `git status
     * --porcelain` quotes non-ASCII PATHS (core.quotepath), but a diff BODY is
     * emitted as raw bytes, so adding the diff put arbitrary working-tree bytes
     * into the system prompt for the first time. REPRODUCED: a one-file repo
     * whose tracked `notes.txt` holds `caf\xe9 na\xefve` and has been rewritten
     * renders a 648 B block for which `mb_check_encoding()` is false,
     * `json_encode()` returns `false` ("Malformed UTF-8 characters"), and
     * `GuzzleHttp\Utils::jsonEncode()` — which is what `'json' => $params`
     * reaches in {@see \SugarCraft\Crush\Providers\SglangProvider} and
     * {@see \SugarCraft\Crush\Providers\CustomProvider} — THROWS. The same
     * fixture against the three-command version of this class: 331 B, valid
     * UTF-8, encodes fine. So it is a regression this feature introduced and not
     * a property the prompt path always had.
     *
     * WHY HERE AND NOT IN THE PROVIDERS. Two reasons. The narrow one: the
     * providers are another lane's file set. The real one: this is the block's
     * own invariant to keep. `JSON_INVALID_UTF8_SUBSTITUTE` in a provider would
     * fix the encode for that provider only, and this class has FIVE consumers'
     * worth of downstream — the session store already sets that flag, the
     * worker pool sets it, the TUI does not — so repairing it at the source
     * fixes all of them at once and leaves each provider's flags a question
     * about that provider.
     *
     * WHY THE WHOLE BLOCK AND NOT JUST THE DIFF. The diff is the reason but not
     * the only route: a ref name, a `--porcelain` line git chose not to quote,
     * and the CAPTURED CWD ITSELF are all bytes this class does not control, and
     * a latin-1 directory name would break the encode with no diff involved.
     * One pass over ≤25 KiB is cheaper than four.
     *
     * WHY IT IS ANNOUNCED. Silent repair is the same defect as silent
     * truncation, one field over: the model would read `caf?` as a filename that
     * really is spelled that way. The note's count is of SUBSTITUTED SEQUENCES
     * IN THE RENDERED, ALREADY-CAPPED BLOCK — not of invalid bytes in the
     * underlying diff, most of which the cap already discarded unread.
     */
    private function utf8Safe(string $block): string
    {
        if (mb_check_encoding($block, 'UTF-8')) {
            return $block;
        }

        // Global mbstring state, so it is restored even if the convert throws.
        $previous = mb_substitute_character();
        mb_substitute_character(self::UTF8_SUBSTITUTE);

        try {
            $scrubbed = mb_convert_encoding($block, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }

        $replaced = substr_count($scrubbed, '?') - substr_count($block, '?');

        return $scrubbed . "\n[encoding: {$replaced} byte sequence(s) of this block were not valid UTF-8"
            . ' and were replaced with "?". Any path or content shown above may be misspelled at those'
            . ' positions.]';
    }

    /**
     * Checks whether the captured working directory is a git repository.
     *
     * `file_exists`, not `is_dir`, and the difference is not cosmetic: a
     * `git worktree` and a submodule both spell `.git` as a FILE containing a
     * `gitdir:` pointer. MEASURED inside a real `git worktree add` while this
     * method still said `is_dir`: the block reported
     * `Is directory a git repo: No` and emitted NO GIT SECTION AT ALL, so
     * everything this class polls — branch, status, log, both diffs — was
     * silently dead in a checkout git itself considers perfectly ordinary.
     * {@see ancestorRoot()} argues the same point 200 lines away and had already
     * chosen `file_exists`; this was the one place left disagreeing with it.
     *
     * A `.git` that is a file git cannot follow (or a stray unrelated file of
     * that name) now reaches the git commands and comes back
     * `unavailable (git exited N)`, which is the honest answer — the same
     * treatment the `mkdir .git` shape gets, and the reason those fields report
     * an exit code instead of an empty string.
     *
     * Still the cheapest possible check, which is the requirement: it runs on
     * every render alongside the git section it gates.
     */
    private function isGitRepo(): bool
    {
        return file_exists($this->cwd . '/.git');
    }

    /**
     * Reads the current git state of the captured working directory.
     *
     * Called from {@see render()}, so it re-runs on every render — FIVE
     * subprocesses per call (branch, status, log, staged diff, unstaged diff),
     * or THREE when the caller has suppressed the diff via
     * {@see withWriteSinceLastRender()} — and `buildSystemPrompt()` renders
     * once per step of the agentic loop. Live rather than frozen on purpose: a model that has just
     * edited files must not be shown the status those files had at session start.
     * Pinned by
     * `tests/Providers/PromptStabilityTest::testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()`.
     *
     * The section OPENS with {@see GIT_STATE_CAVEAT} rather than with the branch
     * line, because that caption is a claim about every field under it and
     * upstream's opposite claim would be false here — see the constant.
     *
     * Each field is captured separately so a failure in one does not poison the
     * others. What a failure LOOKS LIKE differs by field, and the difference is
     * the point rather than an inconsistency: `branch` still reports empty when
     * GIT says empty, because empty is a real answer for it (detached HEAD);
     * `status`, `log` and both diff sections report `unavailable (git exited N)`,
     * because for them empty and broken are otherwise byte-identical and the
     * first reads to a model as "nothing has changed". See {@see gitField()} and
     * {@see gitDiffSection()}.
     *
     * A DISABLED PROCESS HELPER IS A THIRD OUTCOME, distinct from both: git was
     * never asked, so there is no exit code to report and "empty" would be a
     * lie. `branch` reports `unavailable (shell_exec is disabled on this build)`
     * and the four capped fields report {@see NO_PROCESS_REASON}. Five
     * subprocesses per call is therefore the count WHERE BOTH HELPERS EXIST; on
     * a build with `proc_open` disabled it is one, and with both disabled it is
     * zero and the git section is five unavailability lines instead of an
     * exception.
     */
    private function gitStatusSnapshot(): string
    {
        // `function_exists` rather than a bare call: a function in
        // `disable_functions` is UNDEFINED, so calling it raises an Error that
        // `@` does not suppress. MEASURED with `php -d
        // disable_functions=proc_open`: before these three guards, `render()`
        // threw `Error: Call to undefined function ...proc_open()` and took the
        // whole system-prompt build down, where the three-command version of
        // this class returned a 327 B block on the same host. Reporting the
        // missing helper is the same rule the exit codes below follow — an
        // unavailability the model can see beats a silence it cannot.
        $branch = \function_exists('shell_exec')
            ? trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' branch --show-current 2>/dev/null'))
            : 'unavailable (shell_exec is disabled on this build)';
        $status = $this->gitField(['status', '--porcelain'], self::SUMMARY_MAX_BYTES);
        $log = $this->gitField(['log', '--oneline', '-5'], self::SUMMARY_MAX_BYTES);

        // The caption goes FIRST: it is a claim about every line below it, the
        // branch line included. See GIT_STATE_CAVEAT for why the claim is the
        // opposite of the one upstream ships.
        $section = self::GIT_STATE_CAVEAT . "\n\n"
            . "Current branch: {$branch}\n\nStatus:\n{$status}\n\nRecent commits:\n{$log}";

        // The P3.S2 gate: the two diff sections render only on the step after
        // a write — or when nobody has said anything either way (the default,
        // which is what keeps every pre-existing caller and the golden prompt
        // emitting). Withholding them also withholds their two subprocesses,
        // which is the expensive half of the five: the worst case measured in
        // the class docblock spent 373 of its 399 ms inside `git diff`.
        if ($this->writeSinceLastRender) {
            $section .= "\n\n" . $this->gitDiffSection('Staged changes (git diff --cached, index vs HEAD)', '--cached')
                . "\n\n" . $this->gitDiffSection('Unstaged changes (git diff, working tree vs index)', null);
        }

        return $section;
    }

    /**
     * Run one `git` subcommand under the captured cwd, bounded to $maxBytes.
     *
     * WHY THE FAILURE TEXT IS NOT AN EMPTY STRING. This method replaced two
     * `shell_exec(... '2>/dev/null')` calls whose docblock said "empty strings
     * indicate failure" — which conflates a CLEAN TREE with a git that could not
     * run, and they are byte-identical. `mkdir .git` with no `git init` (the
     * shape {@see \SugarCraft\Crush\Tests\Context\EnvironmentBlockTest}
     * builds) reaches here, git exits 128, and the old code rendered an empty
     * `Status:` section that reads as "nothing has changed". That is the same
     * defect a silently-truncated diff is, one field over.
     *
     * @param list<string> $argv Subcommand and flags, each shell-escaped individually
     */
    private function gitField(array $argv, int $maxBytes): string
    {
        if (!\function_exists('proc_open')) {
            return self::NO_PROCESS_REASON;
        }

        $command = 'git -C ' . escapeshellarg($this->cwd);
        foreach ($argv as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $captured = $this->runCaptured($command, null, $maxBytes);

        if ($captured['exitCode'] !== 0) {
            return "unavailable (git exited {$captured['exitCode']})";
        }

        return $this->truncateOutput(
            $captured['stdout'],
            $maxBytes,
            $captured['stdoutDropped'],
            $captured['stdoutMidLine'],
        );
    }

    /**
     * One labelled, size-capped diff section.
     *
     * WHY BOTH SECTIONS EXIST, SEPARATELY LABELLED. `git diff` and
     * `git diff --cached` answer different questions and neither is "the diff":
     * unstaged is working tree vs index, staged is index vs HEAD. The agent's
     * own {@see \SugarCraft\Crush\Tools\BuiltIn\Edit}/`Write` never stage
     * anything, so UNSTAGED is where its edits land; anything a human staged
     * before launching sits only in STAGED. `git diff HEAD` would show the union
     * in one block and lose exactly that distinction, and a model told only "the
     * diff" then reports work it did not do (or misses work it did). The label
     * names the literal command so the reader can reproduce the section.
     *
     * WHY THE SHORTSTAT LEADS. `--shortstat --patch` emits one summary line
     * ("N files changed, X insertions(+), Y deletions(-)") ahead of the patch.
     * {@see TruncatesOutput} clips from the END, so that line is the one part of
     * the section a cap can never remove — which is what lets the model separate
     * "there are no more changes" from "I was not shown the rest": the scale is
     * always complete even when the body is a sample, and the marker says so
     * again in bytes. A silently-clipped patch would be read as the whole
     * change set.
     *
     * WHY AN EXIT CODE IS NOT AN EMPTY DIFF. `git diff` on a broken or
     * inaccessible repository exits non-zero with empty stdout, which is
     * byte-identical to a clean tree. Rendering "(none)" for that case would
     * state "nothing changed" on evidence that says "nothing was read", so the
     * two outcomes get different text. stdout only, deliberately: stderr carries
     * git's own explanation and would land in the prompt as prose, so the exit
     * code is what is reported instead.
     *
     * @param string      $label    Human label naming the exact command, for the model
     * @param string|null $selector `--cached` for the staged view, null for unstaged
     */
    private function gitDiffSection(string $label, ?string $selector): string
    {
        if (!\function_exists('proc_open')) {
            return $label . ': ' . self::NO_PROCESS_REASON;
        }

        $command = 'git -C ' . escapeshellarg($this->cwd) . ' diff --shortstat --patch'
            . ($selector === null ? '' : ' ' . escapeshellarg($selector));

        // $maxBytes bounds what is RETAINED, not what is read: git is drained to
        // completion (so it is never SIGPIPE'd and its exit code stays
        // meaningful) while memory stays bounded whatever the working tree holds.
        // A `git diff` over an accidentally-unignored vendor tree is hundreds of
        // megabytes, and shell_exec() would materialise all of it before any cap
        // could apply.
        $captured = $this->runCaptured($command, null, self::DIFF_MAX_BYTES);

        if ($captured['exitCode'] !== 0) {
            return $label . ": unavailable (git exited {$captured['exitCode']})";
        }

        if ($captured['stdout'] === '' && $captured['stdoutDropped'] === 0) {
            return $label . ': (none)';
        }

        return $label . ":\n" . $this->truncateOutput(
            $captured['stdout'],
            self::DIFF_MAX_BYTES,
            $captured['stdoutDropped'],
            $captured['stdoutMidLine'],
        );
    }
}
