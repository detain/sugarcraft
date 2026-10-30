<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Lang;

/**
 * Manual argv-parsing, dependency-free — no CLI-flag-parsing lib exists
 * elsewhere in the monorepo, so this follows the precedent set by
 * candy-mail/bin/candy-mail's hand-rolled getopt()-style loop rather than mirroring
 * any single upstream charmbracelet method.
 *
 * Supports: -p/--prompt <value>, --help/-h, --version/-v, the real
 * subcommands {@see ParsedArgs::SUBCOMMANDS} names (`mcp list`, `session
 * list|show|rename|delete|pin|unpin|archive|unarchive`, `models`, `doctor`,
 * `completion bash|zsh|fish` — executed by {@see Subcommands}, dispatched by
 * `bin/candy-code` in the same pre-flight place `--help` is) together with
 * the flags each verb owns ({@see ParsedArgs::SUBCOMMAND_FLAGS}, recognised
 * only after that verb), the `run "<prompt>"`
 * positional subcommand form (an alias for -p "<prompt>", advertised by
 * {@see Help::screen()} and {@see NonInteractive::run()}'s own usage
 * message), --output-format/--output-format=<value> (advertised by
 * {@see Help::screen()}, consumed by {@see NonInteractive::format()}), the
 * POSIX `--` end-of-options separator, --config/--config=<path> (the config
 * FILE Bootstrap would otherwise discover, validated by {@see
 * ArgvParser::configError()}), and positional root-path extraction
 * (--root/--root=<value> take precedence over a bare positional).
 *
 * Unrecognised `-`-prefixed arguments are NOT applied, but they are recorded
 * in {@see ParsedArgs::$unknownFlags} rather than dropped: dropping them made
 * every typo fall all the way through `bin/candy-code` into the blocking
 * full-screen TUI instead of failing fast — the same bug class as the
 * already-fixed "`--help` opens the TUI" (crush_code.md Phase 0 item 3). The
 * binary turns a non-empty `$unknownFlags` into a usage error (exit 2) before
 * it can reach `Program`. `--version` was the original reproduction for that
 * item and is now a recognised flag of its own (crush_code.md Phase 4 item 3),
 * dispatched by the binary exactly the way `--help` is.
 */
