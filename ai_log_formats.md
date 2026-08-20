# AI Session Log Files Documentation

## Overview

This document catalogs all AI session log files found across the system, including logs from Claude Code, OpenCode, Caliber, and project-specific logging utilities. Each log type is documented with its file path, format, content description, and sample entries.

**Search Scope:** 7 directories were explored across the SugarCraft project and user home directory.

---

## Directories Searched

| Directory                | Path                               | Status                | Notes                                                          |
| ------------------------ | ---------------------------------- | --------------------- | -------------------------------------------------------------- |
| `.claude/`                 | `/home/sites/sugarcraft/.claude/`    | ❌ No logs            | Configuration files only (settings, hooks, rules, skills)      |
| `.opencode/`               | `/home/sites/sugarcraft/.opencode/`  | ❌ No logs            | Agent definitions, memory/progress tracking (not session logs) |
| `~/.config/opencode/`      | `/home/sites/.config/opencode/`      | ❌ Does not exist     | —                                                              |
| `~/.cache/claude/`         | `/home/sites/.cache/claude/`         | ❌ Does not exist     | —                                                              |
| `~/.claude/`               | `/home/my/.claude/`                  | ✅ **Main log location**  | Extensive logging infrastructure                               |
| `~/.opencode/`             | `/home/my/.opencode/`                | ⚠️ Configuration only | Actual history at `.oc/commands/ocr/history.md` (placeholder)    |
| `~/.local/share/opencode/` | `/home/sites/.local/share/opencode/` | ❌ Does not exist     | —                                                              |

---

## Main Log Location: `~/.claude/`

**Path:** `/home/my/.claude/`

This is the primary location for AI session logging, containing multiple log types and subsystems.

---

### 1. Command History Log

**File:** `history.jsonl`  
**Path:** `/home/my/.claude/history.jsonl`  
**Format:** JSONL (newline-delimited JSON)  
**Size:** ~1.9 MB, 3563 lines  
**Description:** Complete command/input history with timestamps, session IDs, and project paths.

#### Sample Entry
```json
{"display":"/config","pastedContents":{},"timestamp":1773995217691,"project":"/home/sites/mystage","sessionId":"e8f38793-4611-431d-a2db-4925d73241dc"}
```

#### Fields
| Field          | Type          | Description                        |
| -------------- | ------------- | ---------------------------------- |
| `display`        | string        | Command or path displayed          |
| `pastedContents` | object        | Any pasted content (empty if none) |
| `timestamp`      | integer       | Unix timestamp in milliseconds     |
| `project`        | string        | Absolute path to project directory |
| `sessionId`      | string (UUID) | Unique session identifier          |

---

### 2. Daemon Activity Log

**File:** `daemon.log`  
**Path:** `/home/my/.claude/daemon.log`  
**Format:** ASCII plain text, timestamp-prefixed  
**Size:** 96 KB, 1143 lines  
**Description:** Daemon activity logs with ISO 8601 timestamps.

#### Sample Entries
```
[2026-06-03T16:45:11.233Z] [supervisor] ─── daemon start ─── version=2.1.161 pid=3128088 origin=transient
[2026-06-03T16:45:11.234Z] [supervisor] configuration loaded from /path/to/config
[2026-06-03T16:45:12.001Z] [daemon] listening on /unix/socket/path
```

#### Log Format Pattern
```
[YYYY-MM-DDTHH:mm:ss.sssZ] [subsystem] message
```

---

### 3. Conversation Transcripts

**Directory:** `transcripts/`  
**Path:** `/home/my/.claude/transcripts/`  
**Format:** JSONL (newline-delimited JSON)  
**Size:** Multiple files, up to 843 KB each  
**File Pattern:** `ses_*.jsonl` (e.g., `ses_20260510_000529_abc123.jsonl`)  
**Description:** Full conversation transcripts with message type, timestamp, and content.

#### Sample Entry
```json
{"type":"user","timestamp":"2026-05-10T00:05:29.139Z","content":"[search-mode]\nMAXIMIZE SEARCH EFFORT..."}
```

