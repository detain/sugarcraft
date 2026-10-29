5076c63c8 C-WEB-2.b: shared SkillSlashInvocation gate - typed /skill-name rewrites on served sessions too

DONE. Extracted Chat::skillInvocationDraft() body verbatim into new pure
Skills\SkillSlashInvocation::draft(string,?SkillRegistry):?string; Chat keeps a
one-line delegate (unknownSlashNotice {@see} cite survives). SessionHost::admit()
now calls the shared gate after $this->command($text) returns null and before
admitPrompt(), gated on $options->resolveMentions; the typed draft rides
admitPrompt's 3rd arg so checkpoint rewind keeps `/name` while the turn carries
`$name`. docs/SKILLS.md §"`$name` in a prompt" names the shared gate + served door.

Files: src/Skills/SkillSlashInvocation.php (new), src/Chat.php,
src/Host/SessionHost.php, docs/SKILLS.md, tests/Skills/SkillSlashInvocationTest.php
(new, 8T/73A), tests/Host/ServedSkillSlashTest.php (new, 7T/26A). 6 files +596/-60.

Gates (cwd candy-code, -c phpunit.xml, plain pipe, env -u LINES -u COLUMNS):
- SkillSlashInvocation|SlashSkillInvocation|ServedSkillSlash|SkillMentions|SkillRegistryTest: 80T/249A green (existing SlashSkillInvocationTest UNEDITED green = TUI parity)
- SessionHost|Served: 235T/977A green
- skill/dispatch family (UnknownSlash|CustomCommandDispatch|SkillTool|LoadedSkill incl.): 152T/521A green
- guards (DocFigureProseDrift|ClaimFamilies…|SymbolCitation|DuplicatedDocBlockLine|GlobDialect|DuplicatedTestHelperDrift|TreeWideGuardRoster): 154T/25914A, EXACTLY 2 reds = TreeWideGuardRoster pair, PROVEN FOREIGN (accuses batch-8 LaunchFlagsTest/SessionHubTest globs; cure 978ff2889 landed on master after base 4aa9ea204; green at main-tree 3cf70064f). 2 PHPUnit deprecations = pre-existing family baseline.
- php tools/check-one-type-per-file.php: 0 problems. php -l all green. 4/4 mutations one-flip-discriminating (M1 gate-neuter, M2 serve-call removal, M3 draft-arg swap, M4 shortest-first walk -> hits sibling tie pin = parity proof), cp-restore md5-clean.

OWED by welder/closeout (my forbidden files): durations.tsv rows for BOTH new
test files; suite-figure re-pin +15T / >=+99A minimum delta. No web/protocol/dist
touches; no lang keys added. Branch cc/C-WEB-2.b @ 5076c63c8, author/committer
detain@interserver.net verified, zero trailers, NEVER pushed.
