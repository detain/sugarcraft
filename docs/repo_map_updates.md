# SugarCraft Refactor — Shared Updates Scratchpad

This file is the **single shared scratchpad** for the refactor. Every subagent receives its current contents in their prompt and may append to it. The supervisor reads it but never edits it directly.

## How to use this file

- **Append** an entry under `## Active Items` when you discover something the next agent (or the supervisor) needs to know.
- **Move** an entry to `## Resolved Items` when the situation it described is no longer true.
- **Never** delete an item — moving to Resolved is the archive.
- **Use the `BLOCKING:` prefix** in your return message AND append a matching entry here when you cannot complete required work. The supervisor halts on `BLOCKING:` and surfaces it to the user.

Entry format:

```
- [YYYY-MM-DD HH:MM | step-NN | role] Short title — one-paragraph description, file paths, links to PRs, anything the next agent needs to know.
```

## Active Items

*(empty)*

## Resolved Items

*(empty)*
