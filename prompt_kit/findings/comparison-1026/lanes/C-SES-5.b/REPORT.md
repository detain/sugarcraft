# C-SES-5.b — REPORT (authored by the welder from evidence; closes REVIEW-1 [MINOR] gap)
- Shipped (pick a8ead9976 / lane e82cd415e, 4 files +526/−38, no new test file → durations untouched):
  `SelfUpdate` prefers a NEW `candy-code.phar.sha256.sig` — Ed25519 over the SIDECAR BODY, phar never
  enters memory; legacy raw-bytes `.minisig` kept one cycle behind a filesize+64 MiB `memory_limit`
  raise restored in `finally`; en.php `cli.selfupdate.sig.deprecated` + `sig.sidecar_invalid` +
  truth-flipped `signature_missing`; README states both are raw base64 sodium, not minisign.
- Merged-tree gates (cwd candy-code, plain pipe): SelfUpdate OK 42T/759A (lane said 41/753 — delta is
  the E257 per-src-file BinCandyCodeWiring row); StderrEmitterCensus|LangParity|CliLangCatalogue OK
  110T/22798A; five-guard 133T/15349A, ONLY allowed staleness pair (25,537 pinned vs 25,671 live).
  Generators commands/tools/settings all "up to date" (no-op); php -l clean. Carries: [NIT keep] the
  legacy deprecation notice is per-run by design, not one-time.