#### Message Types
| Type      | Description              |
| --------- | ------------------------ |
| `user`      | User input/message       |
| `assistant` | AI assistant response    |
| `system`    | System-generated message |
| `tool`      | Tool invocation result   |

---

### 4. Shell Session Snapshots

**Directory:** `shell-snapshots/`  
**Path:** `/home/my/.claude/shell-snapshots/`  
**Format:** Shell script (`.sh`)  
**File Pattern:** `snapshot-bash-*.sh`  
**Description:** Bash session state snapshots capturing environment variables, working directory, and shell state.

#### Sample Filename
```
snapshot-bash-20260603_164511_e8f38793.sh
```

---

### 5. Task State Tracking

**Directory:** `tasks/`  
**Path:** `/home/my/.claude/tasks/`  
**Format:** UUID-named directories with `.highwatermark`, `.lock` files  
**Description:** Background task state tracking with process locks and completion markers.

#### Directory Structure
```
tasks/<uuid>/
├── .highwatermark   # Task progress marker
├── .lock            # Process lock file
└── [other task files]
```

---

### 6. File History

**Directory:** `file-history/`  
**Path:** `/home/my/.claude/file-history/`  
**Format:** UUID-named directories (60 entries)  
**Description:** File access and modification history, tracking which files were touched during sessions.

#### Structure
```
file-history/<uuid>/
└── [file access metadata]
```

---

### 7. Background Jobs

**Directory:** `jobs/`  
**Path:** `/home/my/.claude/jobs/`  
**Format:** UUID-named directories  
**Description:** Background job tracking and state management.

#### Structure
```
jobs/<uuid>/
└── [job state files]
```

---

### 8. Session Metadata

**Directory:** `sessions/`  
**Path:** `/home/my/.claude/sessions/`  
**Format:** JSON + binary key files  
**Files:** `*.json` and `*.key` (4 files)  
**Description:** Session metadata and cryptographic keys for session authentication/encryption.

#### File Pattern
```
sessions/<session-id>.json   # Session metadata
sessions/<session-id>.key    # Cryptographic key
```

---

### 9. Context Files

**Directory:** `context/`  
**Path:** `/home/my/.claude/context/`  
**Format:** Markdown + subdirectories  
**Description:** Context files including codebase standards and session indices.

#### Notable Files
| File                  | Format   | Description                  |
| --------------------- | -------- | ---------------------------- |
| `CODEBASE_STANDARDS.md` | Markdown | Code standards documentation |
| `index.md`              | Markdown | Context index                |

---

### 10. Credentials Store

**File:** `.credentials.json`  
**Path:** `/home/my/.claude/.credentials.json`  
**Format:** JSON (likely encrypted)  
**Size:** ~17 KB  
**Description:** Stored credentials for external service authentication.

---

### 11. Statistics Cache

**File:** `stats-cache.json`  
**Path:** `/home/my/.claude/stats-cache.json`  
**Format:** JSON  
**Size:** ~25 KB  
**Description:** Usage statistics cache with aggregated session metrics.

---

### 12. Daemon Status Files

**Files:** `daemon.lock`, `daemon.status.json`  
**Path:** `/home/my/.claude/`  
**Format:** Plain text (lock), JSON (status)  
**Description:** Daemon process lock file and status metadata.

#### `daemon.lock` Contents
```
<process-id>
```

#### `daemon.status.json` Structure
```json
{
  "pid": 3128088,
  "started": "2026-06-03T16:45:11.233Z",
  "version": "2.1.161"
}
```

---

## Project Log: SugarCraft `.logs/`

**Path:** `/home/sites/sugarcraft/.logs/subtask2.log`

### Format Details

**Format:** ASCII plain text with JSON-like structured entries  
**Size:** ~49 KB, 1141 lines  
**Description:** Task and subagent activity logs with session IDs, tool calls, and message hooks.

#### Sample Entry
```
[2026-08-19T23:58:06.407Z] Plugin initialized: 3 commands ["devcontainer","worktree","workspaces"]
```

#### Log Format Pattern
```
[YYYY-MM-DDTHH:mm:ss.sssZ] [component] message
```

