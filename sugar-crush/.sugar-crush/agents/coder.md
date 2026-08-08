---
name: coder
description: Implements features and fixes bugs in PHP code; writes new files, edits existing code, and runs tests.
tools: [Read, Write, Edit, Bash, Grep]
disallowedTools: [git commit]
model: inherit
permissionMode: accept-edits
maxTurns: 50
skills: [php-best-practices, phpunit-best-practices]
memory: project
effort: high
isolation: worktree
color: "#6366f1"
---
