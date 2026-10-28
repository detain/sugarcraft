# BRIEF — C-SEC-5.b: Web trust targets and MCP pin re-approval

- area: sec
- src: design-sec.md
- item: C-SEC-5 (sources: GAP-PER-11 web half, GAP-MCP-N1, k3-dorm#5)
- kind: dormant-wire · effort M
- phase: normal
- ruling: none
- wave: None | late: False
- deps: C-SEC-4.b (routing and the write-failure arm), C-SEC-5.a (census and grant door)
- serialized (claims): [protocol] [hot:candy-code/src/Protocol/TrustAsks.php] [hot:candy-code/src/Host/ContextSources.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue] [hot:candy-code-web/src-web/stores/contextSources.ts] [hot:candy-code-web/src-web/components/StatusBar.vue] [hot:candy-code-web/dist] [lang:server.trust.] [docs:PERMISSIONS.md]

## Files to create
`candy-code/tests/Protocol/ContextTrustTargetsTest.php`

## Files to modify

- `candy-code/src/Protocol/TrustAsks.php` (keying, targets, `settle` writer closure)
- `candy-code/src/Protocol/Methods/ContextMethods.php` (`sources`, `trust`)
- `candy-code/src/Protocol/Methods/SessionMethods.php` (`subscribe` discovery)
- `candy-code/src/Host/ContextSources.php` (`answer` shape)
- `candy-code/src/Protocol/Schema/Definitions.php` (`TRUST_PROMPT`, `CONTEXT_SOURCES`)
- `candy-code/src/Protocol/Schema/MethodSchemas.php` (`context.trust` description)
- `candy-code/lang/en.php` (`server.trust.`)
- `candy-code/docs/protocol/candy-code.v1.schema.json`, `candy-code/docs/SERVER.md`, `candy-code-web/src-web/protocol/generated.ts`
- `candy-code-web/src-web/stores/contextSources.ts`
- `candy-code-web/src-web/components/panels/ContextPanel.vue`
- `candy-code-web/src-web/components/StatusBar.vue`
- `candy-code/docs/PERMISSIONS.md` (trust section: the web prompts for all four lists)

## Declared regions
{"candy-code/src/Protocol/TrustAsks.php": ["settle"], "candy-code/src/Protocol/Methods/ContextMethods.php": ["sources", "trust"], "candy-code/src/Protocol/Methods/SessionMethods.php": ["subscribe"], "candy-code/src/Host/ContextSources.php": ["answer"], "candy-code/src/Protocol/Schema/Definitions.php": ["CONTEXT_SOURCES", "TRUST_PROMPT"], "candy-code/src/Protocol/Schema/MethodSchemas.php": ["context.trust"], "candy-code/lang/en.php": ["server.trust."], "candy-code/docs/SERVER.md": ["?"], "candy-code-web/src-web/stores/contextSources.ts": ["?"], "candy-code-web/src-web/components/panels/ContextPanel.vue": ["?"], "candy-code-web/src-web/components/StatusBar.vue": ["?"], "candy-code/docs/PERMISSIONS.md": ["section:", "lists", "prompts", "four", "trust"]}

## Tests

- The new `ContextTrustTargetsTest`:
- a root with untrusted hooks, MCP and settings mints three prompts;
- each grant writes the right list;
- a pin mismatch on a trusted root mints `mcp-pin`, and granting it re-records the pins.
- modify `candy-code/tests/Protocol/ContextTrustPromptTest.php` (`trusts` shape) and `candy-code/tests/Protocol/ContextSourcesTest.php` (`trusts` shape).
- modify `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts` (list rendering).

## Docs/guards
protocol gen checks; `ServerProtocolDocumentationDriftTest`; `TrustKeyDocumentationDriftTest` (PERMISSIONS.md trust paragraph). Web dist rebuild.

## Approach (verbatim)

- `TrustAsks` (`src/Protocol/TrustAsks.php:33`) declares one grantable target (`TARGET_MCP`) and keys records by root. It becomes keyed by root+target.
- `ContextMethods::sources()` mints one prompt per `Bootstrap::pendingProjectTrust($root)` entry. That includes `mcp-pin` when `mcpPinRefusals()` is non-empty on a trusted root.
- `context.trust` settles through `Bootstrap::grantProjectTrust()`, passing the session workspace's `SettingsWriter` service.
- Schema:
- `Definitions::TRUST_PROMPT.target` widens to all five cases;
- the `context.sources` answer replaces the single nullable `trust` with `trusts` (a list);
- 4.b's subscribe-time discovery uses the widened census.
- Web: `ContextPanel` renders a list (one Grant/Reject row per target), and the `StatusBar` badge counts them.
- Each grant still applies next launch (the frozen-roster law), and the panel says so.

## Risks

- `ContextSources.php` and `ContextPanel.vue` are also P-CTX-BREAKDOWN's and C-MCP-4's surfaces, so sequence those against this lane.
- A `trustedProjectSettings` grant widens what the project file may set. The prompt reason must say that.
