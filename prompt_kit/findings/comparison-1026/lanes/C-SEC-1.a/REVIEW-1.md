# C-SEC-1.a round 1 — VERDICT: FINDINGS (0C/0M/1 MINOR step-local/2 NIT) (static-only; php/git exec denied in reviewer sandbox — gates owed by welder)
Confirmed on disk:
1. Subcommands.php removeTree: top-level is_link->@unlink+return (:1963-1967) and child is_link arm (:1978-1982) before any is_dir descent — planted link->outside unlinked as a link; canary survives hand-trace. Dangling links fine (lstat).
2. Clone argv (:1762): 'git','-c','protocol.ext.allow=never','-c','core.symlinks=false','clone',...,'--',$url,$scratch via ProcessContainment::spawnSpec — argv-array no shell, escapeshellarg law satisfied by construction; '--' door + INT-M1 belt preserved. Base single -c; diff exactly declared region.
3. copyBundleTree bool->array{ok,link} refuses at first is_link naming path (:1928-1930); both call sites (:1651-1658, :1713-1722) branch link===''->read_failed else new link_refused; cleanup ordering correct. looksLikeGitUrl/bundlesChildEnv byte-unchanged.
4. en.php key cli.bundles.install.link_refused alphabetical with {source}+{link}; lang/ ships en only -> §6 locale ruling honored.
5. Tests: 4 new methods (+1 renamed argv pin, outsideSentinel helper); 11 existing methods byte-identical; 17T/+6T. Pre-fix 6F traced (sentinel x3, argv substring, path-source EXIT, copy-shape). No glob/Refused-shaped literals.
6. Scope greps only 3 declared files; one commit ce0d57b43 off ff10efc43, no origin ref; committer detain; suite-figure/durations identical base<->worktree; no protocol/web.
7. Disclosed cyclic-pin HONEST: green-pre-fix due to kernel ELOOP ceiling (MAXSYMLINKS=40); declared termination pin not discriminator; §1.5 satisfied by six discriminators.
8. Review-prompt correction: +6T arithmetic-certain -> ReadmeSuiteFigureDrift staleness pair RED at 25,537-vs-25,543 is the by-design merge-owned state.
Findings:
- [MINOR step-local] BundleInstallTest.php:297-298 docblock "before the fix this was a crash, not a failure" contradicts disclosed ELOOP mechanism — reword: "pre-fix relied on the kernel's ELOOP ceiling to stop the descent; the refusal now unlinks the link as a link and never descends."
- [NIT] REPORT.md:13 "5 new test methods" vs true 4 new + 1 renamed (line 41 correct) — fix wording at weld ledger.
- [NIT] BundleInstallTest.php:194 source-slice pin line-format-coupled — house pattern, accepted, noted.
Welder owed: --filter 'BundleInstall|Subcommands' (expect 17/17 green) + five-guard (ONLY staleness pair 25,537-vs-25,543); %ae/%ce + trailer + empty-%b checks.
Not a finding: bundlesInstall call-site edits forced by copyBundleTree signature — within step intent.
