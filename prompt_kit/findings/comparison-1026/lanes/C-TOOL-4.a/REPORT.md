# C-TOOL-4.a — sandbox row in candy-code doctor — DONE
Commit c28d0a473 on cc/C-TOOL-4.a (base 63f2de5d9), 8 files +477/−10, detain A/C, zero trailers, not pushed.

## What landed
- Subcommands::doctorProbes() gains `sandbox` row directly behind `permission policy` (before `provider`), spelled `=> static` for the DocFigure scrape; private sandboxProbe(?Closure $jail, ?string $sysctlPath, string $osFamily) — three host-fact seams, live row un-injected.
- Row asks the jail question through new public Bash::sandboxRefusal() (probe closure moved verbatim out of execute(); execute() now calls it — zero new proc_open site). Bubblewrap::appArmorRestrictsUserns(): ?bool + APPARMOR_RESTRICT_USERNS_PATH const.
- Verdicts: off→OK (detail still says would/would-not start + reason); configured+starts→OK; configured+refused→WARN (AppArmor remedy when sysctl reads 1, generic otherwise; absent sysctl = unknown = generic); non-Linux→Linux-only line (OK off / WARN configured). Never FAIL (pinned: no `'FAIL'` literal in the probe slice).
- lang/en.php: 10 cli.doctor.sandbox_* keys (en-only; lang/ ships only en.php). TROUBLESHOOTING: fifteen→sixteen + doctor-sandbox sentence in the Bash-refused section. ReadPathCensus: 3rd Bubblewrap file_get_contents entry, PROCESS_DERIVED.

## NEW test files (gate owes durations.tsv row)
- candy-code/tests/Cli/DoctorSandboxProbeTest.php (10T)

## Gates (plain pipe, -u LINES -u COLUMNS, cwd candy-code)
- DoctorSandbox|Bubblewrap|Sandbox: 56T/297A OK
- ReadPathCensus|StderrEmitterCensus|DocFigureProseDrift: 205T/25390A OK
- Doctor|LangParity|CliLangCatalogue: 71T/4476A OK
- DoctorFts5|DoctorExtension|DoctorBypassNotice|BinCandyCodeDispatch: 144T OK
- Five-guard family (+DuplicatedDocBlockLine): 47T/12116A OK
- Red-before proven on base via cp-harness: new probe test 8E/2F; BashSandboxTest extraction pin 1E; ReadPathCensus my-ledger-vs-base-src 1F.

## Base-reds disclosed (proven red at 63f2de5d9, NOT this lane)
- KeyHelpTest::testSlashHelpNoLongerOpensTheKeyboardReference (slash-help /skills hint truncation).
- ReadmeSuiteFigureDrift staleness pair (pinned 25,537 vs live 26,588; this lane adds +11) — welder re-pin.

## Deviations / seams
- Design's sandboxProbe seam widened with a 3rd `$osFamily` param (default PHP_OS_FAMILY) — needed to pin both non-Linux arms on Linux CI and Linux arms elsewhere; matches the $sqlite3Available style of pdoDriverProbe.
- docs/CONTRIBUTING.md:93 quotes "fifteen checks" as a stale-example, unpinned — left as-is.
- C-TUI-5.a (doctorProbes extraction) owns the row location later, per design.
