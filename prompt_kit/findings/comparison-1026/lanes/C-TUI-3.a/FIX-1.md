# C-TUI-3.a fixer round 1

STATUS: DONE. 1 MAJOR cured + mutation-proven, 1 NIT truth-fixed. Commit on branch cc/C-TUI-3.a atop 33c15af11.

## MAJOR — untrusted-bytes egress via the clipboard (cured)
- `candy-code/src/Chat.php` `handleCopyCommand()`: `$selection['text']` was raw. Now, at the single point before BOTH legs (OSC-52 relay + SystemClipboard closure) and before the chars notice:
  `$text = Sanitize::untrustedForDisplay(Sanitize::stripPrivateUse($selection['text']));`
  — the exact pair `/export` runs (`src/Commands/ExportCommand.php:189`), order included. No double-sanitize: neither leg sanitizes on its own (drag rides already-sanitized selectable lines; these legs get the raw stored content). Chat.php already imports `SugarCraft\Core\Util\Sanitize` (line 28) — no new import.
- Pins added to `candy-code/tests/Chat/CopySlashCommandTest.php` (no new test files):
  1. `testHostileBytesAreStrippedBeforeBothClipboardLegs` — reply carrying a whole OSC-8 hyperlink (`\x1b]8;;...\x07` x2) and U+E000/U+E001 sentinels; asserts byte-exact `Ansi::setClipboard($clean)` raw-write, the stubbed `SystemClipboard` argv text, and the chars notice — all against hardcoded stripped bytes (no Sanitize call in the expectation, so the pin is not self-derived).
  2. `testAHostileFenceBlockIsStrippedToo` — sentinels INSIDE a fence block ride `/copy 1` out only if unsanitized; pinned stripped.

### Mutation proofs (cp backup /tmp/opencode/chat-fix1-orig.php md5 a651615328f8a4adbc1a63372fa64360; never git-checkout)
- M1 sanitizer deleted entirely (`$text = $selection['text']`): exactly the 2 new pins red; 22T/78A/2F. Restored, md5 verified.
- M2 `stripPrivateUse` leg dropped: both new pins red (sentinel bytes survive) — 2F.
- M3 `untrustedForDisplay` leg dropped (stripPrivateUse only): OSC-8 pin red (escape survives) — 1F. Both halves load-bearing. Restored md5 matches orig.

## NIT — stale cite (fixed)
- `Chat.php` handleCopyCommand docblock: `{@see handlePointer()}` → `{@see trackTextSelection()}`; the batch(relayWidgetCmd(setClipboard), SystemClipboard::copy) precedent is Chat.php:6568-6574 inside trackTextSelection() (verified by reading both bodies).

## Gates (cwd candy-code, env -u LINES -u COLUMNS plain pipe, -c phpunit.xml; PtyRun never matched)
- `--filter 'CopyCommandTest|CopySlashCommandTest'` → **22T/84A OK** (reviewer's 20T/76A + 2T/+8A new pins).
- `--filter 'MenuBarLabelDerivationTest|PaletteSupersetDispatchTest'` → **18T/443A/2F** — exactly the foreign `output-style` standing pair (PaletteSupersetDispatchTest judged-set test, MenuBarLabelDerivationTest:106); diffs carry only the known extra + shifted-line noise, both 'copy' rows absorbed. Matches reviewer claim.
- Guard battery (reviewer class set; substring match ran broader: 1934T/16886A) → only those same 2 standing failures, zero copy-attributable.
- `php -l` clean on both edited files; `php tools/gen-command-docs.php --check` → "up to date" rc=0 (no help/lang text changed).
- Diff = exactly 2 files (+47/−2). suite-figure.json, durations.tsv, candy-code-web/dist, protocol trio: untouched.

## Verdict
Weld-ready per reviewer §4 fixer targets. Carries unchanged: +22T/84A re-pin and the two durations rows remain the welder's.

(disk-stat: file measured 3267 B immediately before this provenance line was appended; the appended line is the only later edit.)
