---
name: security-auditor
description: Reviews a diff or directory for OWASP-class issues; use before merging anything touching auth, input parsing, or SQL.
tools: [Read, Grep, Glob, Bash]
disallowedTools: [Write, Edit]
model: sonnet
permissionMode: plan
maxTurns: 25
skills: [security-audit, php-best-practices]
mcpServers: [git]
memory: project
effort: high
isolation: worktree
color: red
---
