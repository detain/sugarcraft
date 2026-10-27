# REVIEW-1 — C-EXT-7.b (cc/C-EXT-7.b @ 3201a639e, base e3a84187d)
[lead-persist: reviewer static-only (shell/php denied); delivered verbatim @1017@. Weld re-runs --filter 'BundleInstall' expect 19T/57A, broad 86T/501A, five-guard pair-only. No DUR (existing test file only).]

## VERDICT: CLEAN (3 NITs, non-blocking)
1. Scope — C-EXT-7/dot_name markers only in the 4 claimed files; Subcommands hunks entirely inside bundlesInstall + docblock (removeTree byte-identical to main's hardened PLG-1-P1 walker, shifted +24). Cite drift (1692→1850, 1606-1612→1837-1849) confirmed by content. Git plumbing per lead disk-verify.
2. Security — removeTree($staged.'/.git') at :1937 strictly AFTER hasManifest gate (:1925), before rename (:1968), on process-minted $root/.install-<uniqid> scratch; malformed-manifest early-return removes whole scratch, never half-processed. C-SEC-1.a no-follow walker reused (is_link→unlink-as-link first); no second rmdir-recursive; clone -c core.symlinks=false so scratch holds no live links.
3. Dot refusal — single gate at :1949 over FINAL $name (manifest ∪ basename fallback), after segment regex, before exists/rename; refusal removes scratch; '.'/'..' still ride invalid_name. No production side effect beyond unavoidable scratch staging.
4. Tests — both new methods provably red pre-fix (dot: EXIT_OK + installed '.invisible' on base, asserts FAILURE + empty root; .git: plants real .scratch/.git/ nested file via clone seam, base renames it into dest → assertDirectoryDoesNotExist fails). Mutation-discriminating both ways; no new helpers (drift-green). Lang key consumed once (:1954), {name} matches, en-only parity + CliLangCatalogue orphan arm hold.
5. Laws — no §1.9 hazard literals; no timers; PLUGINS.md row in-step (zero guard refs); no suite-figure/durations/dist touches.

NITs (record, no fix owed): [NIT] BundleInstallTest.php:169 dot test asserts exit+empty root but not refusal message text; [NIT] no explicit basename-fallback dot-name case (structurally covered); [NIT] clone arm wouldn't strip a .git REGULAR file (removeTree no-ops non-dirs) — unreachable with --depth 1 sans --recurse-submodules.
