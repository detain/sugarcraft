<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Lang;

/**
 * The launch-warning shelf: what one launch decided to say, once per message
 * on stderr, and what the transcript being built gets to carry.
 *
 * WHY THIS CLASS EXISTS. {@see Bootstrap} raises these warnings from roughly
 * thirty call sites spread across its launch sequence, and until this split the
 * shelf itself — the bounded list, the overflow set, the once-per-process
 * memory and every row shape — lived between statics on that one class file.
 * The state has exactly one contract (below), and it reads better as a named
 * collaborator than as a paragraph in a twelve-thousand-line composition root.
 * Bootstrap keeps thin delegates with the same public surface:
 * {@see Bootstrap::launchNotices()}, {@see Bootstrap::launchNoticesDropped()}
 * and the transcript seam {@see Bootstrap::warnPermissionConfigInTranscript()},
 * so every caller and every census that reaches the launch notices through
 * Bootstrap keeps reaching them there.
 *
 * WHAT DOES NOT LIVE HERE. The cap VALUE and its setting lookup
 * ({@see Bootstrap::LAUNCH_NOTICE_LIMIT}, {@see Bootstrap::launchNoticeLimit()})
 * and the message catalogue ({@see Bootstrap::LAUNCH_NOTICE_CATALOGUE}) stay on
 * Bootstrap because the bounded-source roster is keyed by BOOTSTRAP METHOD
 * names — the feeders that call the seam are enumerated in
 * {@see Bootstrap::LAUNCH_NOTICE_BOUNDED_SOURCES} and that table is the census
 * this file's cap exists to defend.
 *
 * THE ONE CONTRACT, stated here because this file owns all three stores:
 * stderr gets every warning WHOLE and ONCE (the complete record, token-free);
 * the transcript gets CLIPPED rows, de-duplicated on value, capped at the
 * limit, and a synthesised "and N more" tail when the cap was reached — never
 * a silently truncated list. The transcript copy exists because the TUI frame
 * shows what `chat()` handed it, and a user who lost stderr to the alt screen
 * must still be told, inside the app, what the launch complained about.
 */
final class LaunchNotices
{
    /**
     * The ceiling on how many characters one clipped transcript row may cost.
     *
     * A CAP ON A LIST THAT NOTHING ELSE BOUNDS. {@see $launchNotices} feeds
     * the user's config, a glob from a project's `disabledTools`, an exception
     * message from a preset registry. MEASURED against the longest legitimate
     * warning the launcher could build when the bound was set — the untrusted
     * hook-file notice at 283 characters with a realistic pair of absolute
     * paths — and raised past the tool-removal report that later outgrew that,
     * which is the entry the both-ends clip below exists for while every other
     * honest warning still rides under the bound uncut. The bound itself is
     * what makes a hostile message safe.
     *
     * THE CLIP KEEPS BOTH ENDS. A head-only cut let the project-tier
     * tool-removal report grow past the bound silently: a long disabled list
     * pushed the survivor clause — the half a security warning exists to
     * say — off the end of the row. The clip preserves the opening of the
     * message AND its closing clause, cutting the middle of whichever list is
     * long.
     *
     * THE STDERR COPY IS NOT CLIPPED. That channel is the complete record and
     * costs no tokens — see {@see Bootstrap::warnPermissionConfigInTranscript()},
     * which is why the clipped row says where the full text is.
     */
    public const LAUNCH_NOTICE_MAX_CHARS = 400;

    /**
     * How many trailing characters a clipped transcript row keeps.
     *
     * MEASURED against the closing clause of the longest legitimate message:
     * the tool-removal report's survivor tail (` — leaving: Bash.`) is 17
     * characters with one survivor and stays whole up to roughly six average
     * tool names; 96 carries that with room, and a survivor list longer than
     * the tail budget loses only its own middle — same trade the head makes for
     * the disabled list, with stderr still the complete record.
     */
    public const LAUNCH_NOTICE_TAIL_CHARS = 96;

    /**
     * Appended to a clipped notice, and counted against
     * {@see LAUNCH_NOTICE_MAX_CHARS} so the row never exceeds it.
     *
     * "ON STDERR" IS TRUE HERE, unlike the runtime sink's suffix (which names
     * the TUI error log): a launch row's full text is written by
     * {@see Bootstrap::warnPermissionConfig()} with `fwrite(STDERR, …)`, not
     * `error_log()`, so the error-log redirect never touches it, and it is
     * written at construction time, before `Program` takes the terminal — it
     * sits on the main screen under the alt screen and is there after the
     * session exits.
     */
    public const LAUNCH_NOTICE_CLIP_SUFFIX = '… (clipped; full text on stderr)';

