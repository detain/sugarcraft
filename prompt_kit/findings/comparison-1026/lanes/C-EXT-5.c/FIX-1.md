# C-EXT-5.c — round-1 fixes (2026-10-29)

Tip: 75257027d (cat-file: commit; parent d69c9a430; branch cc/C-EXT-5.c)

## Finding → fix
- REVIEW-1 MINOR (Renderer.php:7316-7334 /keys command section untested) →
  ONE new method `testTheKeyHelpOverlayListsBoundCommandsUnderTheirOwnHeading`
  in candy-code/tests/App/CommandKeybindingDispatchTest.php (+68 lines, incl.
  private `keyHelpOverlay()` helper using the KeyHelpTest:1608
  ReflectionMethod(renderKeyHelp) idiom on a deliberately tall (100x200) frame
  so the appended section can never scroll out of reach — the helper pins
  `Renderer::keyHelpMaxOffset() === 0` to keep that premise loud).
  Arms: `useOverrides(['command:keys' => 'Ctrl+Z'])` → heading
  (Lang::t('keys.command.heading'), en.php:1761 — the REAL string is
  "Your own bound commands"; the orchestrator's "Slash Commands Bound To
  Keys" literal does not exist on disk) + row pairing ('Ctrl+Z' and
  'Runs /keys' asserted on the SAME overlay line) + absent-when-none leg
  (no bindings → no heading, no Runs row). Nothing else touched.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- php -l test file: No syntax errors detected.
- --filter 'CommandKeybindingDispatch': Tests: 7, Assertions: 23, 0F
  (new method alone: 1T/9A green). 2 PHPUnit Deprecations are base-present
  (an untouched method shows the same pair).
- --filter 'CommandKeybindingDispatch|KeyBindingCommandBinding|KeyHelp|KeyBindingDrift':
  307T/98451A/5F — all five reds are the PRE-PROVEN foreign KeyHelpTest set
  (reviewer fingerprint 64T/96540A/5F), zero reds in touched files.
- --filter 'KeyBinding|KeyHelp' (wide): 365T/100020A/5F, same foreign set.
- Staleness pair (README/suite-figure 25,537-vs-live) unaffected by filter —
  lane +1T/+9A carried for the weld re-pin, per REVIEW-1 CARRIES.

## Mutation proof
- Neutered src/Renderer.php:7321 `if ($commandBindings !== [])` → `if (false && ...)`
  (php -l clean, applied grep-verified): --filter 'CommandKeybindingDispatch'
  → exactly 1F = testTheKeyHelpOverlayListsBoundCommandsUnderTheirOwnHeading.
- Restored via cp backup: md5 3b022613a91009bfbfb99a01705d0d91 == pre-mutation;
  git status clean for src/; re-run 7T/23A 0F.

## Commit law
- 75257027d: %ae grep -c detain = 1; %ce grep -c detain = 1; %an %cn = "Joe Huss Joe Huss";
  Co-Authored-By grep -ci = 0. Subject: "C-EXT-5.c: review fixes (round 1) — keyHelp
  command-section coverage pin".
- Provenance (disk-verified): the commit landed 01:58:30 -0400 by the FIRST fixer
  incarnation mid-way through this continuation's gates (reflog entry above the
  01:06:41 first-cut); this session's own `git commit` correctly reported a clean
  tree. Content is byte-for-byte the inspected uncommitted diff. No amend: message
  variants are semantically identical and the commit is already the record the
  reviewer round expects. No stash, no dist/suite-figure/durations touches.
