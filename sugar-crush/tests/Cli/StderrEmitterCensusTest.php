<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Support\FlattensSourceProseTrait;

/**
 * Every place in `src/` and `bin/` that can put a line on the user's stderr,
 * counted by channel, so a new one cannot arrive unnoticed.
 *
 * THAT FIRST SENTENCE IS A CLAIM AND NOT A SLOGAN, so here is what it rests
 * on. Channels 1 and 2 are complements over the `STDERR` constant, which makes
 * the pair exhaustive over every use of it — `fprintf(STDERR, …)` and
 * `fputs(STDERR, …)` land in channel 2 without anyone widening anything.
 * Channel 3 covers `error_log()`, channel 5 the funnel whose prefix is applied
 * at the emitter, and
 * {@see testNoStderrChannelOutsideTheScannedOnesHasAppeared()} asserts that the
 * remaining ways to acquire a handle on fd 2 — a `php://` stream,
 * `error_log()`'s destination form, and an import that renames one of the
 * names those channels key on ({@see ALIASABLE_STDERR_NAMES}) — are still
 * unused. What is NOT covered: a
 * child process this application spawns with fd 2 inherited, which is a
 * property of the spawn and not of a call site here. The sentence held false
 * once already, for channel 5's whole family; see below.
 *
 * WHY THIS FILE EXISTS (E120). A full suite run prints 62 lines matching
 * `sugarcrush:` — reproduced independently at `06126017` by the supervisor and
 * again here — and nothing anywhere would have noticed a 63rd. That is not a
 * cosmetic complaint: this application takes the alternate screen roughly half
 * a second after launch (MEASURED elsewhere in this class family at 0.47s on a
 * real pty), and an unaccounted stderr write lands either on a frame it
 * corrupts or in a scrollback the user never sees. Which of those it is depends
 * on the call site, which is the argument for knowing what the call sites ARE.
 *
 * THIS DOES NOT ASSERT THE 62. Running the suite from inside the suite is not
 * available, the figure moves with the tests rather than with the application,
 * and it is a property of the HARNESS — the 62 are child-process launches whose
 * stderr the PHPUnit process inherits, and most of them are lines a test
 * deliberately provoked and then asserted on. What is pinned here is the
 * SOURCE: how many sites, in which files, on which channel. A 63rd runtime line
 * that comes from a new emitter reds here; a 63rd that comes from an existing
 * emitter being exercised once more by a new test does not, and should not.
 *
 * THE CHANNELS, AND THE LAST TWO ARE THE POINT OF THE EXERCISE. The census this
 * project already had —
 * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushAutoloadGuardTest}'s
 * doc-block, "the real census of raw `fwrite(STDERR, …)` call sites across
 * `src/` and `bin/` is ELEVEN" — is CORRECT, and this file asserts that it
 * stays correct ({@see testTheInheritedCensusStillAgreesWithTheScan()}).
 * It is also answering a narrower question than its readers have been taking
 * it to answer, and the gap is a matter of ALPHABET rather than of arithmetic:
 *
 *  1. `fwrite(STDERR, …)` — twelve sites. The channel that census describes.
 *  2. `STDERR` captured into a variable or property and written through later —
 *     ONE site, {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt}, whose
 *     `$err` defaults to `\STDERR` and which writes FOUR distinct
 *     `sugarcrush: ` shapes through it. A grep for `fwrite(STDERR` cannot see
 *     this file at all.
 *  3. `error_log(…)` — TWENTY-ONE sites across eleven files. MEASURED on this
 *     box, PHP 8.3.6, `ini_get('error_log')` is `''` and `php -r
 *     'error_log("x");' 2>file` puts `x` in the file: with no `error_log`
 *     destination configured, this IS stderr. Three of them appear in the
 *     baseline capture of a full suite run. It is by a wide margin the largest
 *     stderr channel in the application and it was not in the census at all.
 *     THIS COUNTS CALL SITES, NOT MESSAGES, and the two moved apart in round
 *     46: funnelling `CommandLoader`'s five `error_log()` calls through one
 *     private `report()` took its roster entry from 5 to 1 and this figure from
 *     thirty-eight to thirty-four while removing no message whatever — that
 *     loader still has five distinct refusals to report and `docs/ENVIRONMENT.md`
 *     documents all five behind one flag. A fall here is a fall in EMITTERS;
 *     read it as a fall in diagnostics and you will go hunting for four that
 *     were never deleted.
 *     IT FELL TWICE MORE, BY EIGHT AND THEN BY SIX, AND AGAIN NO MESSAGE WAS
 *     DELETED. Round 48 (E192) routed `WorktreeManager`'s four and two of
 *     `SglangProvider`'s three onto the same seam, which is why this channel
 *     no longer names `src/Agents/WorktreeManager.php` at all. Read the
 *     absence of a whole file here as six emitters moving, not as six
 *     diagnostics disappearing: every one of them still reaches stderr,
 *     because `RuntimeNoticeSink::warn()` calls `error_log()` for them, and
 *     channel 6 is where they are counted now. Round 47's move, which said the
 *     same thing one round earlier:
 *     Eight of the two tool-call parsers' diagnostics were routed through
 *     {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()}, which
 *     calls `error_log()` itself and then ALSO puts the row on the mid-session
 *     transcript seam — so eight call sites became one, plus a new file on this
 *     roster, and every one of the eight lines still reaches stderr. That is
 *     the shape channel 5 was invented for, one round later, which is why
 *     channel 6 below exists: without it, eight user-visible stderr writes
 *     would have become invisible to every scanner in this file.
 *     A NAIVE `grep -c 'error_log('` OVER `src/` DISAGREES WITH THAT NUMBER by
 *     more than twenty, and the census is the one that is right: the surplus is
 *     entirely this application's own doc-blocks discussing `error_log()`,
 *     which it does more often than it calls it.
 *     {@see testTheNaiveGrepCountReconcilesWithTheTokenScan()} asserts the
 *     identity per file rather than restating either figure.
 *  4. Literal message SHAPES that themselves carry the `sugarcrush: ` prefix.
 *     A LITERAL-BORNE PREFIX AND NOT "THE ROSTER A USER READS", which is what
 *     this line said when it was written, and the difference is the whole of
 *     the paragraph below.
 *  5. Call sites of the `warnPermissionConfig*` family — the funnel that
 *     applies the prefix at the EMITTER, via
 *     {@see \SugarCraft\Crush\Cli\Bootstrap::STDERR_LINE_FORMAT}, to a
 *     message that does not carry it.
 *  6. Call sites of
 *     {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()} — FOURTEEN
 *     of them, in FOUR files. THE SECOND EMITTER-SIDE FUNNEL, and the same
 *     alphabet trap as channel 5 one round later: `warn()` writes
 *     `error_log()` from inside the sink, so channel 3 credits the whole family
 *     with the ONE site in `src/Diagnostics/RuntimeNoticeSink.php` and cannot
 *     see the eight places that decided to emit. Not a subset of another
 *     Not a subset of another channel and not double-counted by one, and the
 *     TWO HALVES OF THAT ARE NOT ESTABLISHED THE SAME WAY — this line said
 *     both were asserted, and only one is. WHAT IT SAID: "these sites contain
 *     no `error_log(` token and no `sugarcrush:` literal, which
 *     {@see testTheTwoEmitterFunnelsDoNotCountTheSameWrite()} asserts rather
 *     than assumes." WHAT IS TRUE NOW, and was already: that test asserts the
 *     `sugarcrush:` half only, and it asserts it per FILE. The `error_log(`
 *     half is asserted nowhere and cannot be asserted as phrased — both
 *     channel-6 files carry `error_log()` call sites of their own, in the
 *     numbers {@see ERROR_LOG_SITES} credits them with, which are pinned by
 *     {@see testTheErrorLogRosterIsUnchanged()} and deliberately not restated
 *     here. What is true
 *     is the per-SITE claim, and it is true BY CONSTRUCTION rather than by
 *     measurement: a site here is the token sequence
 *     `RuntimeNoticeSink` `::` `warn` `(`, which contains no `error_log`
 *     token, so one write cannot be counted on both channels. WHY THE
 *     SENTENCE STILL EARNS ITS PLACE: the disjointness claim is the whole
 *     reason channel 6 is a channel and not a re-count, and the half that
 *     CAN drift — a call site growing a `sugarcrush: ` literal — is the half
 *     the test guards.
 *     WHAT ITS ALPHABET CANNOT EXPRESS, stated here rather than discovered
 *     later. This named ONE blind shape, an aliased import; MEASURED on PHP
 *     8.3.6 by running {@see scan()} over a fixture per shape, there are at
 *     least FOUR, and every one of them scans as zero:
 *       - an aliased import — `use … as X; X::warn()`;
 *       - `self::warn()` and `static::warn()`, which is how the sink itself
 *         would spell a call to its own funnel;
 *       - a variable class name — `$c = RuntimeNoticeSink::class; $c::warn()`;
 *       - `call_user_func([RuntimeNoticeSink::class, 'warn'], …)`.
 *     There is none of any shape today. The scanner reads the class token
 *     before `::` and accepts the bare, qualified and fully-qualified
 *     spellings, so all four make a site INVISIBLE rather than mis-attributed
 *     — which is the failure mode rule 14 warns about.
 *     THREE OF THE FOUR ARE NOW BOUNDED RATHER THAN MERELY NAMED (E195), and
 *     the paragraph above used to stop at naming them. `self::`, `static::`,
 *     a variable class name and an aliased import are all visible to
 *     {@see methodCallSites()}, which is keyed on the CALL rather than on the
 *     receiver, and
 *     {@see testEveryWarnCallInSrcIsEitherASeamSiteOrOnTheNonSeamRoster()}
 *     asserts across all of `src/` that the difference between what that
 *     scanner counts and what channel 6 counts is exactly
 *     {@see NON_SEAM_WARN_SITES}. A seam write in any of those three spellings
 *     therefore reds instead of vanishing.
 *     THE FOURTH — `call_user_func([RuntimeNoticeSink::class, 'warn'], …)` —
 *     reaches the name as a STRING and no scanner keyed on a call site can see
 *     it. There is no such site, and E195's own Step judges the instrument not
 *     worth building until there is; that judgement is recorded on
 *     {@see NON_SEAM_WARN_SITES} rather than left as a silence.
 *     The narrower question of whether the sink calls its own `warn()` is
 *     still asked separately by
 *     {@see testTheTwoEmitterFunnelsDoNotCountTheSameWrite()}.
 *
 * WHY CHANNEL 5 EXISTS, AND IT IS THIS FILE'S OWN ALPHABET TRAP SPRUNG ON
 * ITSELF. WHAT THE LINE ABOVE SAID: channel 4 is "the roster a user actually
 * reads". WHAT IS TRUE NOW, measured: channel 4 counts a literal that CONTAINS
 * `sugarcrush:`, and the largest producer of user-visible stderr in this
 * application does not write one. `Bootstrap`'s warnings are handed to
 * {@see \SugarCraft\Crush\Cli\Bootstrap::STDERR_LINE_FORMAT}, which adds the
 * prefix on the way out, so the message literals are invisible to a scan for
 * it — TWENTY-TWO call sites in `src/Cli/Bootstrap.php`, each producing a
 * distinct `sugarcrush: ` line, against a channel-4 credit of four for that
 * file. Off by roughly four times, in the blind direction.
 *
 * IT WAS NOT A THEORY. Round 45's review added one
 * `self::warnPermissionConfigOnce('a brand new user visible warning nobody
 * censused');` to `Bootstrap::reportProjectTierToolRemovals()` and ran all four
 * rosters: `OK (55 tests, 221 assertions)`, rc 0 — and PHPUnit's own output
 * carried the new line. A user-visible stderr write arrived unnoticed in the
 * same process as the census built to notice it, which is the defect this file
 * names in its first sentence.
 *
 * SO THE HEADLINE IS NOT "ELEVEN WAS WRONG", it is that a census reports what
 * its alphabet can express and this one's alphabet was written to match the
 * sites already known. Round 43's headline finding came from widening a fuzz's
 * pattern alphabet; round 45's first attempt at this file repeated the mistake
 * one channel over, and channel 5 is the repair. WHEN A CENSUS HERE REPORTS A
 * NUMBER, ASK WHAT ITS ALPHABET CANNOT EXPRESS BEFORE BELIEVING IT — the
 * shapes this one still cannot express are named by
 * {@see testNoStderrChannelOutsideTheScannedOnesHasAppeared()}, which is
 * the closest thing here to a claim of exhaustiveness.
 *
 * TRIAGE — WHY NOTHING IS SILENCED HERE, and silencing was the tempting
 * default. The baseline capture yields 36 distinct shapes under the
 * normalisation this round's report states — collapse `/tmp` paths and runs of
 * digits — and the round's brief reports 32 under a normalisation it does not
 * state. The two are NOT reconciled, and neither is quoted as a finding: a
 * shape count is an artefact of its normalisation, which is why the only figure
 * treated as a measurement here is the raw 62. Of those shapes, the large
 * majority are launch warnings a test PROVOKED and then ASSERTED on:
 * {@see BootstrapLaunchNoticeRoutingTest} reads the provider-fallback and
 * retention lines off stderr by design, {@see BootstrapToolAndPermissionSettingsTest}
 * reads the `disabledTools` line, {@see BootstrapHookFileTest} the untrusted
 * `hooks.yaml` line, {@see \SugarCraft\Crush\Tests\Cli\NonInteractiveTest} the
 * one-shot refusals. Those lines are the assertion. Muting them at the source
 * would delete the guard; muting them in the harness — teaching each child
 * spawn to keep stderr on its pipe instead of inheriting fd 2 — is a real and
 * separate improvement, in test files this lane does not own, and it is
 * recorded as a deferred finding rather than half-done here.
 *
 * E95 IS NOT REOPENED. The one MCP line is accepted permanently and round 44
 * rewrote its doc-block to argue from 62 rather than from 1. This file makes
 * the 62 visible; it does not relitigate the one.
 */
final class StderrEmitterCensusTest extends TestCase
{
    /**
     * E196/E224. The trait's doc-block carries the union of what this file's
     * copy and the sibling census's copy each said; nothing was picked between
     * them. This consumer's own known-positive control for the flattener is the
     * first assertion in
     * {@see testEveryCardinalityThisFileStatesInProseHasItsGenerator()}, and it
     * stays here rather than moving into the trait for the reason that test's
     * doc-block gives: sharing the code is not sharing the control.
     */
    use FlattensSourceProseTrait;

    /**
     * Channel 1: a literal `fwrite(STDERR, …)`.
     *
     * @var array<string, int>
     */
    private const DIRECT_SITES = [
        'bin/sugarcrush' => 1,
        'src/Cli/Bootstrap.php' => 2,
        'src/Cli/NonInteractive.php' => 7,
        'src/Cli/Subcommands.php' => 2,
    ];

    /**
     * Channel 2: `STDERR` reached anywhere OTHER than as `fwrite()`'s first
     * argument — captured into a property, defaulted into a parameter, passed
     * on. The complement of channel 1 by construction, which is what makes the
     * pair exhaustive over the `STDERR` constant.
     *
     * @var array<string, int>
     */
    private const INDIRECT_SITES = [
        'src/Cli/HeadlessPermissionPrompt.php' => 1,
    ];

    /**
     * Channel 3: `error_log()`, which with no `error_log` ini destination is
     * stderr.
     *
     * THE COUNTS ARE PINNED PER FILE AND THAT IS DELIBERATE FRICTION. Adding a
     * debug `error_log()` to a TUI application is a change worth a moment's
     * thought — the alternate screen is up, and a line written to fd 2 lands on
     * a frame the renderer believes it owns. This test is that moment. Bumping
     * the number is a perfectly good response; not noticing is not.
     *
     * @var array<string, int>
     */
    private const ERROR_LOG_SITES = [
        'src/Agents/AgentWorkerPool.php' => 1,
        'src/Agents/ForeignAgentPresetRegistry.php' => 2,
        // WorktreeManager HAD FOUR AND HAS NONE, which is the largest single
        // move this roster has recorded, and it is the reason to read a fall
        // here as a fall in EMITTERS rather than in diagnostics: all four of
        // its messages still reach stderr, through
        // RuntimeNoticeSink::warn()'s own error_log(), and all four now reach
        // the transcript too. The file is absent rather than zero because
        // census() omits files with no sites; testEveryFileTheRostersNameExists()
        // is what keeps that from hiding a deletion.
        'src/Chat.php' => 1,
        'src/Cli/Bootstrap.php' => 1,
        'src/Commands/CommandLoader.php' => 1,
        'src/Diagnostics/RuntimeNoticeSink.php' => 1,
        'src/Memory/ForeignMemoryImporter.php' => 1,
        // 3 until round 48 routed the two argument-decode refusals onto the
        // seam (E192). The one left is flagTruncationRiskInLatestToolResults(),
        // which PREDICTS a risk rather than reporting a failure — the routing
        // rule's answer, and that method's doc-block states it.
        'src/Providers/SglangProvider.php' => 1,
        // 11 and 7 until round 47 routed eight of the eighteen onto the
        // transcript seam through RuntimeNoticeSink::warn(), which error_log()s
        // for them. Channel 6 is where those eight are counted now; not one
        // message was deleted.
        'src/Providers/ToolCallParser/DsmlToolCallParser.php' => 7,
        'src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php' => 3,
        'src/Skills/SkillLoader.php' => 2,
    ];