    /**
     * The tail row {@see rows()} synthesises when this launch raised more
     * warnings than {@see Bootstrap::LAUNCH_NOTICE_LIMIT} could seat.
     *
     * PROMOTED BECAUSE A SECOND PARTY READS IT, and this one reproduces the
     * sentence WHOLE: a routing test `assertSame()`s the rendered line rather
     * than a fragment of it. It is also the odd one out of its own family: the
     * cap and the clip suffix are already named, and the sentence that tells a
     * reader the cap was hit was the only part of the cap contract living as an
     * inline literal.
     *
     * `%d` the number dropped, `%s` the plural marker.
     */
    public const LAUNCH_NOTICE_OVERFLOW_FORMAT =
        '…and %d more launch warning%s this transcript could not fit; the full list is on stderr';

    /**
     * The aggregate header {@see grantRows()} packs narrowed agent-tool-grant
     * warnings under.
     *
     * PROMOTED BECAUSE A TEST RENDERS THE WHOLE LINE: the drain test
     * `assertSame()`s the composed row rather than pinning a fragment, so the
     * wording is a contract with the test and belongs in a named constant.
     *
     * `%d` the grant count, `%s` the plural marker, `%s` the was/were verb.
     */
    public const NARROWED_GRANT_NOTICE_FORMAT =
        '%d agent tool grant%s %s narrowed by this session\'s allowedTools/disabledTools';

    /**
     * The tail row {@see grantRows()} appends when the header's pair pack did
     * not fit every grant. Mirrors {@see LAUNCH_NOTICE_OVERFLOW_FORMAT}'s
     * contract — count the rest, point at stderr, spend one row — for the same
     * reason: a silently truncated aggregate is the defect this seam exists to
     * end.
     *
     * `%d` the grants left over, `%s` the plural marker.
     */
    public const NARROWED_GRANT_OVERFLOW_FORMAT =
        '…and %d more narrowed grant%s this transcript could not fit; the full list is on stderr';

    /**
     * The compact `agent "x" declares "y" in tools` prefix of one
     * {@see \SugarCraft\Code\Agents\AgentManager::narrowedGrantWarnings()}
     * sentence — the half worth spending transcript characters on.
     *
     * The regex is intentionally the whole shape of the sentence's first
     * clause, so drift in the collector's wording fails CLOSED into
     * {@see pair()}'s whole-sentence fallback rather than silently mis-packing
     * a row. PUBLIC because its alias on {@see Bootstrap} is consumed by a
     * constant expression there, and constant expressions resolve per the
     * ACCESSING class.
     */
    public const NARROWED_GRANT_PAIR_PATTERN =
        '/^(agent "[^"]*" declares "[^"]*" in (?:tools|disallowedTools))(?:,|$)/';

    /**
     * Launch warnings this LAUNCH wants shown INSIDE the TUI, in the order they
     * were raised — {@see \SugarCraft\Code\Chat::withLaunchNotices()}'s input.
     *
     * SCOPED TO THE LAUNCH, and deliberately NOT keyed the way
     * {@see $reportedPermissionConfigWarnings} is. That map answers "has this
     * process already printed this sentence", which stays true across a second
     * launch; this list answers "what does the transcript being built right
     * now need to carry", which does not. {@see Bootstrap::chat()} resets both
     * from here, in the same breath, through {@see reset()}.
     *
     * De-duplicated on VALUE because the launch raises some of these more than
     * once — the tool set is built twice per launch, so the tool-removal
     * report runs twice with an identical message and the transcript must not
     * say it twice.
     *
     * @var list<string>
     */
    private static array $launchNotices = [];

    /**
     * The notices this launch raised past {@see Bootstrap::LAUNCH_NOTICE_LIMIT},
     * keyed by message.
     *
     * Kept rather than merely refused, so {@see rows()} can say "and N more"
     * instead of ending the list where the cap happened to fall. Reset in
     * {@see Bootstrap::chat()} beside the list it belongs to — what THIS launch
     * could not fit is a fact about the launch, exactly as the list is.
     *
     * A SET, NOT A COUNTER, and that is not tidiness. MEASURED: {@see Bootstrap::chat()}
     * reaches {@see Bootstrap::permissionRules()} twice (through
     * {@see Bootstrap::permissionGate()} and through
     * {@see Bootstrap::agentManager()}), so every message it raises is raised
     * twice. {@see $launchNotices} de-duplicates the ones that FIT, by value;
     * an integer counter had no such memory and charged the overflow twice —
     * a 30-rule config reported "and 12 more" for 6 it could not fit. Keying on
     * the message gives the dropped half the same de-duplication the kept half
     * already had.
     *
     * ITS OWN BOUND IS THE ONE {@see $reportedPermissionConfigWarnings} ALREADY
     * HAS — one entry per DISTINCT message — and it is worth naming because
     * {@see Bootstrap::chat()} is the only thing that resets it. A `-p` run never calls
     * chat(), so a host that loops {@see \SugarCraft\Code\Cli\NonInteractive::run()}
     * in one process accumulates keys here exactly as it already accumulates
     * them in that map. Bounded by the number of distinct malformed config
     * entries, not by the number of runs.
     *
     * @var array<string, true>
     */
    private static array $launchNoticesDropped = [];

