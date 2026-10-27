# C-MCP-1.a — stdio MCP spawn-dir tiering — DONE

Branch `cc/C-MCP-1.a` @ base ff10efc43, commit **0c0d8f55d** (8 files, +659/−5). Author+committer Joe Huss <detain@interserver.net> (%ae/%ce greps separate, 0 Co-Authored). Never pushed.

## Fix
- `sugar-mcp-client/src/StdioMcpServer.php`: ctor tail param `?string $cwd = null` → proc_open 4th arg (was hard `null`). Child-only; spawn planner cannot express it; default null = byte-identical historic inherit.
- `candy-code/src/MCP/StdioMcpServer.php`: adapter forwards `cwd:` to the library transport.
- `candy-code/src/MCP/McpClient.php`: `buildServer(..., string $tierPath = '')` (default keeps all reflection/3-arg callers green); stdio arm passes `cwd: $this->stdioSpawnCwd($config['cwd'] ?? null, $tierPath)`. New `stdioSpawnCwd()`: explicit key must be a string absolute existing directory else RuntimeException (→ per-entry config error via startServers() aggregate, sibling entries unaffected); otherwise user-tier (tierPath === userConfigPath) spawns at home root (dirname(dirname($tierPath)) — byte-inverse of McpDecision::mcpUserConfigPath), project-tier at dirname($tierPath) (the root holding .mcp.json, NOT the launch subdir), `''` provenance → null (legacy). `pathIsAbsolute()` is a char walk — no regex brackets (GlobDialect corpus law).

## Docs (same commit — §1.8 in-step law)
- `candy-code/docs/MCP.md`: stdio Keys cell gains `cwd` (DocFigure arm `testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` re-derives green); new "**Where a `stdio` server runs (C-MCP-1).**" bullet in §Two tiers, one precedence — incl. the disclosed change that project servers now run at the project root, and that a relative/missing `cwd` costs only its entry.
- `sugar-mcp-client/README.md`: StdioMcpServer table row + new "## Spawn directory" section.

## Tests (new)
- `sugar-mcp-client/tests/StdioMcpServerCwdTest.php` (4T) + fixture `tests/Fixtures/cwd_server.php` (NDJSON `where` tool reporting getcwd(); no glob-shaped literals).
- `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php` (6T): project→root-from-subdir, user→home, explicit-wins-both-tiers, relative refused naming entry while sibling server still answers, missing-dir + non-string refused, no-provenance reflection pin (3-arg → cwd null; 4-arg → repo root).

## Gates (all plain-pipe, linked vendors)
- sugar-mcp-client: `--filter Cwd` OK 4T/6A; `--filter StdioMcpServer` OK 89T/383A; full suite OK 234T/918A.
- candy-code: new file OK 6T/14A; DocFigureProseDriftTest full OK 91T/3889A (one in-step guard trim: my stdio-arm comment pushed the `headers'] ?? []` leg past bodyExcerpt's 3000-byte window — comment condensed, tier prose lives in stdioSpawnCwd()'s docblock); MCP neighborhood OK 73T/224A; StderrEmitter|LaunchFormat|SymbolCitation|DuplicatedDocBlock|TreeWideGuardRoster|DescriptorInheritanceGuard OK 158T/44403A; five-guard Glob/DuplicatedTestHelper/ChildWallClock green with ONLY the expected ReadmeSuiteFigureDrift staleness pair red (25,537 pinned vs 25,543 live = my +6 methods; gate re-pins — forbidden files untouched).
- `tools/check-child-lifetimes.php` rc=0 — 33 sites/9 findings/9 accounted, roster unchanged (cwd is not an fd; DescriptorInheritanceGuard sugar-mcp-client row unchanged).
- 2 pre-existing "PHPUnit Deprecations: 2" are the global AccessorDeprecationTest provider noise — sibling MCP tests report the identical pair at base.

## Red-before proof
With the 3 src files checked out at base ff10efc43 (fix files cp-backed, md5-verified restored after): lib Cwd test 4T→3E ("Unknown named parameter $cwd"); candy tier test 6T→1E+5F (all six). Post-restore both re-ran green. One in-test fix during gating: `get_debug_type(7)` is `int` — assertion corrected before commit.

## Deviations
- Project-tier spawn = dirname(configPath): for a launch already at root this equals old inherited behavior; substantive changes are user→home, subdir→root, explicit-key law (documented in MCP.md bullet).
- No new `HomeDirectory::owned()` call site (tier path is byte-reversible; avoids touching path rosters).
- `cwd` intentionally NOT in McpTrustPins UNPINNED_KEYS — unknown keys are fingerprinted by construction (McpTrustPinsTest:26 already uses `cwd` as its example).
- New *Test.php files need gate durations rows + suite-figure re-pin: `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php` (sugar-mcp-client is not in the sharded manifest).
