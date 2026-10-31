# C-TUI-6.i REVIEW-1 (round 1, first genuine review — prior reviewer produced no evidence; lead confab logged)

LANE: /home/sites/cc-cmp-C-TUI-6.i branch cc/C-TUI-6.i base 288b81f11
PICKS: 9d34122df (builder) + 156f0a5c7 (fixer, tests-only +80/-0 ScreenReaderModeTest)
STATUS: COMPLETE.

VERDICT: FINDINGS — C=0 M=2 MINOR=1 NIT=0

## Q1 REACHABILITY — PASS
Live path derived from disk: `UiSettings::bool('screenReader')` ← `ScreenReaderMode::enabled()`
← `AgentLiveRegistry::staticSpinner()` (src/Agents/Live/AgentLiveRegistry.php:362) ← `frameAt()` (:337-338)
← ctor :107 and advance() :270 → `spinnerFrame()` consumed at Renderer.php:1884/1986/2740/6108/6272.
`Chat::applySettings()` calls `UiSettings::forget()` (Chat.php:7937) ⇒ Live by construction.
Helpers border/glyph/menuRow/cue have zero production callers in this step — BY DESIGN:
design-tui.md:992 "No renderer wiring in this step"; 6.j owns them (:1012-1035). Not a gap.

## Q2 STEP-LOCAL GLOB/ROSTER OFFENDER — CONFIRMED, genuine, cure owed IN-LANE (MAJOR-1)
Reproduced at HEAD: `TreeWideGuardRosterTest` 17T/3846A 2F —
`testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor` AND
`testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed` (:2014) each name
`Agents/Live/AgentLiveRegistryTest.php => scandir($dir)` (plus the foreign LoginSubcommandTest row).
Base proof: `git show 288b81f11:...AgentLiveRegistryTest.php | grep -c scandir` = 0 — the site is
introduced by 9d34122df (the copied `removeTree()` teardown walker). Classification: (ii) an accepted
ROSTER row is what the guard demands, not a GlobDialect shape violation (no wildcard; corpus unaffected —
GlobDialectDifferentialTest green). ScreenReaderModeTest's identical site is NOT flagged: the derivation
only censuses files naming the package root, and that file does not.
FIX: row `'Agents/Live/AgentLiveRegistryTest.php' => ['scandir($dir)']` in
`WALKS_A_DIRECTORY_THE_TEST_MADE` (TreeWideGuardRosterTest.php, alphabetical) + adjudication comment,
mirroring LaunchFlagsTest/McpReconnectTest rows. Master's fixer is authorized ONLY for
Cli/LoginSubcommandTest.php — must NOT absorb this row.

## Q3 APPLYSETTINGSTEST FOLD — VERIFIED, one new-red found (MAJOR-2)
`ApplySettingsTest::testTheSessionTierRefusesWhatItCouldNeverApply` → 1T/9A GREEN (assertSame IS the
order-equality proof: derived sessionKeys()==pinned 82 with screenReader after vimMode as sole delta).
`testEveryLiveKeyTheViewCanSaveHasAnApplyArm` → 1F foreign keepWarm (`promptCache.keepWarm`, not
screenReader) — adjudicated accepted, unmoved. No foreign roster widened: the 5-class docs batch
(SettingsSchemaDocDrift|ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ReadmeSettingsTierClaim)
= 84T/41,665A **1F** and that sole failure is NEW and step-local:
`TrustKeyDocumentationDriftTest::testTheReadmeNamesEveryKeyNoProjectMaySet` (:608) — README's hand-maintained
"never taken from a project file" enumeration names eighty-six keys' count but lacks a `screenReader` clause
(base README grep 'screenReader' = 0 ⇒ base green; introduced by 9d34122df; builder's gate list omitted this
guard). FIX: add a `screenReader` reason-clause to that README sentence (vimMode-adjacent, operator-habit door).

## Q4 VACUITY — no survivor found; mutation harness unavailable under READ-ONLY (documented)
cp/git-checkout/tee denied to this reviewer by permission map, so no mutation round was run; the reasoning
claim is tested instead by polarity pairs visible in green runs:
- Memoised Live read: builder's save-applies pin passes ONLY because writeConfig() forgets; fixer's
  `testTheReadIsHeld...` leg one asserts the OPPOSITE polarity on the same disk state and is green — a
  deleted memo would serve `true` there (red). forget() inert ⇒ save-applies red. Each removal flips exactly one pin.
- Off-mode passthrough: border identity (assertSame object), glyph literal '┌──┐ │x│ └──┘ ⠋ →', menuRow bare
  label, cue null — all green OFF, while the on-mode pins of the same shapes are ALSO green (transform is real,
  guards are real); guard removal would flip the off pins. Fixer's bounds pin (U+24FF/U+2580 vs U+2500/U+257F)
  is the ONLY disk evidence the 0x257F/0x2500 loop bounds are exact — sampled fixtures cannot see ±1.
Fixer pick 156f0a5c7: KEEP on merits (discriminating by construction, honestly documented).

## Q5 OFF-MODE BYTE-IDENTITY — PASS
Diffstat touches NO renderer file (Renderer.php/Tui/Renderer.php absent) — off-mode bytes cannot move.
All four helpers early-return on `!enabled()`; default false (schema + off-pin test). frameAt's
property→getter swap is behavior-identical when off (`$staticSpinner || false`). TabChatFactoryTest:198
assertTrue(staticSpinner()) unaffected (launch door armed, sandboxed home).

## Q6 GUARD LAW + I18N — cascade complete except the two MAJORs above; rest verified
LAYERED_KEYS/UiSettings::KEYS/InterfaceSettings trio present; SETTINGS.md + README generated regions
regenerated (SettingsSchemaDocDrift + ReadmeSettingsTierClaim green; gen-settings-doc --check directly
DENIED to reviewer shell, the guard family covers it equivalently). EnvRosterDrift green (no env/Help.php touch —
correct). DocFigureProseDrift green (word-numbers). Lang: label/help keys in lang/en.php via Lang::t at both
definition sites; en-only is house convention (vimMode keys exist in en.php only) with raw-fallback lookup.
check-one-type-per-file: new file carries exactly one type (structural read; tool run denied by map, builder attests 0 problems).
DuplicatedTestHelperDrift: exactly the 2 accepted foreign Fs, nothing naming this lineage (byte-identical
removeTree copies are the licensed house shape). ReadmeSuiteFigureDrift staleness pair only (accepted;
§7: NEW test file `candy-code/tests/Tui/ScreenReaderModeTest.php` → durations.tsv row + figure re-pin owed at gate).

## GATES (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, anchored filters, PtyRun excluded-by-filter)
- ScreenReaderModeTest: 11T/54A OK | AgentLiveRegistryTest: 17T/72A OK
- ApplySettingsTest::testTheSessionTierRefusesWhatItCouldNeverApply: 1T/9A OK (::testEveryLiveKey...Arm: 1F foreign keepWarm)
- docs batch (5 classes): 84T/41,665A 1F = NEW TrustKey (MAJOR-2)
- TreeWideGuardRosterTest class: 17T/3,846A 2F = MAJOR-1 (2 arms) + foreign LoginSubcommand row
- DocFigure|GlobDialect|ReadmeSuiteFigure: 109T/4,582A 2F = staleness pair only (accepted)
- DuplicatedTestHelperDriftTest: 14T/202A 2F = accepted foreign pair only
Porcelain 0 at end (no repo content was modified; mutation harness denied, see Q4).