    /**
     * Channel 4: string literals carrying the user-visible `sugarcrush: `
     * prefix.
     *
     * COUNTS AND NOT TEXTS. Pinning the message texts would red on a typo fix,
     * which teaches people to update the fixture without reading it — the exact
     * failure that let a count go stale for three rounds elsewhere in this
     * family. A count reds on a message being ADDED or REMOVED, which is the
     * event worth a decision.
     *
     * @var array<string, int>
     */
    private const MESSAGE_SHAPES = [
        'bin/sugarcrush' => 4,
        'src/Cli/ArgvParser.php' => 14,
        'src/Cli/Bootstrap.php' => 4,
        'src/Cli/HeadlessPermissionPrompt.php' => 4,
        'src/Cli/NonInteractive.php' => 7,
        'src/Cli/Subcommands.php' => 11,
    ];

    /**
     * Channel 5: call sites of the `warnPermissionConfig*` family.
     *
     * THE ONE CHANNEL WHOSE MESSAGES CHANNEL 4 CANNOT SEE, and the reason it
     * exists — see this class's doc-block. The prefix these lines carry is
     * applied by {@see \SugarCraft\Crush\Cli\Bootstrap::STDERR_LINE_FORMAT}
     * at the emitter, so the message literal at the call site contains no
     * `sugarcrush:` for channel 4 to count.
     *
     * COUNTED AS CALL SITES AND NOT AS MESSAGES, which is a real difference:
     * one call site inside a loop is one row here and any number of lines on
     * the terminal. The event this roster exists to notice is a new PLACE that
     * warns, because that is the thing that gets a routing decision — stderr
     * alone, the transcript seam, or nowhere. How many times it then fires is a
     * property of the run.
     *
     * @var array<string, int>
     */
    private const PREFIXED_WRITER_SITES = [
        'src/Cli/Bootstrap.php' => 22,
    ];

    /**
     * Channel 6: call sites of `RuntimeNoticeSink::warn()`.
     *
     * THE SECOND EMITTER-SIDE FUNNEL — see this class's doc-block. Channel 3
     * sees one `error_log()` inside the sink; these are the places that decided
     * a user should hear about something.
     *
     * WHAT A MOVE HERE MEANS, AND IT IS NOT WHAT A MOVE IN CHANNEL 3 MEANS.
     * Every site here writes stderr AND appends a `Role::System` row to the
     * transcript, which is sent to the model on every subsequent turn. A new
     * row is therefore a token cost on every later request, not just a line on
     * a terminal. The question to answer before bumping this is the routing
     * rule the two parsers' class doc-blocks state: did the parser fail to
     * produce the call the model asked for? If it recovered, the notice belongs
     * on `error_log()` and in channel 3.
     *
     * @var array<string, int>
     */
    private const RUNTIME_NOTICE_SITES = [
        // E192, round 48: all four of this class's diagnostics. Its own
        // doc-block records the per-site decision, including the one that
        // looks like a recovery and is not — a failed `git worktree remove`
        // leaves the path registered and `prunable`, so the NEXT
        // createWorktree() for that agent id is refused.
        'src/Agents/WorktreeManager.php' => 4,
        // E192, round 48: the two argument-decode refusals. The third site in
        // that file stayed on channel 3 — see its entry there.
        'src/Providers/SglangProvider.php' => 2,
        'src/Providers/ToolCallParser/DsmlToolCallParser.php' => 4,
        'src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php' => 4,
    ];

    /**
     * The names channels 1, 2 and 3 key on, which an alias can rename out from
     * under them.
     *
     * MEASURED, PHP 8.3.6 — and the measurement is that both of these are real,
     * working PHP rather than a lexer curiosity: `use const STDERR as E;
     * fwrite(E, "x")` runs and writes to fd 2, and `use function fwrite as w;
     * w(STDERR, "x")` runs and does the same. Under the first, channel 1 scores
     * 0 (its scanner needs the token `STDERR` as `fwrite`'s first argument) and
     * channel 2 scores 1 — for the `use` LINE, not for the write — so three
     * writes through `E` are one row on the wrong channel. Under the second the
     * write moves from channel 1 to channel 2, which is milder: the two are
     * complements over the `STDERR` constant, so the pair stays exhaustive and
     * the write is mis-filed rather than lost.
     *
     * SO THIS IS A CHANNEL AND NOT A REPAIR OF CHANNEL 1. Teaching channel 1 to
     * follow an alias means a `use`-resolver, which at the token level must
     * tell an import from a trait `use Foo;` inside a class body — the same
     * token sequence — and a resolver that gets that wrong INVENTS an alias.
     * The unscanned-channel scan already exists to say "a way to reach fd 2
     * that no roster here covers has appeared", and an alias of one of these
     * three names is exactly that. There is none in `src/` or `bin/` today;
     * when one arrives it reds, and whoever adds it decides where it is
     * counted.
     *
     * @var list<string>
     */
    private const ALIASABLE_STDERR_NAMES = [
        'fwrite', 'fputs', 'fprintf', 'vfprintf', 'error_log', 'STDERR',
    ];

    /**
     * Every function that writes to a stream handed to it as its first
     * argument, which is what channel 1 actually means by "an `fwrite(STDERR,
     * …)` site".
     *
     * WHAT THE ALPHABET USED TO BE: `fwrite`, alone.
     *
     * WHAT IS TRUE NOW, and why a one-name alphabet was the wrong size. PHP
     * ships `fputs` as an ALIAS of `fwrite` — same function, no import needed,
     * one token's difference at the call site — so `fputs(STDERR, 'x')` was a
     * direct fd-2 write that scored 0 on channel 1 and landed on channel 2
     * instead (MEASURED, PHP 8.3.6), i.e. on the channel whose reasoning treats
     * a `STDERR` mention as benign because nothing is written through it.
     * `fprintf`/`vfprintf` take the stream first too. That is rule 11's shape
     * exactly: the alphabet had been drawn around the aliasing mechanism the
     * round was already thinking about — `use function fwrite as w` — and it
     * could not express PHP's own aliases, which need no import at all.
     *
     * LATENT AND NOT LIVE. There are no `fputs`, `fprintf` or `vfprintf` calls
     * in `src/` or `bin/` (MEASURED, PHP 8.3.6), so widening the alphabet moved
     * no site between channels and changed no roster. It is the next one that
     * this catches.
     */
    private const STREAM_WRITE_FUNCTIONS = ['fwrite', 'fputs', 'fprintf', 'vfprintf'];

    /**
     * Calls of a method named `warn` in `src/` that are NOT channel-6 sites,
     * per file.
     *
     * THE COMPLEMENT THAT MAKES CHANNEL 6 CHECKABLE (E195). Channel 6's scanner
     * requires the receiver token — `RuntimeNoticeSink` in one of the three
     * spellings PHP lexes differently — and that requirement is what makes it
     * correct AND what makes it blind. MEASURED on PHP 8.3.6, each of these
     * scans as 0 on channel 6 where the bare spelling scans 1:
     * `self::warn()`, `static::warn()`, `$c = RuntimeNoticeSink::class;
     * $c::warn()`, `call_user_func([RuntimeNoticeSink::class, 'warn'])`, and
     * `use … as Sink; Sink::warn()`. They fail QUIET — the site becomes
     * INVISIBLE rather than mis-attributed, which is the shape rule 14 warns
     * about, and it is strictly worse than an over-count: nothing anywhere
     * reports a number that looks wrong.
     *
     * SO EVERY `warn(` CALL IN `src/` IS ACCOUNTED FOR, on channel 6 or here.
     * {@see methodCallSites()} is receiver-agnostic — its doc-block and its
     * own known-positive fixture cover `self::`, `static::`, `$this->`, an
     * aliased import and a variable class name — so the difference between
     * what it counts and what channel 6 counts is exactly the set of `warn`
     * calls channel 6 cannot see. Today that difference is one file's three
     * `$this->warn(` calls, which are a private method of
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} and have no
     * relation to this seam. A fourth would be a decision, which is the point.
     *
     * WHY NOT THE `use`-RESOLVER E195's OWN STEP PRESCRIBED, and this is
     * measured rather than preferred. That Step says a `use`-statement resolver
     * in {@see scan()} "would close the alias case and would also strengthen
     * channels 1, 2 and 5". The channel-5 half is FALSE: channel 5 keys on the
     * METHOD name plus a scope operator and never looks at the receiver, so an
     * aliased class cannot hide anything from it — MEASURED, PHP 8.3.6,
     * `use X\Bootstrap as B; B::warnPermissionConfigOnce("x")` already scans
     * 1. And a token-level `use` resolver has to tell an IMPORT from a trait
     * `use Foo;` inside a class body, which is the same token sequence; a
     * resolver that gets that wrong INVENTS an alias, which is a worse failure
     * than the blindness it replaces. This closes three of the four shapes with
     * an instrument that already exists and already has a known-positive.
     *
     * THE FOURTH REMAINS OPEN AND IS NAMED RATHER THAN LEFT TO BE FOUND:
     * `call_user_func([RuntimeNoticeSink::class, 'warn'], …)` reaches the name
     * as a STRING, so no scanner keyed on a `T_STRING` call site can see it.
     * There is no site of that shape in `src/` today and E195's own Step judges
     * it not worth an instrument until there is; this file agrees, and says so
     * here so that the agreement is a recorded decision rather than an
     * omission.
     *
     * @var array<string, int>
     */
    private const NON_SEAM_WARN_SITES = [
        // Three `$this->warn(` calls on the registry's own private helper.
        'src/Agents/ForeignAgentPresetRegistry.php' => 3,
    ];

    /**
     * The three operators a method call can be spelled with, and the two
     * partitions of them that
     * {@see testEveryWarnCallInSrcIsEitherASeamSiteOrOnTheNonSeamRoster()}
     * measures separately.
     *
     * EXHAUSTIVE BY CONSTRUCTION, and asserted to be: a method call in PHP
     * 8.3.6 reaches its name through `::`, `->` or `?->` and through nothing
     * else, so the two partitions must sum to the whole. That sum is checked
     * per file rather than assumed, because a fourth spelling appearing in some
     * later PHP is exactly the kind of change that would otherwise make both
     * partitions quietly under-count at once.
     */
    private const WARN_CALL_OPERATORS = [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

    private const SCOPED_CALL_OPERATORS = [T_DOUBLE_COLON];

    private const INSTANCE_CALL_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

    /**
     * The bracket openers PHP lexes as an ARRAY token while lexing the closer
     * that balances them as a plain one-byte string token.
     *
     * MEASURED on PHP 8.3.6 via `token_get_all()`, which is the only version
     * this box has; CI also runs 8.4 and the list is not asserted there. A
     * fourth shape arriving in a later PHP does not silently corrupt the count
     * — {@see argumentCount()} throws when the walk balances on the wrong
     * closer, which is precisely what a missing entry here looks like.
     *
     *  - `T_ATTRIBUTE` is `#[`, closed by `]`.
     *  - `T_CURLY_OPEN` is the `{` of `"{$a}"`, closed by `}`.
     *  - `T_DOLLAR_OPEN_CURLY_BRACES` is `${`, closed by `}`.
     *
     * `match (…) { … }` is NOT one of them and was checked: both its braces are
     * plain string tokens, so the plain opener list already balances it.
     *
     * @var list<int>
     */
    private const ARRAY_TOKEN_OPENERS = [T_ATTRIBUTE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    /**
     * Only the words a count in this file could plausibly take. A word outside
     * this map is a sentence the guard cannot read, and that is a FAILURE
     * rather than a skip.
     *
     * WIDER THAN THE ONE WORD IT READS TODAY, ON PURPOSE, and this paragraph
     * exists because a reviewer read that as softening the rule above. It does
     * not, and the reason is that BOTH arms of the guard are failures.
     * {@see testTheInheritedCensusStillAgreesWithTheScan()} does
     * `assertArrayHasKey()` and then `assertSame()` against the live scan, so
     * for a prose word this map does not carry the verdict is "the guard cannot
     * read the sentence", and for one it does carry the verdict is "the prose
     * says N and the scan counts M". A word in this map is still COMPARED; it
     * is never waved through. Adding an entry therefore cannot turn a red into
     * a green — it can only exchange the vaguer failure for the one that names
     * both numbers. What WOULD soften the rule is an `?? continue`, and there
     * is none.
     *
     * The reason to keep the list bounded at all is the other direction: a
     * sentence saying "several" or "a couple" must not be silently readable,
     * and it is not.
     *
     * @var array<string, int>
     */
    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
        'twenty-one' => 21, 'twenty-two' => 22, 'twenty-three' => 23,
        'twenty-seven' => 27,
        'thirty-three' => 33, 'thirty-four' => 34, 'thirty-five' => 35,
        'thirty-seven' => 37, 'thirty-eight' => 38, 'thirty-nine' => 39,
        'forty-two' => 42, 'forty-three' => 43, 'forty-four' => 44,
    ];

    public function testTheDirectFwriteStderrRosterIsUnchanged(): void
    {
        self::assertSame(
            self::DIRECT_SITES,
            self::census('direct'),
            self::message('fwrite(STDERR, …)', self::DIRECT_SITES, self::census('direct')),
        );
    }

    public function testTheIndirectStderrHandleRosterIsUnchanged(): void
    {
        self::assertSame(
            self::INDIRECT_SITES,
            self::census('indirect'),
            self::message('captured STDERR handle', self::INDIRECT_SITES, self::census('indirect')),
        );
    }

    public function testTheErrorLogRosterIsUnchanged(): void
    {
        self::assertSame(
            self::ERROR_LOG_SITES,
            self::census('error_log'),
            self::message('error_log()', self::ERROR_LOG_SITES, self::census('error_log')),
        );
    }

    public function testTheSugarcrushMessageShapeRosterIsUnchanged(): void
    {
        self::assertSame(
            self::MESSAGE_SHAPES,
            self::census('shape'),
            self::message('`sugarcrush: ` message', self::MESSAGE_SHAPES, self::census('shape')),
        );
    }

    public function testTheRuntimeNoticeSeamRosterIsUnchanged(): void
    {
        self::assertSame(
            self::RUNTIME_NOTICE_SITES,
            self::census('runtime_notice'),
            self::message('RuntimeNoticeSink::warn()', self::RUNTIME_NOTICE_SITES, self::census('runtime_notice')),
        );
    }

    /**
     * CHANNEL 6 IS DISJOINT FROM THE OTHERS AND NOT A RE-COUNT OF ONE.
     *
     * The doc-block's claim that the two funnels describe different writes is
     * checkable, so it is checked rather than asserted in prose. If `warn()`
     * were ever inlined back to `error_log()` at a call site, or a call site
     * grew a `sugarcrush: ` literal of its own, two rosters would start
     * describing one write and both would look healthy.
     */
    public function testTheTwoEmitterFunnelsDoNotCountTheSameWrite(): void
    {
        foreach (self::RUNTIME_NOTICE_SITES as $file => $sites) {
            self::assertGreaterThan(0, $sites, "{$file} is on the channel-6 roster with no sites");

            $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $file);

            // KNOWN-POSITIVE, IN THE SAME TEST AND IN THE SAME SCANNER (rule
            // 15). Every other assertion in this method is an absence, and an
            // absence proves nothing if the instrument is dead: with the
            // channel-6 classifier mutated to never match, all of them pass.
            // Derived from the roster rather than written as a literal, so it
            // cannot drift from it.
            self::assertSame(
                $sites,
                self::scan('runtime_notice', $source),
                "the channel-6 classifier no longer sees {$file}'s sites; every absence below is vacuous",
            );

            self::assertSame(
                0,
                self::scan('shape', $source),
                "{$file} routes through RuntimeNoticeSink::warn() AND carries a `sugarcrush: ` literal; "
                    . 'one of the two channels is now describing the other\'s write',
            );
        }

        // The sink itself is the one place the two channels legitimately meet:
        // it holds channel 3's single site for the whole family, and none of
        // channel 6's.
        $sink = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Diagnostics/RuntimeNoticeSink.php');
        self::assertSame(1, self::scan('error_log', $sink), 'the sink stopped writing stderr, or writes twice');
        self::assertSame(0, self::scan('runtime_notice', $sink), 'the sink calls its own warn()');

        // AND ASKED IN AN ALPHABET THAT CAN ACTUALLY EXPRESS THE ANSWER. The
        // line above cannot: `scan('runtime_notice', …)` requires the literal
        // class token before `::`, and the way a class calls its own static
        // funnel is `self::warn(` — which scans as 0 whether or not it is
        // there (MEASURED, PHP 8.3.6). So the assertion whose message says
        // "the sink calls its own warn()" was blind to the one spelling that
        // sentence describes. {@see methodCallSites()} is keyed on the CALL,
        // not on the receiver, so `self::`, `static::`, `$this->`, an aliased
        // import and a variable class name all count.
        self::assertSame(
            0,
            self::methodCallSites('warn', $sink),
            'the sink calls warn() from inside itself, in some spelling; channel 6 credits the funnel '
                . 'with one error_log() and would now be counting a write twice',
        );

        // KNOWN-POSITIVE for that scanner too, in the same test: five calls a
        // roster would have to catch, and two shapes that are not calls at all.
        self::assertSame(5, self::methodCallSites('warn', <<<'PHP'
            <?php
            use A\B\RuntimeNoticeSink as Sink;
            class A {
                public static function warn(string $m): void {}
                public function f(): void {
                    self::warn('a');
                    static::warn('b');
                    $this->warn('c');
                    Sink::warn('d');
                    $c = RuntimeNoticeSink::class;
                    $c::warn('e');
                    $callable = self::warn(...);
                }
            }
            PHP), 'methodCallSites() has gone blind; the assertion above is vacuous');

        // AND THE SAME FIXTURE THROUGH THE PARTITION
        // {@see testEveryWarnCallInSrcIsEitherASeamSiteOrOnTheNonSeamRoster()}
        // now measures with, since a partition whose halves are both dead sums
        // to a correct-looking whole in every file that has no calls at all.
        // Four of the five are `::` — self, static, an aliased import and a
        // variable class name, which is every spelling channel 6 cannot read —
        // and exactly one is `->`.
        $partitionFixture = <<<'PHP'
            <?php
            use A\B\RuntimeNoticeSink as Sink;
            class A {
                public static function warn(string $m): void {}
                public function f(): void {
                    self::warn('a');
                    static::warn('b');
                    $this?->warn('c');
                    Sink::warn('d');
                    $c = RuntimeNoticeSink::class;
                    $c::warn('e');
                    $callable = self::warn(...);
                }
            }
            PHP;
        self::assertSame(
            4,
            self::methodCallSites('warn', $partitionFixture, self::SCOPED_CALL_OPERATORS),
            'the scoped half of the partition has gone blind, so a seam write in any `::` spelling '
                . 'would now pass the roster it is supposed to red',
        );
        self::assertSame(
            1,
            self::methodCallSites('warn', $partitionFixture, self::INSTANCE_CALL_OPERATORS),
            'the instance half of the partition has gone blind — `?->` included, which is the spelling '
                . 'this fixture uses precisely because the bare `->` one is the obvious one to test',
        );

        self::assertArrayNotHasKey('src/Diagnostics/RuntimeNoticeSink.php', self::RUNTIME_NOTICE_SITES);
    }

