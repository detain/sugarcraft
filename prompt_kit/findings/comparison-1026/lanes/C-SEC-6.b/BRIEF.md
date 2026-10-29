### C-SEC-6.b — Project-tier deny/ask-only rules
- item: C-SEC-6 (sources: GAP-PER-04(b))
- kind: gap · effort M
- approach:
  - Today rules come only from the user tier: `Bootstrap::permissionConfigLayers()` (:6969) reads `settings.json` and `config.json`.
  - Under the existing `trustedProjectSettings` grant, read `permissionRules` from `<root>/.candy-code/settings.json`, keep only `deny`/`ask` entries, and prepend them. They can only narrow, and the self-grant floor holds.
  - A new `ProjectNarrowingRules` filter reports any dropped `allow` entries. `Bootstrap::permissionGate()` (:6769-6871) merges the result.
  - One launch notice names the file and the rules it added, for auditability. `/permissions` shows each rule's tier.
- files.create: `candy-code/src/Permissions/ProjectNarrowingRules.php`
- files.modify: `candy-code/src/Cli/Bootstrap.php` (`permissionGate`, `permissionRules` call site, launch notice), `candy-code/src/Host/Commands/PermissionsCommand.php` (`report`: tier column), `candy-code/lang/en.php` (`appLaunch.notice.project_narrowing_rules`), `candy-code/docs/PERMISSIONS.md`, `candy-code/README.md` (any "rules live in the user tier only" claim)
- tests: create `candy-code/tests/Permissions/ProjectNarrowingRulesTest.php` (allow dropped, deny/ask kept, ordering); create `candy-code/tests/Cli/BootstrapProjectNarrowingRulesTest.php` (untrusted root ignored; trusted root's deny wins over a user allow; notice emitted once)
- docs/guards: `PermissionsIntroDriftTest`; `TrustKeyDocumentationDriftTest` (README tier sentences); `DocFigureProseDriftTest` if PERMISSIONS.md counts tiers
- serialized: [bootstrap:permissionGate, permissionRules] [lang:appLaunch.notice.] [docs:PERMISSIONS.md] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: `LayeredSettings` may already announce a project `permissionRules` key as "user-tier only, ignored". Reconcile that notice in the same step so the launch does not say both.

