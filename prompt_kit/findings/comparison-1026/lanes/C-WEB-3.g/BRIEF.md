# BRIEF — C-WEB-3.g
**Dormant-method roster spec (fail-loud on new uncalled RPCs)** · area web · wave 15 · kind dormant-wire · S · effort  · phase normal

## Item
C-WEB-3 (sources: WEB-1-P1 (fix: dormancy roster spec))

## Approach

- **What the spec derives.** One spec: the `METHOD_SCOPES` keys (`protocol/generated.ts`) minus every method a shipped module calls. It reuses the `shutdownDormant.spec.ts` source-scan regex (`request('M'` / `method: 'M'`).
- **What it pins.** The exact deliberate-dormant set, `{server.shutdown}`, with a one-line reason per entry.
- **Why.** A method a later lane adds with no browser caller now fails loudly until it is wired or declared. `shutdownDormant.spec.ts` stays as the behavioural pin.

## Files create
`candy-code-web/src-web/__tests__/dormantRoster.spec.ts`
## Files modify
none
## Tests
create `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` (the derivation plus the pinned set).
## Docs/guards
vitest. The `candy-code/COMPARISON.md` §6.7 wording is the orchestrator's (see Notes).
## Serialized
[hot:candy-code-web/src-web/__tests__/dormantRoster.spec.ts]
## Deps
C-WEB-3.a, C-WEB-3.b, C-WEB-3.c, C-WEB-3.d, C-WEB-3.e
## Ruling
none
## Risks
Methods other areas add before this lands (C-SEC-*, C-MCP-3, C-SES-7/8, P-CTX-BREAKDOWN) must already have callers or join the set. Re-derive at landing.