    /**
     * EVERY `warn(` CALL IN `src/` IS EITHER A CHANNEL-6 SITE OR ON
     * {@see NON_SEAM_WARN_SITES} — see that constant for why (E195).
     *
     * WHAT THIS SAID: "THE ASSERTION IS THE DIFFERENCE, PER FILE, and not two
     * totals. A total would net a new hidden seam call against a removed
     * `$this->warn(` and report nothing; the per-file identity cannot."
     *
     * WHAT IS TRUE NOW, MEASURED rather than reasoned. The per-file identity
     * nets too, inside a file, and round 48's review demonstrated it: add
     * `use …\RuntimeNoticeSink as Sink;` to
     * `src/Agents/ForeignAgentPresetRegistry.php` and turn ONE of its three
     * `$this->warn(` calls into `Sink::warn(`. That is a working seam write in
     * the spelling channel 6 is blindest to. {@see methodCallSites()} is
     * receiver-agnostic and still counts 3; `scan('runtime_notice', …)` still
     * scores 0; the gap is still 3; the roster still matches. The whole census
     * ran byte-identical to baseline. The netting was available in that file
     * precisely BECAUSE it is the only one carrying a non-zero non-seam budget
     * — there were three calls to displace.
     *
     * WHY THIS STILL EARNS ITS PLACE, and what was added rather than removed.
     * The difference is kept, because its `assertGreaterThanOrEqual()` arm is
     * the only thing that reds when the two scanners contradict each other. But
     * the identity is no longer the whole guard: the `warn(` calls are now
     * PARTITIONED BY OPERATOR and each half pinned against its own roster.
     * `::warn(` — every spelling of a seam write, `self::`, `static::`,
     * `$class::` and an aliased import included — must equal
     * {@see RUNTIME_NOTICE_SITES} per file; `->warn(`/`?->warn(` must equal
     * {@see NON_SEAM_WARN_SITES}. A hidden seam write now reds TWICE over: it
     * appears in a file the scoped roster credits with none, and it is missing
     * from the instance roster it displaced. Displacement is what the old
     * formulation could not see, and it is the direction a partition closes and
     * a difference cannot.
     *
     * BOTH INSTRUMENTS ARE LOAD-BEARING IN BOTH DIRECTIONS, which is what makes
     * this something other than an absence assertion. A dead
     * {@see methodCallSites()} reports 0 for a file the roster credits with 3
     * and reds; a dead `scan('runtime_notice', …)` makes the gap equal the
     * whole `warn` count in four files that are supposed to have none and reds.
     * The fixture at the bottom is still there, because "both would red" is an
     * argument and the fixture is a measurement — and because it is the only
     * thing here that demonstrates the blindness this test exists to bound.
     */
    public function testEveryWarnCallInSrcIsEitherASeamSiteOrOnTheNonSeamRoster(): void
    {
        $gaps = [];
        $scoped = [];
        $instance = [];

        foreach (self::sources() as $relative => $absolute) {
            $source = (string) file_get_contents($absolute);
            $all = self::methodCallSites('warn', $source);
            $viaScope = self::methodCallSites('warn', $source, self::SCOPED_CALL_OPERATORS);
            $viaObject = self::methodCallSites('warn', $source, self::INSTANCE_CALL_OPERATORS);

            self::assertSame(
                $all,
                $viaScope + $viaObject,
                "{$relative}: the two operator partitions no longer sum to every warn() call, so a "
                    . 'method-call spelling exists that neither roster below can see',
            );

            if ($viaScope > 0) {
                $scoped[$relative] = $viaScope;
            }
            if ($viaObject > 0) {
                $instance[$relative] = $viaObject;
            }

            $gap = $all - self::scan('runtime_notice', $source);

            self::assertGreaterThanOrEqual(
                0,
                $gap,
                "{$relative}: channel 6 counts more RuntimeNoticeSink::warn() sites than there are calls of "
                    . 'a method named warn() at all. One of the two scanners is wrong; they cannot both be.',
            );

            if ($gap > 0) {
                $gaps[$relative] = $gap;
            }
        }
        ksort($gaps);
        ksort($scoped);
        ksort($instance);

        // THE PARTITION, WHICH IS WHAT CLOSES DISPLACEMENT. Every `::warn(` in
        // `src/` is a seam write in SOME spelling — this package has no other
        // static `warn()` — so the scoped half must be channel 6's roster
        // exactly. An aliased import, `self::`, `static::` or a variable class
        // name all land here whether or not `scan('runtime_notice', …)` can
        // read them, which is the point: this roster is keyed on the CALL and
        // channel 6's is keyed on the RECEIVER, so a write that hides from one
        // is counted by the other.
        self::assertSame(
            self::RUNTIME_NOTICE_SITES,
            $scoped,
            'a `::warn(` call in src/ is not where channel 6 says the seam writes are. Either a seam '
                . 'write appeared in a spelling channel 6 cannot read (an aliased import, self::, '
                . 'static::, $class::) — in which case the census is under-counting by that much — or '
                . 'somebody added a static warn() that is not the sink\'s, which needs its own roster.',
        );

        self::assertSame(
            self::NON_SEAM_WARN_SITES,
            $instance,
            'an instance `->warn(` call in src/ is not on the non-seam roster. A DISAPPEARING one matters '
                . 'as much as a new one: round 48 hid a seam write by replacing exactly one of these, and '
                . 'the difference-based assertion below saw nothing because the totals still balanced.',
        );

        self::assertSame(
            self::NON_SEAM_WARN_SITES,
            $gaps,
            'a call of a method named warn() in src/ is not a channel-6 site and is not on the non-seam '
                . 'roster. Either it is somebody else\'s warn() — add it to NON_SEAM_WARN_SITES with a '
                . 'sentence saying whose — or it is a seam write in one of the spellings channel 6 cannot '
                . 'see (self::, static::, a variable class name, an aliased import), in which case channel '
                . '6\'s roster is under-counting by that much and the census is quietly wrong.',
        );

        // THE BLINDNESS THIS TEST BOUNDS, DEMONSTRATED IN THE SAME TEST rather
        // than argued. An aliased import is a real seam write that channel 6
        // scores 0 for; the roster above is what would notice it, and this row
        // is what proves the two scanners still disagree about it the way the
        // reasoning above assumes. MEASURED, PHP 8.3.6.
        $aliased = "<?php\nuse SugarCraft\\Crush\\Diagnostics\\RuntimeNoticeSink as Sink;\nSink::warn('x');\n";
        self::assertSame(
            0,
            self::scan('runtime_notice', $aliased),
            'channel 6 can now see an aliased import on its own, which is better than this test assumed — '
                . 'rewrite the reasoning above rather than deleting the row',
        );
        self::assertSame(
            1,
            self::methodCallSites('warn', $aliased),
            'the receiver-agnostic scanner has gone blind to an aliased import, so the identity above '
                . 'cannot detect the shape it exists to detect',
        );
    }

    /**
     * {@see \SugarCraft\Crush\Agents\WorktreeManager} carries FOUR channel-6
     * sites and NOTHING IN `src/` OR `bin/` CONSTRUCTS IT, so all four are
     * dormant — and this test is what makes that a pinned fact rather than a
     * sentence three doc-blocks happen to agree on.
     *
     * WHY A DORMANCY GUARD AND NOT A DELETION. "DORMANT IS NOT UNGATED" is this
     * package's own doctrine — {@see \SugarCraft\Crush\Agents\WorktreeConfig}
     * is the file it was written against — and a dormant emitter's channel is
     * the channel its FIRST caller inherits. Round 48 routed all four onto the
     * seam for that reason, and then wrote two doc-blocks describing them as
     * firing "while the alternate screen is up", which was a reachability claim
     * that had never been checked and was false. This guard exists so the next
     * such sentence is a red rather than a plausible paragraph.
     *
     * WHAT IT ASSERTS, in two halves that fail differently. The roster half
     * pins the four sites; the construction half pins the zero. A file that
     * starts building one reds here with a message telling the reader which
     * paragraphs are now out of date, which is the moment to REWRITE them — not
     * to delete this test.
     *
     * WHAT THE SCANNER CANNOT SEE, named rather than left to be found:
     * `new $class` with the name in a variable. MEASURED on this tree, PHP
     * 8.3.6, there are SIX such sites and all six are in
     * `src/Providers/VertexProvider.php`, across four variables —
     * `$requestClass`, `$clientClass`, `$bodyClass`, `$valueClass` — each
     * assigned a literal `Google\…` protobuf class-name string earlier in the
     * same method, so none of them can be this class. (A previous draft of this
     * sentence said "three lines above the `new`". The six distances are 3, 4,
     * 4, 4, 4 and 6, so that was one site of six; a line-distance in a comment
     * rots on the next edit anyway, and naming the variables does not.) Also
     * invisible:
     * `(new ReflectionClass(...))->newInstance()` and container resolution,
     * neither of which this package does.
     *
     * A NOTE ON THE LEXER THAT COST A DRAFT OF THIS TEST. In `Foo::new(`, PHP
     * 8.3.6 lexes `new` as `T_NEW` and not as `T_STRING` — so the project's
     * canonical `::new()` factory is invisible to every scanner in this file
     * that keys on {@see callableName()}, {@see methodCallSites()} included.
     * That is why {@see constructionSites()} matches the factory shape on
     * `T_NEW` explicitly.
     *
     * WHAT THIS SAID: "…and why a naive 'count the `new` tokens' scan of `src/`
     * reports 285 rather than 6."
     *
     * WHAT IS TRUE NOW: no generator produces 285. MEASURED, PHP 8.3.6, token
     * walk over `src/` plus `bin/sugarcrush`: `T_NEW` tokens number in the
     * thousands, `T_NEW` followed by a name token slightly fewer, textual
     * occurrences of `new ` slightly fewer again — and none of the five
     * candidate readings lands anywhere near 285. The trailing "rather than 6"
     * was borrowed from a different paragraph two sentences earlier, where 6 is
     * the `new $variable` count; the fixture below asserts 4, not 6, so the
     * comparison did not even name this test's own answer.
     *
     * WHY THE POINT STILL EARNS ITS PLACE, restated over something that cannot
     * rot. A cardinality over `src/` written into prose is wrong the moment any
     * other work merges, which is half of why that figure went unchallenged.
     * The comparison is therefore made against the FIXTURE below, in the
     * fixture's own assertion: a bare `T_NEW` count over it is EIGHT where
     * {@see constructionSites()} answers FOUR. The gap is the whole reason this
     * scanner discriminates by token shape instead of counting `new`.
     */
    public function testTheWorktreeManagerSeamSitesAreDormantBecauseNothingConstructsIt(): void
    {
        self::assertSame(
            4,
            self::RUNTIME_NOTICE_SITES['src/Agents/WorktreeManager.php'] ?? 0,
            'WorktreeManager left channel 6; the dormancy reasoning below is about sites that no longer exist',
        );

        $built = [];
        foreach (self::sources() as $relative => $absolute) {
            $sites = self::constructionSites('WorktreeManager', (string) file_get_contents($absolute));
            if ($sites > 0) {
                $built[$relative] = $sites;
            }
        }
        ksort($built);

        self::assertSame(
            [],
            $built,
            'something in src/ or bin/ now constructs a WorktreeManager, so its four seam sites are live. '
                . 'That is a good change and this is not a request to revert it — but three doc-blocks say '
                . 'the class is dormant (WorktreeManager\'s own, Bootstrap\'s, WorktreeConfig\'s) and '
                . 'Chat::subscriptions() says its notices are NOT among the in-turn emitters. Rewrite those '
                . 'four, then update this test to pin the new reachability instead of the old dormancy.',
        );

        // KNOWN-POSITIVE THROUGH THE SAME SCANNER IN THE SAME TEST (rule 15).
        // An assertion of [] proves nothing unless something here proves the
        // instrument still matches. Round 44 shipped an empty census whose
        // scanner was dead and stayed green through 18,228 assertions.
        //
        // FOUR CONSTRUCTIONS AND FOUR NON-CONSTRUCTIONS, in one fixture: the
        // bare, fully-qualified and namespace-qualified `new`, plus the
        // `::new()` factory; against a different class, a `::class` reference,
        // the DECLARATION of a static method named `new`, and — the shape that
        // matters most, because it is what `src/` is full of — a `::new()`
        // factory call on some other class.
        $fixture = <<<'PHP'
            <?php
            use SugarCraft\Crush\Agents\WorktreeManager;
            $a = new WorktreeManager();
            $b = new \SugarCraft\Crush\Agents\WorktreeManager($config);
            $c = Agents\WorktreeManager::new('/repo');
            $d = new Agents\WorktreeManager($config);
            $e = new WorktreeConfig();
            $f = WorktreeManager::class;
            $g = WorktreeConfig::new();
            class X { public static function new(): self { return new self(); } }
            PHP;

        self::assertSame(
            4,
            self::constructionSites('WorktreeManager', $fixture),
            'constructionSites() has gone blind; the empty assertion above is vacuous',
        );

        // THE NAIVE COUNT, GENERATED HERE RATHER THAN QUOTED. Eight `T_NEW`
        // tokens against four constructions of this class — the four the
        // scanner must reject are a different class, that class's `::new()`
        // factory, the DECLARATION of a static `new()`, and `new self()`. This
        // is the comparison a `src/`-wide figure used to make in prose, moved
        // onto something a merge cannot invalidate.
        $naive = 0;
        foreach (self::significantTokens($fixture) as $token) {
            if (\is_array($token) && $token[0] === T_NEW) {
                $naive++;
            }
        }
        self::assertSame(
            8,
            $naive,
            'the fixture no longer carries eight `new` tokens, so the paragraph above comparing the '
                . 'naive count against this scanner\'s four is describing a fixture that is gone',
        );

        // AND IT MUST NOT SEE A DOC-COMMENT. `WorktreeManager`'s own doc-blocks
        // mention `new WorktreeManager()` and `WorktreeManager::new($repoRoot)`
        // five times between them (MEASURED, this tree) — a guard that read
        // them would red on its own explanation of why it is green.
        //
        // ONE COMMENTED CONSTRUCTION AND ONE LIVE ONE, ASSERTING *ONE*, and not
        // a comment-only fixture asserting zero. A comment-only fixture cannot
        // discriminate here and the first draft of this test shipped one:
        // `token_get_all()` returns a whole comment as a SINGLE `T_DOC_COMMENT`
        // or `T_COMMENT` token (MEASURED, PHP 8.3.6), so a `T_NEW` never
        // appears inside one and NO mutation of this scanner's comment handling
        // can make an all-comments source answer anything but zero. Mutating
        // `significantTokens()` out of {@see constructionSites()} entirely left
        // that assertion green. Asserting ONE fails in both directions instead:
        // a grep-shaped reimplementation counts the comments and answers two,
        // a dead scanner answers zero.
        self::assertSame(1, self::constructionSites('WorktreeManager', <<<'PHP'
            <?php
            /** Built by `new WorktreeManager()` or `WorktreeManager::new($root)`. */
            // new WorktreeManager();
            $live = new WorktreeManager();
            PHP), 'constructionSites() no longer separates a commented constructor from a called one');
    }

