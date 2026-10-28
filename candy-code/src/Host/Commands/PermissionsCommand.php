<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\SavedRules;

/**
 * `/permissions` — what this session is actually gated by (roadmap O-2h moved
 * it out of `Chat::handlePermissionsCommand()`, which now delegates here).
 *
 * The name sat in `CommandRegistry::CONTROL_PLANE` for two rounds with no row
 * and no arm: reserved against a project's `permissions.md`, on behalf of a
 * command that did not exist. Typing it sent the word "/permissions" to the
 * MODEL, which is the one place a question about local policy has no business
 * going.
 *
 * A TRANSCRIPT MESSAGE, not a modal overlay like `/keys`. The answer is text
 * worth scrolling back to, worth having above the turn it explains, and worth
 * still being there when the next refusal lands — which is exactly when it
 * gets typed.
 *
 * The report is READ-ONLY, in the strong sense: see {@see report()} for the
 * accessors it is built on and why reaching for {@see PermissionGate::evaluate()}
 * here would have been a bug rather than a shortcut. It also lists the
 * session's "always" grants, numbered, and how to change things. Four
 * subcommands do: `/permissions revoke <n>|all` takes grants back (the
 * transcript row `a` leaves points here), `/permissions mode <name>`
 * switches the mode for the session's next turns — any mode except
 * `dont-ask`, which stays launch-only (see {@see switchableModes()} for the
 * ruling on both), `/permissions saved` lists the SAVED rules `p` writes to
 * the user config (numbered as {@see forgetRule()} takes them; C-SEC-6.a
 * moved that list into {@see SavedRules} and gave it a reader and a remover),
 * and `/permissions forget <n>|<pattern>` takes saved rules out — which
 * lands on the NEXT launch, because the live gate reads its rules once at
 * start, so the answer says so and points at `mode` for what is deciding
 * right now. Any other argument is ignored: the report is total.
 */
