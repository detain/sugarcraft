<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

/**
 * The `-p "<prompt>"` / `run "<prompt>"` one-shot, non-interactive CLI path.
 *
 * Mirrors Claude Code's `-p`/`--print`, opencode's `run` subcommand, Codex's
 * `exec`, and Gemini CLI's `-p`/`--prompt`: take a single prompt (optionally
 * with piped stdin prepended as context), run it through the existing
 * synchronous backend, print the result, and exit with a Unix-style status
 * code — no TUI, no alt-screen, no render loop.
 *
 * `EngineBackend::complete()` (src/Backend/EngineBackend.php:130) already
 * runs the full bounded agentic loop synchronously and returns a finished
 * `Message` with no `Program`/`Chat` involved — this class is purely the
 * argv/stdin/stdout/exit-code plumbing wrapped around that existing
 * capability. See crush_feat.md section 2 ("CLI commands, parameters, help
 * screens & non-interactive mode"), Recommendations 2 ("Add sugarcrush -p
 * ... one-shot mode built directly on EngineBackend::complete()"), 3
 * ("--output-format text|json on the one-shot path"), and 4 ("Exit codes
 * matching the Unix convention already implied by other bins in the repo").
 *
 * Production reachability: `bin/sugarcrush` calls `ArgvParser::parse($argv)`
 * first, then `exit(NonInteractive::run($args, null, $args->outputFormat))`
 * whenever `$args->promptRequested` is true (`-p`/`--prompt`/`--prompt=` or
 * the `run "<prompt>"` form appeared at all — including with no value, so the
 * "no prompt given" branch below is the single owner of that error rather
 * than the binary silently opening the TUI), ahead of ever constructing
 * `Bootstrap::chat()`/`Program` — so a real `sugarcrush -p "..." \
 * --output-format json` invocation reaches this class directly, with
 * `$args->outputFormat` (parsed from `--output-format`/`--output-format=`,
 * defaulting to `ParsedArgs::DEFAULT_OUTPUT_FORMAT`) flowing straight into
 * {@see self::format()}, and no TUI or alt-screen ever entered.
 *
 * Provider selection here is deliberately STRICTER than the TUI's. Both paths
 * agree on WHICH provider a run selected — {@see
 * Bootstrap::selectedProviderName()} is the single definition — but they
 * disagree on what to do when that provider cannot be built:
 * {@see Bootstrap::backend()} warns and degrades to the offline
 * {@see \SugarCraft\Crush\Providers\EchoProvider}, which is right for a
 * developer opening an editor, while this class calls
 * {@see Bootstrap::backendFor()} and hard-fails, which is right for a caller
 * whose entire view of the run is stdout plus an exit code (crush_code.md
 * Phase 0 item 10 / §5). The Echo provider is still reachable from here — it
 * is simply never *substituted* for something else.
 */