    /**
     * AN ANONYMOUS CLASS NAMING THE TARGET IN ITS HEADER IS A FAILURE, NOT A
     * ZERO — the third of this file's refusals, alongside
     * {@see testTheDepthWalkRedsOnAnOpenerItDoesNotRecognise()} and
     * {@see testTheAliasScanRedsOnAnImportThatNeverTerminates()}, and here for
     * the same reason: `new class extends WorktreeManager {}` constructs a
     * subclass, which lights the four seam sites exactly as thoroughly as
     * constructing the class itself, and it is the one construction shape
     * {@see constructionSites()} cannot attribute.
     *
     * NOT REACHABLE FROM THE CENSUS TODAY, which is why it needs its own test:
     * a token walk over `src/` and `bin/` found no `new class` at all when this
     * was written (MEASURED, PHP 8.3.6), so nothing else in this suite fires
     * the throw and an unfired throw is an assumption rather than a guard. The
     * file count that walk covered is deliberately NOT recorded here: a
     * cardinality over `src/` written into prose is wrong the moment any other
     * work merges, and this claim does not need one to stand up.
     *
     * THE NEGATIVE HALF MATTERS AS MUCH. An anonymous class that does NOT name
     * the target must still answer, or the refusal would make every future
     * `new class` in this package unmeasurable — so the same shape extending
     * something else scans quietly, and the live construction beside it is
     * still counted.
     */
    public function testTheConstructionScanRedsOnAnAnonymousClassItCannotAttribute(): void
    {
        self::assertSame(1, self::constructionSites('WorktreeManager', <<<'PHP'
            <?php
            $other = new class ($arg) extends WorktreeConfig implements Countable {};
            $live = new WorktreeManager();
            PHP), 'an anonymous class extending something else now derails the walk');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot attribute');

        self::constructionSites('WorktreeManager', <<<'PHP'
            <?php
            $x = new class ($config) extends WorktreeManager {};
            PHP);
    }

    public function testThePrefixedWriterRosterIsUnchanged(): void
    {
        self::assertSame(
            self::PREFIXED_WRITER_SITES,
            self::census('prefixed'),
            self::message('warnPermissionConfig*()', self::PREFIXED_WRITER_SITES, self::census('prefixed')),
        );
    }

    /**
     * Channel 5's one file decomposes into its three entry points, and the
     * transcript one is the count `Bootstrap` already declares.
     *
     * WHY THIS IS NOT A SECOND HAND-MAINTAINED INTEGER, which is the objection
     * the sibling census raises against exactly that shape and is right to.
     * `PREFIXED_WRITER_SITES` says 22 and
     * {@see \SugarCraft\Crush\Cli\Bootstrap::TRANSCRIPT_SEAM_CALL_SITES}
     * says 16; this test is what makes the second a COMPONENT of the first
     * rather than an unrelated number that happens to be smaller. Add a seam
     * call and both move together; add a stderr-only warning and only the total
     * moves, which is the distinction a reader of either census wants and
     * neither could previously make.
     *
     * WHICH CENSUS OWNS WHAT: the seam count and its ten prose sentences belong
     * to
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapTranscriptSeamCallSiteCensusTest},
     * and nothing here duplicates them. This file owns the OTHER two entry
     * points, which that file does not count and no census did before channel 5.
     */
    public function testTheWarnFamilyDecomposesIntoItsThreeEntryPoints(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');

        $direct = self::scan('prefixed:warnPermissionConfig', $source);
        $once = self::scan('prefixed:warnPermissionConfigOnce', $source);
        $seam = self::scan('prefixed:warnPermissionConfigInTranscript', $source);

        self::assertSame(
            Bootstrap::TRANSCRIPT_SEAM_CALL_SITES,
            $seam,
            'the transcript-seam component of channel 5 no longer equals Bootstrap::'
                . 'TRANSCRIPT_SEAM_CALL_SITES. One of the two scans is wrong, or a seam call was added '
                . 'without the sibling census noticing; do not bump either number until you know which.',
        );
        self::assertSame(1, $direct, 'the number of warnings that go to stderr and bypass the once-guard moved');
        self::assertSame(5, $once, 'the number of stderr-only, once-per-process warnings moved');
        self::assertSame(
            self::PREFIXED_WRITER_SITES['src/Cli/Bootstrap.php'],
            $direct + $once + $seam,
            'the three entry points no longer add up to the channel-5 roster; the scanner is double-counting '
                . 'or the family gained a fourth entry point',
        );
    }

    /**
     * NO STDERR CHANNEL OUTSIDE THE SCANNED ONES HAS APPEARED.
     *
     * This is the only claim of exhaustiveness this file makes, and it is
     * deliberately a narrow one: it does not prove that no other way to reach
     * fd 2 exists in PHP, it proves that the FOUR other ways this application
     * could plausibly acquire one have not been used. `php://stderr` and
     * `php://output` opened as streams; `error_log()`'s three-argument
     * destination form, which routes somewhere channel 3's reasoning about
     * `ini_get('error_log')` does not cover; and — added by E195 — a
     * `use function`/`use const` import that renames `fwrite`, `error_log` or
     * `STDERR`, which does not add a way to reach fd 2 so much as take an
     * existing one out of the other scanners' alphabet. See
     * {@see ALIASABLE_STDERR_NAMES} for what each of those does to channels 1
     * and 2, measured.
     *
     * AND THE CLAIM IS ONLY AS WIDE AS ITS ALPHABET, which is the thing to
     * attack before believing it. WHAT THIS SAID, implicitly, by listing the
     * import-renaming channel as the answer to aliasing: that renaming an
     * import is how a write becomes unrecognisable. WHAT IS TRUE NOW: PHP
     * renames two of these itself. `fputs` IS `fwrite`, no import required, and
     * `fprintf`/`vfprintf` take the stream first as well — so before
     * {@see STREAM_WRITE_FUNCTIONS} existed, `fputs(STDERR, 'x')` scored 0 on
     * channel 1 and 1 on channel 2 (MEASURED, PHP 8.3.6), landing on the
     * channel that reads a `STDERR` mention as benign. The alphabet had been
     * drawn around the aliasing mechanism the round was thinking about rather
     * than around the ones the language provides. WHY THE CLAIM STILL EARNS ITS
     * PLACE: the channels were right, the alphabet was short, and it is the
     * alphabet that was widened — no channel was added or removed, and no site
     * in `src/` moved between them.
     *
     * AN ASSERTION OF ZERO IS NOT EVIDENCE, so the positive control is IN THIS
     * TEST and not in the shared provider: round 44 emptied a census in this
     * tree, blinded the scanner, and watched "nothing is stale" pass with
     * 18,228 assertions green. If the scanner below stops working, the
     * synthetic source reds here before the zero can be believed.
     */
    public function testNoStderrChannelOutsideTheScannedOnesHasAppeared(): void
    {
        self::assertSame(
            2,
            self::scan('other', '<?php fopen("php://stderr", "w"); error_log("x", 3, "/tmp/f");'),
            'the other-channel scanner no longer sees a channel it exists to find; the [] below is vacuous',
        );
        self::assertSame(
            0,
            self::scan('other', '<?php error_log("x"); fwrite(STDERR, "y");'),
            'the other-channel scanner reports channels 1 and 3 as unscanned ones',
        );

        self::assertSame(
            [],
            self::census('other'),
            'src/ or bin/ acquired a stderr channel no scanner in this file covers — a php:// stream handle, '
                . 'or error_log()\'s destination form. Give it a channel and a roster: an emitter nothing '
                . 'counts is exactly what this file exists to prevent.',
        );
    }

    /**
     * EVERY `other`-CHANNEL FIXTURE IS REAL PHP, not merely lexable text.
     *
     * `token_get_all()` will happily lex a source the compiler rejects, so a
     * fixture written to exercise a token shape can pin a shape that cannot
     * occur — which makes the row look like coverage and buy nothing.
     * `TOKEN_PARSE` runs the parser over the same string, so a fixture that is
     * not a program reds here rather than sitting in the provider forever.
     *
     * ONLY THE `other` CHANNEL, deliberately. The channel-1/2/5 fixtures
     * include deliberate FRAGMENTS — `<?php private static function
     * warnPermissionConfig(string $m): void {}` is a method body outside a
     * class — and they are fragments on purpose, so requiring them to parse
     * would delete the rows rather than strengthen them.
     */
    public function testEveryOtherChannelFixtureIsRealPhp(): void
    {
        $checked = 0;

        foreach (self::scannerCases() as $name => [$channel, $source, $_expected]) {
            if ($channel !== 'other') {
                continue;
            }

            $checked++;
            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (\ParseError $e) {
                self::fail("the `other` fixture \"{$name}\" is not valid PHP: {$e->getMessage()}");
            }
        }

        // The loop above asserts nothing when it runs zero times, which is the
        // vacuous shape this file exists to distrust.
        self::assertGreaterThan(4, $checked, 'the other-channel fixtures vanished from the provider');
    }

    /**
     * The depth walk answers for every `error_log()` call site in `src/` today,
     * and at least one of them exercises the array-token openers.
     *
     * WHY THE SECOND HALF IS THE LOAD-BEARING ONE. The first half is an
     * absence assertion — "nothing throws" — and round 44 proved what an
     * absence is worth without a known-positive beside it. The second half
     * derives, from the tree rather than from prose, that the shape E161 called
     * latent is in fact live: `error_log()` sites with an interpolated message
     * already run through this walk on every suite run. If that ever drops to
     * zero the assertion reds, and the honest response is to keep the
     * {@see scannerCases()} rows and rewrite this paragraph — not to delete a
     * guard because the tree stopped needing it this week (rule 6).
     *
     * DERIVED AND NEVER WRITTEN DOWN (rule 18): the count lives in the failure
     * message, so a sibling lane adding or removing a site cannot make a
     * sentence here false.
     */
    public function testEveryLiveErrorLogSiteSurvivesTheDepthWalk(): void
    {
        $sites = 0;
        $withArrayTokenOpener = 0;

        foreach (self::sources() as $relative => $absolute) {
            $significant = self::significantTokens((string) file_get_contents($absolute));

            foreach ($significant as $i => $token) {
                if (self::callableName($token) !== 'error_log' || ($significant[$i + 1] ?? null) !== '(') {
                    continue;
                }

                $sites++;

                try {
                    self::argumentCount($significant, $i + 1);
                } catch (\RuntimeException $e) {
                    self::fail("argumentCount() cannot answer for the error_log() call in {$relative}: "
                        . $e->getMessage());
                }

                if (self::carriesArrayTokenOpener($significant, $i + 1)) {
                    $withArrayTokenOpener++;
                }
            }
        }

        self::assertGreaterThan(0, $sites, 'the walk found no error_log() call at all; the scan is dead');
        self::assertGreaterThan(
            0,
            $withArrayTokenOpener,
            "no error_log() site in src/ carries an interpolation or an attribute any more ({$sites} sites "
                . 'scanned). E161 called that shape latent and it was not; if it has genuinely become '
                . 'latent, rewrite argumentCount()\'s doc-block to say so — the fixtures stay either way.',
        );
    }

