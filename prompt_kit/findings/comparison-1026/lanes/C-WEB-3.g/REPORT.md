# C-WEB-3.g — DONE

Dormant-method roster spec landed. Derivation (METHOD_SCOPES keys minus shipped-module
callers via the shutdownDormant regex idiom) yields exactly `{mcp.resources, server.shutdown}`
against tip 093ea6e13; roster pins both with per-method reasons.

## Deviation (for welder/ledger)
- Roster expanded beyond the brief's `{server.shutdown}` to include `mcp.resources`:
  C-MCP-3.a landed the server-side read surface with NO browser caller (grep of all
  src-web shipped modules is empty; client.ts exposes only generic `request<M>`, no
  per-method wrapper to miss). Reason row cites C-WEB-7.d (composer '@' popup) as the
  future caller to drop the row. This is exactly the fail-loud contract the spec itself
  enforces; the brief's candidate list anticipated it.

## Files
- create: `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` (3 arms: set-equality,
  roster-only-real-methods, rostered-genuinely-uncalled)
- `shutdownDormant.spec.ts` byte-untouched.

## Gates (plain pipe, env -u LINES -u COLUMNS, --no-file-parallelism)
- `vitest run dormantRoster.spec.ts shutdownDormant.spec.ts protocol/__tests__/generated.spec.ts`:
  Test Files 3 passed (3); Tests 9 passed (9); 2.01s. Re-run post-mutations identical.

## Non-vacuity
- M1 plant `'probe.fake'` roster row → 2F (set-equality + real-methods arms red —
  derivation enumerates real methods).
- M2 delete `'server.shutdown'` row → exactly 1F (set-equality red — undeclared
  dormancy fails loudly).
- Restores md5-verified c57d8756e73ac625c8b2a6a8eda973f9 both times.

## Notes
- First M2 attempt aborted on a wrong self-assert (header docblock mentions
  `server.shutdown`); mutation never hit disk, file stayed pristine — re-applied clean.
- Commit: a712efdcb on cc/C-WEB-3.g (1 file, +66), author/committer detain-verified,
  zero trailers, never pushed.