final class NonInteractive
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_JSON = 'json';

    /** The prompt ran and produced an answer. */
    public const EXIT_OK = 0;

    /**
     * "Ran and failed": the backend was usable and threw anyway — an
     * unreachable host, a rejected key, a rate limit, a model error. Also the
     * code for an answer that arrived but could not be rendered in the
     * requested output format ({@see self::format()}'s `encoding` type).
     *
     * Everything under this code has the same operational meaning: something
     * was attempted, and attempting it again might produce a different result.
     *
     * Since crush_code.md Phase 5 item 8 that is a weaker statement than it
     * used to be FOR ONE OF THE BACKENDS, and both halves of that matter to
     * whoever wires a CI gate on this code.
     *
     * When the run's backend is the engine — which is every provider-selected
     * run and the offline default — the transient shapes
     * ({@see \SugarCraft\Crush\Providers\TransientFailure}) have already been
     * attempted `TransientFailure::MAX_ATTEMPTS` times inside the provider call
     * before this code is returned. That is TOTAL attempts, not retries: at the
     * current value, one call and up to two retries. So a 1 from the engine
     * means "every attempt this run was willing to make failed", an outer retry
     * is still worth having for an outage longer than the seconds of backoff
     * that policy spends, and a gate that retries instantly is duplicating work
     * that just happened.
     *
     * When `$SUGARCRUSH_BACKEND_CMD` selects {@see \SugarCraft\Crush\Backend\CommandBackend}
     * or `$SUGARCRUSH_BACKEND_CMD_STREAM` selects
     * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend}
     * — or an embedder injects its own {@see \SugarCraft\Crush\Backend} — nothing
     * has been retried at all: `grep -rn TransientFailure src/` reaches only
     * `Runtime`, `AgentManager` and the providers, so a delegating backend's
     * failure arrives here on its first attempt. For those runs a 1 still means
     * "one attempt failed", exactly as it did before item 8.
     */
    public const EXIT_FAILURE = 1;

    /**
     * "Never ran": the invocation or the configuration is wrong, so nothing
     * was attempted and a retry cannot help. Three shapes reach it —
     *
     *   - the provider this run explicitly selected could not even be
     *     CONSTRUCTED: an unknown name, or a required credential/field missing
     *     ({@see \SugarCraft\Crush\Providers\ProviderFactory::validateRequiredKeys()});
     *   - a usage error `bin/sugarcrush` catches pre-flight: an unrecognised
     *     flag, a `--root` naming no directory (both via {@see
     *     self::failUsage()});
     *   - a one-shot invocation with no prompt VALUE (`sugarcrush -p`,
     *     `sugarcrush run`), which is the same class: the invocation is
     *     malformed and nothing ran.
     *
     * That last one exited 1 in the first cut of this class and was moved here
     * deliberately. "No prompt given" is not "ran and failed" by any reading —
     * the backend is never even selected — and a CI gate that retries on 1
     * would have retried it forever. The 1 is preserved for the case it
     * describes (see {@see self::EXIT_FAILURE}); the one-shot path is
     * unreleased (pre-1.0, no tagged version of this binary exists), so the
     * back-compat cost of correcting it now is limited to this repo's own
     * tests, and correcting it later would not be.
     *
     * 2 rather than 1 deliberately, and it is the whole point of
     * crush_code.md Phase 0 item 10: no provider constructor in this codebase
     * performs any I/O (the OpenAI/Anthropic/SGLang factories only build a
     * Guzzle client, Bedrock only an AWS SDK client, Vertex only stores
     * strings), so a throw from construction is *always* a configuration
     * error and *never* a network one. Network failures surface later, from
     * `Backend::complete()`, and keep {@see self::EXIT_FAILURE}. A CI gate
     * can therefore tell "my config is wrong, retrying will not help" from
     * "the model was unreachable, retry might" — a distinction that did not
     * exist while both shapes silently became a canned Echo reply at exit 0.
     *
     * The value matches `bin/sugarcrush`'s existing usage-error exit (an
     * unrecognised flag, a `--root` naming no directory): same class of
     * problem — the invocation is wrong, nothing was attempted.
     */
    public const EXIT_CONFIG = 2;

    /**
     * Matches Claude Code's documented 10MB piped-stdin cap
     * (code.claude.com/docs/en/headless) so a runaway pipe can't exhaust
     * memory before the prompt is even sent to a backend.
     */
    private const MAX_STDIN_BYTES = 10 * 1024 * 1024;

    /**
     * Run one prompt to completion and print the result.
     *
     * $backend can be supplied directly — used by tests to avoid depending on
     * `SUGARCRUSH_*` environment state, and available to a future headless
     * server mode that already holds a constructed `Backend`. When it is
     * null, one is built here — and that is where this path deliberately
     * stops matching the TUI's leniency, see {@see
     * self::failUnusableProvider()}.
     *
     * @return int Unix exit code: {@see self::EXIT_OK} on success,
     *   {@see self::EXIT_CONFIG} when no prompt was given or an explicitly
     *   selected provider is unusable, {@see self::EXIT_FAILURE} when the
     *   backend threw — extending the existing `sugar-post`/`sugar-wishlist`
     *   bins' 0/1 convention (crush_feat.md Recommendation 4) with
     *   `bin/sugarcrush`'s own 2-means-usage-error.
     */
    public static function run(ParsedArgs $args, ?Backend $backend = null, string $outputFormat = self::FORMAT_TEXT): int
    {
        if ($args->prompt === null || \trim($args->prompt) === '') {
            return self::failUsage(
                'sugarcrush: no prompt given - pass -p "<prompt>" or `sugarcrush run "<prompt>"`',
                $outputFormat,
            );
        }

        if ($backend === null) {
            $providerName = Bootstrap::selectedProviderName();

            if ($providerName === null) {
                // No provider was asked for, so nothing is being substituted
                // for one — see noticeOfflineDefault() for why this stays
                // lenient. The notice is still worth a line of stderr.
                $backend = self::consoleBackend($args->root, null);
                self::noticeOfflineDefault();
            } else {
                try {
                    $backend = self::consoleBackend($args->root, $providerName);
                } catch (PermissionConfigException $e) {
                    // An unusable permission policy is not this provider's
                    // fault and must not be reported as if it were — it
                    // propagates to `bin/sugarcrush`, which reports it with
                    // its own message. See Bootstrap::permissionGate().
                    throw $e;
                } catch (\Throwable $e) {
                    return self::failUnusableProvider($providerName, $e, $outputFormat);
                }
            }

            // Building a backend scans the skill trees, and this path never
            // went through Bootstrap::chat(), which is where the one-line
            // "some skill files could not be read" notice was raised — so a
            // `-p` run collected the skips and told nobody. Only meaningful
            // when a backend was actually built here: a caller that supplied
            // one (the tests, a headless server) has scanned nothing.
            Bootstrap::reportSkillSkips();
            // And the other half of the same silence: a project skills
            // directory refused wholesale for resolving out of the checkout.
            Bootstrap::reportProjectTierRefusals();
        }

        $history = self::historyFrom($args->prompt, self::readStdinIfPiped());

        try {
            $message = $backend->complete($history);
        } catch (\Throwable $e) {
            \fwrite(\STDERR, $e->getMessage() . "\n");
            self::emitErrorDocument($outputFormat, 'backend', $e->getMessage(), null);

            return self::EXIT_FAILURE;
        }

        // The answer exists but may not be representable in the format the
        // caller asked for. Emitting the typed document instead of an empty
        // stdout keeps the JSON contract intact on this branch too, and 1
        // rather than 2 because something really did run.
        try {
            $rendered = self::format($message, $outputFormat);
        } catch (\JsonException $e) {
            \fwrite(\STDERR, 'sugarcrush: the answer could not be encoded as JSON: ' . $e->getMessage() . "\n");
            self::emitErrorDocument($outputFormat, 'encoding', $e->getMessage(), null);

            return self::EXIT_FAILURE;
        }

        echo $rendered . "\n";

        return self::EXIT_OK;
    }

    /**
     * Report a usage error — the invocation itself is malformed, so nothing
     * ran — and return {@see self::EXIT_CONFIG}.
     *
     * Public because `bin/sugarcrush` detects two of these BEFORE this class
     * is reached (an unrecognised flag, a `--root` naming no directory) and
     * used to `exit(2)` straight from the binary with an empty stdout — which
     * broke the "`--output-format json` always puts exactly one JSON object on
     * stdout" contract on two of the three exit-2 causes the README lists.
     * Routing them through here rather than hand-rolling a second document in
     * the binary is what keeps the two from drifting: there is one definition
     * of the failure shape, in {@see self::emitErrorDocument()}.
     *
     * THAT ARGUMENT USED TO BE STATED WITHOUT AN EXCEPTION, and there is one.
     * WHAT IS TRUE NOW: `bin/sugarcrush`'s autoload guard DOES hand-roll a
     * second document (E84), because it runs before `vendor/autoload.php` has
     * been found and cannot call this method at all. WHY THE RULE STILL EARNS
     * ITS PLACE: that is the only site in the project where this class is
     * unreachable, so it is the only site where the choice is between a
     * duplicate and an empty pipe. Every OTHER pre-flight check in the binary
     * can reach here and therefore must — a second hand-rolled document
     * anywhere the autoloader is available would be drift with no reason
     * behind it.
     *
     * @param string $message One line, and also the JSON document's
     *   `error.message`.
     * @param string|null $hint An extra stderr-only line (e.g. "Try
     *   `sugarcrush --help`…"). Deliberately not part of the document: a
     *   machine consumer branches on `error.type`, and the hint is prose
     *   aimed at whoever is reading the terminal.
     */
    public static function failUsage(string $message, string $outputFormat, ?string $hint = null): int
    {
        \fwrite(\STDERR, $message . "\n" . ($hint === null ? '' : $hint . "\n"));
        self::emitErrorDocument($outputFormat, 'usage', $message, null);

        return self::EXIT_CONFIG;
    }

    /**
     * Report an explicitly selected provider that could not be constructed,
     * and refuse to answer (crush_code.md Phase 0 item 10).
     *
     * This is the asymmetry the item exists to create. {@see
     * Bootstrap::backend()} — which the interactive TUI still reaches through
     * {@see Bootstrap::app()} — catches this same throw, warns, and hands back
     * a working offline backend, because refusing to open the editor over a
     * missing API key would be a worse experience than an offline session a
     * developer can still browse files and run `/help` in. A one-shot run has
     * no such consolation: its entire observable output is one string on
     * stdout and one exit code, and a canned Echo sentence at exit 0 is
     * indistinguishable from a real answer to the CI job consuming it.
     *
     * The provider name is named in the message because the whole failure is
     * "you asked for X and X is unusable" — and the remediation line names the
     * SOURCE, because {@see Bootstrap::selectedProviderName()} has two of them
     * and they are fixed in completely different places. Telling an operator
     * to "unset SUGARCRUSH_PROVIDER" when the name actually came from a
     * persisted Ctrl+P "Switch model" choice sends them looking for a variable
     * nothing ever set; the config file is named instead, since that is the
     * file they have to edit. The branch mirrors
     * {@see Bootstrap::selectedProviderName()}'s own precedence: a non-empty
     * `$SUGARCRUSH_PROVIDER` wins, so if it is set it IS the source.
     *
     * Note this also refuses the `$SUGARCRUSH_BACKEND_CMD` /
     * `$SUGARCRUSH_BACKEND_CMD_STREAM` tiers that {@see
     * Bootstrap::backend()} would drop to next when BOTH are set. Substituting
     * a shell-out for a requested provider is a smaller lie than substituting
     * Echo, but it is the same lie: the caller reads one string and cannot see
     * which backend produced it. Clearing the selection selects the command
     * backend deliberately, which is what the message says.
     */
    private static function failUnusableProvider(string $providerName, \Throwable $e, string $outputFormat): int
    {
        $fromEnv = \getenv('SUGARCRUSH_PROVIDER');
        $remedy = ($fromEnv !== false && $fromEnv !== '')
            ? 'unset SUGARCRUSH_PROVIDER to select the fallback deliberately'
            : \sprintf(
                'remove the "provider" entry from %s — the persisted Ctrl+P "Switch model" choice this run'
                . ' selected it from — to select the fallback deliberately',
                Bootstrap::userConfigPath(),
            );

        \fwrite(\STDERR, \sprintf(
            "sugarcrush: provider '%s' is unusable: %s\n"
            . "sugarcrush: refusing to silently answer from a different backend on a one-shot run"
            . " — fix the provider configuration, or %s.\n",
            $providerName,
            $e->getMessage(),
            $remedy,
        ));

        self::emitErrorDocument($outputFormat, 'provider_configuration', $e->getMessage(), $providerName);

        return self::EXIT_CONFIG;
    }

    /**
     * Say on stderr that this run has no provider at all and the reply below
     * came from the offline {@see \SugarCraft\Crush\Providers\EchoProvider}.
     *
     * Not an error, and deliberately not fatal: with nothing configured
     * nothing was substituted for anything, `sugarcrush -p "hi"` with zero
     * config is the documented zero-network smoke test, and turning "no
     * config" into a crash would make the offline provider unreachable from
     * the one-shot path entirely rather than merely un-substitutable. But the
     * CI caller who simply forgot to export `$SUGARCRUSH_PROVIDER` hits the
     * same "plausible canned sentence" trap as the misconfigured one, so the
     * run says so out loud on the stream that is not the result.
     *
     * Silent when either shell-out tier is what got selected
     * (`$SUGARCRUSH_BACKEND_CMD` or `$SUGARCRUSH_BACKEND_CMD_STREAM`): that IS
     * an explicit backend choice, and it really did run. The condition asks
     * {@see Bootstrap::selectedProviderLabel()} rather than reading env vars
     * here, so a tier added to {@see Bootstrap::backend()} cannot leave this
     * notice claiming nothing was configured on a run that configured
     * something — which is what a second copy of the list would have done.
     */
    private static function noticeOfflineDefault(): void
    {
        if (Bootstrap::selectedProviderLabel()[0] !== 'echo') {
            return;
        }

        \fwrite(
            \STDERR,
            "sugarcrush: no provider configured (SUGARCRUSH_PROVIDER, SUGARCRUSH_BACKEND_CMD and"
            . " SUGARCRUSH_BACKEND_CMD_STREAM unset, none persisted); answering from the offline"
            . " echo provider.\n"
        );
    }

    /**
     * Write the machine-readable failure document, for `--output-format json`
     * only.
     *
     * Every failure branch above already writes a human line to stderr, which
     * is the whole story for `--output-format text`. It is NOT the whole story
     * for JSON: a caller running `sugarcrush -p ... --output-format json | jq
     * -r .result` reads stdout and nothing else, and on every failure path
     * stdout used to be completely empty — so `jq` failed on an unexpected EOF
     * and the caller learned nothing beyond "something went wrong somewhere in
     * the pipeline". Emitting a document keeps the contract "if you asked for
     * JSON, stdout is always one JSON object" true on both outcomes.
     *
     * `result` is present and null rather than omitted so a consumer reading
     * only `.result` keeps working; `error.type` is the field to branch on.
     *
     * `error.type` is NOT the exit code by another name — the earlier claim
     * that it "mirrors" it was wrong, because `usage` reaches this method from
     * two different places. Each type does determine exactly one exit code,
     * but the mapping is many-to-one:
     *
     *   `usage`                 -> {@see self::EXIT_CONFIG} (2), from the
     *                              no-prompt branch here and from
     *                              `bin/sugarcrush`'s pre-flight checks via
     *                              {@see self::failUsage()}
     *   `provider_configuration`-> {@see self::EXIT_CONFIG} (2)
     *   `installation`          -> {@see self::EXIT_CONFIG} (2), and it is the
     *                              one type this method never emits — see below
     *   `backend`               -> {@see self::EXIT_FAILURE} (1)
     *   `encoding`              -> {@see self::EXIT_FAILURE} (1)
     *
     * A consumer that kept the exit code and wants to know WHICH kind of 2 it
     * got is exactly who `type` is for.
     *
     * `installation` IS EMITTED BY `bin/sugarcrush`'s AUTOLOAD GUARD, hand-rolled
     * there rather than routed here, and the duplication is deliberate: that
     * branch fires when `vendor/autoload.php` was not found, so THIS CLASS
     * CANNOT BE LOADED at that point in the boot. `json_encode()` is core and
     * needs no autoloader, which is why the guard can still honour the contract
     * (E84) — the shape's OWNER is unreachable there, the shape is not. The two
     * are kept from drifting by
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushAutoloadGuardTest},
     * which asserts them key-for-key against each other; if you change the keys
     * or the flags here, that test reds until the guard is changed to match.
     */
    private static function emitErrorDocument(string $outputFormat, string $type, string $message, ?string $provider): void
    {
        if ($outputFormat !== self::FORMAT_JSON) {
            return;
        }

        $error = ['type' => $type, 'message' => $message];
        if ($provider !== null) {
            $error['provider'] = $provider;
        }

        try {
            $json = self::encodeDocument(['result' => null, 'error' => $error]);
        } catch (\JsonException) {
            // Unreachable with the flags below, and handled anyway because an
            // empty stdout is the one outcome this whole method exists to
            // prevent. $type is always one of this class's own ASCII literals,
            // so the replacement document is encodable by construction; only
            // the provider-supplied message — the sole field that can carry
            // bytes we do not control — is dropped.
            $json = \sprintf(
                '{"result":null,"error":{"type":"%s","message":"error message could not be encoded as JSON"}}',
                $type,
            );
        }

        echo $json . "\n";
    }

    /**
     * Encode one contract document.
     *
     * WHY the flags, and why this is not an inline `json_encode()` any more:
     * `json_encode()` returns `false` — not a partial string — on a single
     * invalid UTF-8 byte, and `(string) false` is `''`, so the JSON contract
     * broke in precisely the case it exists to cover. A provider exception
     * quoting a response body (Guzzle embeds body excerpts, and a 500 from a
     * proxy is routinely not UTF-8) put a bare newline on stdout at exit 1 and
     * `jq` died on a syntax error — the empty pipe, from the code written to
     * prevent it. `JSON_INVALID_UTF8_SUBSTITUTE` replaces those bytes with
     * U+FFFD so a document is always produced, and `JSON_THROW_ON_ERROR` makes
     * any other failure loud at the call site rather than silently empty.
     *
     * PUBLIC because {@see Subcommands} emits contract documents of its own
     * (`sugarcrush models --output-format json`, `doctor`, `session list`) and
     * has to produce the SAME shape with the SAME flags. A second
     * `json_encode()` over there would be a second definition of the document —
     * exactly the drift {@see failUsage()} routes through
     * {@see emitErrorDocument()} to avoid — and it would re-open the
     * invalid-UTF-8 hole described above for every session name and MCP command
     * string, which are bytes this package does not control.
     *
     * @param array<string, mixed> $document
     *
     * @throws \JsonException
     */
    public static function encodeDocument(array $document): string
    {
        $json = \json_encode(
            $document,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );

        // json_encode() is declared `string|false`; JSON_THROW_ON_ERROR turns
        // every false into an exception, so this is unreachable — asserted
        // rather than silently cast, because the silent cast IS the bug above.
        return $json === false
            ? throw new \JsonException('json_encode() returned false without raising')
            : $json;
    }

    /**
     * Build the one-shot conversation history: piped stdin context (if
     * any) followed by the CLI prompt, collapsed into a single user turn —
     * mirrors Claude Code's `cat file | claude -p '...'` contract, where
     * stdin is context prepended to the prompt rather than a separate
     * message.
     *
     * @return list<Message>
     */
    public static function historyFrom(string $prompt, ?string $stdinContext): array
    {
        $content = ($stdinContext !== null && $stdinContext !== '')
            ? $stdinContext . "\n\n" . $prompt
            : $prompt;

        return [Message::user($content)];
    }

    /**
     * Read piped stdin, capped at {@see self::MAX_STDIN_BYTES}. Returns
     * null when $stream is a TTY (nothing was piped, mirrors
     * `sugar-post/bin/pop`'s `!stream_isatty(STDIN)` guard) or when the
     * pipe was empty.
     *
     * $stream defaults to the real STDIN; tests pass a `php://memory`
     * stream instead so the suite never blocks on, or depends on, the
     * PHPUnit process's actual stdin.
     *
     * @param resource $stream
     */
    public static function readStdinIfPiped($stream = \STDIN): ?string
    {
        if (\stream_isatty($stream)) {
            return null;
        }

        $data = @\stream_get_contents($stream, self::MAX_STDIN_BYTES + 1);
        if ($data === false || $data === '') {
            return null;
        }

        if (\strlen($data) > self::MAX_STDIN_BYTES) {
            \fwrite(\STDERR, "sugarcrush: piped stdin exceeds 10MB cap; truncating.\n");
            $data = \substr($data, 0, self::MAX_STDIN_BYTES);
        }

        return $data;
    }

    /**
     * The backend a one-shot run executes on — the ONLY route `run()` has to
     * one when the caller did not supply it.
     *
     * Two things happen here rather than at the two call sites. The first is
     * the existing strictness split: a run that named no provider gets
     * {@see Bootstrap::backend()}, which may degrade to the offline engine,
     * and a run that named one gets {@see Bootstrap::backendFor()}, which
     * throws instead (see the class docblock).
     *
     * The second is new, and is the reason this is a method: BOTH branches
     * pass `$consolePermissionPrompt: true`, attaching
     * {@see HeadlessPermissionPrompt} as the engine's approver. That closes the
     * hole where a `-p` run under `default`/`accept-edits`/`auto` had every
     * ASK settled as "permission required and no approver is attached to this
     * run" — {@see \SugarCraft\Crush\Runtime::settleAsk()}'s fail-closed arm —
     * because nothing in `src/` or `bin/` called
     * {@see \SugarCraft\Crush\Backend\EngineBackend::withPermissionApprover()}
     * at all. This path is the right owner: it holds stdin, it is synchronous
     * all the way down to `complete()`, and it never enters an alt-screen.
     *
     * Flag passed at BOTH branches, never one: an approver attached on only
     * one of them is an ASK answered when the run happens to reach that
     * branch and refused when it reaches the other, which is worse than
     * either behaviour applied consistently.
     *
     * @throws \SugarCraft\Crush\Permissions\PermissionConfigException
     * @throws \Throwable when $providerName names a provider that cannot be built
     */
    public static function consoleBackend(?string $root, ?string $providerName): Backend
    {
        return $providerName === null
            ? Bootstrap::backend($root, null, null, true)
            : Bootstrap::backendFor($providerName, $root, null, null, true);
    }

    /**
     * Render the finished Message per --output-format.
     *
     * `text` (default): the assistant's raw content, matching the plain
     * stdout contract of every tool surveyed in crush_feat.md section 2.
     * `json`: `{"result": "<content>"}`. This is a deliberately minimal
     * first cut of crush_feat.md Recommendation 3 — the recommendation's own
     * sketch also includes `session_id` and `usage` (token-cost) fields,
     * which this step does not yet surface; a caller piping through `jq
     * '.usage'` per that recommendation's full intent gets nothing today.
     * Any value other than `self::FORMAT_JSON` falls back to plain text.
     *
     * The success path had the same invalid-UTF-8 hole the failure path did
     * (see {@see self::encodeDocument()}): a model that returns a byte
     * sequence PHP will not accept as UTF-8 — a hexdump, a file excerpt read
     * by the Read tool, a mojibake'd paste — used to produce a bare newline at
     * exit 0, which is the worst shape of all: an empty pipe that claims
     * success. Those bytes are now substituted, and the residual impossible
     * failure throws instead of returning `''`.
     *
     * @throws \JsonException When `--output-format json` was asked for and the
     *   answer cannot be encoded even with invalid bytes substituted.
     *   {@see self::run()} turns this into an `encoding`-typed document at
     *   {@see self::EXIT_FAILURE}; never into an empty stdout.
     */
    public static function format(Message $message, string $outputFormat): string
    {
        if ($outputFormat === self::FORMAT_JSON) {
            return self::encodeDocument(['result' => $message->content]);
        }

        return $message->content;
    }
}