    /**
     * A bracket opener the walk does not recognise is a FAILURE, not a number.
     *
     * THE KNOWN-POSITIVE FOR {@see argumentCount()}\'s throw, and the reason it
     * is here rather than trusted: the throw exists for a token shape that does
     * not exist yet, so nothing else in this suite can ever reach it. Simulated
     * by handing the walk a token stream whose `(` is balanced by a `]` — which
     * is exactly the stream an unrecognised array-token opener produces.
     */
    public function testTheDepthWalkRedsOnAnOpenerItDoesNotRecognise(): void
    {
        // `error_log(` … `]` — a hand-built stream, because every real source
        // that produces one is a shape the openers list now balances.
        $stream = [[T_STRING, 'error_log', 1], '(', [T_LNUMBER, '1', 1], ']'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ARRAY_TOKEN_OPENERS');

        self::argumentCount($stream, 1);
    }

    /**
     * AN IMPORT THE ALIAS SCAN CANNOT FINISH READING IS A FAILURE, NOT A ZERO.
     *
     * The twin of {@see testTheDepthWalkRedsOnAnOpenerItDoesNotRecognise()},
     * and here for the same reason: the throw exists for a source shape no
     * well-formed file produces, so nothing else in this suite can reach it,
     * and an unreachable throw that has never been fired is an assumption
     * rather than a guard. REACHABLE IN ONE PLACE THAT MATTERS THOUGH —
     * {@see scan()} is also run over the hand-written fixtures in
     * {@see scannerCases()}, and a fixture whose `use function` line lost its
     * semicolon would otherwise scan as a quiet 0 and pin nothing.
     */
    public function testTheAliasScanRedsOnAnImportThatNeverTerminates(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('never reaches its `;`');

        // NOT a watched name, deliberately, and the first draft of this test
        // used one and stayed green: an import that matches returns `true` from
        // the first matching token and never walks to the `;` at all. The throw
        // is only reachable for an import the walk has to read to the END —
        // which is exactly the case where a quiet `false` would be a hole.
        self::scan('other', '<?php use function someOtherThing as w');
    }

    /**
     * The naive `substr_count('error_log(')` count reconciles, per file and
     * exactly, with the token scan plus the mentions in comments and doc-blocks.
     *
     * THE INSTRUMENT IS NAMED AS THE ONE THE CODE USES. WHAT THIS SAID: "the
     * naive `grep -c 'error_log('` count". WHAT IS TRUE NOW: the body has
     * always run `substr_count()`, and the two are different measurements —
     * `grep -c` counts LINES that match, `substr_count()` counts OCCURRENCES.
     * They agreed when this was written only because no line in `src/` happens
     * to carry two (MEASURED at `62f4e5d1`: 58 by both, over thirteen files).
     * WHY THE COMPARISON STILL EARNS ITS PLACE: the point was never the tool,
     * it is that the WEAK instrument — whichever one a reader reaches for
     * first — is reconciled against the token scan rather than trusted or
     * dismissed. Naming the tool the code actually runs is what lets the next
     * reader reproduce the number.
     *
     * WHY THIS TEST AND NOT A SENTENCE. Two counts of the same thing were in
     * circulation — the token census\'s and a `grep | uniq -c`\'s — differing by
     * twenty across thirteen files, with two files appearing in one and not the
     * other and `src/Cli/Bootstrap.php` at 10 against 1. A census disagreeing
     * with a grep by that much has an alphabet problem until proven otherwise,
     * and the proof is an IDENTITY rather than a pair of numbers: every naive
     * occurrence is either a call the scan counts or a mention inside a
     * `T_COMMENT`/`T_DOC_COMMENT`, with nothing left over. The token census is
     * the correct one; the grep was counting this application\'s own prose about
     * `error_log()`, which it writes more often than it calls it.
     *
     * THE RESIDUE IS THE POINT, IN BOTH DIRECTIONS. An occurrence that is
     * neither a call nor a comment — one inside a string literal, say, which is
     * how a `sprintf()` template or a heredoc could smuggle one past both
     * counts — reds this, and neither of the two existing rosters would notice
     * it. So does the opposite: a real call the naive count cannot SEE, which
     * `error_log ("x")` with a space before the paren already is on PHP 8.3.6
     * (MEASURED: `substr_count` 0, token scan 1). That direction used to be
     * unreachable, because the loop skipped any file whose naive count was
     * zero — 275 of the 289 files `sources()` walks, at the time that guard was
     * removed. Asserting an identity only over the files the weaker instrument
     * already agrees about is not a reconciliation.
     * {@see testTheTwoInstrumentsDisagreeOnAShapeOnlyOneCanSee()} pins the case
     * with both instruments side by side.
     *
     * NO CARDINALITY IS STATED ABOVE (rule 18). The figures are derived below
     * and appear only in failure messages, because a count written into prose
     * here is invalidated by the next lane that adds a call site.
     */
    public function testTheNaiveGrepCountReconcilesWithTheTokenScan(): void
    {
        $naiveTotal = 0;
        $callTotal = 0;
        $commentTotal = 0;
        $examined = 0;

        foreach (self::sources() as $relative => $absolute) {
            $source = (string) file_get_contents($absolute);
            $naive = substr_count($source, 'error_log(');

            // NO `if ($naive === 0) { continue; }` HERE, deliberately. It read
            // as a cheap skip and was a hole: it dropped every file the weak
            // instrument cannot see, which is precisely the file this
            // reconciliation exists to find. For a genuinely empty file the
            // identity below is `0 === 0` and costs nothing. The `$examined`
            // counter at the bottom of this body is what keeps the skip from
            // coming back unnoticed — nothing else in this file reds when it
            // does.
            $inComments = 0;
            foreach (token_get_all($source) as $token) {
                if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $inComments += substr_count($token[1], 'error_log(');
                }
            }

            $calls = self::scan('error_log', $source);

            $accounted = $calls + $inComments;
            $direction = $naive > $accounted
                ? 'occurrence(s) the token scan cannot account for, most likely inside a string literal — '
                    . 'neither the channel-3 roster nor a naive count would report that'
                : 'call(s) or mention(s) the naive count cannot SEE, which is what `error_log (` with a '
                    . 'space before the paren looks like — the roster is right and the naive figure is blind';

            self::assertSame(
                $naive,
                $accounted,
                "{$relative}: substr_count() finds {$naive} occurrences of `error_log(`, but the token scan "
                    . "counts {$calls} calls and {$inComments} mentions inside comments — a gap of "
                    . (string) abs($naive - $accounted) . ' ' . $direction
                    . '. Find it before trusting either count.',
            );

            $naiveTotal += $naive;
            $callTotal += $calls;
            $commentTotal += $inComments;
            // LAST STATEMENT IN THE BODY, so that any early exit added above
            // fails to reach it. This is what pins the removal of the
            // `if ($naive === 0) { continue; }` skip: re-adding that line
            // leaves every assertion above green (there is no file in `src/`
            // today where the two instruments disagree, which is the whole
            // reason the skip looked free), and only this count notices that
            // the reconciliation quietly stopped covering most of the tree.
            // MEASURED: with the skip re-added and this counter absent, the
            // whole of this file stayed green.
            ++$examined;
        }

        self::assertSame(
            \count(self::sources()),
            $examined,
            'the reconciliation did not reach the end of its loop body for every file `sources()` walks. '
                . 'A file skipped here is a file whose identity is not asserted, and the ones worth '
                . 'skipping — those the naive instrument reports zero for — are exactly the ones where a '
                . 'call it cannot see would hide. No cardinality is written here on purpose (rule 18): '
                . 'both sides are derived from the same walk.',
        );

        self::assertSame($naiveTotal, $callTotal + $commentTotal, 'the per-file identity holds but the totals do not');

        // KNOWN-POSITIVE, in the same test, on the same instrument. A tree
        // whose files all happened to have zero occurrences would pass every
        // assertion above without the scanner working at all.
        self::assertGreaterThan(0, $callTotal, 'the token scan sees no error_log() call anywhere in src/');
        self::assertGreaterThan(
            0,
            $commentTotal,
            'no comment in src/ mentions `error_log(` any more, so the reconciliation above is trivially '
                . 'true and proves nothing about the grep/scan gap it exists to explain',
        );
    }

    /**
     * THE TWO INSTRUMENTS, SIDE BY SIDE, ON A SHAPE ONLY ONE OF THEM CAN SEE.
     *
     * The reconciliation above asserts the two agree across `sources()`. That
     * is worth nothing unless they are capable of disagreeing, and the shape
     * that makes them disagree is not exotic: PHP allows whitespace between a
     * function name and its `(`, so `error_log ("x")` is a call the token scan
     * counts and a literal `error_log(` search cannot match. There is no such
     * site in `src/` today — which is why it needs a fixture rather than a
     * sentence, and why the loop above had to stop skipping the files where
     * the naive count is zero.
     *
     * PHP 8.3.6, the only version on this box; CI also runs 8.4 and this is not
     * asserted there.
     */
    public function testTheTwoInstrumentsDisagreeOnAShapeOnlyOneCanSee(): void
    {
        // The control: both instruments see the ordinary spelling, so a
        // disagreement below is about the SHAPE and not about a dead scanner.
        $plain = '<?php error_log("x");';
        self::assertSame(1, substr_count($plain, 'error_log('), 'the naive instrument is dead');
        self::assertSame(1, self::scan('error_log', $plain), 'the token scan is dead');

        $spaced = '<?php error_log ("x", 3, "/tmp/f");';
        self::assertSame(
            0,
            substr_count($spaced, 'error_log('),
            'a literal `error_log(` search now matches across the space, which would make this fixture '
                . 'prove nothing — pick a shape the naive instrument still cannot see',
        );
        self::assertSame(
            1,
            self::scan('error_log', $spaced),
            'the token scan no longer sees a call with whitespace before its paren, so the channel-3 '
                . 'roster is under-counting by however many of those src/ has acquired',
        );
    }

    /**
     * The prose census this file supersedes must keep agreeing with the scan.
     *
     * READ-ONLY, and pinned rather than rewritten: the sentence lives in
     * `tests/Integration/BinSugarcrushAutoloadGuardTest.php`, which is not this
     * lane's file. It is right today. The reason to anchor it anyway is that it
     * is the sentence a reader finds FIRST when they ask how many stderr writes
     * this application has, and it answers a narrower question than they asked
     * — so the day channel 1 moves, the reader's first answer must move with it.
     *
     * WHAT THIS METHOD USED TO BE CALLED, and why the name is now
     * cardinality-free (E245). It was
     * `testTheInheritedElevenSiteCensusStillAgreesWithTheScan()`. That number
     * was correct when the name was written and stopped being correct when
     * E219 added a `fwrite(\STDERR, …)` site to `src/Cli/NonInteractive.php`:
     * the rosters and the anchored prose were all bumped, because each of them
     * has a generator, and the METHOD NAME was the one statement of the count
     * with nothing to contradict it.
     * WHY THE POINT STILL EARNS ITS PLACE rather than just a quiet rename: a
     * cardinality baked into an identifier rots exactly like one baked into
     * prose, and unlike prose it cannot be anchored — there is no way to point
     * {@see selfCountAnchors()} at a method name and have the mismatch red.
     * The only available defence is not to put one there. The number this
     * method validates is derived, below, from `census('direct')`.
     */
    public function testTheInheritedCensusStillAgreesWithTheScan(): void
    {
        $path = \dirname(__DIR__, 2) . '/tests/Integration/BinSugarcrushAutoloadGuardTest.php';
        self::assertFileExists($path, 'the file carrying the prose census has moved');

        $flat = self::flattened((string) file_get_contents($path));

        $matched = preg_match_all(
            '/call sites across `src\/` and `bin\/` is ([A-Z]+)/',
            $flat,
            $all,
            PREG_SET_ORDER,
        );
        self::assertSame(
            1,
            $matched,
            "the prose census sentence matches {$matched} times, not once; re-point this anchor rather than "
                . 'dropping it — an unanchored prose count is how three rounds of staleness happened',
        );

        $scanned = array_sum(self::census('direct'));
        $word = strtolower($all[0][1]);
        self::assertArrayHasKey(
            $word,
            self::NUMBER_WORDS,
            "the prose census says \"{$all[0][1]}\", which this guard cannot read",
        );

        // THE SCAN AND NOT THE ROSTER, which is what this compared against
        // when it was written while its name and its failure message both said
        // "the scan". Transitively the same thing —
        // testTheDirectFwriteStderrRosterIsUnchanged() pins roster to scan — but
        // a reader debugging this failure would have gone looking in the wrong
        // one of the two.
        self::assertSame(
            $scanned,
            self::NUMBER_WORDS[$word],
            'BinSugarcrushAutoloadGuardTest still says the raw fwrite(STDERR, …) census is "' . $word
                . '" while the token scan counts ' . $scanned,
        );
    }

