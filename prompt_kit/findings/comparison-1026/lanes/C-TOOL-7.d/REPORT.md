# C-TOOL-7.d — JSON-output escaping of control/format chars — DONE

Fix shipped on branch cc/C-TOOL-7.d (base b985fbe51): NonInteractive::encodeDocument
gains the invisibility post-pass — after json_encode, a preg_replace_callback over
`/[\x{007F}-\x{009F}\p{Cf}]/u` rewrites DEL, C1 and every Cf format character as
`\uXXXX` (astral Cf as its surrogate pair). Docblock carries the WHY + the
Message::escapeInvisible (ISS-100) antecedent. This is the single encoder for
`-p --output-format json`, stream-json and the subcommand JSON documents, so the
headless wire now matches the TUI's invisibility policy.

Subcommands.php: NOT touched. The two direct json_encode sites (brief cites
:1516/:2419; live :1750 bundles-list --json, :2730 mcp-import non-json preview)
are JSON_PRETTY_PRINT listing/preview documents without the schema envelope —
not contract documents under the block's "IF they emit contract documents"
condition (mcp-import's contract branch already routes through encodeDocument).

Regression proof (fails before the fix): stripping the post-pass reddens exactly
3 tests — testInvisibleCodePointsNeverReachTheEncodedDocumentRaw,
testInvalidUtf8SubstitutionSurvivesTheSweep, and StreamJsonEmitterTest's new
testABidiOverridePayloadArrivesEscapedOnEveryLine; round-trip/zero-changed-bytes
legs stay green under the strip (they pin sweep-preserved properties). Restored
by md5, re-run green.

NEW test file: candy-code/tests/Cli/EncodeDocumentEscapingTest.php (4 tests: raw
absence + escape presence, byte-identical decode round-trip incl. Cf object keys
and unescaped-slash/backslash structure, visible content — ASCII/CJK/emoji/
U+E002-U+E003 PUA markers — zero changed bytes vs plain json_encode, invalid-UTF-8
substitute survives). Modified tests/Cli/StreamJsonEmitterTest.php (+1 bidi
override arrives escaped on every line). Durations.tsv row owed by the gate for
the new file only (not touched here, per law).

Gates (targeted, plain pipe, cwd worktree candy-code): 'NonInteractive|StreamJson|
BinCandyCodeAutoloadGuard|ReadmeJsonErrorContractDrift|Headless' → 296 tests
1528A all OK. Guard family (StderrEmitterCensus|Glob*|Duplicated*|EnvRoster|Readme
Roster) → 235T/61480A with exactly 3 StderrEmitterCensus roster-staleness failures
PROVEN FOREIGN: clean base b985fbe51 without my files reproduces the byte-identical
95T/20682A/3F subset (the missing +1 fwrite/message sites are ArgvParser/Chat/
Renderer territory owned by lane C-TOOL-3.b, currently mid-stash — see below).
Pre-existing 2 'not public' provider deprecations (AccessorDeprecationTest era)
unrelated. php -l clean on all three files.

ORCHESTRATOR NOTE (stash hazard): while proving base-green I pathspec-stashed; a
sibling C-TOOL-3.b stash push/pop raced on the shared refs/stash and my pop
applied THEIR WIP (en.php/Chat.php/Renderer.php/ToolTailSlots.php, dropped stash
commit f21cbf11d1fd44a6c9c6dac351971f724942715b 'On cc/C-TOOL-3.b: tmp'). This
lane reset --hard back to b985fbe51 and restored its own files from md5-verified
backups; nothing of theirs was committed here. Their diff survives at
/tmp/opencode/cc-cmp-C-TOOL-7d/foreign-applied.diff and the dangling object can be
re-registered with `git stash store f21cbf11d1...`. Durable law re-proven: file-copy
harness for red-vs-base, never git stash in parallel worktrees sharing refs/stash.
