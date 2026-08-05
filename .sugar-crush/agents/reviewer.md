---
name: reviewer
description: Reviews code for quality, security, and consistency; use before committing or opening a PR on anything non-trivial.
tools: [Read, Grep, Glob, Bash]
disallowedTools: [Write, Edit]
model: sonnet
permissionMode: plan
maxTurns: 30
skills: [code-review, php-best-practices, security-audit]
mcpServers: [git]
memory: project
effort: high
isolation: worktree
color: green
---