    /**
     * EVERY SCANNER, RUN AGAINST A SOURCE WHOSE ANSWER IS KNOWN, IN THE SAME
     * SUITE THAT TRUSTS IT ON SOURCES WHOSE ANSWER IS NOT.
     *
     * WHAT THIS PARAGRAPH FIRST CLAIMED, and it was wrong in the direction that
     * matters: "the four assertions above are `assertSame(<map>, <scan>)`, which
     * looks like evidence and is not — a blinded scanner returns `[]` for every
     * channel and the only thing that notices is a fixture." MEASURED, by
     * blinding {@see scan()} (drop every token before classification) and
     * running `--filter
     * StderrEmitterCensusTest::testTheDirectFwriteStderrRosterIsUnchanged`
     * ALONE: `Tests: 1, Assertions: 1, Failures: 1`. The roster assertions are
     * PRESENCE assertions — they compare against a non-empty map — so a dead
     * instrument reds them on its own. That is a real difference from an
     * `assertSame([], …)` census, which is the shape round 44's dead-instrument
     * failure actually had, and this file does not have it.
     *
     * WHY THE FIXTURES STILL EARN THEIR PLACE, narrowed to what they buy. FIRST,
     * they pin the CLASSIFICATION independently of the tree: `direct` and
     * `indirect` are defined as complements over the `STDERR` constant, and
     * `\STDERR` inside a comment, inside a doc-block and inside a string must
     * all count as none of the above. A roster that reds tells you a number
     * moved; only these rows tell you whether the SCAN moved or the tree did,
     * and that is the question someone about to bump a roster is asking.
     * SECOND, they are the only guard on a channel whose expected roster is
     * empty — there is none today, and the moment one is added (a `php://stderr`
     * handle, say, expected absent) the `[]` shape and its vacuity arrive with
     * it.
     *
     * WHAT EACH ROW EXPECTING 0 ACTUALLY KILLS (E228). Round 48 shipped a
     * fixture asserting 0 whose failure message named a mutation the fixture
     * survived, so the rows here were swept the same way: mutate the
     * instrument, re-run the whole provider, and record which rows change
     * answer. MEASURED, PHP 8.3.6. The result, in three groups:
     *
     *  - CLAUSE ROWS, which kill the removal of the clause they describe and
     *    are what they claim to be. `the declaration is not a call` and
     *    `an unscoped same-named function is not one of ours` kill the scope
     *    operator; the two first-class-callable rows kill the ellipsis guard;
     *    `some other class's warn() is not the seam` kills the receiver check
     *    and `the seam class reached with an instance operator is not the seam`
     *    kills the operator check beside it; `the seam record() is not the warn
     *    funnel` kills the method-name check; three rows kill the
     *    `T_CONST`/`T_FUNCTION` disambiguation in the import walk; `a const
     *    import of something else is not a channel` kills a widened
     *    {@see ALIASABLE_STDERR_NAMES}; and the destination-form rows kill both
     *    a relaxed threshold and the loss of a {@see ARRAY_TOKEN_OPENERS}
     *    entry, the latter by making the walk THROW rather than answer.
     *  - GREP ROWS. Every row whose subject is a comment, a doc-block or a
     *    string literal — and there are seven — answers 0 under every
     *    structural mutation of the scanners, because a comment is a single
     *    token and the names never appear as a T_STRING inside one. What they
     *    kill is the channel reimplemented as a `substr_count()`, which is the
     *    exact temptation {@see scan()}'s own doc-block spends a paragraph
     *    talking a reader out of. They are load-bearing; they are just not
     *    load-bearing for the reason a reader would assume.
     *  - THE BARE CONTROLS, `<?php echo 1;` on a channel that must find nothing
     *    in it. These kill only a scanner that counts something in a source
     *    with nothing in it (measured: one that increments per significant
     *    token answers 4 — that fixture's significant-token count is a
     *    property of the fixture and does not move). They cannot kill a dead
     *    scanner and are not asked to, because most of this provider expects a
     *    non-zero count and a dead {@see scan()} therefore reds it on its own.
     *
     * AND THE SENTENCE THAT USED TO CARRY THAT LAST CLAIM WAS ALREADY WRONG
     * WHEN IT WAS COMMITTED — inside the very commit that swept this provider
     * for fixtures crediting themselves with mutations they survive. WHAT IT
     * SAID: "THE FOUR BARE CONTROLS at the bottom, `<?php echo 1;` on each
     * channel … That was measured too, by blinding {@see scan()}: thirty-two
     * rows go red."
     * WHAT IS TRUE NOW, MEASURED at round 49 by inserting `return 0;` at the
     * top of {@see scan()} and running this class (PHP 8.3.6): THIRTY-FIVE
     * rows go red, not thirty-two. Thirty-two was the count BEFORE the same
     * commit added three non-zero rows to this provider, and nothing
     * re-derived it afterwards. The other two numerals were wrong in the same
     * way: this provider carries five `<?php echo 1;` rows and not four, and
     * they sit on five of its seven channel spellings and not on each — there
     * is none on `indirect` and none on `shape`.
     * WHY THE CLAIM STILL EARNS ITS PLACE: it is the entire reason the bare
     * controls are allowed to have no positive component of their own, so it
     * has to be true, and a numeral in a doc-block is the one form of it that
     * nothing keeps honest (rule 18). It is now carried by a generator
     * instead: {@see testEveryChannelInThisProviderHasARowADeadScanWouldRed()}
     * asserts the property the number was standing in for, per channel, which
     * is strictly stronger than any single total and cannot go stale when a row
     * is added.
     *
     * THE ONE HOLE THE SWEEP FOUND was the comment strip in
     * {@see significantTokens()} — no row killed its removal, on any channel.
     * The rows added for it are marked in place. There are FOUR of them and
     * all four red when the strip goes, which is one more than the sentence
     * here used to claim: the fourth is `and that write is still not indirect`,
     * and it is the interesting one, because it fails in the OPPOSITE
     * direction. It expects 0 at head and answers 1 with the comment strip
     * gone — an unstripped comment displaces `STDERR` out of the first
     * argument position, so channel 2 starts counting a direct write as a
     * captured handle. MEASURED, PHP 8.3.6, round 49.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function scannerCases(): iterable
    {
        yield 'a direct write' => ['direct', '<?php fwrite(STDERR, "x");', 1];
        yield 'a namespaced direct write' => ['direct', '<?php \\fwrite(\\STDERR, "x");', 1];

        // THE COMMENT STRIP, PINNED (E228). {@see significantTokens()} drops
        // T_WHITESPACE, T_COMMENT and T_DOC_COMMENT, and until this row nothing
        // in this provider noticed if it stopped dropping the last two: the
        // three rows that MENTION a comment all answer 0 whether the strip runs
        // or not, because `token_get_all()` returns a whole comment as ONE
        // token and the names these channels key on never appear as a T_STRING
        // inside one. What the strip actually buys is ADJACENCY — every channel
        // here reads `$significant[$i - 1]` and `$significant[$i + 1]` — and a
        // comment is legal in exactly those two positions. MEASURED, PHP 8.3.6:
        // this row is 1 at head, 0 with T_COMMENT/T_DOC_COMMENT out of the
        // strip, and 0 with T_WHITESPACE out of it, so it fails in both
        // directions against either half.
        yield 'a direct write with a comment before the handle' => [
            'direct',
            '<?php fwrite(/* c */ STDERR, "x");',
            1,
        ];
        yield 'and that write is still not indirect' => [
            'indirect',
            '<?php fwrite(/* c */ STDERR, "x");',
            0,
        ];
        yield 'a direct write is not indirect' => ['indirect', '<?php fwrite(STDERR, "x");', 0];

        // PHP'S OWN ALIASES OF fwrite(), which need no import and so cannot be
        // caught by the aliased-import channel. Each of these was a direct fd-2
        // write scoring 0 on channel 1 and 1 on channel 2 until
        // {@see STREAM_WRITE_FUNCTIONS} widened the alphabet — landing, that
        // is, on the channel whose reasoning is "STDERR is mentioned but not
        // written through, so it is benign".
        yield 'fputs is fwrite' => ['direct', '<?php fputs(STDERR, "x");', 1];
        yield 'fputs is not indirect' => ['indirect', '<?php fputs(STDERR, "x");', 0];
        yield 'fprintf takes the stream first' => ['direct', '<?php fprintf(STDERR, "%s", "x");', 1];
        yield 'fprintf is not indirect' => ['indirect', '<?php fprintf(STDERR, "%s", "x");', 0];
        yield 'vfprintf takes the stream first' => ['direct', '<?php vfprintf(STDERR, "%s", $a);', 1];
        yield 'a namespaced fputs' => ['direct', '<?php \\fputs(\\STDERR, "x");', 1];

        // AND STDERR NOT IN FIRST POSITION IS STILL INDIRECT, which is what
        // stops the widened alphabet from swallowing channel 2 whole: it is the
        // POSITION that makes a write, not the function name.
        yield 'STDERR passed to fputs second is indirect' => ['indirect', '<?php fputs($h, STDERR);', 1];

        // AN IMPORT RENAMING ONE OF THE ALIASES IS STILL THE OTHER CHANNEL'S
        // JOB, and it now has the names for it.
        yield 'an import renaming fputs' => ['other', '<?php use function fputs as p;', 1];
        yield 'an import renaming fprintf' => ['other', '<?php use function Ns\\fprintf as p;', 1];
        yield 'a captured handle' => ['indirect', '<?php $this->err = $err ?? \\STDERR;', 1];
        yield 'a defaulted parameter' => ['indirect', '<?php function f($h = STDERR) {}', 1];
        yield 'STDERR in a comment' => ['indirect', "<?php // fwrite(STDERR, 'x');\n\$x = 1;", 0];
        yield 'STDERR in a doc-block' => ['indirect', "<?php /** writes to STDERR */\n\$x = 1;", 0];
        yield 'the word inside a string' => ['indirect', '<?php $x = "STDERR";', 0];
        yield 'an error_log call' => ['error_log', '<?php error_log("x");', 1];
        yield 'a namespaced error_log call' => ['error_log', '<?php \\error_log("x");', 1];
        yield 'error_log named in prose' => ['error_log', "<?php // error_log() is stderr\n\$x = 1;", 0];
        yield 'a prefixed literal' => ['shape', "<?php \$x = 'sugarcrush: nope';", 1];
        yield 'a prefixed interpolation' => ['shape', '<?php $x = "sugarcrush: {$y} nope";', 1];
        yield 'the prefix only in a comment' => ['shape', "<?php // sugarcrush: nope\n\$x = 1;", 0];
        yield 'a warn call' => ['prefixed', '<?php self::warnPermissionConfigOnce("x");', 1];
        // The same adjacency, on the channel that reads the token BEFORE the
        // name rather than the token before the handle. 0 with the comment
        // strip removed.
        yield 'a warn call with a comment before the name' => [
            'prefixed',
            '<?php self::/* c */warnPermissionConfigOnce("x");',
            1,
        ];
        yield 'all three entry points' => [
            'prefixed',
            '<?php self::warnPermissionConfig("a"); self::warnPermissionConfigOnce("b"); '
                . 'self::warnPermissionConfigInTranscript("c");',
            3,
        ];
        yield 'one entry point out of three' => [
            'prefixed:warnPermissionConfigOnce',
            '<?php self::warnPermissionConfig("a"); self::warnPermissionConfigOnce("b"); '
                . 'self::warnPermissionConfigInTranscript("c");',
            1,
        ];
        yield 'the declaration is not a call' => [
            'prefixed',
            '<?php private static function warnPermissionConfig(string $m): void {}',
            0,
        ];
        yield 'a {@see} reference is not a call' => [
            'prefixed',
            "<?php /** {@see warnPermissionConfigOnce()} */\n\$x = 1;",
            0,
        ];
        yield 'a first-class callable is not a call' => [
            'prefixed',
            '<?php $f = self::warnPermissionConfigOnce(...);',
            0,
        ];
        yield 'an unscoped same-named function is not one of ours' => [
            'prefixed',
            '<?php warnPermissionConfigOnce("x");',
            0,
        ];
        yield 'a runtime-notice seam call' => ['runtime_notice', '<?php RuntimeNoticeSink::warn("x");', 1];
        yield 'a qualified runtime-notice seam call' => [
            'runtime_notice',
            '<?php Diagnostics\\RuntimeNoticeSink::warn("x");',
            1,
        ];
        yield 'a fully-qualified runtime-notice seam call' => [
            'runtime_notice',
            '<?php \\SugarCraft\\Crush\\Diagnostics\\RuntimeNoticeSink::warn("x");',
            1,
        ];
        // And on channel 6, which reads TWO tokens back — the operator and then
        // the class — so a comment in either position blinds it without the
        // strip. 0 with the comment strip removed.
        yield 'a seam call with a comment before the name' => [
            'runtime_notice',
            '<?php RuntimeNoticeSink::/* c */warn("x");',
            1,
        ];
        yield 'some other class\'s warn() is not the seam' => ['runtime_notice', '<?php Logger::warn("x");', 0];
        yield 'an instance warn() is not the seam' => ['runtime_notice', '<?php $this->warn("x");', 0];
        // THE ROW THAT ACTUALLY PINS THE `::` REQUIREMENT, and the row above
        // does not. `$this->warn(` is rejected one clause later by the class
        // token check — `$this` is `T_VARIABLE` — so it scans as 0 with the
        // operator clause blinded too, and passes either way. MEASURED, PHP
        // 8.3.6: this row is 0 at head and 1 with
        // {@see StderrEmitterCensusTest::isRuntimeNoticeSinkCall()}'s
        // `T_DOUBLE_COLON` test replaced by `false`. Token-level, not valid
        // PHP semantics, which is all `token_get_all()` needs.
        yield 'the seam class reached with an instance operator is not the seam' => [
            'runtime_notice',
            '<?php RuntimeNoticeSink->warn("x");',
            0,
        ];
        yield 'the seam named in a doc-block is not a call' => [
            'runtime_notice',
            "<?php /** {@see RuntimeNoticeSink::warn()} */\n\$x = 1;",
            0,
        ];
        yield 'a seam first-class callable is not a call' => [
            'runtime_notice',
            '<?php $f = RuntimeNoticeSink::warn(...);',
            0,
        ];
        yield 'the seam record() is not the warn funnel' => [
            'runtime_notice',
            '<?php RuntimeNoticeSink::record("x");',
            0,
        ];
        yield 'nor on the runtime-notice channel' => ['runtime_notice', '<?php echo 1;', 0];
        yield 'a php:// stderr stream' => ['other', '<?php fopen("php://stderr", "w");', 1];
        yield 'a php:// output stream' => ['other', '<?php file_put_contents("php://output", $x);', 1];
        yield 'error_log with a destination' => ['other', '<?php error_log("x", 3, "/tmp/f");', 1];
        yield 'plain error_log is not an other channel' => ['other', '<?php error_log("x");', 0];
        // E195. Both of these RUN and both really write fd 2 (MEASURED, PHP
        // 8.3.6), which is what makes them a channel rather than a lexer
        // curiosity.
        yield 'a const import renaming STDERR' => ['other', '<?php use const STDERR as E; fwrite(E, "x");', 1];
        yield 'a function import renaming fwrite' => [
            'other',
            '<?php use function fwrite as w; w(STDERR, "x");',
            1,
        ];
        yield 'a function import renaming error_log' => [
            'other',
            '<?php use function error_log as elog; elog("x");',
            1,
        ];
        yield 'a const import of something else is not a channel' => [
            'other',
            '<?php use const PHP_EOL as NL; echo NL;',
            0,
        ];
        yield 'a plain class import is not a const or function import' => [
            'other',
            '<?php use A\\B\\STDERR; echo 1;',
            0,
        ];
        yield 'a closure use clause is not an import' => [
            'other',
            '<?php $f = function () use ($fwrite) { return $fwrite; };',
            0,
        ];
        yield 'a trait use inside a class body is not an import' => [
            'other',
            '<?php trait STDERR {} class A { use STDERR; }',
            0,
        ];
        yield 'error_log with a type but no destination' => ['other', '<?php error_log("x", 0);', 0];

        // E161's three shapes, as the `other` channel sees them. Each is a
        // REAL destination-form call that {@see argumentCount()} counted as one
        // argument before the array-token openers were recognised, so each row
        // read 0 — an absence census silently missing a channel that is there.
        // All three are valid PHP 8.3.6 and not merely lexable; the assertion
        // in {@see testEveryOtherChannelFixtureIsRealPhp()} is what says so.
        yield 'a destination form whose message interpolates' => [
            'other',
            '<?php error_log("x{$y}", 3, "/tmp/f");',
            1,
        ];
        yield 'a destination form using the dollar-brace interpolation' => [
            'other',
            '<?php error_log("x${y}", 3, "/tmp/f");',
            1,
        ];
        yield 'a destination form carrying an attribute' => [
            'other',
            '<?php error_log((string) new #[\\AllowDynamicProperties] class {}, 3, "/tmp/f");',
            1,
        ];
        yield 'an interpolating call with no destination is still not one' => [
            'other',
            '<?php error_log("x{$y}");',
            0,
        ];
        yield 'match arms do not disturb the depth walk' => [
            'other',
            '<?php error_log(match ($n) { 1 => "a", default => "b" }, 3, "/tmp/f");',
            1,
        ];
        // NULLSAFE DISPATCH, on the channel whose scanner reads an operator.
        // Not reachable through the real family (all `private static`), which
        // is exactly why the alphabet needed a fixture rather than an argument.
        yield 'a nullsafe-dispatched prefixed warning is still a call site' => [
            'prefixed',
            '<?php $b?->warnPermissionConfig("x");',
            1,
        ];
        yield 'a nullsafe first-class callable is still not a call' => [
            'prefixed',
            '<?php $b?->warnPermissionConfig(...);',
            0,
        ];
        yield 'a source with nothing at all' => ['direct', '<?php echo 1;', 0];
        yield 'and nothing on the other channels' => ['error_log', '<?php echo 1;', 0];
        yield 'nor on channel five' => ['prefixed', '<?php echo 1;', 0];
        yield 'nor on the unscanned-channel scan' => ['other', '<?php echo 1;', 0];
    }

    /** @dataProvider scannerCases */
    public function testEachScannerAnswersCorrectlyOnASourceWhoseAnswerIsKnown(
        string $channel,
        string $source,
        int $expected,
    ): void {
        self::assertSame($expected, self::scan($channel, $source));
    }

    /**
     * A channel name this file cannot answer for is a FAILURE, not a zero.
     *
     * `scan('prefixed:noSuchThing', …)` used to be indistinguishable from a
     * genuine zero, which is a hole shaped exactly like the next typo: a
     * decomposition test asking for an entry point that had been renamed would
     * have reported 0 sites and passed.
     */
    public function testTheScannerRedsOnAnEntryPointItCannotAnswerFor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('noSuchEntryPoint');

        self::scan('prefixed:noSuchEntryPoint', '<?php echo 1;');
    }

    /**
     * Every channel spelling {@see scannerCases()} uses carries at least one
     * row a DEAD {@see scan()} would red.
     *
     * THIS IS THE GENERATOR FOR A SENTENCE THAT USED TO BE A NUMERAL. The
     * provider's doc-block licenses its bare `<?php echo 1;` controls to have
     * no positive component of their own, and the licence rests entirely on
     * "a dead scan() reds this provider anyway". That was written down as a
     * count of rows, the count was wrong the day it was committed, and a count
     * is the wrong shape for the claim regardless: what has to be true is not
     * that some total of rows reds, it is that NO CHANNEL is left where every
     * row expects 0 — because on such a channel a dead scanner is green, and
     * that is exactly the failure E228 is about.
     *
     * PER CHANNEL AND NOT IN TOTAL, which is the strengthening. A provider
     * could hold a hundred non-zero rows on `direct` and still have every
     * `shape` row expecting 0; the total would look reassuring and the `shape`
     * scanner would be untested against death. The per-channel form cannot be
     * satisfied that way, and it does not move when a row is added.
     *
     * `prefixed:<entryPoint>` IS FOLDED ONTO `prefixed` deliberately. It is not
     * a separate channel, it is a filtered view of that one — see
     * {@see scan()}, which parses the suffix and then runs the `prefixed`
     * branch — so requiring each suffix spelling to carry its own non-zero row
     * would red on a legitimate negative row for one entry point. The
     * suffixed form has its own dedicated guard for the failure that matters
     * to it, {@see testTheScannerRedsOnAnEntryPointItCannotAnswerFor()}.
     *
     * ITS OWN KNOWN-POSITIVE CONTROL, and it needs one for the reason rule 15
     * exists: the per-channel assertion is `assertArrayHasKey`, and a bucketer
     * that put every row in the non-zero bucket would satisfy it on every
     * channel while reading nothing. So this first asserts that both buckets
     * are populated AND that two rows whose expected values are known landed
     * on the correct side of the split.
     */
    public function testEveryChannelInThisProviderHasARowADeadScanWouldRed(): void
    {
        $withANonZeroRow = [];
        $zeroOnly = [];
        foreach (self::scannerCases() as $label => [$channel, , $expected]) {
            $base = str_starts_with($channel, 'prefixed:') ? 'prefixed' : $channel;
            if ($expected === 0) {
                $zeroOnly[$base][] = $label;

                continue;
            }

            $withANonZeroRow[$base][] = $label;
        }

        // THE CONTROL. Two rows this file spells out a few hundred lines up,
        // one of each kind, asserted to be on the side the split should have
        // put them. A bucketer that ignored $expected passes the loop below
        // and fails here.
        self::assertContains(
            'a direct write',
            $withANonZeroRow['direct'] ?? [],
            'the split no longer reads the expected value: a row expecting 1 is not in the non-zero bucket',
        );
        self::assertContains(
            'a direct write is not indirect',
            $zeroOnly['indirect'] ?? [],
            'the split no longer reads the expected value: a row expecting 0 is not in the zero bucket',
        );

        foreach (array_keys($zeroOnly + $withANonZeroRow) as $channel) {
            self::assertArrayHasKey(
                $channel,
                $withANonZeroRow,
                "every row on channel '{$channel}' expects 0, so a dead scan() returning 0 would leave all "
                    . 'of them green and nothing here would notice. Add a row on this channel whose expected '
                    . 'value only a live scanner can produce; do not rely on another channel\'s rows to '
                    . 'cover it.',
            );
        }
    }

    /**
     * A file the roster names but the tree does not have is a FAILURE.
     *
     * WHAT THIS SAID, and it contradicted itself inside one sentence: "deleting
     * `src/Cli/Subcommands.php` would take its two sites out of the scan AND
     * out of nothing else — the roster would simply stop mentioning a file …
     * This asserts the direction the maps cannot."
     * WHAT IS TRUE NOW: the rosters are `const`s. Deleting the file drops the
     * key from {@see census()} and leaves it in the roster, so
     * `assertSame(<roster>, <census>)` reds on its own — the clause immediately
     * after the dash says exactly that, and the claim before the dash is the
     * false one. This test is REDUNDANT with the roster assertions, in
     * both directions — the numeral that stood where "the" now does said four
     * and the rosters have since grown past it, which is the same rot this
     * file is about.
     * WHY IT STILL EARNS ITS PLACE: the message. A roster assertion failing on
     * a deleted file prints an array diff and leaves the reader to notice that
     * one key is a path that no longer exists; this prints the path and says
     * so. It is kept for the diagnosis, not for the coverage, and calling it
     * coverage is what produced the sentence above.
     */
    public function testEveryFileTheRostersNameExists(): void
    {
        $named = array_unique(array_merge(
            array_keys(self::DIRECT_SITES),
            array_keys(self::INDIRECT_SITES),
            array_keys(self::ERROR_LOG_SITES),
            array_keys(self::MESSAGE_SHAPES),
            array_keys(self::PREFIXED_WRITER_SITES),
        ));
        self::assertNotSame([], $named, 'the rosters are empty, so every assertion here is vacuous');

        foreach ($named as $file) {
            self::assertFileExists(\dirname(__DIR__, 2) . '/' . $file, "{$file} is named by a roster but is gone");
        }
    }

    /**
     * Every cardinality this file states in prose, matched to the roster that
     * generates it.
     *
     * WHY THIS EXISTS AND WHY IT IS EMBARRASSING. This file's whole subject is
     * that a count nobody derives goes stale, and it shipped six of its own —
     * one per channel, plus the two in the channel-5 paragraph — hand-typed
     * from numbers the tests below derive, in a round whose sibling census had
     * just built the anchoring machinery for exactly this. All six were correct
     * the day they were written, which is the only thing that is ever true of
     * them.
     *
     * MATCHED AGAINST {@see flattened()} AND NOT THE RAW BYTES, for the reason
     * the sibling census gives: a doc-block wraps at 80 columns with ` * ` on
     * every continuation, so a sentence is never those bytes in a row. Two of
     * the six cross a wrap. The flattener's own known-positive control is the
     * first assertion in the test, because a flattener returning `''` would
     * make every anchor fail open into a zero match — which this treats as a
     * failure, not a skip.
     *
     * @return list<array{anchor: string, expected: int, what: string}>
     */
    private static function selfCountAnchors(): array
    {
        return [
            [
                'anchor' => '/`fwrite\(STDERR, …\)` — ([a-z]+) sites/',
                'expected' => array_sum(self::DIRECT_SITES),
                'what' => 'channel 1, the fwrite(STDERR, …) total',
            ],
            [
                'anchor' => '/written through later — ([A-Z]+) site,/',
                'expected' => array_sum(self::INDIRECT_SITES),
                'what' => 'channel 2, the captured-handle total',
            ],
            [
                'anchor' => '/and which writes ([A-Z]+) distinct/',
                'expected' => self::MESSAGE_SHAPES['src/Cli/HeadlessPermissionPrompt.php'],
                'what' => "channel 4's credit for HeadlessPermissionPrompt",
            ],
            [
                'anchor' => '/`error_log\(…\)` — ([A-Z-]+) sites across/',
                'expected' => array_sum(self::ERROR_LOG_SITES),
                'what' => 'channel 3, the error_log() total',
            ],
            [
                'anchor' => '/sites across ([a-z]+) files\. MEASURED/',
                'expected' => \count(self::ERROR_LOG_SITES),
                'what' => 'channel 3, the number of files it spans',
            ],
            [
                'anchor' => '/RuntimeNoticeSink::warn\(\)} — ([A-Z]+) of them, in [A-Z]+ files/',
                'expected' => array_sum(self::RUNTIME_NOTICE_SITES),
                'what' => 'channel 6, the RuntimeNoticeSink::warn() total',
            ],
            [
                'anchor' => '/of them, in ([A-Z]+) files\. THE SECOND EMITTER/',
                'expected' => \count(self::RUNTIME_NOTICE_SITES),
                'what' => 'channel 6, the number of files it spans',
            ],
            [
                'anchor' => '/it — ([A-Z-]+) call sites in/',
                'expected' => self::PREFIXED_WRITER_SITES['src/Cli/Bootstrap.php'],
                'what' => "channel 5's count for Bootstrap",
            ],
            [
                'anchor' => '/against a channel-4 credit of ([a-z]+) for that/',
                'expected' => self::MESSAGE_SHAPES['src/Cli/Bootstrap.php'],
                'what' => "channel 4's credit for Bootstrap, the blind one",
            ],
        ];
    }

    public function testEveryCardinalityThisFileStatesInProseHasItsGenerator(): void
    {
        // KNOWN-POSITIVE CONTROL FIRST. Assembled rather than written whole:
        // this test scans its own file, so a fixture spelling an anchor phrase
        // contiguously here becomes a second match for it and reds the
        // uniqueness assertion on the scaffolding. The sibling census measured
        // that happening; so did round 45's first attempt at its own anchors.
        $sentence = 'written through later — ' . 'NINE' . ' site,';
        $fixture = "    /**\n     * written through later —\n     * " . 'NINE' . " site, it says.\n     */\n";
        self::assertSame(
            ' /** ' . $sentence . ' it says. */ ',
            self::flattened($fixture),
            'flattened() no longer joins a wrapped doc-block sentence; every anchor below would fail open',
        );

        $own = self::flattened((string) file_get_contents(__FILE__));

        foreach (self::selfCountAnchors() as $site) {
            $matched = preg_match_all($site['anchor'], $own, $all, PREG_SET_ORDER);
            self::assertSame(
                1,
                $matched,
                "{$site['anchor']} matches this file {$matched} times, not once. Zero means the sentence "
                    . "stating {$site['what']} was rewritten past its anchor — re-point it, do not drop the "
                    . 'row. More than one means two sentences now state it through one anchor, and only one '
                    . 'of them is being checked.',
            );

            $word = strtolower($all[0][1]);
            self::assertArrayHasKey(
                $word,
                self::NUMBER_WORDS,
                "{$site['anchor']} captured \"{$all[0][1]}\", which is not a number word this guard can "
                    . 'read. Widen self::NUMBER_WORDS or re-point the anchor; do not leave it unparsed.',
            );
            self::assertSame(
                $site['expected'],
                self::NUMBER_WORDS[$word],
                "the prose for {$site['what']} says \"{$word}\" but its roster generates {$site['expected']}",
            );
        }
    }

    // ── the scanners ─────────────────────────────────────────────────────

    /**
     * The failure text for a roster assertion, with the per-file delta spelled
     * out rather than left to PHPUnit's array diff.
     *
     * WRITTEN FOR A READER WHO DID NOT CAUSE THE FAILURE. This census counts
     * what OTHER lanes write: it pins exact per-file cardinalities across
     * `src/`, so a lane that adds or removes one stderr write reds it at merge
     * — which is the design, and round 48 is the round that proved an exact
     * cardinality is what makes a shared census safe to merge. The person who
     * sees the red is therefore usually the person MERGING five branches, not
     * the person who moved the number. An array diff makes them read two maps
     * and spot the differing key; this names the file, what the roster claims
     * and what the scan actually found, so the resolution is a one-line edit
     * with nothing re-derived.
     *
     * DERIVED, NEVER WRITTEN DOWN (rule 18): both sides come from the same call
     * the assertion is making, so no cardinality here can go stale.
     *
     * ITS OWN CORRECTNESS IS TESTED, and it has to be, because a failure
     * message's generator is the one piece of a green suite that never runs. A
     * `message()` returning `''` would be invisible for as long as the census
     * stayed green and would then be missing at exactly the moment it is
     * needed. {@see testTheRosterFailureMessageNamesEveryFileThatMovedAndBothCounts()}
     * runs it on known input.
     *
     * @param array<string, int> $expected the roster
     * @param array<string, int> $actual   what the scan found
     */
    private static function message(string $channel, array $expected = [], array $actual = []): string
    {
        $moved = [];
        foreach (array_keys($expected + $actual) as $file) {
            $was = $expected[$file] ?? null;
            $now = $actual[$file] ?? null;
            if ($was === $now) {
                continue;
            }

            $moved[] = sprintf(
                '  %s: the roster says %s, the scan counts %s',
                $file,
                $was === null ? 'nothing' : (string) $was,
                $now === null ? 'nothing' : (string) $now,
            );
        }
        sort($moved);

        $delta = $moved === [] ? '' : "\n\nWHAT MOVED, per file:\n" . implode("\n", $moved) . "\n";

        return "The roster of {$channel} sites in src/ and bin/ moved. That is not automatically wrong — but "
            . 'a new stderr write in this application needs a decision: does it belong on '
            . 'Bootstrap::warnPermissionConfigInTranscript()\'s transcript seam (it names something the '
            . 'session can no longer DO), on stderr alone (the user\'s config is malformed but the session '
            . 'is intact), or nowhere (it is debug output). Make the decision, then update the roster.'
            . $delta;
    }

    /**
     * {@see message()} names every file whose count moved, in both directions,
     * and stays quiet about the ones that did not.
     *
     * THE QUIET HALF IS AN ABSENCE ASSERTION and would prove nothing on its own
     * — a dead `message()` mentions no file at all, so "it does not mention the
     * unchanged one" passes (E228, rule 15). Its positive component is the
     * three assertions above it, on the same call, in this test.
     */
    public function testTheRosterFailureMessageNamesEveryFileThatMovedAndBothCounts(): void
    {
        $message = self::message(
            'error_log()',
            ['src/Bumped.php' => 2, 'src/Gone.php' => 1, 'src/Unchanged.php' => 3],
            ['src/Bumped.php' => 5, 'src/Arrived.php' => 1, 'src/Unchanged.php' => 3],
        );

        self::assertStringContainsString(
            'src/Bumped.php: the roster says 2, the scan counts 5',
            $message,
            'a file whose count moved is no longer named with both numbers',
        );
        self::assertStringContainsString(
            'src/Gone.php: the roster says 1, the scan counts nothing',
            $message,
            'a file the roster names and the scan no longer finds is not reported as a removal',
        );
        self::assertStringContainsString(
            'src/Arrived.php: the roster says nothing, the scan counts 1',
            $message,
            'a file the scan found and no roster names is not reported as an arrival — which is the '
                . 'direction a sibling lane adding an emitter fails in',
        );
        self::assertStringNotContainsString(
            'src/Unchanged.php',
            $message,
            'the delta lists a file that did not move, so a real one-file change would arrive buried',
        );
    }

    /** @return array<string, int> file => count, files with zero omitted */
    private static function census(string $channel): array
    {
        $out = [];
        foreach (self::sources() as $relative => $absolute) {
            $n = self::scan($channel, (string) file_get_contents($absolute));
            if ($n > 0) {
                $out[$relative] = $n;
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> relative path => absolute path */
    private static function sources(): array
    {
        $root = \dirname(__DIR__, 2);
        $out = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[ltrim(str_replace($root, '', $file->getPathname()), '/')] = $file->getPathname();
            }
        }
        $out['bin/sugarcrush'] = $root . '/bin/sugarcrush';

        return $out;
    }

    /**
     * How many sites of `$channel` are in `$source`.
     *
     * TOKENS AND NOT TEXT, for the reason the sibling seam census gives at
     * length: `grep` counts the doc-blocks, and this application's doc-blocks
     * discuss `fwrite(STDERR, …)` and `error_log()` more often than they call
     * them — MEASURED on `src/Cli/Bootstrap.php`, PHP 8.3.6, `grep -c
     * 'fwrite(STDERR'` gives 4 where the token scan gives 2, and `grep -c
     * 'error_log('` gives 10 where the scan gives 1.
     */
    private static function scan(string $channel, string $source): int
    {
        $significant = self::significantTokens($source);

        $warnFamily = [
            'warnPermissionConfig' => true,
            'warnPermissionConfigOnce' => true,
            'warnPermissionConfigInTranscript' => true,
        ];
        $onlyEntryPoint = str_starts_with($channel, 'prefixed:')
            ? substr($channel, \strlen('prefixed:'))
            : null;
        if ($onlyEntryPoint !== null && !isset($warnFamily[$onlyEntryPoint])) {
            throw new \RuntimeException("no warnPermissionConfig entry point named {$onlyEntryPoint}()");
        }

        $count = 0;
        foreach ($significant as $i => $token) {
            $name = self::callableName($token);

            if ($channel === 'prefixed' || $onlyEntryPoint !== null) {
                if ($name === null || !isset($warnFamily[$name])) {
                    continue;
                }
                if ($onlyEntryPoint !== null && $name !== $onlyEntryPoint) {
                    continue;
                }
                if (self::isSelfScopedCall($significant, $i)) {
                    $count++;
                }

                continue;
            }

            if ($channel === 'runtime_notice') {
                if ($name === 'warn' && self::isRuntimeNoticeSinkCall($significant, $i)) {
                    $count++;
                }

                continue;
            }

            if ($channel === 'other') {
                if (
                    \is_array($token)
                    && \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('#php://(?:stderr|output)#i', $token[1]) === 1
                ) {
                    $count++;
                }

                // AN IMPORT THAT RENAMES ONE OF THE NAMES THE STDERR CHANNELS
                // KEY ON — see self::ALIASABLE_STDERR_NAMES. Detected at the
                // `use` and not at the call: the call is the thing that has
                // become unrecognisable, so there is nothing there left to
                // match on.
                //
                // `T_USE` FOLLOWED BY `T_CONST` OR `T_FUNCTION` IS THE WHOLE
                // DISAMBIGUATION, and it is why this clause does not need the
                // class-body tracking a general `use`-resolver would. The other
                // two things spelled `use` cannot take that shape: a closure's
                // `use` is followed by `(`, and a trait `use` is followed by a
                // name. A plain `use A\B\C;` class import is not scanned here
                // either — it cannot rename a function or a constant.
                if (
                    \is_array($token)
                    && $token[0] === T_USE
                    && self::importsAnAliasableStderrName($significant, $i)
                ) {
                    $count++;
                }

                // error_log()'s THREE-argument form: the destination argument
                // takes it somewhere channel 3's `ini_get('error_log')` is ''
                // reasoning does not describe, so it is a separate channel and
                // not a third error_log site.
                if ($name === 'error_log' && ($significant[$i + 1] ?? null) === '(') {
                    if (self::argumentCount($significant, $i + 1) >= 3) {
                        $count++;
                    }
                }

                continue;
            }

            if ($channel === 'shape') {
                if (
                    \is_array($token)
                    && \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && str_contains($token[1], 'sugarcrush:')
                ) {
                    $count++;
                }

                continue;
            }

            if ($channel === 'error_log') {
                if ($name === 'error_log' && ($significant[$i + 1] ?? null) === '(') {
                    $count++;
                }

                continue;
            }

            if ($name !== 'STDERR') {
                continue;
            }

            $isWrittenThrough = ($significant[$i - 1] ?? null) === '('
                && \in_array(
                    self::callableName($significant[$i - 2] ?? null),
                    self::STREAM_WRITE_FUNCTIONS,
                    true,
                );

            if ($channel === 'direct' ? $isWrittenThrough : !$isWrittenThrough) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether the `warn` token at `$i` is `RuntimeNoticeSink::warn(` — in any of
     * the three spellings PHP lexes differently — rather than a declaration or
     * a first-class callable.
     *
     * THE CLASS TOKEN IS REQUIRED AND THAT IS THE POINT. `warn` is an ordinary
     * method name: `src/Agents/ForeignAgentPresetRegistry.php` calls
     * `$this->warn(` three times (MEASURED, PHP 8.3.6) and none of those is a
     * write of this shape. A scanner keyed on the method name alone would
     * credit channel 6 with them.
     *
     * THREE SPELLINGS, because PHP 8.3.6 lexes them as three different tokens
     * and an alphabet that knows only the first is how a census goes blind:
     * `RuntimeNoticeSink` is `T_STRING`, `Diagnostics\RuntimeNoticeSink` is
     * `T_NAME_QUALIFIED`, `\SugarCraft\…\RuntimeNoticeSink` is
     * `T_NAME_FULLY_QUALIFIED`. All three are matched on their last segment.
     * An ALIASED import is not matched, and is named as this channel's known
     * blind spot in the class doc-block.
     *
     * @param list<array{0: int, 1: string}|string> $significant
     */
    /**
     * Every CALL of a method named `$method` in `$source`, WHOEVER THE RECEIVER
     * IS — the deliberate complement of {@see isRuntimeNoticeSinkCall()}.
     *
     * WHY A SECOND SCANNER RATHER THAN A WIDER CHANNEL 6. Channel 6 must know
     * the receiver: `warn` is an ordinary method name, and
     * `src/Agents/ForeignAgentPresetRegistry.php` calls `$this->warn(` three
     * times (MEASURED, PHP 8.3.6) with no relation to this seam. But requiring
     * the receiver is exactly what makes channel 6 blind to `self::warn(` and
     * `static::warn(` — and INSIDE `RuntimeNoticeSink` those are not somebody
     * else's `warn`, they are the one call the disjointness test says must not
     * exist. So the receiver requirement is dropped and the SCOPE is narrowed
     * instead: this is asked of one file, whose every call of `warn` is the
     * sink's own.
     *
     * WHAT IT MATCHES, on PHP 8.3.6: a `T_STRING` equal to `$method`, preceded
     * by `::`, `->` or `?->`, followed by `(`, and not followed by `(...)`.
     * That covers `self::`, `static::`, `$this->`, `X::`, an aliased import and
     * a variable class name, and excludes the DECLARATION (`function warn(`
     * has `T_FUNCTION` before the name, not an operator) and a first-class
     * callable. WHAT IT STILL CANNOT SEE: a name reached as a string, e.g.
     * `call_user_func([self::class, 'warn'], …)` or `$m = 'warn'; self::$m()`.
     * Named rather than left to be discovered, for the reason the class
     * doc-block's channel-6 bullet gives.
     */
    private static function methodCallSites(string $method, string $source, ?array $only = null): int
    {
        $significant = self::significantTokens($source);
        $operators = $only ?? self::WARN_CALL_OPERATORS;

        $count = 0;
        foreach ($significant as $i => $token) {
            if (self::callableName($token) !== $method) {
                continue;
            }

            $operator = $significant[$i - 1] ?? null;
            if (!\is_array($operator) || !\in_array($operator[0], $operators, true)) {
                continue;
            }

            if (($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            $after = $significant[$i + 2] ?? null;
            if (\is_array($after) && $after[0] === T_ELLIPSIS) {
                continue;
            }

            $count++;
        }

        return $count;
    }
    /**
     * How many times `$class` is CONSTRUCTED in `$source`: the `new` shapes,
     * plus this project's canonical `::new()` factory.
     *
     * TOKENS AND NOT TEXT, for the reason {@see scan()} gives at length. It
     * matters more here than anywhere else in this file, because the class this
     * guard is pointed at documents its own constructor: a `grep`-based version
     * would red on the prose explaining why it is green.
     *
     * MATCHED ON THE LAST NAMESPACE SEGMENT, so `new WorktreeManager`,
     * `new \SugarCraft\Crush\Agents\WorktreeManager` and
     * `Agents\WorktreeManager::new(` each count once. The cost of that
     * shortcut is that a DIFFERENT class of the same short name would count
     * too; this package has one of each, and a false POSITIVE here reds a
     * dormancy claim rather than hiding a live one, which is the direction an
     * over-eager guard should fail in.
     *
     * WHY THE FACTORY ARM KEYS ON `T_NEW` AND NOT ON A NAME. In `Foo::new(`,
     * PHP 8.3.6 lexes `new` as `T_NEW` and not as `T_STRING` — MEASURED on this
     * box — so {@see callableName()} returns `null` for it and
     * {@see methodCallSites('new', …)} cannot see the factory at all. The
     * factory arm therefore matches a `T_NEW` PRECEDED by `T_DOUBLE_COLON` and
     * reads the class name from the token before that operator. The same rule
     * is what excludes the factory's own DECLARATION, `public static function
     * new()`, whose `T_NEW` is preceded by `T_FUNCTION`.
     *
     * NO `(` IS REQUIRED ON THE CONSTRUCTOR ARM, because `new Foo;` is legal
     * PHP and constructs exactly as much as `new Foo()` does. The factory arm
     * does require one, since `Foo::new` without a call is a syntax error and a
     * bare `T_NEW` after `::` in any other position is not a construction.
     *
     * WHAT IT REFUSES RATHER THAN MISSES: `new class … extends <target>`, which
     * constructs a subclass and so defeats a dormancy claim just as thoroughly,
     * but which this walk cannot attribute. It throws rather than answering
     * zero — a guard that quietly ignores the unparseable has a hole shaped
     * exactly like the next defect. There are no `new class` sites in `src/` or
     * `bin/` today (MEASURED, PHP 8.3.6), so the arm is a tripwire and not a
     * live path. `new $variable` it can neither see nor detect; that hole is
     * named and measured in {@see
     * testTheWorktreeManagerSeamSitesAreDormantBecauseNothingConstructsIt()}.
     */
    private static function constructionSites(string $class, string $source): int
    {
        $significant = self::significantTokens($source);
        $count = 0;

        foreach ($significant as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $previous = $significant[$i - 1] ?? null;
            if (\is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                if (($significant[$i + 1] ?? null) !== '(') {
                    continue;
                }
                if (self::constructedName($significant[$i - 2] ?? null) === $class) {
                    $count++;
                }

                continue;
            }

            $named = $significant[$i + 1] ?? null;
            if (\is_array($named) && $named[0] === T_CLASS) {
                self::refuseUnattributableAnonymousClass($significant, $i + 1, $class);

                continue;
            }

            if (self::constructedName($named) === $class) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The short class name a `new`-adjacent token denotes, or `null` if the
     * token names no class at all.
     *
     * Deliberately NOT {@see callableName()}, which accepts only `T_STRING` and
     * `T_NAME_FULLY_QUALIFIED` because the calls it serves are function calls.
     * A class reference is also spelled `T_NAME_QUALIFIED` — `Agents\Worktree`
     * — and two of the four constructions this file's own fixture pins arrive
     * in exactly that shape.
     */
    private static function constructedName(array|string|null $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }
        if (!\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $segments = explode('\\', $token[1]);
        $short = array_pop($segments);

        return $short === '' ? null : $short;
    }

    /**
     * Throw if the anonymous class whose `T_CLASS` sits at `$classToken` names
     * `$class` in its header, since {@see constructionSites()} cannot decide
     * whether that is a construction of a subclass or an unrelated mention.
     *
     * Walks to the `{` that opens the class body, tracking parenthesis depth so
     * that a constructor argument list is skipped rather than read as a header.
     * A header that never opens a body is itself unparseable and also throws.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant
     */
    private static function refuseUnattributableAnonymousClass(
        array $significant,
        int $classToken,
        string $class,
    ): void {
        $depth = 0;
        $total = \count($significant);

        for ($j = $classToken + 1; $j < $total; $j++) {
            $token = $significant[$j];

            if ($token === '(') {
                $depth++;

                continue;
            }
            if ($token === ')') {
                $depth--;

                continue;
            }
            if ($depth === 0 && $token === '{') {
                return;
            }
            if ($depth === 0 && self::constructedName($token) === $class) {
                throw new \RuntimeException(
                    "constructionSites() found `new class` naming {$class} in its header and cannot "
                        . 'attribute it. Teach it that shape rather than letting it answer zero.',
                );
            }
        }

        throw new \RuntimeException(
            'constructionSites() found a `new class` header that never opens a body; the token walk '
                . 'has lost sync with the source it is reading.',
        );
    }

    /**
     * Whether the `use` at `$i` is a `use function`/`use const` import naming
     * one of {@see ALIASABLE_STDERR_NAMES}.
     *
     * Every name token up to the terminating `;` is checked, aliases included:
     * an import whose ALIAS is `fwrite` is as capable of confusing a reader —
     * and a future scanner — as one whose target is.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant
     */
    private static function importsAnAliasableStderrName(array $significant, int $i): bool
    {
        $kind = $significant[$i + 1] ?? null;
        if (!\is_array($kind) || !\in_array($kind[0], [T_CONST, T_FUNCTION], true)) {
            return false;
        }

        for ($j = $i + 2; $j < \count($significant); $j++) {
            $token = $significant[$j];
            if ($token === ';') {
                return false;
            }

            $name = self::callableName($token);
            if ($name === null && \is_array($token) && $token[0] === T_NAME_QUALIFIED) {
                $name = $token[1];
            }
            if ($name === null) {
                continue;
            }

            $segments = explode('\\', trim($name, '\\'));
            if (\in_array(end($segments), self::ALIASABLE_STDERR_NAMES, true)) {
                return true;
            }
        }

        // A `use` that never terminates is a source this scanner cannot answer
        // for, and a quiet `false` is the hole rule 14 names.
        throw new \RuntimeException(
            'a `use function`/`use const` import opened at token ' . $i . ' never reaches its `;`; '
                . 'the unscanned-channel scan cannot answer for this source',
        );
    }

    private static function isRuntimeNoticeSinkCall(array $significant, int $i): bool
    {
        $operator = $significant[$i - 1] ?? null;
        if (!\is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
            return false;
        }

        $class = $significant[$i - 2] ?? null;
        if (
            !\is_array($class)
            || !\in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
        ) {
            return false;
        }

        $segments = explode('\\', trim($class[1], '\\'));
        if (end($segments) !== 'RuntimeNoticeSink') {
            return false;
        }

        if (($significant[$i + 1] ?? null) !== '(') {
            return false;
        }

        // `RuntimeNoticeSink::warn(...)` is a first-class callable, not a call.
        $after = $significant[$i + 2] ?? null;

        return !(\is_array($after) && $after[0] === T_ELLIPSIS);
    }

    /**
     * Whether the token at `$i` is a scoped call — `self::name(`, `$x->name(`
     * — rather than a declaration or a first-class callable.
     *
     * ALL THREE CLAUSES ARE LOAD-BEARING, and the sibling seam census measured
     * why: requiring the scope token is what excludes `function name(`, and
     * excluding `(...)` is what stops a first-class callable being counted as a
     * call — PHP 8.3.6 lexes `self::f(...)` as T_STRING, `(`, T_ELLIPSIS, `)`,
     * so the paren requirement alone does not exclude it. Round 44's review
     * planted one in that file and got a fabricated extra site out of the scan.
     *
     * @param list<array{0: int, 1: string}|string> $significant
     */
    private static function isSelfScopedCall(array $significant, int $i): bool
    {
        // T_NULLSAFE_OBJECT_OPERATOR is `?->`. It cannot occur on this channel
        // today — the whole `warnPermissionConfig*` family is `private static`
        // and reached through `self::` — so widening the list moves no count,
        // which the pinned channel-5 roster is what confirms. It is here
        // because "the operator the current call sites happen to use" is how an
        // alphabet gets written too narrow, and this is the third copy of this
        // three-token list in the lane to be caught that way.
        $previous = $significant[$i - 1] ?? null;
        if (
            !\is_array($previous)
            || !\in_array(
                $previous[0],
                [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR],
                true,
            )
        ) {
            return false;
        }
        if (($significant[$i + 1] ?? null) !== '(') {
            return false;
        }

        $after = $significant[$i + 2] ?? null;

        return !(\is_array($after) && $after[0] === T_ELLIPSIS);
    }

    /**
     * How many top-level arguments the call whose `(` sits at `$open` passes.
     *
     * Depth-tracked, so a comma inside a nested call or array is not an
     * argument of this one. A call with no arguments answers 0.
     *
     * NOT EVERY BRACKET IS A ONE-BYTE TOKEN, and the walk got that wrong
     * (E161). WHAT E161 SAID: the walk "goes negative on a PHP attribute",
     * `#[` opening a bracket the opener list does not see while its `]` closes
     * one, and "no attribute appears inside an `error_log()` call in `src/`
     * today, so this is latent". WHAT IS TRUE NOW, both halves corrected.
     * FIRST, the depth never goes negative: the loop returns the instant depth
     * reaches 0, so the unmatched closer makes it return EARLY at a spurious
     * zero and UNDER-count. SECOND, it is not latent. `#[` is only one of
     * THREE openers PHP 8.3.6 lexes as an array token whose closer it lexes as
     * a plain one-byte string — the other two are `T_CURLY_OPEN` (`{` in
     * `"{$a}"`) and `T_DOLLAR_OPEN_CURLY_BRACES` (`${`), and interpolation is
     * an everyday shape rather than an exotic one. MEASURED by
     * {@see testEveryLiveErrorLogSiteSurvivesTheDepthWalk()}, which derives the
     * figure rather than stating it: live `error_log()` sites in `src/` already
     * carry `{$` inside their argument list today.
     *
     * WHY IT MATTERS, and it is rule 15's shape exactly. The only consumer of
     * this method is the `other` channel, asking `>= 3` to find
     * `error_log()`'s destination form — and that channel asserts an ABSENCE.
     * An under-count can only push a real 3-argument call below the threshold,
     * so the miss is silent and the empty census reads as proof. It is not the
     * count being wrong that is dangerous; it is which DIRECTION it is wrong in.
     *
     * @param list<array{0: int, 1: string}|string> $significant
     */
    private static function argumentCount(array $significant, int $open): int
    {
        $depth = 0;
        $commas = 0;
        $sawToken = false;

        for ($i = $open; $i < \count($significant); $i++) {
            $token = $significant[$i];
            if (
                \is_array($token)
                && \in_array($token[0], self::ARRAY_TOKEN_OPENERS, true)
            ) {
                // Counted as content BEFORE the descent, because at depth 1 an
                // attribute or an interpolation IS the argument's first token
                // and `$sawToken` decides 0-vs-1 for the whole call.
                if ($depth === 1) {
                    $sawToken = true;
                }
                $depth++;

                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }
            if (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    // RED ON WHAT IT CANNOT PARSE, never a quiet number. The
                    // call opened on `(`, so the token that balances it must be
                    // `)`. Reaching depth 0 on `]` or `}` means an opener went
                    // by unrecognised — the exact defect above, in whatever
                    // NEW token shape a later PHP grows — and the honest answer
                    // is a failure rather than an under-count nothing notices.
                    if ($token !== ')') {
                        throw new \RuntimeException(
                            "the argument list opened at token {$open} balances to zero on '{$token}' rather "
                                . "than on ')': a bracket opener in it is lexed as an array token this walk "
                                . 'does not know. Add its id to self::ARRAY_TOKEN_OPENERS. Do NOT relax this '
                                . 'check — the only caller asks `>= 3` of the answer, so an under-count '
                                . 'silently empties a census that asserts an absence.',
                        );
                    }

                    return $sawToken ? $commas + 1 : 0;
                }

                continue;
            }
            if ($depth === 1) {
                $sawToken = true;
                if ($token === ',') {
                    $commas++;
                }
            }
        }

        throw new \RuntimeException('a call opened at this token never closes; the scan cannot answer for it');
    }

    /**
     * `$source` as a token list with whitespace, comments and doc-blocks
     * dropped.
     *
     * PROMOTED OUT OF {@see scan()} RATHER THAN COPIED, which matters more here
     * than tidiness usually does: this list IS the alphabet every channel in
     * this file counts over, and a second copy that dropped a different token
     * kind would give two scanners two different views of the same file while
     * both looked right.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function significantTokens(string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }

    /**
     * Whether the call whose `(` sits at `$open` contains a bracket opener PHP
     * lexes as an array token — see {@see ARRAY_TOKEN_OPENERS}.
     *
     * Its own walk rather than a flag threaded out of {@see argumentCount()},
     * because the two answer different questions and the one that reds must not
     * depend on the one being measured: a bug that made `argumentCount()` stop
     * descending would otherwise also make this report zero, and the guard in
     * {@see testEveryLiveErrorLogSiteSurvivesTheDepthWalk()} would go quiet at
     * exactly the moment it is needed.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $significant
     */
    private static function carriesArrayTokenOpener(array $significant, int $open): bool
    {
        $depth = 0;

        for ($i = $open; $i < \count($significant); $i++) {
            $token = $significant[$i];

            if (\is_array($token)) {
                if (\in_array($token[0], self::ARRAY_TOKEN_OPENERS, true)) {
                    return true;
                }

                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }
            if (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * The name a `T_STRING`/`T_NAME_FULLY_QUALIFIED` token denotes, leading
     * backslash stripped — `\fwrite` and `fwrite` are the same call, and
     * `\STDERR` and `STDERR` the same constant.
     */
    private static function callableName(array|string|null $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }
        if (!\in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        return ltrim($token[1], '\\');
    }
}
