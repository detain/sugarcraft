# REVIEW-1 — C-TOOL-7.d (independent, 2026-10-28)

VERDICT: CLEAN (1 NIT)

Commit 674e54bc0 on cc/C-TOOL-7.d off b985fbe51. Diff = exactly the 3 declared files;
worktree clean. All 8 verification items pass:

1. No double-escape. Flags confirmed: JSON_UNESCAPED_SLASHES|UNESCAPED_UNICODE|
   INVALID_UTF8_SUBSTITUTE|THROW_ON_ERROR (test PLAIN_FLAGS mirrors them byte-equal).
   The sweep class [\x{7F}-\x{9F}\p{Cf}] contains no ASCII, so every json_encode
   artifact (structure, \uXXXX escapes, `\\u0041` literal-backslash text) is
   pattern-immutable; preg never re-scans replacements. Non-vacuous absence+presence
   legs in test 1.
2. Astral Cf: surrogate math verified by hand for U+1D173 -> \ud834\udd73 (asserted
   verbatim in test) and round-trip identity pinned. preg /u subject is valid UTF-8
   by construction (SUBSTITUTE); testInvalidUtf8SubstitutionSurvivesTheSweep pins
   U+FFFD pair + escaped Cf and is RED under base swap (not vacuous).
3. Prompt cache intact: all encodeDocument callers are output-echo (Serve.php,
   Subcommands::emitDocument, StreamJsonEmitter:175, NonInteractive :1400/:2127
   stdout arms). Zero refs under src/Providers — no wire-request path touches it.
4. Subcommands untouched verdict honest: live :1750 bundles-list --json and :2730
   mcp-import non-json preview are JSON_PRETTY listings without the schema
   envelope; mcp-import's FORMAT_JSON branch routes emitDocument -> encodeDocument
   (read at both sites).
5. Single-encoder claim holds — -p json, stream-json (delta AND terminal lines,
   pinned by the emitter test), subcommand contract docs all ride the one post-pass.
6. Regression RE-VERIFIED via redirect cp-swap of the base file (git show
   b985fbe51:NonInteractive.php > scratch, cat-swap into worktree): exactly
   3 failures (testInvisibleCodePointsNeverReachTheEncodedDocumentRaw,
   testInvalidUtf8SubstitutionSurvivesTheSweep, testABidiOverridePayloadArrivesEscapedOnEveryLine)
   — round-trip/zero-changed-bytes legs stay red-free as REPORT describes. Restored;
   `git status --porcelain` empty; green-after 5T/26A.
7. Gates re-run (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS):
   - 'NonInteractive|StreamJson|EncodeDocumentEscapingTest' -> Tests: 165, Assertions: 812, OK (2 pre-existing deprecations, disclosed).
   - 'BinCandyCodeAutoloadGuard|ReadmeJsonErrorContractDrift' -> Tests: 57, Assertions: 550, OK.
   - extra due-diligence 'GlobDialectDifferential|DuplicatedTestHelperDrift' -> Tests: 27, Assertions: 560, OK (new regex literals do not drift the PathGlob corpus).
   Durations row for the new test file is claimed in REPORT as weld duty. Present. OK.
8. Laws: 3 files only; %ae and %ce separate greps both detain@interserver.net;
   trailers empty; suite-figure/durations/dist untouched; no glob-shaped or
   denial-shaped test literals (Glob guard green); mb_ord safe (ext-mbstring hard
   require).

NIT (step-local, non-blocking): NonInteractive.php ~:1506 — `(string)` cast on
preg_replace_callback's return maps a hypothetical PCRE-internal null (backtrack-
limit exhaustion, ~10^6 Cf chars in one document) to an empty string: the silent
empty-stdout outcome the file's own docblock calls unrecoverable. Practically
unreachable (single-char class, linear scan, and the sweep-failure polarity is
elsewhere handled by throw-not-cast), but a `?? throw new \JsonException('invisibility sweep failed')`
before the cast would match the house discipline of the $json === false arm above.
