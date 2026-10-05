<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Lang;

/**
 * Manual argv-parsing, dependency-free — no CLI-flag-parsing lib exists
 * elsewhere in the monorepo, so this follows the precedent set by
 * sugar-post/bin/pop's hand-rolled getopt()-style loop rather than mirroring
 * any single upstream charmbracelet method.
 *
 * Supports: -p/--prompt <value>, --help/-h, --version/-v, the real
 * subcommands {@see ParsedArgs::SUBCOMMANDS} names (`mcp list`, `session
 * list|show|rename|delete|pin|unpin|archive|unarchive`, `models`, `doctor`,
 * `completion bash|zsh|fish` — executed by {@see Subcommands}, dispatched by
 * `bin/sugarcrush` in the same pre-flight place `--help` is) together with
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
 * every typo fall all the way through `bin/sugarcrush` into the blocking
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
     *
     * `configPath` is the config FILE `--config` named, whose EXISTENCE is not
     * validated here (see {@see self::configError()}), and null when the flag
     * is absent, which leaves discovery to
     * {@see \SugarCraft\Crush\Cli\Bootstrap}. A `--config` with no value at
     * all, or one followed by another option, IS decided here — as a usage
     * error, because null would otherwise be indistinguishable from absence
     * and silently restore discovery; see the branch itself.
     *
     * A bare `--` is the POSIX end-of-options separator: it is consumed, and
     * every argument after it is an operand, never a flag — so
     * `sugarcrush -- --version` opens the TUI rather than reporting an
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
     * (`sugarcrush explain src/Chat.php`), and claiming it as the root turned
     * that request into a "not a directory" usage error.
     *
     * `promptRequested` records whether the user asked for one-shot mode at
     * all (`-p` / `--prompt` / `--prompt=` / `run`), independently of whether
     * a prompt VALUE followed. `bin/sugarcrush` dispatches on that flag, not
     * on `prompt !== null`, so a bare `sugarcrush -p` reaches
     * {@see NonInteractive::run()}'s "no prompt given" error instead of
     * silently opening the TUI.
     *
     * A prompt VALUE that is itself flag-shaped is a usage error rather than a
     * prompt (follow-up #48): `sugarcrush -p --verbose` used to run a one-shot
     * turn whose prompt text was the literal string "--verbose", which is
     * never what the caller meant and is unreadable in a CI log. The offending
     * token is left unconsumed and {@see ParsedArgs::$usageError} carries the
     * message; `--prompt=--verbose` remains the escape hatch for a prompt that
     * genuinely starts with a dash, because the `=` form takes its value from
     * the same token and so cannot be ambiguous.
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
        $usageError = null;
        $usageHint = null;
        $configPath = null;
        $model = null;
        $permissionMode = null;
        $endOfOptions = false;
        $subcommand = null;
        $subcommandArgs = [];
        $subcommandFlags = [];
        $continueSession = false;
        $resumeSession = null;
        $resumeRequested = false;

        $i = 1; // skip $argv[0] (script name)
        while ($i < \count($argv)) {
            $arg = $argv[$i];

            // Both `--` branches come first so that no later branch — the
            // `run` subcommand, any flag, and above all the unknown-flag
            // recorder — can claim a token the user has explicitly moved out
            // of flag space.
            if ($endOfOptions) {
                // Routed to the SUBCOMMAND's operands when one is open, not to
                // $positional: `sugarcrush session delete -- -weird-id` has to
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
            // it silently discarded the subcommand: `sugarcrush
            // --output-format json run` parsed to promptRequested=false and
            // fell through bin/sugarcrush's dispatch into the blocking
            // full-screen TUI — the same hang crush_code.md Phase 0 item 3
            // fixed for `--version`, reopened by flag order alone.
            //
            // $promptRequested guards the SECOND occurrence, which keeps the
            // two documented non-subcommand readings intact: `-p run` is a
            // prompt (its value was consumed by the -p branch, so it never
            // reaches here at all), and `sugarcrush run run` is a prompt of
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
            // dispatched by `bin/sugarcrush` in the same pre-flight place
            // --help/--version are: every one of them has to answer on a
            // machine with no provider, no config and no TTY, so a subcommand
            // that reached Program::run() would be the "`--help` opens the
            // TUI" bug (crush_code.md Phase 0 item 3) all over again.
            //
            // Recognised at ANY operand position for the same reason `run` is
            // (see above): pinning it to $argv[1] means `sugarcrush
            // --output-format json doctor` silently discards the subcommand
            // and boots the TUI. Guarded by `$subcommand === null` so a
            // SECOND verb is data — `sugarcrush completion doctor` asks for a
            // shell named "doctor" (a usage error with a message) rather than
            // re-arming itself into a health check.
            //
            // `!$promptRequested` keeps `sugarcrush run models` a one-shot
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
                $prompt = $next;
                $promptRequested = true;
                $i += 2;
                continue;
            }

            // --prompt=<value>  (no space)
            if (\str_starts_with($arg, '--prompt=')) {
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
            if ($arg === '--output-format') {
                $outputFormat = $argv[++$i] ?? $outputFormat;
                ++$i;
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
                            ? 'sugarcrush: ' . Lang::t('cli.argv.root.missing')
                            : 'sugarcrush: ' . Lang::t('cli.argv.root.flag', ['option' => $next]);
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
            //   `sugarcrush --config`        stored null, which configError()
            //     reads as absence -- so the run fell straight through to
            //     Bootstrap's own discovery and applied the DEFAULT permission
            //     policy on an invocation that explicitly named a file, and on
            //     the TUI path entered the alt-screen while doing it (MEASURED:
            //     rc 124 under a 15s bound, stdout beginning `\e[?1049h`). That
            //     is the "`--help` opens the TUI" class again, and the silent
            //     policy downgrade `--config` validation exists to prevent.
            //
            //   `sugarcrush --config -p hi`  consumed `-p` as the file name and
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
                            ? 'sugarcrush: ' . Lang::t('cli.argv.config.missing')
                            : 'sugarcrush: ' . Lang::t('cli.argv.config.flag', ['option' => $next]);
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
                            ? 'sugarcrush: ' . Lang::t('cli.argv.model.missing')
                            : 'sugarcrush: ' . Lang::t('cli.argv.model.flag', ['option' => $next]);
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
            // $SUGARCRUSH_PERMISSION_MODE and the config key, so all three
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
                            ? 'sugarcrush: ' . Lang::t('cli.argv.mode_flag.missing')
                            : 'sugarcrush: ' . Lang::t('cli.argv.mode_flag.flag', ['option' => $next]);
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
            // (`sugarcrush ./app --resume`) or in --root.
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
                                $usageError = 'sugarcrush: ' . Lang::t('cli.argv.verb_flag.no_value', ['verb' => $subcommand, 'flag' => $name]);
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
                                $usageError = 'sugarcrush: ' . ($next === null
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
                            $usageError = 'sugarcrush: ' . Lang::t('cli.argv.verb_flag.empty', ['verb' => $subcommand, 'flag' => $name]);
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

            // Unrecognised flag — recorded, never applied. bin/sugarcrush
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
        // living in NonInteractive would let `sugarcrush --output-format xml`
        // open the alt-screen while `sugarcrush -p x --output-format xml` failed.
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
                $usageError = 'sugarcrush: ' . Lang::t('cli.argv.output_format.unsupported', ['format' => $outputFormat]);
                $usageHint = Lang::t('cli.argv.hint.output_formats', ['formats' => \implode(', ', ParsedArgs::OUTPUT_FORMATS)]);
            }
        }

        // --continue and --resume both choose the session a TUI launch opens,
        // so they cannot both win, and neither means anything to a one-shot
        // run, which opens no session at all. Refused rather than ignored: a
        // `-c -p "…"` that silently ran without the earlier conversation
        // would look like a model that forgot everything.
        if ($usageError === null && $continueSession && $resumeRequested) {
            $usageError = 'sugarcrush: ' . Lang::t('cli.argv.continue_with_resume');
            $usageHint = Lang::t('cli.argv.hint.continue_or_resume');
        }
        if ($usageError === null && $promptRequested && ($continueSession || $resumeRequested)) {
            $usageError = 'sugarcrush: ' . Lang::t('cli.argv.session_with_prompt', [
                'flag' => $continueSession ? '--continue' : '--resume',
            ]);
            $usageHint = Lang::t('cli.argv.hint.drop_prompt');
        }

        // The FIRST positional, when it looks like a path, is the root (see
        // the doc-block for why only the first). It leaves the list; the rest
        // stay for resolveOperands().
        if ($root === null && $positional !== [] && self::looksLikePath($positional[0])) {
            $root = \array_shift($positional);
        }

        return ParsedArgs::from($help, $prompt, $root, $outputFormat, $unknownFlags, $promptRequested, $version, $usageError, $usageHint, $configPath, $subcommand, $subcommandArgs, $model, $permissionMode, $continueSession, $resumeSession, $resumeRequested, \array_values($positional), $subcommandFlags);
    }

    /**
     * The filesystem half of operand handling (audit CLI-2): turn a first
     * operand that names an existing directory into the root, and the words
     * after it into the TUI's first prompt.
     *
     * Kept OUT of {@see parse()} for the reason {@see rootError()} is: parse()
     * stays a pure argv->value-object transform. `bin/sugarcrush` calls this
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
     *  - `sugarcrush src` with `src/` present: `src` becomes the root, exactly
     *    as `sugarcrush ./src` always did. Only the FIRST operand, and only
     *    when nothing claimed the root yet -- a directory name further along
     *    is a word of the prompt. It applies to `-p`/`run` and subcommand runs
     *    too, because a path-shaped operand already did.
     *  - A single operand left beside a root that itself names a directory
     *    (`sugarcrush src lib`, `--root /tmp src`) is a usage error: two
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
                'sugarcrush: ' . Lang::t('cli.argv.root_twice', [
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
                'sugarcrush: ' . ($one
                    ? Lang::t('cli.argv.unexpected_after_prompt.one', ['operands' => $listed])
                    : Lang::t('cli.argv.unexpected_after_prompt.many', ['operands' => $listed])),
                Lang::t('cli.argv.hint.quote_prompt'),
            );
        }

        $prompt = \implode(' ', $leftover);
        if ($args->subcommand !== null || \trim($prompt) === '') {
            return $args->withUsageError(
                'sugarcrush: ' . ($one
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
     * transform with no filesystem access; `bin/sugarcrush` calls this
     * immediately after parsing, next to the unknown-flag check.
     *
     * Validated at all because the root is not merely a convenience: it
     * becomes {@see \SugarCraft\Crush\App\App::$root}, then
     * {@see \SugarCraft\Crush\Runtime}'s `HookContext::$projectRoot`, then
     * the `proc_open()` cwd of every {@see \SugarCraft\Crush\Hooks\ScriptHook}.
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

        return 'sugarcrush: ' . Lang::t('cli.argv.root.no_such_directory', ['root' => $args->root]);
    }

    /**
     * The usage error for a `--config` that names no readable file, or null
     * when the parsed config path is usable (or absent).
     *
     * Kept OUT of {@see parse()} for the same reason {@see rootError()} is:
     * parsing stays a pure argv->value-object transform with no filesystem
     * access, and `bin/sugarcrush` calls this immediately after that one.
     *
     * Validated at all, and in the same class as `--root /typo`, because the
     * file this names is not a preference file: {@see
     * \SugarCraft\Crush\Cli\Bootstrap::permissionConfig()} reads the permission
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
            return 'sugarcrush: ' . Lang::t('cli.argv.config.empty');
        }

        if (!is_file($args->configPath)) {
            return 'sugarcrush: ' . Lang::t('cli.argv.config.no_such_file', ['path' => $args->configPath]);
        }

        if (!is_readable($args->configPath)) {
            return 'sugarcrush: ' . Lang::t('cli.argv.config.not_readable', ['path' => $args->configPath]);
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
     * The hint that accompanies every {@see flagAsPromptError()}.
     *
     * Lives here rather than in `bin/sugarcrush` because the binary now emits
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
     * Worded to match {@see \SugarCraft\Crush\Cli\Bootstrap::configError()}'s
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
        return 'sugarcrush: ' . Lang::t('cli.argv.model.empty');
    }

    /**
     * The usage error for `--permission-mode=` and `--permission-mode ""`.
     *
     * See {@see emptyModelError()} for why an empty value is an error and why
     * the check lives in the parser. The stake is higher for this flag: an
     * operator writing `sugarcrush --permission-mode="$MODE"` with `$MODE`
     * unset believed a mode was in force, got none, and was told nothing at
     * exit 0 — the run silently continued under whatever the config or
     * `$SUGARCRUSH_PERMISSION_MODE` said.
     *
     * That is a flag that SILENTLY DOES NOTHING, not a privilege escalation:
     * the default this fell back to is the documented one for the path
     * ({@see \SugarCraft\Crush\Cli\Bootstrap::permissionGate()} — `default`
     * in the TUI, the permissive `bypass-permissions` for `-p`). The defect is
     * the silence, not the destination.
     */
    private static function emptyPermissionModeError(): string
    {
        return 'sugarcrush: ' . Lang::t('cli.argv.mode_flag.empty');
    }

    /**
     * DERIVED from {@see PermissionMode::cases()} rather than written out, so
     * the hint cannot come to list a set of modes the enum no longer has —
     * the same construction {@see \SugarCraft\Crush\Cli\Bootstrap::permissionModeFrom()}
     * uses for its own "expected one of" list.
     */
    private static function permissionModeValueHint(): string
    {
        $modes = \implode(', ', \array_map(
            static fn (\SugarCraft\Crush\Permissions\PermissionMode $m): string => $m->value,
            \SugarCraft\Crush\Permissions\PermissionMode::cases(),
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
        return 'sugarcrush: ' . Lang::t('cli.argv.prompt_is_flag', ['option' => $option, 'value' => $value]);
    }
}
