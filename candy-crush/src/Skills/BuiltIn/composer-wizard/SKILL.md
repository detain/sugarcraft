---
description: Composer dependency management, version constraints, and autoloading configuration.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash,Composer"
effort: medium
paths:
  - "composer.json"
  - "composer.lock"
---
# Composer Wizard Skill

When managing Composer dependencies:

## Version Constraints
- Use caret (^) for patch/minor updates: ^1.2.3
- Use tilde (~) for minor updates: ~1.2.3
- Use exact for critical: 1.2.3
- Avoid * unless intentional

## Security
- Run composer audit regularly
- Update dependencies frequently
- Use require-dev for dev-only packages
- Lock versions in production

## Autoloading
- PSR-4 for modern code
- Classmap for legacy
- Optimize after adding many classes

## Scripts
- Use scripts for repeated tasks
- Keep scripts cross-platform
- Document script dependencies