WAVE-VERDICT: FINDINGS (0 CRITICAL / 0 MAJOR / 2 MINOR) — wave-23 (10 steps) landed honestly.
Audit at master tip 88495bee5 (started 80fe2275b; concurrent-master caveat: foreign C-SEC-7.b W25 pick landed mid-audit, welder may still be adding picks — all claims below re-derived from master blobs/tree at run time).

## 1. Claims vs landing — 10/10 PASS
All §4 marks exist as objects; every promised artifact proven in the landed tree:
- C-AGT-4.e: SessionMailbox.php:43 wired into SessionHost.php:1295, Chat.php:20287, SendMessageTool; docs/AGENTS.md:366 documents `session:<id>`; 4 DUR rows present (SessionHostMailDrainTest 16.088/17, SessionMailboxTest 0.064/48, SessionMailWakeTest 0.244/21, SendMessageCrossSessionTest 0.497/51). Mark SHA 94ef2b0f2 IS on master (final pick).
- C-AGT-5.f: ServerSettings.php:168 `webhooksOut` (user-config-only law in :160-167 comment + Bootstrap.php:2256 reads $userConfig only, workspace registers WebhookDispatcher :2259); lang rows label/help/refuse :3451-3920; SETTINGS.md:1364 + restart roster; SERVER.md event rows :351-355; OutboundWebhook* files exist. gen-settings-doc --check 'up to date'; SettingsSchemaDocDrift green.
- C-CTX-4.d: PublicUrlGuard.php:26; InstructionFileLoader.php:446 fetch seam injectable; MEMORY.md SSRF sentences :890-897; DUR x2 at 4f1bdab31 (mint commit honest about 17T->16T correction).
- C-CTX-4.e: builtin-commands/1405-output-style.php, OutputStyleHostCommand.php:61, RulesState one-of-one slot, lang cmd.output-style/host.output_style; CommandDocsDrift + ReadmeRosterDrift + DocFigureProseDrift (92T) green at tip => counters self-consistent incl later foreign 63->64 bump (dc9eea92d disclosed).
- C-MCP-2.c: mark 58fff9bb6 lane-only, pick 2e29b87e1 + dist rider c0bb77e7c on master (protocol trio regen + dist rebuild, message discloses carriers); tip dist bundle index-ZSMjOfw.js contains `mcp:` picker literals.
- C-MCP-4.d: Subcommands.php:4172 --write doors; DUR row SubcommandsMcpImportWriteTest present; ReadPathCensus row rode in-pick; StderrEmitterCensus green.
- C-PRV-4.b: CodexInvocation always-`-s` :121-123 + --output-schema spill; DUR row present; merged Codex window green.
- C-SEC-6.c: remember:"user" + saved echo (PermissionMethods :82-295), MethodSchemas tier/savedIndex + permission.forget; SERVER.md row present; web specs at correct tip paths green (rulesPanelSaved/composerMcpMentions/permissionCardExtras 3 files 24T; approvals 6T); DUR row present; vitest-only account +14T/+50A recorded.
- C-SEC-6.e: OtlpEncoder.php:45/:87 SAFETY_EVENT_NAME; ENVIRONMENT untouched (no new env var — EnvRosterDrift green); DUR row present.
- C-SEC-7.c: skeleton e850dd9a4 -> impl 62528ab70 (EngineBackend PLAN_MODE_ADVERTISED_NAMES trio at :261/:3646, +348L test) -> FIX-1 7516e7e42; tip PlanModeToolRosterTest complete (not the 26-line stub); PromptPrefixByteStability green.

## 2. Merged-tree gates at tip
- W23 lane family: 161T/820A OK. Guard family: 441T/66293A/2F — BOTH reds foreign-attributed, not W23 account: (a) ReadPathCensusTest — ServerConfig.php fgc site from C-WEB-5.a 3eac7826c + RemoteSessionPush site already W25-account (plan :154); (b) PermissionsIntroDriftTest::testThePatternToolRosterIsTheWholeCatalog — catalog gained Advisor via C-PRV-3.d 8defce213 but the :813 roster paragraph never lists `Advisor` (only the no-ask bullet was edited). §7 must carry (b) explicitly — it is NOT yet in any mark.
- Generators: gen-protocol-schema --check, gen-settings-doc --check, gen-command-docs --check, gen-tool-docs --check all 'up to date'. DuplicatedTestHelperDrift green (W23 added many test FILES — law (g) satisfied). vitest serial green. dist in sync (rebuilt b82aa73c6 after last src-web touch).

## 3. Cross-lane interactions
Chat.php/SessionHost.php (AGT-4.e + AGT-5.f) and lang/en.php/SETTINGS.md (multiple) co-merge clean — guard + family runs green at tip. SessionView.vue shared MCP-2.c/SEC-6.c serialized per :2822 disclosure. No duplicate keys/canceling roster edits found; E646 clean (webhooksOut timers are webhook-bound, disclosed verbatim).

## 4. FINDINGS
- W23-MINOR-1: candy-code/lang/en.php:3919 — `settings.webhooksOut.help` (single-quoted PHP string) contains literal `\u2014` (sole occurrence in the file) which renders as backslash-text in the settings-help UI, not an em-dash. Fix: replace `\u2014` with a literal `—` (file uses it elsewhere). Lane C-AGT-5.f.
- W23-MINOR-2: weld hygiene — /home/sites/cc-cmp-C-CTX-4.e worktree + branch cc/C-CTX-4.e left for a welded lane (branch content-proven fully absorbed: zero branch-unique lines across its 6 lane files vs master). Fix: prune both at closeout after re-running the absorption diff; also prune /tmp/opencode/weld1030L/base-check.
- LEDGER-NOTES (disclosed §7 carries, verified consistent): DUR rows owed for PlanModeToolRosterTest.php + OutboundWebhookWiringTest.php (weld 10950ade2 'owed closeout'; marks :2755/:2844 omit 'minted' = honest); COMPARISON.md :383/:938/:3545 stale-quotes doc-truth pass (SEC-7.c carry); suite-figure staleness-pair re-pin duty; NEW owed row for §7: PermissionsIntroDrift Advisor paragraph cure (foreign, §2a).

## 5. Hygiene/laws
Authorship: 20/20 sampled picks+welds %ae/%ce detain@interserver.net; 33-commit trailer grep = 0 Co-Authored/Sign-off. Generated files regenerated (rider c0bb77e7c; gen --checks). No weld-only file committed by lanes (evidence files ride weld commits per house pattern). Dormant code wired not deleted (dormantRoster row dropped WITH its caller). No wave-23 lane left tree dirt in the main checkout.