#### Common Log Patterns
| Pattern                        | Description                     |
| ------------------------------ | ------------------------------- |
| `[timestamp] Plugin initialized` | Plugin loading complete         |
| `[timestamp] Tool called`        | Tool invocation with parameters |
| `[timestamp] Session started`    | New session initialization      |
| `[timestamp] Message hook`       | Message processing event        |

---

## Project Log: Caliber Learning

**Path:** `/home/sites/sugarcraft/.caliber/`

### 1. Current Session Tool Events

**File:** `learning/current-session.jsonl`  
**Path:** `/home/sites/sugarcraft/.caliber/learning/current-session.jsonl`  
**Format:** JSONL  
**Size:** 1.3 MB  
**Description:** Tool use events captured via PostToolUse hooks, including file paths, tool inputs, and tool outputs.

#### Sample Entry
```json
{
  "type": "PostToolUse",
  "timestamp": "2026-08-19T23:58:06.407Z",
  "tool": "read",
  "input": {"filePath": "/home/sites/sugarcraft/composer.json"},
  "output": {"content": "..."}
}
```

---

### 2. Finalize Notifications

**File:** `learning/finalize.log`  
**Path:** `/home/sites/sugarcraft/.caliber/learning/finalize.log`  
**Format:** ASCII plain text  
**Size:** 272 KB  
**Description:** npm update notifications and package finalization events.

#### Sample Entry
```
[2026-08-20T00:15:33.001Z] npm update: sugarcraft/candy-core updated to 1.2.0
```

---

### 3. ROI Statistics

**File:** `learning/roi-stats.json`  
**Path:** `/home/sites/sugarcraft/.caliber/learning/roi-stats.json`  
**Format:** JSON  
**Size:** 213 KB  
**Description:** Learning observations, patterns, and gotchas with token waste metrics.

#### Sample Structure
```json
{
  "observations": [
    {
      "pattern": "early-exit-validation",
      "occurrences": 47,
      "tokenWaste": 12400,
      "description": "Validate input at function entry, not after processing"
    }
  ],
  "gotchas": [
    {
      "id": "g001",
      "description": "Always check file existence before reading",
      "prevention": "Use glob/find tools instead of direct read"
    }
  ]
}
```

---

### 4. Error Tracking

**Files:** `learning/last-error.json`, `error-log.md`

#### `last-error.json`
**Path:** `/home/sites/sugarcraft/.caliber/learning/last-error.json`  
**Format:** JSON  
**Size:** 159 B  
**Description:** Last error information with stack trace context.

```json
{
  "timestamp": "2026-08-19T22:30:00.000Z",
  "error": "File not found",
  "path": "/nonexistent/path",
  "context": "read tool invocation"
}
```

#### `error-log.md`
**Path:** `/home/sites/sugarcraft/.caliber/error-log.md`  
**Format:** Markdown  
**Description:** Generation errors with provider info, model, stop reason, and troubleshooting tips.

```markdown
## Error: 2026-08-19T22:30:00Z

**Provider:** anthropic
**Model:** claude-sonnet-4-6
**Stop Reason:** max_tokens

### Troubleshooting
- Consider increasing max_tokens limit
- Check if response was truncated
```

---

### 5. Session State

**File:** `learning/state.json`  
**Path:** `/home/sites/sugarcraft/.caliber/learning/state.json`  
**Format:** JSON  
**Size:** 140 B  
**Description:** Session state metadata.

```json
{
  "sessionId": "abc123",
  "started": "2026-08-19T20:00:00Z",
  "lastActivity": "2026-08-19T23:58:06Z"
}
```

---

### 6. Score History

**File:** `score-history.jsonl`  
**Path:** `/home/sites/sugarcraft/.caliber/score-history.jsonl`  
**Format:** JSONL (19 entries)  
**Description:** Agent score history with timestamps, scores, grades, target agents, and triggers.

#### Sample Entry
```json
{"timestamp":"2026-05-07T20:32:17.698Z","score":94,"grade":"A","targetAgent":["claude","codex"],"trigger":"init"}
```

