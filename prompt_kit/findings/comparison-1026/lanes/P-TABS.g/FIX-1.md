# P-TABS.g — FIX-1 (round 1, step-local MINOR-1)

FIX: MINOR-1 cured — WindowStripShellTest gains testTheStripStandsDownWhileAModalOwnsTheFrame:
deck app (1 tab, 120x30, rows >= WINDOW_STRIP_MIN_ROWS) armed with TrustDialog::new(...) at the
cheapest modal leg; asserts chromeScanner ZONE_NEW null AND no '[+]' row in the frame — pinning
the early-modal stand-down that was structurally true but unpinned. Non-modal leg already covered
by testOneDeckTabPaintsThePlus (positive) and the dock/row-floor pins (existing). Mutation proof:
hoisting the strip render into the trustDialog return path reddens exactly the new test (1F/8),
cp-restore md5-verified (Renderer.php 751e5b66...). Gates (plain pipe, -u LINES -u COLUMNS):
WindowStrip filter 21T/747A OK; shell-file testdox 8T/182A OK (7T-era +1); neighborhood
PaneTest|PaneReverseCycle|TuiLang|MouseModal|WindowTitle|TabAttention 207T/4140A OK; guards
GlobDialectDifferential|DuplicatedTestHelperDrift|DenialPrefixRoster 44T/715A OK. php -l clean.
Branch cc/P-TABS.g amended in place (single-commit law): a1504e05e -> d8732079268397e9120cf06e91262bca6cf9a49c
(cat-file verified; 6638d6415..tip = exactly 1 commit; 15 files, +1112/-43; author+committer
detain@interserver.net verified, zero Co-Authored/Co-developed trailers; only the test file grew,
+28 lines). Durations impact: WindowStripShellTest per-file count 7 -> 8 — welder must mint the
tsv row from the per-file junit sum 8 (supersedes REVIEW-1 MINOR-2's 7).
