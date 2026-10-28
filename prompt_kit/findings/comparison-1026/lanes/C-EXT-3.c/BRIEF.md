# BRIEF — C-EXT-3.c

## C-EXT-3.c — Bundled `skill-creator` + `/skills new <name>` scaffold

- **area:** ext
- **kind:** gap · effort S
- **effort:** null
- **phase:** normal
- **item:** C-EXT-3 (sources: GAP-SKL-14)
- **deps:** C-EXT-2.b, C-EXT-2.c (the skill must teach the keys as they actually behave)
- **serialized:** [docs:SKILLS.md] [docs:README.md] [docs:COMMANDS.md] [lang:host.skills.new.] [hot:candy-code/src/Host/Commands/SkillsHostCommand.php]
- **ruling:** none
- **risks:** the built-in is listed to every project, which costs listing bytes.

## Files

- candy-code/README.md
- candy-code/docs/COMMANDS.md
- candy-code/docs/SKILLS.md
- candy-code/lang/en.php
- candy-code/src/Host/Commands/SkillsHostCommand.php
- candy-code/src/Skills/BuiltIn/skill-creator/SKILL.md
- candy-code/tests/Host/Commands/SkillsNewTest.php
- candy-code/tests/Skills/BuiltInSkillsTest.php

## Regions

{
 "candy-code/src/Host/Commands/SkillsHostCommand.php": [
  "new"
 ],
 "candy-code/lang/en.php": [
  "host.skills.new.*",
  "cmd.skills.hint"
 ],
 "candy-code/docs/SKILLS.md": [
  "nine",
  "eight",
  "table",
  "built-in"
 ],
 "candy-code/README.md": [
  "built-ins",
  "Ships"
 ],
 "candy-code/docs/COMMANDS.md": [
  "?"
 ]
}

## Approach


- Ship a ninth built-in skill, `skill-creator`. It teaches the `SKILL.md` format and the KNOWN keys from `FrontmatterKeyAudit` with their now-honoured meaning (fork, `allowed-tools`, `${CLAUDE_SKILL_DIR}`). It also covers gating and tiers, and the rule that drafts go through `/skills accept`.
- `/skills new <name> [description]` writes a scaffold draft with `ProposedSkills::propose()`, so `accept` stays the only promotion path. The reply prints the draft path.

## Tests

create `candy-code/tests/Host/Commands/SkillsNewTest.php`. Modify `candy-code/tests/Skills/BuiltInSkillsTest.php` (the ninth roster row).

## Docs/guards

null