final class PermissionsCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        $arg = trim(CommandText::argument($text));
        $words = preg_split('/\s+/', $arg) ?: [];
        if (strtolower($words[0] ?? '') === 'revoke') {
            return self::revoke($context, $text, $words[1] ?? '');
        }
        if (strtolower($words[0] ?? '') === 'mode') {
            return self::switchMode($context, $text, strtolower($words[1] ?? ''));
        }
        if (strtolower($words[0] ?? '') === 'saved') {
            return self::savedRules($context, $text);
        }
        if (strtolower($words[0] ?? '') === 'forget') {
            // Rest-of-argument, not words[1]: a rule pattern carries spaces
            // (`Bash(git status *)` is the house shape), and split-and-rejoin
            // would silently collapse any run of them inside a pattern. The
            // verb arm already proved $arg opens with the six bytes "forget",
            // so cutting past them is exact — no pattern-math to drift.
            return self::forgetRule($context, $text, trim(substr($arg, 6)));
        }

        return CommandResult::reply(
            $text,
            self::report($context->permissionGate) . "\n\n" . self::grantsReport($context->grants),
        );
    }

    /**
     * The session's "always" grants, numbered as `revoke` takes them, and
     * how to take one back — or that there are none and how one is made.
     */
    public static function grantsReport(\SugarCraft\Code\Permissions\SessionPermissionMemo $grants): string
    {
        $entries = $grants->entries();
        if ($entries === []) {
            return Lang::t('host.permissions.no_grants');
        }

        $lines = [Lang::t('host.permissions.grants', ['count' => \count($entries)])];
        foreach ($entries as $index => $entry) {
            $lines[] = sprintf('  %d. %s', $index + 1, self::reportField($entry));
        }
        $lines[] = Lang::t('host.permissions.revoke_hint');

        return implode("\n", $lines);
    }

    /**
     * `/permissions mode <name>`: run this session's next turns in <name> —
     * the in-session way to reach `accept-edits` or `auto` that `Alt+M`
     * (plan only) does not give. Applied through the same switch `Alt+M`
     * uses (between turns, the model told once). `bypass-permissions` is
     * reachable from here too (owner ruling 2026-10-06, see
     * {@see switchableModes()}): the command is typed by the human with their
     * own hands, so the switch IS the explicit choice, and it prints a loud
     * warning beside the confirmation line. `dont-ask` stays refused unless
     * the session was LAUNCHED in that mode ({@see PermissionGate::launchMode()}).
     */
    private static function switchMode(CommandContext $context, string $text, string $name): CommandResult
    {
        $gate = $context->permissionGate;
        if ($gate === null) {
            return CommandResult::reply($text, Lang::t('host.permissions.no_gate'));
        }
        $mode = PermissionMode::tryFrom($name);
        if ($mode === null) {
            return CommandResult::reply($text, Lang::t('host.permissions.mode_usage', [
                'mode' => $gate->mode()->value,
                'modes' => implode(', ', array_map(static fn (PermissionMode $m): string => $m->value, self::switchableModes($gate))),
            ]));
        }
        if (!in_array($mode, self::switchableModes($gate), true)) {
            return CommandResult::errorReply($text, Lang::t('host.permissions.mode_refused', [
                'mode' => $mode->value,
                'launch' => $gate->launchMode()->value,
            ]));
        }
        if ($mode === $gate->mode()) {
            return CommandResult::reply($text, Lang::t('host.permissions.mode_unchanged', ['mode' => $mode->value]));
        }

        $switched = Lang::t('host.permissions.mode_switched', [
            'from' => $gate->mode()->value,
            'mode' => $mode->value,
        ]);
        if ($mode === PermissionMode::BypassPermissions) {
            // The ruling that opened this switch pays for itself here: one
            // typed command is the human's consent, and this second line is
            // the record that the consent was given with eyes open — the
            // mode gates nothing but the deny rules and the `rm -rf /`
            // floor, and only this session's next turns are covered by it.
            $switched .= "\n" . Lang::t('host.permissions.bypass_warning');
        }

        return CommandResult::reply($text, $switched)
            ->withEffect(CommandEffect::setPermissionMode($mode));
    }

    /**
     * The modes `/permissions mode` may switch to: every mode that still
     * asks or refuses, plus `bypass-permissions` (owner ruling 2026-10-06 —
     * a slash command is typed by the human with their own hands, so
     * switching INTO bypass is by construction the user's explicit choice;
     * the mode still cannot escape the deny-rule + `rm -rf /` floor, and the
     * switch prints a loud warning beside the confirmation line); but NOT
     * `dont-ask`, which stays launch-only — it converts every would-be
     * question into a silent Deny, so its failure mode is invisible
     * breakage rather than unguarded writes, and the owner did not open it.
     *
     * @return list<PermissionMode>
     */
    public static function switchableModes(PermissionGate $gate): array
    {
        return array_values(array_filter(
            PermissionMode::cases(),
            static fn (PermissionMode $m): bool => $m !== PermissionMode::DontAsk
                || $m === $gate->launchMode(),
        ));
    }

    /**
     * `/permissions revoke <n>|all`: the grants without row <n> (as the
     * report numbers them), or none.
     */
    private static function revoke(CommandContext $context, string $text, string $which): CommandResult
    {
        $grants = $context->grants;
        if (strtolower($which) === 'all') {
            $count = \count($grants->entries());

            return CommandResult::reply($text, Lang::t('host.permissions.revoked_all', ['count' => $count]))
                ->withEffect(CommandEffect::setPermissionGrants(\SugarCraft\Code\Permissions\SessionPermissionMemo::new()));
        }

        $index = ctype_digit($which) ? (int) $which : 0;
        $kept = $grants->without($index);
        if ($kept === null) {
            return CommandResult::reply($text, Lang::t('host.permissions.revoke_usage') . "\n\n" . self::grantsReport($grants));
        }

        return CommandResult::reply($text, Lang::t('host.permissions.revoked', [
            'grant' => self::reportField($grants->entries()[$index - 1]),
        ]))->withEffect(CommandEffect::setPermissionGrants($kept));
    }

    /**
     * `/permissions saved`: the user's saved rules — what `p` appends to
     * `permissionRules` — in stored order, numbered as `forget` below takes
     * them. Read through {@see SavedRules}; the door is the same
     * {@see SettingsWriter} the settings editor writes with, so this never
     * reads a file the launch would not have read.
     */
    private static function savedRules(CommandContext $context, string $text): CommandResult
    {
        $writer = $context->workspace?->service(SettingsWriter::class);
        if (!$writer instanceof SettingsWriter) {
            return CommandResult::errorReply($text, Lang::t('host.permissions.no_writer'));
        }

        try {
            $body = self::savedBody(new SavedRules($writer));
        } catch (\Throwable $e) {
            // Refuse loudly: an empty list shown here would claim the user
            // saved nothing when in truth the file could not be read.
            return self::readFailed($text, $e);
        }

        return CommandResult::reply($text, $body);
    }

    /**
     * The one read-failure refusal this command answers with — shaped by
     * {@see SavedRules::list()} throwing where the file cannot be read at
     * all (a corrupt top-level, say). Every surface that WOULD show the
     * list answers through here, so a crash can never ride out of a typed
     * slash command and `saved` and `forget` refuse identically on the
     * same unreadable file (C-SEC-6.a review r1, MAJOR).
     */
    private static function readFailed(string $text, \Throwable $e): CommandResult
    {
        return CommandResult::errorReply($text, Lang::t('host.permissions.read_failed', [
            'error' => self::reportField($e->getMessage()),
        ]));
    }

    /**
     * `/permissions forget <n>|<pattern>`: take saved rules out — the entry
     * the `saved` list numbered <n>, or every entry whose pattern matches
     * exactly. A failed write REFUSES visibly (the opposite of the save
     * notice's kinder half: silence here would forge a removal that did not
     * land). The reply carries the next-launch truth: {@see PermissionGate}
     * loads `permissionRules` once at start, so forgetting changes the NEXT
     * session's decisions, and the live one is tightened with `mode`.
     */
    private static function forgetRule(CommandContext $context, string $text, string $target): CommandResult
    {
        $writer = $context->workspace?->service(SettingsWriter::class);
        if (!$writer instanceof SettingsWriter) {
            return CommandResult::errorReply($text, Lang::t('host.permissions.no_writer'));
        }
        $rules = new SavedRules($writer);
        if ($target !== '') {
            try {
                $removed = $rules->remove(ctype_digit($target) ? (int) $target : $target);
            } catch (\Throwable $e) {
                return CommandResult::errorReply($text, Lang::t('host.permissions.write_failed', [
                    'error' => self::reportField($e->getMessage()),
                ]));
            }

            if ($removed !== []) {
                $what = [];
                foreach ($removed as $entry) {
                    $action = \is_string($entry['action'] ?? null) ? $entry['action'] : 'allow';
                    $pattern = \is_string($entry['pattern'] ?? null) ? $entry['pattern'] : '';
                    $what[] = $action . ' ' . self::reportField($pattern);
                }

                return CommandResult::reply($text, Lang::t('host.permissions.forgot', [
                    'count' => \count($removed),
                    'rules' => implode(', ', $what),
                    'path' => self::reportField($rules->path()),
                ]));
            }
        }

        // Both remaining answers — the bare-verb teaching reply and the
        // no-match reply — re-show the list, and that read can fail on the
        // very file whose existence prompted them: the corrupt config that
        // makes `saved` refuse must make THIS refuse the same way, never
        // throw out of run() into the program loop (C-SEC-6.a review r1,
        // MAJOR). One guarded read serves both branches.
        try {
            $body = self::savedBody($rules);
        } catch (\Throwable $e) {
            return self::readFailed($text, $e);
        }

        if ($target === '') {
            return CommandResult::reply($text, Lang::t('host.permissions.forget_usage') . "\n\n" . $body);
        }

        return CommandResult::reply($text, Lang::t('host.permissions.forget_none', [
            'target' => self::reportField($target),
        ]) . "\n\n" . $body);
    }

    /**
     * The saved list as one report block: header naming the file, one line
     * per rule in the `report()` rule shape (number, action column, escaped
     * pattern), and the hint that says plainly how removal lands. Every
     * field is run through {@see reportField()} — the pattern is config text
     * on its way into a line-per-fact surface, exactly the case that promoted
     * this method's own honesty rule.
     */
    private static function savedBody(SavedRules $rules): string
    {
        $entries = $rules->list();
        $path = self::reportField($rules->path());
        if ($entries === []) {
            $lines = [Lang::t('host.permissions.no_saved', ['path' => $path])];
        } else {
            $lines = [Lang::t('host.permissions.saved', ['count' => \count($entries), 'path' => $path])];
            foreach ($entries as $index => $entry) {
                $lines[] = sprintf(
                    '  %d. %-5s %s',
                    $index + 1,
                    self::reportField($entry['action']),
                    self::reportField($entry['pattern']),
                );
            }
        }
        $lines[] = '';
        $lines[] = Lang::t('host.permissions.saved_hint');

        return implode("\n", $lines);
    }

    /**
     * The `/permissions` body, DERIVED from the launch's live
     * {@see PermissionGate} rather than restating what the config said.
     *
     * WHY DERIVED MATTERS HERE MORE THAN USUAL. A permission screen that
     * disagrees with the enforcing gate is worse than no screen: it tells
     * somebody they are in `plan` while `bypass-permissions` runs. So every
     * line comes off the gate itself — {@see PermissionGate::mode()},
     * {@see PermissionGate::modeSource()}, {@see PermissionGate::rules()},
     * {@see PermissionGate::autoBreaker()} — the mode's own sentence comes off
     * {@see PermissionMode::description()}, and the breaker's thresholds come
     * back from the gate alongside the counters so this method never writes
     * "of 3" in its own hand.
     *
     * WHAT IT MUST NOT USE, and this is the trap the item was really about:
     * {@see PermissionGate::evaluate()} MUTATES the Auto-mode circuit breaker.
     * Building a preview on it — "what would this gate say about a Write?" —
     * would advance or reset the strike counters every time a user opened a
     * read-only screen, i.e. a safety state changed by being looked at. The
     * gate grew read-only doors for this; nothing here calls the evaluator.
     *
     * Rule patterns, classifier categories AND the mode source are run through
     * {@see reportField()} on the way out — NOT through
     * {@see Sanitize::untrusted()} directly, which preserves the two bytes that
     * matter most to a report built line-per-fact. All three are
     * caller-supplied text landing in the transcript — the source label is
     * built around a config path that `--config` can name — and an ESC byte in
     * any of them would put raw ANSI in front of the frame-diff renderer. That
     * is the `[33m`-as-literal-text defect `Commands\NoRawAnsiInTranscriptTest`
     * guards at the SOURCE for the `ob_start()`-captured commands; this one is
     * not among them (it writes no stdout, so that census cannot see it by
     * construction), and its guard is
     * `Commands\PermissionsCommandTest::testTheReportHasExactlyTheLinesTheRendererIntended()`
     * — a RUNTIME check on the bytes actually produced, which is the stronger
     * half of that pair anyway. The mode source was measured getting through
     * before that test was written.
     *
     * A LINE OF THIS REPORT IS ONE LINE BY CONSTRUCTION, and that is a property
     * of the whole method rather than of any field: `$lines` is assembled here
     * and joined with `"\n"` at the bottom, so the report's line count is
     * exactly `count($rules) + 6` for every possible config — the mode line,
     * the description, a blank, one rules line (the header, or the "none
     * configured" sentence that stands in its place, which is why the formula
     * needs no special case at zero), one line per rule, a blank, and the
     * breaker. Nothing a caller supplies may change it.
     * {@see reportField()} is what enforces
     * that, and the paragraph there records what got through before it existed.
     */
    public static function report(?PermissionGate $gate): string
    {
        if ($gate === null) {
            // NOT "you are unprotected": this Chat has no gate to report, which
            // is the ordinary shape for an embedder and for a Chat built with
            // neither a hook chain nor an engine backend. Whatever hooks are
            // installed still run — see checkProjectCommandShell()'s own
            // "no gate is not a refusal" note.
            return Lang::t('host.permissions.no_gate');
        }

        $mode = $gate->mode();

        $lines = [
            Lang::t('host.permissions.mode', [
                // The mode is enum-constrained and safe by construction; the
                // SOURCE is not. Bootstrap builds it around a file path, and
                // that path can come from `--config`, so it is caller text on
                // its way into the transcript exactly as a rule pattern is.
                'mode' => $mode->value,
                'source' => $gate->modeSource() === null
                    ? Lang::t('host.permissions.unrecorded_source')
                    : self::reportField($gate->modeSource()),
            ]),
            $mode->description(),
            Lang::t('host.permissions.mode_change', [
                'modes' => implode('|', array_map(static fn (PermissionMode $m): string => $m->value, self::switchableModes($gate))),
            ]),
            '',
        ];

        $rules = $gate->rules();
        if ($rules === []) {
            // The path is deliberately not sentence-final. `Cli\ProjectTierRefusalInventoryTest`
            // enumerates every dot-path literal in src/ and a trailing period
            // makes `config.json.` a second, unclassified entry — measured, it
            // reds that inventory.
            // BOTH files are named, and the omission this replaces was one the
            // feature already knew about: `Cli\Bootstrap::PERMISSION_SETTINGS_KEYS`
            // lists `permissionRules`, `permissionConfigLayers()` merges the
            // `settings.json` layer beneath `config.json`, and
            // `Cli\BootstrapToolAndPermissionSettingsTest::testTheGateRemembersWhichFileSetTheMode()`
            // asserts the source label printed one line above this one can read
            // `settings.json`. Sending a user to edit one of two files
            // is a coin flip they lose half the time, and they lose it silently
            // — rules in the file this sentence did not name still load.
            $lines[] = Lang::t('host.permissions.no_rules');
        } else {
            $lines[] = Lang::t('host.permissions.rules', ['count' => count($rules)]);
            foreach ($rules as $index => $rule) {
                $lines[] = sprintf(
                    '  %d. %-5s %s',
                    $index + 1,
                    $rule->action->value,
                    self::reportField($rule->pattern),
                );
            }
        }

        $lines[] = '';
        $lines[] = self::autoBreakerLine($gate);

        return implode("\n", $lines);
    }

    /**
     * One caller-supplied value, made safe to be PART OF A REPORT LINE.
     *
     * {@see Sanitize::untrusted()} is the wrong tool on its own here, and the
     * reason is a deliberate feature of it: it PRESERVES `\t`, `\n` and `\r`
     * (`Util\Sanitize` strips `[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]`, and those
     * three are excluded). That is right for a sink that renders a paragraph
     * and wrong for one that builds a line-per-fact report and joins with
     * `"\n"`: an LF inside a rule pattern or a `--config` path does not become
     * visible text, it becomes A NEW REPORT LINE, indistinguishable from one
     * this method wrote.
     *
     * MEASURED, on the build that shipped this screen. A single rule whose
     * `pattern` carried two LFs added
     * `Permission mode: bypass-permissions - from --permission-mode` to a
     * report drawn off a gate that was in `default`; a `modeSource` carrying
     * LFs added `Rules (9), tried in this order:` to a gate holding no rules at
     * all; and a CR mid-pattern let the tail of the value overwrite the head of
     * its own line on a real terminal. So the screen whose entire purpose is to
     * stop a lie about permissions could be made to tell one, in the gate's own
     * voice, by the config it reads — and `permissionRules[].pattern` is
     * validated only by `is_string()` in {@see \SugarCraft\Code\Cli\Bootstrap},
     * while `~/.candy-code/config.json` is writable by any model holding
     * `Write` under `auto` or `bypass-permissions`.
     *
     * ESCAPED, not stripped. A pattern that really does contain a newline is a
     * broken rule its author needs to SEE; deleting the byte silently would
     * print a pattern that is not the one the gate is matching with, which is
     * the same class of lie one step quieter. `\n` renders as the two
     * characters a user would have typed. TAB goes with them: it forges no
     * line, but it does move the cursor across the `%-5s` action column and
     * mis-set the alignment that makes the rule list readable as a table.
     *
     * The guard is
     * `Commands\PermissionsCommandTest::testTheReportHasExactlyTheLinesTheRendererIntended()`,
     * which counts LINES against the count the renderer intended rather than
     * scanning for residue bytes — the residue scan that shipped with this
     * screen asserted a byte class that was a strict SUBSET of what
     * `untrusted()` already removes, so it could only ever confirm that
     * `untrusted()` had been called.
     *
     * Promoted to public for `Commands\NoticesCommand` (E653 Shape B), which
     * renders config paths and on-disk preset names into the same class of
     * transcript surface — the second sibling screen to need this guard, in
     * the E164 promotion line rather than a copy that could drift.
     */
    public static function reportField(string $value): string
    {
        return strtr(Sanitize::untrusted($value), [
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
    }

    /**
     * Where the Auto-mode circuit breaker stands, in the gate's own numbers.
     *
     * Reported for every mode rather than only for `auto`, and saying plainly
     * that it is idle elsewhere: the counters exist on every gate, and a line
     * that simply vanished under the other five modes reads as "there is no
     * such thing" rather than "it is not counting right now".
     *
     * Both thresholds come back from {@see PermissionGate::autoBreaker()}. They
     * are private constants of the evaluator, and printing this method's own
     * copy of them is precisely the drift that would let the screen advertise
     * "of 3" the day the evaluator started escalating at 4.
     */
    private static function autoBreakerLine(PermissionGate $gate): string
    {
        $breaker = $gate->autoBreaker();

        if ($gate->mode() !== PermissionMode::Auto) {
            return Lang::t('host.permissions.breaker_idle', [
                'auto' => PermissionMode::Auto->value,
                'mode' => $gate->mode()->value,
            ]);
        }

        return Lang::t('host.permissions.breaker', [
            'consecutive' => $breaker['consecutiveBlocks'],
            'strikes' => $breaker['strikeThreshold'],
            'last' => $breaker['lastBlockedCategory'] === null
                ? Lang::t('host.permissions.breaker_nothing_blocked')
                : Lang::t('host.permissions.breaker_last', ['category' => self::reportField($breaker['lastBlockedCategory'])]),
            'total' => $breaker['totalBlocks'],
            'limit' => $breaker['totalBlockThreshold'],
        ]);
    }
}
