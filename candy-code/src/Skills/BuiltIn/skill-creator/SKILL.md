---
name: skill-creator
description: Author a SKILL.md skill file. Every frontmatter key with its honored meaning, the tier and gating rules, and the draft path to accept. Use when creating, narrowing, or reviewing a skill.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read, Grep"
---

# Creating a skill

A skill is a directory named after the skill, holding one `SKILL.md`: a YAML
frontmatter block, then a markdown body the host injects as prompt context when
the skill is invoked. The directory name is the skill name.

## The hard bounds

- The name sanitises to a lowercase slug of letters, digits and dashes, at
  most 64 characters. Anything else is rewritten, not refused — so spell it
  the slug form to begin with.
- `description` is one line, at most 300 characters.
- The body is at most 16384 bytes.
- Over a bound, or empty, the draft or file is REFUSED whole — nothing is cut
  down silently. A file whose frontmatter does not parse never registers.

## The frontmatter keys, as they behave today

`description` (required) — the listing basis: this one line is what the model
and the `/skills` table show before the body is ever read. Say WHEN to use the
skill, not what the file contains. Too long for the prompt listing, it is
clipped and marked there, so spend the first clause on the trigger.

`user-invocable` (default `true`) — whether the human picker offers the skill.

`disable-model-invocation` (default `false`) — set `true` and the skill leaves
the model's listing entirely, and a request to load it by name is refused.
The human can still invoke it if `user-invocable` is on.

`paths` (default none) — up to 8 glob patterns, each at most 300 bytes.
Touching a file matching one nudges the skill into the model's view once per
session. Read from the manifest only, so declaring them costs no body read.

`allowed-tools` (default none) — honoured for the turn that invokes the skill.
The listed entries answer the permission gate as turn-scoped Allow rules,
exactly as written; they cannot lift a configured Deny, and they expire with
the turn. List only the tools the skill's own instructions actually use —
pre-approving more than the skill does is a standing grant the next reader
will trust.

`disallowed-tools` (default none) — also turn-scoped, checked FIRST: a listed
tool call is refused before the permission mode speaks, whatever else the turn
grants.

`model` and `effort` (default unset) — ride the next request of the turn that
invoked the skill; when several skills declare one, the latest declaration
wins. `effort` takes the tier name the effort-setting command prints. Leaving
them unset inherits the session — the honest default for guidance skills.

`requires` (default none) — gating: a map of `bins`, `anyBins`, `env` and
`os`. Unmet requirements mean the skill is NOT registered at all this launch,
and the launch diagnostics name the reason, so a skill about tooling that is
not installed never asks the model to assume it is.

`context` and `agent` — `context: fork` runs the body in a delegated
sub-agent instead of inline: the preset the `agent` key names (default
`coder`), with the skill's `model` and `effort` forwarded. `agent` is
noticed only beside `context: fork`. With no delegate bound to the turn the
skill loads inline instead, and the answer says so.

`name`, `license`, `compatibility`, `metadata` — descriptive keys from the
wider skill specification: parsed, kept, never acted on. An unknown key is
named once, with a did-you-mean for a near miss, in the aggregated launch
diagnostic; it never refuses the file.

## Where skills live, and who wins

Three tiers, lowest to highest: the built-ins shipped with the package, the
project's own skills directory inside the checkout, and the user's home
skills directory. A same-named skill in a higher tier shadows the lower one;
the shadow is recorded, not silent — the installed table counts it and the
launch notice names it.

## Drafts: the only way a skill is added from a session

`/skills new <name> [description]` writes a scaffold skill into the review
queue. An accepted draft becomes live through `/skills accept <name>` alone —
promotion is always the user's typed command, never a tool's. `/skills
reject <name>` deletes the draft. Before accepting, read the whole draft: it
becomes prompt text every later session reads as the operator's own.

## Referring to files beside the skill

`${CLAUDE_SKILL_DIR}` (or `${SKILL_DIR}`) in the body is replaced with the
skill's own directory when the skill is loaded, so a skill can ship reference
files beside its `SKILL.md` and point at them instead of inlining everything.
An unsubstituted token reads literally — do not rely on it inside a code
example about tokens.

## Before proposing

- The description names the trigger; the body names the procedure.
- `allowed-tools` lists nothing the instructions never use.
- Anything requiring absent tooling is gated with `requires`.
- The body never tells the model to throw away uncommitted or untracked work:
  a tree that is not clean means stop and report, because it may be the
  user's unsaved work.