#### Fields
| Field       | Type              | Description                 |
| ----------- | ----------------- | --------------------------- |
| `timestamp`   | string (ISO 8601) | When the score was recorded |
| `score`       | integer (0-100)   | Numerical score             |
| `grade`       | string (A-F)      | Letter grade                |
| `targetAgent` | array             | Which agents were evaluated |
| `trigger`     | string            | What triggered the scoring  |

---

## Summary Table

| Log File/Directory    | Path                       | Format           | Size                 | Purpose                  |
| --------------------- | -------------------------- | ---------------- | -------------------- | ------------------------ |
| `history.jsonl`         | `~/.claude/`                 | JSONL            | ~1.9 MB              | Command/input history    |
| `daemon.log`            | `~/.claude/`                 | Plain text       | 96 KB                | Daemon activity          |
| `transcripts/*.jsonl`   | `~/.claude/transcripts/`     | JSONL            | Up to 843 KB         | Conversation transcripts |
| `shell-snapshots/*.sh`  | `~/.claude/shell-snapshots/` | Shell script     | Various              | Bash session state       |
| `tasks/`                | `~/.claude/tasks/`           | UUID dirs        | Various              | Task state tracking      |
| `file-history/`         | `~/.claude/file-history/`    | UUID dirs        | Various (60 entries) | File access history      |
| `jobs/`                 | `~/.claude/jobs/`            | UUID dirs        | Various              | Background job tracking  |
| `sessions/*.json`       | `~/.claude/sessions/`        | JSON + binary    | 4 files              | Session metadata         |
| `context/`              | `~/.claude/context/`         | Markdown         | Various              | Context files            |
| `.credentials.json`     | `~/.claude/`                 | JSON (encrypted) | ~17 KB               | Stored credentials       |
| `stats-cache.json`      | `~/.claude/`                 | JSON             | ~25 KB               | Usage statistics         |
| `daemon.lock`           | `~/.claude/`                 | Plain text       | Lock file            | Process lock             |
| `daemon.status.json`    | `~/.claude/`                 | JSON             | Various              | Daemon status            |
| `subtask2.log`          | `.logs/`                     | Plain text       | ~49 KB               | Task/subagent activity   |
| `current-session.jsonl` | `.caliber/learning/`         | JSONL            | 1.3 MB               | Tool use events          |
| `finalize.log`          | `.caliber/learning/`         | Plain text       | 272 KB               | npm update notifications |
| `roi-stats.json`        | `.caliber/learning/`         | JSON             | 213 KB               | Learning observations    |
| `last-error.json`       | `.caliber/learning/`         | JSON             | 159 B                | Last error info          |
| `state.json`            | `.caliber/learning/`         | JSON             | 140 B                | Session state            |
| `score-history.jsonl`   | `.caliber/`                  | JSONL            | 19 entries           | Agent score history      |
| `error-log.md`          | `.caliber/`                  | Markdown         | Various              | Error documentation      |

---

## File Naming Conventions

### UUID-Based Directories
Many log directories use UUIDs for isolation:
```
<uuid> = 8-4-4-4-12 hex characters
Example: e8f38793-4611-431d-a2db-4925d73241dc
```

### Transcript Files
```
ses_YYYYMMDD_HHMMSS_<uuid>.jsonl
Example: ses_20260510_000529_abc123def.jsonl
```

### Shell Snapshots
```
snapshot-bash-YYYYMMDD_HHMMSS_<uuid>.sh
Example: snapshot-bash-20260603_164511_e8f38793.sh
```

---

## Timestamp Formats

| Format       | Example                  | Usage                                 |
| ------------ | ------------------------ | ------------------------------------- |
| ISO 8601     | `2026-08-19T23:58:06.407Z` | Daemon logs, transcripts, tool events |
| Unix ms      | `1773995217691`            | history.jsonl                         |
| Unix seconds | `3128088`                  | Process IDs in some contexts          |

---

## Retention Notes

- **Transcript files** may grow large (843 KB+) and should be rotated
- **history.jsonl** at 1.9 MB / 3563 lines represents a significant session history
- **Caliber learning files** accumulate observations over time (`roi-stats.json` at 213 KB)
- **Daemon logs** use append-only plain text, consider log rotation for production