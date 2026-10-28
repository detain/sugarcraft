# C-TOOL-5.c — LSP installer: pinned release allowlist + launcher install-dir precedence
Verdict: DONE on cc/C-TOOL-5.c (base 4b771634d).
Shipped: LspReleases.php pinned manifest — npm (intelephense 1.18.5, typescript-language-server
6.0.2, pyright 1.1.414→pyright-langserver) + rust-analyzer 2026-10-26 binaries, 4 targets, sha256s
(linux/x86_64 pin re-hashed from the real asset: 4c83…80ce). isAllowedUrl gate: https-only, exact
hosts {github.com, objects.githubusercontent.com}, date-grammar repo path + rust-analyzer-*.gz asset
law; objects arm needs release-asset bucket + filename query. LspInstaller.php: explicit-only into
PrivateDir 0700 ~/.candy-code/lsp; binary arm re-gates BEFORE request, Guzzle connect 5s/read 60s +
curl low-speed, NO total timeout (E646; pinned via assertArrayNotHasKey 'timeout'), progress 512MiB
ceiling + on-redirect re-gate, hash_equals sha BEFORE decompress, staged compress.zlib copy→chmod
755→atomic rename, temp unlinked on every refusal; npm arm --ignore-scripts, pinned pkg@ver,
registry pinned, every token escapeshellarg, via runCaptured (zero new proc_open; child-lifetimes
rc=0), 300s bound, post-hoc bin verification, injectable $run seam. LspLauncher.php fromConfig
trailing ?installRoot: production probe checks <root>/bin then node_modules/.bin BEFORE PATH and
stores the ABSOLUTE path (provenance names it); injected probe keeps the old bare-name contract
byte-identical. Tests: NEW LspInstallerTest (19: gate shapes/refusals, verified/mismatched bytes,
404/transport, guard wiring, npm command+arms, probe precedence), AutoDetect trio (bin wins,
node_modules arm, injected probe ignores tree), ReadPathCensus fopen OWNED_HOME ×2,
TreeWideGuardRoster scandir($dirs[$i]) row.
FIX-1 (review round 1): MINOR-1 cured with a structural source-slice pin
(testTheChosenExpressionResolvesTheInstallTreeAheadOfThePathProbe — red under a PATH-first
reorder while the behavioral trio stays green, proving it IS the discriminator); NIT-1 cured —
chmod-failure branch now unlinks $staged like the rename sibling, pinned structurally
(testTheChmodFailureBranchDiscardsTheStagedFileLikeItsRenameSibling, red under re-introducing
the leak); NIT-2 no action per ruling. Figures below include the +2 pins.
Gates (cwd=candy-code, plain pipe -u LINES -u COLUMNS): tests/LSP + ReadPathCensus OK 197/1437 as originally built (reviewer window 210/1515 = same filter + 13 foreign BinCandyCodeWiring rows), 212/1527 at FIX-1 (+2 pins);
final set (LSP, ReadPathCensus, DuplicatedTestHelper, GlobDialect, ChildWallClock, DocFigure,
TreeWideGuardRoster) 343/20830 with 2F — both the TreeWideGuard pair accusing foreign
Cli/JsonSchemaEnvelopeTest glob + Context/MemoryRecallBlockTest iterator (files byte-identical to
base; git diff --name-only = my 7 paths only). StderrEmitterCensus 3F / ContainedPathInventory 2F /
ReadmeSuiteFigureDrift 2F at base too (foreign NonInteractive/ArgvParser/AgentMemory/ContextHostCommand
drift; figure 25,537 vs live 26,516). tools child-lifetimes + path-repos rc=0.
Owed downstream: durations.tsv row for NEW tests/LSP/LspInstallerTest.php; figure re-pin; the
`lsp install` CLI verb + docs ride C-TOOL-5.d. No Lang/env keys (raw English per LspLauncher
problems() precedent). Windows/musl/gopls refusals name the verdict (no zip arm; go needs toolchain).
