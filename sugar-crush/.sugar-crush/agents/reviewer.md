---
name: reviewer
description: Reviews code changes for quality, security, and style; reads diffs, grep patterns, and runs analysis tools.
tools: [Read, Grep, Bash]
disallowedTools: [Write, Edit]
model: inherit
permissionMode: plan
maxTurns: 30
skills: [code-review, php-best-practices]
memory: project
effort: high
color: yellow
---