final class ArgvParser
{
    /**
     * Parse $argv into a structured ParsedArgs value object.
     *
     * Flag precedence (same flag, different forms):
     *   -p <value>       -> prompt = <value>
     *   --prompt <value> -> prompt = <value>
     *   run <value>      -> prompt = <value> (at any operand position, i.e.
     *                       not as a flag's value and not after `--`)
     *   --help           -> help = true
     *   -h               -> help = true
     *   --version        -> version = true
     *   -v               -> version = true
     *   --output-format <value>       -> outputFormat = <value>
     *   --output-format=<value>       -> outputFormat = <value>
     *
     *   --config <value>              -> configPath = <value>
     *   --config=<value>              -> configPath = <value>
     *
     * `outputFormat` defaults to `NonInteractive::FORMAT_TEXT` ('text') when
     * the flag is absent, and an unrecognised value is a usage error carried
     * in `$usageError` — it used to fall through to text rendering in
     * {@see NonInteractive::format()}, indistinguishably from an explicit
     * `text`, so a `| jq` consumer of `--output-format jsonl` got an empty
     * pipe at exit 0. The match is case-sensitive; see the check itself.
     * A MISSING value (`--output-format` last) and a flag-shaped one are
     * usage errors too (ISS-084), in the same family as `--root`/`--config`;
     * `--output-format=<value>` remains the escape hatch.
     *
     * `configPath` is the config FILE `--config` named, whose EXISTENCE is not
     * validated here (see {@see self::configError()}), and null when the flag
     * is absent, which leaves discovery to
     * {@see \SugarCraft\Code\Cli\Bootstrap}. A `--config` with no value at
     * all, or one followed by another option, IS decided here — as a usage
     * error, because null would otherwise be indistinguishable from absence
     * and silently restore discovery; see the branch itself.
     *
     * A bare `--` is the POSIX end-of-options separator: it is consumed, and
     * every argument after it is an operand, never a flag — so
     * `candy-code -- --version` opens the TUI rather than reporting an
     * unrecognised option. Note that operands still go through the same
     * path-shape heuristic below, so `--` guarantees "not a flag", not
     * "definitely the root".
     *
     * Positional arguments (those not consumed by any flag) are collected.
     * If the FIRST positional looks like a path (starts with / or . or
     * contains a path separator), it is assigned to root. Every other
     * positional is carried in {@see ParsedArgs::$positionals}, NOT
     * discarded: {@see self::resolveOperands()} makes a first operand that is
     * an existing directory the root and turns the rest into the TUI's first
     * prompt (audit CLI-2). That half needs is_dir(), which is why it is not
     * done here.
     *
     * ONLY THE FIRST, since leftover words became a prompt (CLI-2(b)): a
     * path-shaped word further along is part of what the user is asking
     * (`candy-code explain src/Chat.php`), and claiming it as the root turned
     * that request into a "not a directory" usage error.
     *
     * `promptRequested` records whether the user asked for one-shot mode at
     * all (`-p` / `--prompt` / `--prompt=` / `run`), independently of whether
     * a prompt VALUE followed. `bin/candy-code` dispatches on that flag, not
     * on `prompt !== null`, so a bare `candy-code -p` reaches
     * {@see NonInteractive::run()}'s "no prompt given" error instead of
     * silently opening the TUI.
     *
     * A prompt VALUE that is itself flag-shaped is a usage error rather than a
     * prompt (follow-up #48): `candy-code -p --verbose` used to run a one-shot
     * turn whose prompt text was the literal string "--verbose", which is
     * never what the caller meant and is unreadable in a CI log. The offending
     * token is left unconsumed and {@see ParsedArgs::$usageError} carries the
     * message; `--prompt=--verbose` remains the escape hatch for a prompt that
     * genuinely starts with a dash, because the `=` form takes its value from
     * the same token and so cannot be ambiguous.
     *
     * More than one prompt VALUE in one command line keeps its long-standing
     * last-wins precedence, and since 1021 INFO-4 the parser says so: one
     * stderr line per parse, from {@see self::noticePromptOverride()} at the
     * three assigning arms (`run` cannot reach a second assignment, its arm
     * is gated on `!$promptRequested`).
     *
     * @param array<int, string> $argv Command-line arguments, as received by
     *   a PHP CLI entry point ($argv[0] = script name is expected and
     *   skipped — parsing starts at $argv[1]).
     * @return ParsedArgs
     */
    public static function parse(array $argv): ParsedArgs
    {
        $help = false;
        $version = false;
        $prompt = null;
        $root = null;
        $outputFormat = ParsedArgs::DEFAULT_OUTPUT_FORMAT;
        $positional = [];
        $unknownFlags = [];
        $promptRequested = false;
        $duplicateNoticed = false;
        $usageError = null;
        $usageHint = null;
        $configPath = null;
        $model = null;
        $permissionMode = null;
        $maxCost = null;
        $maxTurns = null;
        $jsonSchema = null;
        $appendSystemPrompt = null;
        $endOfOptions = false;
        $subcommand = null;
        $subcommandArgs = [];
        $subcommandFlags = [];
        $continueSession = false;
        $resumeSession = null;
        $resumeRequested = false;
        $worktreeRequested = false;
        $worktreeName = null;
        $incognito = false;
        // C-SEC-7.b: the two launch-narrowing switches, read as one-shot
        // decisions by Bootstrap (the tool-set drop and the trust/bundle/
        // foreign choke points). They change NO stored state, so they carry
        // no refusal family beside --continue/--resume the way --incognito
        // does: reopening a stored session under --safe-mode is exactly what
        // the flag is FOR (run the old transcript through a locked-down
        // process).
        $restricted = false;
        $safeMode = false;

        $i = 1; // skip $argv[0] (script name)
        while ($i < \count($argv)) {
            $arg = $argv[$i];

            // Both `--` branches come first so that no later branch — the
            // `run` subcommand, any flag, and above all the unknown-flag
            // recorder — can claim a token the user has explicitly moved out
            // of flag space.
            if ($endOfOptions) {
                // Routed to the SUBCOMMAND's operands when one is open, not to
                // $positional: `candy-code session delete -- -weird-id` has to
                // be able to name an id that begins with a dash, and the whole
                // point of `--` is that the token after it is an operand of
                // whatever is consuming operands. Sending it to $positional
                // instead would have handed it to the root heuristic, i.e.
                // thrown it away and then reported "session delete needs an id".
                if ($subcommand !== null) {
                    $subcommandArgs[] = $arg;
                } else {
                    $positional[] = $arg;
                }
                ++$i;
                continue;
            }

            // The POSIX end-of-options separator itself: consumed, not kept.
            if ($arg === '--') {
                $endOfOptions = true;
                ++$i;
                continue;
            }

            // `run "<prompt>"` — the positional-subcommand alias for
            // -p "<prompt>" that Help::screen() and NonInteractive::run()'s
            // own usage message both advertise.
            //
            // Recognised wherever a bare operand can legitimately appear, not
            // only at $i === 1. Every flag VALUE is consumed inline by its own
            // branch below (`$argv[++$i]`) and so never reaches this test, and
            // the two `--` branches above already claimed everything after the
            // separator — so a `run` arriving here is genuinely a standalone
            // operand. Pinning it to the first position meant ANY flag before
            // it silently discarded the subcommand: `candy-code
            // --output-format json run` parsed to promptRequested=false and
            // fell through bin/candy-code's dispatch into the blocking
            // full-screen TUI — the same hang crush_code.md Phase 0 item 3
            // fixed for `--version`, reopened by flag order alone.
            //
            // $promptRequested guards the SECOND occurrence, which keeps the
            // two documented non-subcommand readings intact: `-p run` is a
            // prompt (its value was consumed by the -p branch, so it never
            // reaches here at all), and `candy-code run run` is a prompt of
            // "run" rather than a subcommand that re-arms itself.
            if ($arg === 'run' && !$promptRequested && $subcommand === null) {
                $next = $argv[$i + 1] ?? null;
                if (self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = self::flagAsPromptError('run', (string) $next);
                        $usageHint = self::promptDashHint();
                    }
                    $promptRequested = true;
                    ++$i; // leave the flag for the loop rather than eating it
                    continue;
                }
                $prompt = $next;
                $promptRequested = true;
                $i += 2;
                continue;
            }

            // The real subcommands (crush_code.md Phase 4 item 6):
            // `mcp list`, `session list|delete <id>`, `models`, `doctor` and
            // `completion bash|zsh|fish`. Recognised HERE, in the parser, and
            // dispatched by `bin/candy-code` in the same pre-flight place
            // --help/--version are: every one of them has to answer on a
            // machine with no provider, no config and no TTY, so a subcommand
            // that reached Program::run() would be the "`--help` opens the
            // TUI" bug (crush_code.md Phase 0 item 3) all over again.
            //
            // Recognised at ANY operand position for the same reason `run` is
            // (see above): pinning it to $argv[1] means `candy-code
            // --output-format json doctor` silently discards the subcommand
            // and boots the TUI. Guarded by `$subcommand === null` so a
            // SECOND verb is data — `candy-code completion doctor` asks for a
            // shell named "doctor" (a usage error with a message) rather than
            // re-arming itself into a health check.
            //
            // `!$promptRequested` keeps `candy-code run models` a one-shot
            // prompt of the word "models"; that token never reaches here
            // anyway, because the `run`/-p/--prompt branches consume their
            // value inline.
            if ($subcommand === null && !$promptRequested && \in_array($arg, ParsedArgs::SUBCOMMANDS, true)) {
                $subcommand = $arg;
                ++$i;
                continue;
            }

            // --help or -h
            if ($arg === '--help' || $arg === '-h') {
                $help = true;
                ++$i;
                continue;
            }

            // --version or -v
            if ($arg === '--version' || $arg === '-v') {
                $version = true;
                ++$i;
                continue;
            }

            // -p <value>
            if ($arg === '-p') {
                $next = $argv[$i + 1] ?? null;
                if (self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = self::flagAsPromptError('-p', (string) $next);
                        $usageHint = self::promptDashHint();
                    }
                    $promptRequested = true;
                    ++$i;
                    continue;
                }
                $duplicateNoticed = self::noticePromptOverride($prompt, $duplicateNoticed); // 1021 INFO-4
                $prompt = $next;
                $promptRequested = true;
                $i += 2;
                continue;
            }

            // --prompt=<value>  (no space)
            if (\str_starts_with($arg, '--prompt=')) {
                $duplicateNoticed = self::noticePromptOverride($prompt, $duplicateNoticed); // 1021 INFO-4
                $prompt = \substr($arg, 9); // length of "--prompt="
                $promptRequested = true;
                ++$i;
                continue;
            }

