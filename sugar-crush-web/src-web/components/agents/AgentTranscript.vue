<script setup lang="ts">
import { computed } from 'vue'
import { oneLine } from '../../lib/format'
import type { TranscriptEntry } from '../../stores/agents/agents'
import MessageMarkdown from '../MessageMarkdown.vue'
import ReasoningFold from '../ReasoningFold.vue'

/**
 * A delegated run's own transcript (`agents.transcript`), in the shapes the
 * main transcript uses: what it was told (and what you sent it), its
 * replies as Markdown, its thinking folded, each tool call with its result
 * paired by call id, and how it ended.
 */
const props = defineProps<{ items: TranscriptEntry[] }>()

interface Row {
  key: string
  kind: 'user' | 'inbox' | 'assistant' | 'thinking' | 'tool' | 'status'
  text: string
  from?: string
  tool?: string
  args?: string
  ok?: boolean
  content?: string
  running?: boolean
}

const rows = computed<Row[]>(() => {
  const out: Row[] = []
  const calls = new Map<string, Row>()
  props.items.forEach((item, index) => {
    const key = `${index}`
    switch (item.t) {
      case 'user':
        out.push({ key, kind: 'user', text: item.text ?? '' })
        break
      case 'inbox':
        out.push({ key, kind: 'inbox', text: item.text ?? '', from: item.from ?? '' })
        break
      case 'assistant':
        if ((item.text ?? '') !== '') out.push({ key, kind: 'assistant', text: item.text ?? '' })
        break
      case 'thinking':
        out.push({ key, kind: 'thinking', text: item.text ?? '' })
        break
      case 'tool_call': {
        const row: Row = { key, kind: 'tool', text: '', tool: item.tool ?? '?', args: oneLine(JSON.stringify(item.args ?? {}), 160), running: true }
        out.push(row)
        if (item.callId) calls.set(item.callId, row)
        break
      }
      case 'tool_result': {
        const open = item.callId ? calls.get(item.callId) : undefined
        const result = { ok: item.ok === true, content: (item.content ?? '') + (item.truncated ? '\n[… truncated]' : ''), running: false }
        if (open) Object.assign(open, result)
        else out.push({ key, kind: 'tool', text: '', tool: item.tool ?? '?', ...result })
        break
      }
      case 'status':
        out.push({ key, kind: 'status', text: `Sub-agent ${item.outcome || item.status || 'finished'}${item.error ? `: ${item.error}` : ''}` })
        break
    }
  })
  return out
})
</script>

<template>
  <ol class="agent-transcript" data-testid="agent-transcript">
    <li v-for="row in rows" :key="row.key" :class="row.kind" :data-kind="row.kind" data-testid="agent-row">
      <template v-if="row.kind === 'user'">
        <div class="who">task</div>
        <div class="plain">{{ row.text }}</div>
      </template>
      <template v-else-if="row.kind === 'inbox'">
        <div class="who">{{ row.from === 'user' ? 'you' : row.from || 'message' }} →</div>
        <div class="plain">{{ row.text }}</div>
      </template>
      <MessageMarkdown v-else-if="row.kind === 'assistant'" :text="row.text" />
      <ReasoningFold v-else-if="row.kind === 'thinking'" :text="row.text" />
      <details v-else-if="row.kind === 'tool'" class="tool" :class="{ failed: row.ok === false && !row.running }">
        <summary>
          <span class="icon" aria-hidden="true">{{ row.running ? '◌' : row.ok ? '✔' : '✖' }}</span>
          <strong>{{ row.tool }}</strong> <span class="args">{{ row.args }}</span>
        </summary>
        <pre v-if="row.content">{{ row.content }}</pre>
      </details>
      <p v-else class="status-line">{{ row.text }}</p>
    </li>
  </ol>
</template>

<style scoped>
.agent-transcript {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.who {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted);
}
.plain {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.inbox .plain {
  color: var(--accent);
}
.tool summary {
  cursor: pointer;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tool .icon {
  display: inline-block;
  width: 1.2em;
  color: var(--ok);
}
.tool.failed .icon {
  color: var(--error);
}
.args {
  font-family: var(--mono);
  font-size: 0.8125rem;
  color: var(--muted);
}
pre {
  margin: 0.3rem 0 0;
  padding: 0.4rem;
  max-height: 16rem;
  overflow: auto;
  font-family: var(--mono);
  font-size: 0.8125rem;
  background: var(--bg);
  border-radius: 4px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.status-line {
  margin: 0;
  color: var(--muted);
  font-style: italic;
}
</style>