    /**
     * Permission-config warnings this process has already printed, keyed by
     * message — see {@see Bootstrap::warnPermissionConfigOnce()}.
     *
     * @var array<string, true>
     */
    private static array $reportedPermissionConfigWarnings = [];

    /**
     * Empty the launch-scoped shelves. Called by {@see Bootstrap::chat()} at
     * the top of each launch, before any warning can be raised — what the
     * transcript being built carries is a fact about THIS launch.
     */
    public static function reset(): void
    {
        self::$launchNotices = [];
        self::$launchNoticesDropped = [];
    }

    /**
     * Whether stderr already carried this exact sentence in this process.
     */
    public static function reported(string $message): bool
    {
        return isset(self::$reportedPermissionConfigWarnings[$message]);
    }

    /**
     * Claim the sentence for the process so {@see reported()} answers true for
     * it from now on.
     */
    public static function markReported(string $message): void
    {
        self::$reportedPermissionConfigWarnings[$message] = true;
    }

    /**
     * Put one warning on the transcript shelf: clipped to the row bound if it
     * needs clipping, de-duplicated, and capped — past the cap the message
     * lands in the overflow set instead, to be counted by {@see rows()}.
     *
     * The stderr half of the contract is NOT here: the caller keeps saying it
     * through {@see Bootstrap::warnPermissionConfigOnce()}, in the original
     * record-then-print order.
     */
    public static function record(string $message, int $limit): void
    {
        // mb_*, not substr(): these messages interpolate paths and globs, and
        // cutting one mid-codepoint would hand Chat a row that is not valid
        // UTF-8 — which json_encode() (the session store, the `-p` document)
        // refuses outright rather than degrading.
        //
        // BOTH ENDS SURVIVE, joined by an explicit ellipsis: the clipped half
        // is always a list's middle, never a sentence's point. A head-only cut
        // let the tool-removal report push its survivor clause past the bound,
        // which is exactly the information the warning is issued to carry.
        $suffix = self::translated(self::LAUNCH_NOTICE_CLIP_SUFFIX);
        $notice = $message;
        if (mb_strlen($message, 'UTF-8') > self::LAUNCH_NOTICE_MAX_CHARS) {
            $headChars = self::LAUNCH_NOTICE_MAX_CHARS
                - mb_strlen($suffix, 'UTF-8')
                - 3 // the ' … ' joiner between the kept ends
                - self::LAUNCH_NOTICE_TAIL_CHARS;
            if ($headChars < 1) {
                // Config bug, unreachable today (400 - 32 - 3 - 96 = 269, the
                // suffix counting its leading ellipsis as one UTF-8 char): the
                // bounds no longer compose to a row that fits. Loud, because a
                // silently negative mb_substr length would clip to the empty
                // head and advertise text the row does not contain.
                throw new \LogicException('LAUNCH_NOTICE_MAX_CHARS does not leave room for the clip suffix, joiner and tail');
            }
            $notice = mb_substr($message, 0, $headChars, 'UTF-8')
                . ' … '
                . mb_substr($message, -self::LAUNCH_NOTICE_TAIL_CHARS, null, 'UTF-8')
                . $suffix;
        }

        // The de-dup check comes FIRST so a message already recorded is never
        // counted as an overflow — a repeat costs the transcript nothing, and
        // charging it to the "and N more" tail would overstate what was lost.
        if (!\in_array($notice, self::$launchNotices, true)) {
            if (\count(self::$launchNotices) < $limit) {
                self::$launchNotices[] = $notice;
            } else {
                self::$launchNoticesDropped[$notice] = true;
            }
        }
    }