            // --prompt <value>
            if ($arg === '--prompt') {
                $next = $argv[$i + 1] ?? null;
                if (self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = self::flagAsPromptError('--prompt', (string) $next);
                        $usageHint = self::promptDashHint();
                    }
                    $promptRequested = true;
                    ++$i;
                    continue;
                }
                $duplicateNoticed = self::noticePromptOverride($prompt, $duplicateNoticed); // 1021 INFO-4
                $prompt = $next;
                $promptRequested = true;
                $i += 2;
                continue;
            }

            // --output-format=<value>  (no space)
            if (\str_starts_with($arg, '--output-format=')) {
                $outputFormat = \substr($arg, 16); // length of "--output-format="
                ++$i;
                continue;
            }

            // --output-format <value>
            //
            // STRICT, like --root/--config/--model (ISS-084). It used to take
            // `$argv[++$i]` unconditionally, so a trailing `--output-format`
            // silently kept `text` — an invocation that explicitly asked for a
            // machine-readable stream got prose at exit 0, and the `| jq`
            // consumer saw an empty pipe with a success status, the exact
            // failure this flag's contract promises never happens. A
            // flag-shaped value was worse: `--output-format --help` made the
            // format the literal `--help`, whose unsupported-format error then
            // shadowed the sharper one the loop would have raised. The
            // `=`-form stays the escape hatch for a value beginning with "-".
            if ($arg === '--output-format') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = 'candy-code: ' . Lang::t($next === null
                            ? 'cli.argv.output_format.missing'
                            : 'cli.argv.output_format.flag', ['option' => (string) $next]);
                        $usageHint = Lang::t('cli.argv.hint.output_format_value');
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                $outputFormat = $next;
                $i += 2;
                continue;
            }

            // --root=<value>  (no space)
            if (\str_starts_with($arg, '--root=')) {
                $root = \substr($arg, 7); // length of "--root="
                ++$i;
                continue;
            }

            // --root <value>
            //
            // STRICT, like --config/--model/--permission-mode below (audit
            // CLI-2). It used to take `$argv[++$i]` unconditionally, so
            // `--root --model x` made the root the literal string "--model",
            // left `x` as a stray operand, and reported "--root --model: no
            // such directory" -- blaming the wrong token -- while a bare
            // trailing `--root` silently meant "the cwd" on an invocation that
            // explicitly asked for another directory. The =-form stays the
            // escape hatch for a directory whose name begins with "-".
            if ($arg === '--root') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.root.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.root.flag', ['option' => $next]);
                        $usageHint = self::rootValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                $root = $next;
                $i += 2;
                continue;
            }

            // --config=<value>  (no space)
            if (\str_starts_with($arg, '--config=')) {
                $configPath = \substr($arg, 9); // length of "--config="
                ++$i;
                continue;
            }

            // --config <value>
            //
            // A MISSING and a FLAG-SHAPED value are usage errors HERE rather
            // than being left to configError(), which cannot tell either of
            // them apart from "the flag was never given":
            //
            //   `candy-code --config`        stored null, which configError()
            //     reads as absence -- so the run fell straight through to
            //     Bootstrap's own discovery and applied the DEFAULT permission
            //     policy on an invocation that explicitly named a file, and on
            //     the TUI path entered the alt-screen while doing it (MEASURED:
            //     rc 124 under a 15s bound, stdout beginning `\e[?1049h`). That
            //     is the "`--help` opens the TUI" class again, and the silent
            //     policy downgrade `--config` validation exists to prevent.
            //
            //   `candy-code --config -p hi`  consumed `-p` as the file name and
            //     lost the prompt with it -- the same misreading the -p/
            //     --prompt/run branches above already refuse, applied to the
            //     option that takes a path instead of a prompt.
            //
            // `--root` gets the same treatment now (audit CLI-2): a null root
            // means "use the cwd" only when the flag was never typed.
            if ($arg === '--config') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.config.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.config.flag', ['option' => $next]);
                        $usageHint = self::configValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                $configPath = $next;
                $i += 2;
                continue;
            }

            // --model=<value>  (no space)
            //
            // An EMPTY value is a usage error, not a value — see
            // self::emptyModelError().
            if (\str_starts_with($arg, '--model=')) {
                $value = \substr($arg, 8); // length of "--model="
                if ($value === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyModelError();
                        $usageHint = self::modelValueHint();
                    }
                    ++$i;
                    continue;
                }
                $model = $value;
                ++$i;
                continue;
            }

            // --model <value>
            //
            // STRICT rather than lenient (--output-format swallows whatever
            // follows, including nothing): a model name is never
            // flag-shaped, so `--model -p "hi"` is a typo in which the bare
            // form ate the next OPTION, and silently starting on the provider
            // default while dropping -p is worse than refusing. Same treatment
            // --config already gets, and the =-form above stays available for
            // the value that legitimately begins with "-".
            if ($arg === '--model') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.model.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.model.flag', ['option' => $next]);
                        $usageHint = self::modelValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                if ($next === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyModelError();
                        $usageHint = self::modelValueHint();
                    }
                    $i += 2; // the empty token is consumed; it is not a prompt
                    continue;
                }
                $model = $next;
                $i += 2;
                continue;
            }

            // --permission-mode=<value>  (no space)
            //
            // An EMPTY value is a usage error, not a value — see
            // self::emptyPermissionModeError().
            if (\str_starts_with($arg, '--permission-mode=')) {
                $value = \substr($arg, 18); // length of "--permission-mode="
                if ($value === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyPermissionModeError();
                        $usageHint = self::permissionModeValueHint();
                    }
                    ++$i;
                    continue;
                }
                $permissionMode = $value;
                ++$i;
                continue;
            }

            // --permission-mode <value>
            //
            // The VALUE is not validated here. Bootstrap::permissionGate() runs
            // it through the same permissionModeFrom() that judges
            // $CANDY_CODE_PERMISSION_MODE and the config key, so all three
            // sources fail with one message shape and one exit path — see
            // Bootstrap::usePermissionMode(). What IS checked here is the
            // shape: a missing value must not silently leave the mode at its
            // default, which for this flag means running unattended under
            // whatever the config said.
            if ($arg === '--permission-mode') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.mode_flag.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.mode_flag.flag', ['option' => $next]);
                        $usageHint = self::permissionModeValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                if ($next === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyPermissionModeError();
                        $usageHint = self::permissionModeValueHint();
                    }
                    $i += 2; // the empty token is consumed; it is not a prompt
                    continue;
                }
                $permissionMode = $next;
                $i += 2;
                continue;
            }

            // --max-cost=<value>  (no space)
            //
            // AN EMPTY value is a usage error, not a value — the same rule
            // --permission-mode above earned: `--max-cost="$CAP"` with $CAP
            // unset meant a ceiling, got none, and was told nothing. WHAT the
            // ceiling must look like is judged by Bootstrap::maxCostUsd(),
            // which runs the flag through the same positive-finite-dollars
            // check that decides $CANDY_CODE_MAX_COST and the maxCostUsd
            // setting, so all three sources fail with one message shape and
            // one exit path — see Bootstrap::useMaxCost(). What IS checked
            // here is the shape: a missing value must not silently leave the
            // launch uncapped.
            if (\str_starts_with($arg, '--max-cost=')) {
                $value = \substr($arg, 11); // length of "--max-cost="
                if ($value === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyMaxCostError();
                        $usageHint = self::maxCostValueHint();
                    }
                    ++$i;
                    continue;
                }
                $maxCost = $value;
                ++$i;
                continue;
            }

            // --max-cost <value>
            if ($arg === '--max-cost') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.max_cost.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.max_cost.flag', ['option' => $next]);
                        $usageHint = self::maxCostValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                if ($next === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyMaxCostError();
                        $usageHint = self::maxCostValueHint();
                    }
                    $i += 2; // the empty token is consumed; it is not a prompt
                    continue;
                }
                $maxCost = $next;
                $i += 2;
                continue;
            }

            // --max-turns=<value>  (no space)
            //
            // The step-ceiling twin of --max-cost directly above, and the
            // same two-layer split: the parser judges only the SHAPE of
            // presence (missing/empty values are indistinguishable from an
            // absent flag once parsed), while what counts as a usable
            // ceiling is judged by Bootstrap::maxTurns() through the same
            // positive-whole-number check the maxToolSteps setting gets from
            // Bootstrap::resolvedMaxToolSteps() — so flag and config fail
            // with one message shape and one exit path. See
            // Bootstrap::useMaxTurns().
            if (\str_starts_with($arg, '--max-turns=')) {
                $value = \substr($arg, 12); // length of "--max-turns="
                if ($value === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyMaxTurnsError();
                        $usageHint = self::maxTurnsValueHint();
                    }
                    ++$i;
                    continue;
                }
                $maxTurns = $value;
                ++$i;
                continue;
            }

            // --max-turns <value>
            if ($arg === '--max-turns') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.max_turns.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.max_turns.flag', ['option' => $next]);
                        $usageHint = self::maxTurnsValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                if ($next === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyMaxTurnsError();
                        $usageHint = self::maxTurnsValueHint();
                    }
                    $i += 2; // the empty token is consumed; it is not a prompt
                    continue;
                }
                $maxTurns = $next;
                $i += 2;
                continue;
            }

            // --json-schema=<path-or-json> / --json-schema <path-or-json> (AUT-4)
            //
            // The JSON-Answer-contract twin of --max-cost's shape split: the
            // parser judges only PRESENCE and never touches the filesystem,
            // so — like the config paths — no fs read rides `parse()` (the
            // SUBCOMMANDS docblock law). WHAT the value must decode to is
            // judged at NonInteractive's own boundary, the one place that
            // runs the one-shot path; every provider that cannot honour the
            // contract is refused there BEFORE the first billed call, never
            // silently ignored. Inline JSON is distinguished from a path by
            // its first byte (`{`); anything else is handed through as the
            // file name it is.
            if (\str_starts_with($arg, '--json-schema=')) {
                $value = \substr($arg, 14); // length of "--json-schema="
                if ($value === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyJsonSchemaError();
                        $usageHint = self::jsonSchemaValueHint();
                    }
                    ++$i;
                    continue;
                }
                $jsonSchema = $value;
                ++$i;
                continue;
            }

            // --json-schema <path-or-json>
            if ($arg === '--json-schema') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.json_schema.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.json_schema.flag', ['option' => $next]);
                        $usageHint = self::jsonSchemaValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                if ($next === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyJsonSchemaError();
                        $usageHint = self::jsonSchemaValueHint();
                    }
                    $i += 2; // the empty token is consumed; it is not a prompt
                    continue;
                }
                $jsonSchema = $next;
                $i += 2;
                continue;
            }

            // --append-system-prompt=<text|@file> / --append-system-prompt <text|@file> (C-CTX-4.g)
            //
            // The one-shot rule-append twin of --json-schema's shape split:
            // the parser judges only PRESENCE and never touches the
            // filesystem, so a leading `@` is carried verbatim and resolved
            // (or refused) at NonInteractive's boundary — the same
            // raw-store-and-later-judge law the config paths follow. The
            // value splices as ONE fenced `<user-rules>` section at the end
            // of the system prompt; it is never an override, and the size
            // bound rides the splice, not this loop.
            if (\str_starts_with($arg, '--append-system-prompt=')) {
                $value = \substr($arg, 23); // length of "--append-system-prompt="
                if ($value === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyAppendSystemPromptError();
                        $usageHint = self::appendSystemPromptValueHint();
                    }
                    ++$i;
                    continue;
                }
                $appendSystemPrompt = $value;
                ++$i;
                continue;
            }

            // --append-system-prompt <text|@file>
            if ($arg === '--append-system-prompt') {
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next)) {
                    if ($usageError === null) {
                        $usageError = $next === null
                            ? 'candy-code: ' . Lang::t('cli.argv.append_system_prompt.missing')
                            : 'candy-code: ' . Lang::t('cli.argv.append_system_prompt.flag', ['option' => $next]);
                        $usageHint = self::appendSystemPromptValueHint();
                    }
                    ++$i; // leave a flag-shaped $next for the loop to judge
                    continue;
                }
                if ($next === '') {
                    if ($usageError === null) {
                        $usageError = self::emptyAppendSystemPromptError();
                        $usageHint = self::appendSystemPromptValueHint();
                    }
                    $i += 2; // the empty token is consumed; it is not a prompt
                    continue;
                }
                $appendSystemPrompt = $next;
                $i += 2;
                continue;
            }

            // -c / --continue: reopen the most recently used session, with
            // its transcript, instead of starting a new one.
            if ($arg === '--continue' || $arg === '-c') {
                $continueSession = true;
                ++$i;
                continue;
            }

            // --resume=<id|name>
            if (\str_starts_with($arg, '--resume=')) {
                $value = \substr($arg, 9); // length of "--resume="
                $resumeRequested = true;
                $resumeSession = $value === '' ? null : $value;
                ++$i;
                continue;
            }

            // --resume [<id|name>]: the value is OPTIONAL, and that is the one
            // way this differs from --model's strict shape. Bare, or followed
            // by another option, it opens the session picker at launch rather
            // than being a usage error — "resume, but let me choose" is a
            // request of its own. A following non-flag token is always the
            // value, so the directory to open goes before the flag
            // (`candy-code ./app --resume`) or in --root.
            if ($arg === '--resume') {
                $resumeRequested = true;
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next) || $next === '') {
                    $i += $next === '' ? 2 : 1;
                    continue;
                }
                $resumeSession = $next;
                $i += 2;
                continue;
            }

            // --incognito (C-SES-7.c): launch without a session row at all.
            // Reads of stored memory stay on; every automatic write is off —
            // the TUI/`-p` sibling of the serve-side ephemeral host
            // (C-SES-7.e). A bare switch: no value, no short form.
            if ($arg === '--incognito') {
                $incognito = true;
                ++$i;
                continue;
            }

            // --restricted / --safe-mode (C-SEC-7.b): launch-narrowing
            // switches, read once at dispatch by Bootstrap's two choke points
            // (the tool-set drop; the trust/bundle/foreign refusals). Bare
            // form arms. The `=` form lets a wrapper carry the switch through
            // with an explicit verdict — including an OFF that outvotes an
            // alias carrying the bare flag — and a value outside the two
            // families is recorded as an unknown flag rather than guessed:
            // a narrowing silently dropped by a typo is the failure mode
            // these flags exist to prevent, so the bin's usage error (exit 2)
            // answers instead.
            if ($arg === '--restricted') {
                $restricted = true;
                ++$i;
                continue;
            }
            if (\str_starts_with($arg, '--restricted=')) {
                $value = self::switchValue(\substr($arg, 13)); // strlen('--restricted=')
                if ($value === null) {
                    $unknownFlags[] = $arg;
                } else {
                    $restricted = $value;
                }
                ++$i;
                continue;
            }
            if ($arg === '--safe-mode') {
                $safeMode = true;
                ++$i;
                continue;
            }
            if (\str_starts_with($arg, '--safe-mode=')) {
                $value = self::switchValue(\substr($arg, 12)); // strlen('--safe-mode=')
                if ($value === null) {
                    $unknownFlags[] = $arg;
                } else {
                    $safeMode = $value;
                }
                ++$i;
                continue;
            }

            // --worktree=<name> (C-AGT-6.c): a main session run in its own
            // git worktree. Empty after `=` means bare, as --resume's does.
            if (\str_starts_with($arg, '--worktree=')) {
                $value = \substr($arg, 11); // length of "--worktree="
                $worktreeRequested = true;
                $worktreeName = $value === '' ? null : $value;
                ++$i;
                continue;
            }

            // --worktree [<name>]: the value is OPTIONAL, in --resume's
            // shape — bare or before another option, the launch auto-names
            // the worktree rather than erroring; a following non-flag token
            // is always the name. Which name is LEGAL (a path-safe word, no
            // traversal) is judged by {@see MainWorktree::enter()}, the code
            // that turns it into a directory and a branch — parse() keeps
            // its no-filesystem law and carries the raw string.
            if ($arg === '--worktree') {
                $worktreeRequested = true;
                $next = $argv[$i + 1] ?? null;
                if ($next === null || self::looksLikeFlag($next) || $next === '') {
                    $i += $next === '' ? 2 : 1;
                    continue;
                }
                $worktreeName = $next;
                $i += 2;
                continue;
            }

            // A flag the OPEN verb owns (ParsedArgs::SUBCOMMAND_FLAGS):
            // `session list --archived`. Checked only once a verb is open and
            // only against that verb's own table, so the same word before the
            // verb, or after a different one, still falls to the unknown-flag
            // recorder below. The global flags above win a name clash because
            // they are matched first. A value flag is STRICT in the way
            // --model is: a missing or flag-shaped value is a usage error
            // rather than silently dropping the flag.
            if ($subcommand !== null) {
                $name = \explode('=', $arg, 2)[0];
                $takesValue = ParsedArgs::SUBCOMMAND_FLAGS[$subcommand][$name] ?? null;
                if ($takesValue !== null) {
                    $inline = \str_contains($arg, '=') ? \substr($arg, \strlen($name) + 1) : null;
                    if (!$takesValue) {
                        if ($inline !== null) {
                            if ($usageError === null) {
                                $usageError = 'candy-code: ' . Lang::t('cli.argv.verb_flag.no_value', ['verb' => $subcommand, 'flag' => $name]);
                                $usageHint = Lang::t('cli.argv.hint.flag_alone', ['flag' => $name]);
                            }
                        } else {
                            $subcommandFlags[$name] = true;
                        }
                        ++$i;
                        continue;
                    }
                    if ($inline !== null) {
                        $value = $inline;
                        ++$i;
                    } else {
                        $next = $argv[$i + 1] ?? null;
                        if ($next === null || self::looksLikeFlag($next)) {
                            if ($usageError === null) {
                                $usageError = 'candy-code: ' . ($next === null
                                    ? Lang::t('cli.argv.verb_flag.missing', ['verb' => $subcommand, 'flag' => $name])
                                    : Lang::t('cli.argv.verb_flag.flag', ['verb' => $subcommand, 'flag' => $name, 'option' => $next]));
                                $usageHint = Lang::t('cli.argv.hint.flag_value', ['flag' => $name]);
                            }
                            ++$i;
                            continue;
                        }
                        $value = $next;
                        $i += 2;
                    }
                    if ($value === '') {
                        if ($usageError === null) {
                            $usageError = 'candy-code: ' . Lang::t('cli.argv.verb_flag.empty', ['verb' => $subcommand, 'flag' => $name]);
                            $usageHint = Lang::t('cli.argv.hint.flag_value', ['flag' => $name]);
                        }
                        continue;
                    }
                    if (
                        \is_string($subcommandFlags[$name] ?? null)
                        && \in_array($name, ParsedArgs::REPEATABLE_SUBCOMMAND_FLAGS[$subcommand] ?? [], true)
                    ) {
                        $value = $subcommandFlags[$name] . ',' . $value;
                    }
                    $subcommandFlags[$name] = $value;
                    continue;
                }
            }

            // Unrecognised flag — recorded, never applied. bin/candy-code
            // turns a non-empty list into a usage error (exit 2); dropping it
            // here is what let `--version` boot the TUI.
            if (\str_starts_with($arg, '-')) {
                $unknownFlags[] = $arg;
                ++$i;
                continue;
            }

            // Positional argument -- or, once a subcommand is open, one of
            // ITS operands (`list`, `delete`, a session id, a shell name).
            // Deliberately NOT also offered to the root heuristic below: a
            // path-shaped operand of a subcommand is that subcommand's
            // argument, and letting it set the root as well would give one
            // token two unrelated meanings.
            if ($subcommand !== null) {
                $subcommandArgs[] = $arg;
                ++$i;
                continue;
            }

            $positional[] = $arg;
            ++$i;
        }

        // An --output-format nobody implements is a usage error, decided HERE
        // rather than at the consumers, because at the consumers it cannot be
        // decided at all: every one of them tests `=== NonInteractive::FORMAT_JSON`
        // and renders text otherwise, so `--output-format xml` was byte-for-byte
        // indistinguishable from `--output-format text` and exited 0. A caller
        // piping to `jq` got a silent empty pipe with a success status, which is
        // the exact failure the JSON contract in README.md promises never happens.
        //
        // In the PARSER, so the one-shot path and the TUI path reject it
        // identically -- the TUI ignores $outputFormat entirely, so a check
        // living in NonInteractive would let `candy-code --output-format xml`
        // open the alt-screen while `candy-code -p x --output-format xml` failed.
        //
        // CASE-SENSITIVE, deliberately: the consumers compare with `===`, so
        // accepting `JSON` here without also rewriting the stored value would
        // re-open the very hole this closes -- a validated format that still
        // prints text. Rewriting it instead would silently change what
        // `--output-format JSON` has always done. Rejecting it is the only
        // reading under which every ACCEPTED value behaves exactly as
        // documented, and the message below names the valid spellings.
        if (!\in_array($outputFormat, ParsedArgs::OUTPUT_FORMATS, true)) {
            if ($usageError === null) {
                $usageError = 'candy-code: ' . Lang::t('cli.argv.output_format.unsupported', ['format' => $outputFormat]);
                $usageHint = Lang::t('cli.argv.hint.output_formats', ['formats' => \implode(', ', ParsedArgs::OUTPUT_FORMATS)]);
            }
        }

        // --continue and --resume both choose the session to open, so they
        // cannot both win - on a TUI launch or a one-shot run alike.
        if ($usageError === null && $continueSession && $resumeRequested) {
            $usageError = 'candy-code: ' . Lang::t('cli.argv.continue_with_resume');
            $usageHint = Lang::t('cli.argv.hint.continue_or_resume');
        }
        // C-SES-7.c: --incognito promises no session row is written, and
        // --continue/--resume both open a stored row — the pair contradicts
        // itself, so the launch is refused before either reading wins.
        if ($usageError === null && $incognito && ($continueSession || $resumeRequested)) {
            $usageError = 'candy-code: ' . Lang::t('cli.argv.incognito_with_session');
            $usageHint = Lang::t('cli.argv.hint.incognito_no_session');
        }
        // AUT-2: a session flag beside -p is no longer refused wholesale.
        // --continue and a TARGETED --resume seed the one-shot's history from
        // the stored transcript and save the answer back to the same row -
        // NonInteractive::run() reuses the TUI's openSession funnel. What
        // cannot work is a BARE --resume: it asks for the interactive session
        // picker, and a one-shot run has no surface to show it on. The hint
        // carries the remedy (name a target, or drop -p and pick) because
        // both readings of the user's intent remain open here.
        if ($usageError === null && $promptRequested && $resumeRequested && $resumeSession === null) {
            $usageError = 'candy-code: ' . Lang::t('cli.argv.resume_picker_with_prompt');
            $usageHint = Lang::t('cli.argv.hint.resume_needs_target');
        }

        // C-AGT-7.b: --json-schema with nothing to constrain is not a launch
        // that forgot the flag, it is a launch that will ignore it. The schema
        // is read in exactly ONE place in production — NonInteractive::run()
        // hands it to the engine — and bin/candy-code reaches that method only
        // when $promptRequested. Without -p (or `run "<prompt>"`) the value was
        // parsed, carried, and silently dropped at the TUI boundary, so a CI
        // line that lost its -p through a refactor kept "asking" for structured
        // output forever while free-form prose came back.
        //
        // THE SIBLING FLAG KEEPS ITS ASYMMETRY, DELIBERATELY. --output-format
        // is NOT prompt-only: `serve`, `self-update`, the ACP route and bin's
        // own usage-failure path all read $args->outputFormat on launches with
        // no prompt, so a guard there would break working commands to police a
        // flag that is doing real work. Both branches of the sibling question
        // were checked; only this one is inert without a prompt.
        if ($usageError === null && $jsonSchema !== null && !$promptRequested) {
            $usageError = 'candy-code: ' . Lang::t('cli.argv.json_schema.requires_prompt');
            $usageHint = Lang::t('cli.argv.hint.json_schema_needs_prompt');
        }

        // C-CTX-4.g: the append flag keeps --json-schema's prompt-only gate,
        // for the same AUT-4-P1 reason. The splice arm that consumes the
        // value lives in NonInteractive's headless path ONLY — a TUI launch
        // parses, carries, and silently drops it, so a launch that lost its
        // -p to a refactor would believe the operator's rules were appended
        // while the interactive prompt shipped without them. Refused at the
        // parser so both spellings of the clash fail identically on stdout
        // and in the JSON error document.
        if ($usageError === null && $appendSystemPrompt !== null && !$promptRequested) {
            $usageError = 'candy-code: ' . Lang::t('cli.argv.append_system_prompt.requires_prompt');
            $usageHint = Lang::t('cli.argv.hint.append_system_prompt_needs_prompt');
        }

        // C-AGT-6.c, broadened at weld: --worktree is a MAIN-session flag —
        // it swaps the launch's root for a worktree before the TUI or a
        // one-shot opens there. bin exits at a subcommand verb's OWN
        // dispatch before that swap, so beside ANY verb (ParsedArgs::
        // SUBCOMMANDS) the flag is carried and silently ignored — the lie
        // the jsonSchema guard above was minted to forbid. `serve` has the
        // deeper reason too: its host opens one root per client session
        // (and --allow-dir-browse/--browse-root already own that decision).
        // Refused at the parser so both spellings of the clash fail
        // identically on stdout and in the JSON error document. The verb-
        // first order (`serve --worktree`) is caught earlier by the
        // unknown-flag recorder: serve's own flag table does not list it.
        if ($usageError === null && $worktreeRequested && $subcommand !== null) {
            $usageError = 'candy-code: ' . Lang::t('cli.argv.worktree.with_subcommand');
            $usageHint = Lang::t('cli.argv.hint.worktree_subcommand');
        }

        // The FIRST positional, when it looks like a path, is the root (see
        // the doc-block for why only the first). It leaves the list; the rest
        // stay for resolveOperands().
        if ($root === null && $positional !== [] && self::looksLikePath($positional[0])) {
            $root = \array_shift($positional);
        }

        return ParsedArgs::from($help, $prompt, $root, $outputFormat, $unknownFlags, $promptRequested, $version, $usageError, $usageHint, $configPath, $subcommand, $subcommandArgs, $model, $permissionMode, $continueSession, $resumeSession, $resumeRequested, \array_values($positional), $subcommandFlags, $maxCost, $maxTurns, $jsonSchema, $worktreeRequested, $worktreeName, $appendSystemPrompt, $incognito, $restricted, $safeMode);
    }

    /**
     * The filesystem half of operand handling (audit CLI-2): turn a first
     * operand that names an existing directory into the root, and the words
     * after it into the TUI's first prompt.
     *
     * Kept OUT of {@see parse()} for the reason {@see rootError()} is: parse()
     * stays a pure argv->value-object transform. `bin/candy-code` calls this
     * right after the unknown-flag check -- AFTER it, because an unknown flag
     * that was meant to take a value (`--sesion foo`) leaves that value behind
     * as a stray operand, and the flag is the real mistake -- and reports the
     * returned {@see ParsedArgs::$usageError} through
     * {@see NonInteractive::failUsage()}, so `--output-format json` still gets
     * its error document. Returned unchanged when `$args->usageError` is
     * already set: the parser's message is the earlier, sharper one.
     *
     * The rules, in order:
     *
     *  - `candy-code src` with `src/` present: `src` becomes the root, exactly
     *    as `candy-code ./src` always did. Only the FIRST operand, and only
     *    when nothing claimed the root yet -- a directory name further along
     *    is a word of the prompt. It applies to `-p`/`run` and subcommand runs
     *    too, because a path-shaped operand already did.
     *  - A single operand left beside a root that itself names a directory
     *    (`candy-code src lib`, `--root /tmp src`) is a usage error: two
     *    operands both naming the project root cannot both win, and one word
     *    that is a directory is not a plausible prompt.
     *  - On a `-p`/`run` run anything left is a usage error (the prompt was
     *    already given), and so it is before a subcommand.
     *  - Otherwise -- a TUI launch -- the words left are joined with single
     *    spaces into {@see ParsedArgs::$initialPrompt}, which the TUI submits
     *    as its first prompt (CLI-2(b), Claude Code's `claude "<prompt>"`).
     *    Before this they were a usage error pointing at `-p`.
     *  - Subcommand operands never reach here; parse() routes them to
     *    {@see ParsedArgs::$subcommandArgs}.
     */
    public static function resolveOperands(ParsedArgs $args): ParsedArgs
    {
        if ($args->usageError !== null || $args->positionals === []) {
            return $args;
        }

        $leftover = $args->positionals;
        if ($args->root === null && $leftover[0] !== '' && \is_dir($leftover[0])) {
            $root = \array_shift($leftover);
            $args = $args->withRoot($root, $leftover);
            if ($leftover === []) {
                return $args;
            }
        }

        if ($args->root !== null && \count($leftover) === 1
            && (self::looksLikePath($leftover[0]) || ($leftover[0] !== '' && \is_dir($leftover[0])))
        ) {
            return $args->withUsageError(
                'candy-code: ' . Lang::t('cli.argv.root_twice', [
                    'root' => $args->root,
                    'operand' => self::listOperands($leftover),
                ]),
                Lang::t('cli.argv.hint.root_once'),
            );
        }

        $listed = self::listOperands($leftover);
        $one = \count($leftover) === 1;

        if ($args->promptRequested) {
            return $args->withUsageError(
                'candy-code: ' . ($one
                    ? Lang::t('cli.argv.unexpected_after_prompt.one', ['operands' => $listed])
                    : Lang::t('cli.argv.unexpected_after_prompt.many', ['operands' => $listed])),
                Lang::t('cli.argv.hint.quote_prompt'),
            );
        }

        $prompt = \implode(' ', $leftover);
        if ($args->subcommand !== null || \trim($prompt) === '') {
            return $args->withUsageError(
                'candy-code: ' . ($one
                    ? Lang::t('cli.argv.unexpected.one', ['operands' => $listed])
                    : Lang::t('cli.argv.unexpected.many', ['operands' => $listed])),
                Lang::t('cli.argv.hint.words_open_tui'),
            );
        }

        return $args->withInitialPrompt($prompt);
    }

    /**
     * The usage error for a `--root` that names no directory, or null when
     * the parsed root is usable.
     *
     * Kept OUT of {@see parse()} so parsing stays a pure argv->value-object
     * transform with no filesystem access; `bin/candy-code` calls this
     * immediately after parsing, next to the unknown-flag check.
     *
     * Validated at all because the root is not merely a convenience: it
     * becomes {@see \SugarCraft\Code\App\App::$root}, then
     * {@see \SugarCraft\Code\Runtime}'s `HookContext::$projectRoot`, then
     * the `proc_open()` cwd of every {@see \SugarCraft\Code\Hooks\ScriptHook}.
     * A `--root /typo` therefore used to be far worse than a wrong directory
     * — it silently downgraded a DENYING hook into an allow. ScriptHook now
     * fails closed on its own, but a typo deserves a message at the boundary
     * rather than a run that quietly does the wrong thing everywhere
     * (crush_code.md Phase 0 item 6).
     */
    public static function rootError(ParsedArgs $args): ?string
    {
        if ($args->root === null || is_dir($args->root)) {
            return null;
        }

        return 'candy-code: ' . Lang::t('cli.argv.root.no_such_directory', ['root' => $args->root]);
    }

    /**
     * The usage error for a `--config` that names no readable file, or null
     * when the parsed config path is usable (or absent).
     *
     * Kept OUT of {@see parse()} for the same reason {@see rootError()} is:
     * parsing stays a pure argv->value-object transform with no filesystem
     * access, and `bin/candy-code` calls this immediately after that one.
     *
     * Validated at all, and in the same class as `--root /typo`, because the
     * file this names is not a preference file: {@see
     * \SugarCraft\Code\Cli\Bootstrap::permissionConfig()} reads the permission
     * MODE, the permission RULES and the `trustedProjectHooks` list out of it.
     * A `--config /typo` that fell through to discovery would therefore run the
     * DEFAULT policy while the operator believed a restrictive one was in
     * force -- the same silent downgrade `--root` validation exists to prevent,
     * and worse for being explicitly asked for on the command line.
     *
     * A directory, a dangling symlink and an absent path all report "no such
     * file": the caller's mistake is the same in each case, and Bootstrap
     * draws the finer present-but-unusable distinctions for the file it goes
     * on to read.
     */
    public static function configError(ParsedArgs $args): ?string
    {
        if ($args->configPath === null) {
            return null;
        }

        // `--config=` (the equals form with nothing after it) is the one
        // spelling that reaches here with a path that is not a path. Answered
        // BEFORE the is_file() walk, and with its own message, because that
        // walk's "--config : no such file" names the empty string as though it
        // were a filename. Answered here at all — rather than by folding '' in
        // with null above — because '' must be an ERROR: null means "the flag
        // was absent, use discovery", and treating '' the same way would set an
        // override that readUserConfig() then resolves to nothing, i.e. an
        // empty policy on a run that asked for a named one.
        if ($args->configPath === '') {
            return 'candy-code: ' . Lang::t('cli.argv.config.empty');
        }

        if (!is_file($args->configPath)) {
            return 'candy-code: ' . Lang::t('cli.argv.config.no_such_file', ['path' => $args->configPath]);
        }

        if (!is_readable($args->configPath)) {
            return 'candy-code: ' . Lang::t('cli.argv.config.not_readable', ['path' => $args->configPath]);
        }

        return null;
    }

    /**
     * The operands as one readable list; an empty one is shown as '' so the
     * message does not end in a bare separator.
     *
     * @param list<string> $operands
     */
    private static function listOperands(array $operands): string
    {
        return \implode(', ', \array_map(
            static fn (string $operand): string => $operand === '' ? "''" : $operand,
            $operands,
        ));
    }

    /**
     * Heuristic: does this string look like a filesystem path?
     */
    private static function looksLikePath(string $s): bool
    {
        return $s !== '' && ($s[0] === '/' || $s[0] === '.' || \strpos($s, '/') !== false);
    }

    /**
     * Is this token flag-shaped, i.e. something no caller ever meant as a
     * prompt?
     *
     * A lone `-` is deliberately NOT flag-shaped: it is the conventional
     * stdin/stdout placeholder across the whole POSIX toolset, so treating it
     * as an error would reject a token that has a long-standing meaning as an
     * operand. `--` IS flag-shaped — swallowing the end-of-options separator
     * as prompt text is the same silent misreading as swallowing `--verbose`.
     */
    private static function looksLikeFlag(?string $token): bool
    {
        return $token !== null && \strlen($token) > 1 && $token[0] === '-';
    }

    /**
     * The `=`-form verdict of a boolean launch switch (C-SEC-7.b): the TRUE
     * family is {@see \SugarCraft\Code\Support\EnvBool::isTrue()}'s — `1`,
     * `true`, `yes`, case-insensitive — and the FALSE family is its mirror
     * `0`/`false`/`no`, carried because an argv wrapper must be able to
     * outvote an alias that carries the bare flag. Anything else answers
     * null, and the caller records the WHOLE token as an unknown flag, so
     * the bin's usage error names it: two bool spellings for one switch
     * (guessing `on`, or treating a typo as OFF) is how a narrowing flag
     * silently stops narrowing.
     */
    private static function switchValue(string $raw): ?bool
    {
        switch (\strtolower($raw)) {
            case '1':
            case 'true':
            case 'yes':
                return true;
            case '0':
            case 'false':
            case 'no':
                return false;
            default:
                return null;
        }
    }

    /**
     * The hint that accompanies every {@see flagAsPromptError()}.
     *
     * Lives here rather than in `bin/candy-code` because the binary now emits
     * {@see ParsedArgs::$usageHint} verbatim for whichever usage error the
     * parser produced, and this one is only correct for the prompt errors --
     * it was being printed under an `--output-format` complaint too until the
     * hint moved next to the error that earns it.
     */
    private static function promptDashHint(): string
    {
        return Lang::t('cli.argv.hint.prompt_dash');
    }

    /**
     * Say once per parse — 1021 INFO-4 — when a second one-shot prompt value
     * overrides an earlier one (`-p a -p b`, `-p a --prompt=b`, and twins).
     * LAST-WINS IS DELIBERATELY UNCHANGED: callers that lean on it stay
     * working, and this parser's silence, not its order, was the defect.
     *
     * The line is stderr-alone (stdout is the answer/document on every
     * format), and it carries the literal `candy-code: ` label the same way
     * the rest of this file's prefixed shapes do rather than reaching into
     * NonInteractive's private ERROR_LABEL. The sentence itself is
     * catalogued as `cli.argv.prompt.duplicate`.
     */
    private static function noticePromptOverride(?string $previous, bool $alreadyNoticed): bool
    {
        if ($previous === null || $alreadyNoticed) {
            return $alreadyNoticed;
        }

        \fwrite(\STDERR, 'candy-code: ' . Lang::t('cli.argv.prompt.duplicate') . "\n");

        return true;
    }

    /**
     * The hint under the two `--config` value errors {@see parse()} raises.
     *
     * Separate from {@see promptDashHint()} for the reason that one exists at
     * all: the remedy for "the value is missing or flag-shaped" is not the
     * `--prompt=<text>` escape hatch, and printing that under this error is
     * what {@see ParsedArgs::$usageHint} was introduced to stop.
     */
    private static function configValueHint(): string
    {
        return Lang::t('cli.argv.hint.config_value');
    }

    private static function rootValueHint(): string
    {
        return Lang::t('cli.argv.hint.root_value');
    }

    private static function modelValueHint(): string
    {
        return Lang::t('cli.argv.hint.model_value');
    }

    /**
     * The usage error for `--model=` and `--model ""` — an EMPTY value.
     *
     * Worded to match {@see \SugarCraft\Code\Cli\Bootstrap::configError()}'s
     * "but the value is empty", because it answers the identical question for
     * the identical reason: null means "the flag was absent, fall back", so
     * letting '' collapse into null would make an explicitly-typed flag do
     * nothing and say nothing. `--config` has refused this since it was
     * written; this flag now matches its precedent rather than half of it.
     *
     * Raised in the PARSER rather than in a later validator (where
     * `--config`'s equivalent lives) because the parser is the only layer that
     * can still tell `--model ""` from `--model` never given — by the time a
     * validator sees {@see ParsedArgs::$model} the empty token is
     * indistinguishable from absence for the SPACE form, since the token was
     * consumed. The =-form could be checked later; the space form could not,
     * and one site for both beats two that can drift.
     */
    private static function emptyModelError(): string
    {
        return 'candy-code: ' . Lang::t('cli.argv.model.empty');
    }

    /**
     * The usage error for `--permission-mode=` and `--permission-mode ""`.
     *
     * See {@see emptyModelError()} for why an empty value is an error and why
     * the check lives in the parser. The stake is higher for this flag: an
     * operator writing `candy-code --permission-mode="$MODE"` with `$MODE`
     * unset believed a mode was in force, got none, and was told nothing at
     * exit 0 — the run silently continued under whatever the config or
     * `$CANDY_CODE_PERMISSION_MODE` said.
     *
     * That is a flag that SILENTLY DOES NOTHING, not a privilege escalation:
     * the default this fell back to is the documented one for the path
     * ({@see \SugarCraft\Code\Cli\Bootstrap::permissionGate()} — `default`
     * in the TUI, the permissive `bypass-permissions` for `-p`). The defect is
     * the silence, not the destination.
     */
    private static function emptyPermissionModeError(): string
    {
        return 'candy-code: ' . Lang::t('cli.argv.mode_flag.empty');
    }

    /**
     * The usage error for `--max-cost=` and `--max-cost ""`.
     *
     * See {@see emptyPermissionModeError()} for why an empty value is an error
     * and why the check lives in the parser. The stake here is money in the
     * other direction: an operator writing `candy-code --max-cost="$CAP"` with
     * $CAP unset believed a ceiling was bolted to the run, got no ceiling, and
     * the run could spend all night telling them nothing.
     */
    private static function emptyMaxCostError(): string
    {
        return 'candy-code: ' . Lang::t('cli.argv.max_cost.empty');
    }

    /**
     * The remedy line under the `--max-cost` usage errors. Plain prose rather
     * than a DERIVED enumeration (compare {@see permissionModeValueHint()}):
     * the value's grammar is "a positive number of US dollars", which is not a
     * closed set of spellings anything in the code can be re-read against —
     * the numeric check itself lives in Bootstrap::maxCostUsd().
     */
    private static function maxCostValueHint(): string
    {
        return Lang::t('cli.argv.hint.max_cost');
    }

    /**
     * The usage error for `--json-schema=` and `--json-schema ""` — the
     * empty value rule {@see emptyMaxCostError()} documents, on the answer
     * contract axis (AUT-4).
     */
    private static function emptyJsonSchemaError(): string
    {
        return 'candy-code: ' . Lang::t('cli.argv.json_schema.empty');
    }

    /**
     * The remedy line under the `--json-schema` usage errors: plain prose,
     * the same reason {@see maxCostValueHint()} gives — the grammar is "a
     * JSON object, or a file containing one", not a closed set anything can
     * be re-read against; the decode itself lives in NonInteractive.
     */
    private static function jsonSchemaValueHint(): string
    {
        return Lang::t('cli.argv.hint.json_schema');
    }

    /**
     * The usage error for `--append-system-prompt=` and
     * `--append-system-prompt ""` — the empty value rule
     * {@see emptyJsonSchemaError()} documents, on the rule-append axis
     * (C-CTX-4.g).
     */
    private static function emptyAppendSystemPromptError(): string
    {
        return 'candy-code: ' . Lang::t('cli.argv.append_system_prompt.empty');
    }

    /**
     * The remedy line under the `--append-system-prompt` usage errors:
     * plain prose like {@see jsonSchemaValueHint()}'s — the grammar is "any
     * text, or @file to read one from", not a closed set; the read, the cap
     * and the fence all live in NonInteractive.
     */
    private static function appendSystemPromptValueHint(): string
    {
        return Lang::t('cli.argv.hint.append_system_prompt');
    }

    /**
     * The usage error for `--max-turns=` and `--max-turns ""` — the empty
     * value rule {@see emptyMaxCostError()} documents, on the steps axis.
     */
    private static function emptyMaxTurnsError(): string
    {
        return 'candy-code: ' . Lang::t('cli.argv.max_turns.empty');
    }

    /**
     * The remedy line under the `--max-turns` usage errors: plain prose, the
     * same reason {@see maxCostValueHint()} gives — the grammar is "a
     * positive whole number", not a closed set anything can be re-read
     * against; the number check itself lives in Bootstrap::maxTurns().
     */
    private static function maxTurnsValueHint(): string
    {
        return Lang::t('cli.argv.hint.max_turns');
    }

    /**
     * DERIVED from {@see PermissionMode::cases()} rather than written out, so
     * the hint cannot come to list a set of modes the enum no longer has —
     * the same construction {@see \SugarCraft\Code\Cli\Bootstrap::permissionModeFrom()}
     * uses for its own "expected one of" list.
     */
    private static function permissionModeValueHint(): string
    {
        $modes = \implode(', ', \array_map(
            static fn (\SugarCraft\Code\Permissions\PermissionMode $m): string => $m->value,
            \SugarCraft\Code\Permissions\PermissionMode::cases(),
        ));

        return Lang::t('cli.argv.hint.valid_modes', ['modes' => $modes]);
    }

    /**
     * The usage error for a prompt option whose value is a flag.
     *
     * Names the escape hatch inline because the alternative reading — "the
     * tool refuses to accept my prompt" — is the wrong conclusion: a prompt
     * beginning with a dash is legal, it just has to arrive in a token that
     * cannot be mistaken for an option.
     */
    private static function flagAsPromptError(string $option, string $value): string
    {
        return 'candy-code: ' . Lang::t('cli.argv.prompt_is_flag', ['option' => $option, 'value' => $value]);
    }
}
