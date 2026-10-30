# C-WEB-5.a — REVIEW-2 (round 2, fresh reviewer)

Anchors: branch cc/C-WEB-5.a tip 7dc02bc89; master re-read 07e49e872 (branch base 574e69f1d; 17 master-side files moved since cut, ZERO overlap with the lane file set — weld pick is conflict-free). Chain 20137004a/7569546be/28bed9aa6/44c9f2bc4/7dc02bc89 each rev-parse OK individually. Tree clean (git status --porcelain empty).

## VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT)

## 1. MINOR-4 cure verified (r1's sole open item)
New ServeArgsTest::testTheCleartextRemoteWarningIsAnnouncedOnlyForPlainRemoteBinds (:398-412) exercises the REAL gate: reflectively invokes private static Serve::announce (src Serve.php:1290, gate at :1342 `!$config->isLoopback() && !$config->hasTls()`) in a throwaway php child with `2>` capture — announce writes fwrite(STDERR) per src :1349/:1378, so child routing is the honest shape; `timeout -s KILL 20` bounds it (E646-clean, test-side). Token `'!! Plain HTTP on '` matches lang/en.php:1012 verbatim; row text 'candy-code serve: listening on' matches en.php:1007 — vacuity legs are real strings.
Mutation-REASON (src unedited, structural per r1 method): M1 drop-!hasTls -> gate becomes !isLoopback -> TLS case warns -> :407 NotContains reds exactly 1 (:402 count==1 unaffected, :411 loopback still clean). M2 drop-!isLoopback -> loopback case warns -> :411 reds exactly 1 (:402/:407 green). Builder's line attributions :407/:411 confirmed on disk. :402 assertSame(1, substr_count) additionally pins single emission. config() only file-validates the pair (readable+perms; relus refused only at launch) so children for real PEM pairs never refuse; a refusing child fails loud via the listening-row leg, never vacuously.
Child shape PROVEN by my run: test green means all three children reached announce (both remote cases carry --allow-remote, satisfying the ServerConfig:733 consent law; default loopback needs none).

## 2. Zero src change in fix commits
`diff 28bed9aa6..HEAD -- src/ bin/ candy-code-web/src` EMPTY. Full range stat: only ServeArgsTest (+97/-... new test, tlsScratchDir() extraction behavior-identical, pair-path retarget), TlsListenerTest one assertion-message respell, prompt_kit lane evidence (lane-owned paths). 7dc02bc89 = REPORT.md stamp, 9 lines, nothing else.

## 3. N1 cured honestly: TlsListenerTest:162 respelled (no apostrophe); grep -P U+2019/2018 over both test files = zero hits at HEAD (r1's ServeArgsTest cite had drifted — never a curly there, FIX-1 states this).

## 4. Gates measured here (cwd=candy-code, vendor linked, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'ServeArgs|TlsListener|LangParity|ServerProtocolDocumentationDrift|ReadmeRosterDrift'`: Tests: 69, Assertions: 3647 — OK.
- `--filter ServeArgs` alone: 42T/213A — FIX-1's "+1T/+9A over 41T/204A" reproduced EXACT.
- `--filter 'GlobDialect|DuplicatedTestHelperDrift|TlsListener'`: 32T/615A — OK (new helpers tlsScratchDir/announceStderr single-file, no twin; no glob-shaped literals; exec() is not a proc_open/roster site and tests/ is autoload-dev, outside check-child-lifetimes roots anyway).
- "PHPUnit Deprecations: 2": pre-existing config-level, adjudicated NOT-lane by r1 across every run incl single-test.
- Staleness pair: NOT in my filters; r1 + FIX-1 measured it pair-only-red (25,537 pinned vs 28,457 live, +1 = this method) — standing §7 weld duty, expected-red.

## 5. r1 verified-clean items — no regression
File set identical to r1's audited blast radius (base..HEAD --stat above); TLS laws (both-or-neither needs_both, flag-only no config/env reader, (mode & 0o077) key-perm refusal, openssl_x509_check_private_key at launch, ext-openssl absence refused), real-handshake e2e + plaintext-refusal + Secure-cookie TLS-vs-plain contrast, local_pk discriminating split-PEM fixtures — all byte-untouched between 28bed9aa6 and HEAD, and re-green in my TlsListener run. ACCEPTED disclosures (ClientAddress out-of-list, perms-stricter) not re-litigated. Per-hop redirect re-check stays a SEAM for C-WEB-5.c/.e (zero dials added) — not charged.

## Weld carries (restated for the wave)
- durations.tsv row tests/Server/TlsListenerTest.php (4T/47A); ServeArgsTest needs NO new row (existing carries the +1 method).
- Suite-figure re-pin total for this lane: +8T/+~134A over base (r1 carry +7T/+125A + fix-round +1T/+9A); welder re-derives live, does not hand-sum.
- plan_comparison.md Help.php modify-row design erratum: dated weld note (auto-covered by testEveryServeFlagIsOnTheHelpScreen lockstep guard).
- Master moved 17 files post-cut with zero lane overlap; pick clean.

Step loop CLOSED: 0 findings at any severity. Ship-ready for weld.
