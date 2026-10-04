<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;

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
 * READ-ONLY, in the strong sense: see {@see report()} for the accessors it is
 * built on and why reaching for {@see PermissionGate::evaluate()} here would
 * have been a bug rather than a shortcut. The argument, if any, is ignored:
 * the report is total.
 */
final class PermissionsCommand implements HostCommand
{
    public function run(CommandContext $context, string $text): CommandResult
    {
        return CommandResult::reply($text, self::report($context->permissionGate));
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
            return 'No permission gate is attached to this session, so no mode and no rule are '
                . 'deciding anything here. That is what an embedder gets, and a Chat built without a '
                . 'hook chain and without an engine backend; a `sugarcrush` launch always builds one. '
                . 'Any hooks that are installed still run.';
        }

        $mode = $gate->mode();

        $lines = [
            sprintf(
                'Permission mode: %s — from %s',
                // The mode is enum-constrained and safe by construction; the
                // SOURCE is not. Bootstrap builds it around a file path, and
                // that path can come from `--config`, so it is caller text on
                // its way into the transcript exactly as a rule pattern is.
                $mode->value,
                $gate->modeSource() === null
                    ? 'a source this gate did not record'
                    : self::reportField($gate->modeSource()),
            ),
            $mode->description(),
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
            $lines[] = 'Rules: none configured, so every decision above is the mode\'s own. '
                . 'A `permissionRules` array in ~/.sugar-crush/config.json or in '
                . '~/.sugar-crush/settings.json is where they go; config.json wins where both set a key.';
        } else {
            $lines[] = sprintf(
                'Rules (%d), tried in this order — the first one that matches decides, ahead of the mode:',
                count($rules),
            );
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
     * validated only by `is_string()` in {@see \SugarCraft\Crush\Cli\Bootstrap},
     * while `~/.sugar-crush/config.json` is writable by any model holding
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
            return sprintf(
                'Auto-mode circuit breaker: idle. It only counts under `%s`, and this session is `%s`.',
                PermissionMode::Auto->value,
                $gate->mode()->value,
            );
        }

        return sprintf(
            'Auto-mode circuit breaker: %d of %d consecutive blocks (%s), %d of %d blocks this session. '
            . 'Reaching either threshold turns the next block into a prompt instead of a refusal.',
            $breaker['consecutiveBlocks'],
            $breaker['strikeThreshold'],
            $breaker['lastBlockedCategory'] === null
                ? 'nothing blocked yet'
                : 'last category: ' . self::reportField($breaker['lastBlockedCategory']),
            $breaker['totalBlocks'],
            $breaker['totalBlockThreshold'],
        );
    }
}
