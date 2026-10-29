### C-MCP-2.c — Web composer `@mcp:` resource picker
- item: C-MCP-2 (sources: CLAW-new#9, p1-miss "web composer `@mcp:` completion")
- kind: feature · effort S
- approach: The composer's `@` popup is file-only (`searchFiles` prop, `candy-code-web/src-web/components/Composer.vue:17-18, 72-110`). When the trailing partial starts with `mcp:`, the popup lists `mcp.resources` rows (fetched once per session and cached; filtered by server/uri/name). Accepting inserts `@mcp:<server>:<uri> `, the keyword form the server already resolves. SessionView passes a `searchMcpResources` prop the way it passes `searchFiles`. With no rows (`not-started`, no servers), the popup falls back to files.
- files.create: `candy-code-web/src-web/components/__tests__/composerMcpMentions.spec.ts`
- files.modify: `candy-code-web/src-web/components/Composer.vue` (mention popup), `candy-code-web/src-web/views/SessionView.vue` (Composer props at :175)
- tests: create `candy-code-web/src-web/components/__tests__/composerMcpMentions.spec.ts` (an `@mcp:` partial lists resource rows; accept inserts the two-colon form; an empty roster falls back to files). Run vitest with `--no-file-parallelism`.
- docs/guards: none (MCP.md parity sentence landed in C-MCP-2.a/2.b)
- serialized: [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/views/SessionView.vue]
- deps: C-MCP-2.b
- phase: normal
- ruling: none
- risks: Composer.vue is a central web component (other WEB and image-menu lanes), so keep the change inside the mention-popup computed and helpers.