    /**
     * The transcript rows for this launch, in raise order, with the overflow
     * tail appended when there is one.
     *
     * Public through {@see Bootstrap::launchNotices()} so a test can read what
     * a launch decided to say without capturing stderr, and so an embedder
     * building its own Chat can route them into whatever surface it has.
     *
     * THE OVERFLOW ROW IS SYNTHESISED HERE rather than pushed onto the list,
     * and that is what keeps {@see Bootstrap::LAUNCH_NOTICE_LIMIT} an honest
     * cap: an overflow marker stored IN the list would occupy a slot, and a
     * second overflow after it would have to rewrite an entry rather than
     * append. Reading it out at the accessor means every caller of this method
     * — {@see Bootstrap::chat()}, a doctor report, an embedder — gets the same
     * complete answer, and none of them can see a truncated list without being
     * told it was truncated.
     *
     * @return list<string>
     */
    public static function rows(): array
    {
        if (self::$launchNoticesDropped === []) {
            return self::$launchNotices;
        }

        $dropped = \count(self::$launchNoticesDropped);

        return [
            ...self::$launchNotices,
            sprintf(
                self::translated(self::LAUNCH_NOTICE_OVERFLOW_FORMAT),
                $dropped,
                $dropped === 1 ? '' : 's',
            ),
        ];
    }

    /**
     * The messages {@see record()} dropped once the transcript shelf filled
     * its cap — the CLIPPED sentences behind the synthesised "...and N more"
     * row {@see rows()} appends.
     *
     * The door for `/notices`, the surface whose entire promise is that
     * truncation ends somewhere. Before this class the overflow count was all
     * a reader could recover from process state — the dropped sentences
     * themselves were only ever written to stderr, a scrollback no command can
     * re-read. The store has always existed, keyed by the clipped message.
     *
     * Deliberately shaped like {@see rows()} — read-only, emits nothing,
     * allocates a plain list — so the pair cannot drift in tone.
     *
     * @return list<string>
     */
    public static function droppedMessages(): array
    {
        // The store is a dedup set keyed by the CLIPPED message; the keys ARE
        // the sentences, and the values only say "seen".
        return array_keys(self::$launchNoticesDropped);
    }

    /**
     * The pair regex's group, or the whole sentence when the collector's
     * shape drifted — dropping a warning from the aggregate is never an
     * option; paying for it in characters is.
     */
    public static function pair(string $warning): string
    {
        if (preg_match(self::NARROWED_GRANT_PAIR_PATTERN, $warning, $matches) === 1) {
            return $matches[1];
        }

        return $warning;
    }

    /**
     * The transcript rows for a narrowed-grant flood: ONE header row — the
     * count, then as many compact grant pairs as fit inside
     * {@see LAUNCH_NOTICE_MAX_CHARS} — plus ONE tail row counting what the
     * pack could not fit. At most two rows, whatever the fan-out.
     *
     * The pack is greedy in collector order: the pairs share the whole
     * message, so any order is as honest as another, and collector order
     * keeps the visible ones aligned with the stderr record above them. The
     * row never reaches the clip — the budget is checked while packing, not
     * after — which is the property the drain test pins.
     *
     * @param list<string> $warnings
     *
     * @return list<string>
     */
    public static function grantRows(array $warnings): array
    {
        $count = \count($warnings);

        if ($count === 0) {
            return [];
        }

        $header = sprintf(
            self::translated(self::NARROWED_GRANT_NOTICE_FORMAT),
            $count,
            $count === 1 ? '' : 's',
            $count === 1 ? 'was' : 'were',
        );

        $row = $header;
        $shown = 0;
        foreach ($warnings as $warning) {
            $pair = self::pair($warning);
            $candidate = $row . ($shown === 0 ? ': ' : '; ') . $pair;
            if (mb_strlen($candidate, 'UTF-8') > self::LAUNCH_NOTICE_MAX_CHARS) {
                break;
            }
            $row = $candidate;
            ++$shown;
        }

        $rows = [$row];

        if ($shown < $count) {
            $remaining = $count - $shown;
            $rows[] = sprintf(
                self::translated(self::NARROWED_GRANT_OVERFLOW_FORMAT),
                $remaining,
                $remaining === 1 ? '' : 's',
            );
        }

        return $rows;
    }

    /**
     * $text in the active locale: its catalogue entry when the launch-notice
     * catalogue on {@see Bootstrap} knows this exact string, else $text
     * unchanged. The catalogue stays on Bootstrap — it is the one English-text
     * to lang-key map {@see \SugarCraft\Code\Tests\Cli\CliLangCatalogueTest}
     * pins bidirectionally against lang/en.php, and this shelf renders only a
     * four-line slice of it.
     */
    private static function translated(string $text): string
    {
        $key = Bootstrap::LAUNCH_NOTICE_CATALOGUE[$text] ?? null;

        return $key === null ? $text : Lang::t($key);
    }
}
