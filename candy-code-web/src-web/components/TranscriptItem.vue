<script setup lang="ts">
import { computed, ref } from 'vue'
import type { SubAgent, ToolItem, TranscriptItem, UserItem } from '../stores/reducer'
import MessageMarkdown from './MessageMarkdown.vue'
import ReasoningFold from './ReasoningFold.vue'
import ToolCard from './ToolCard.vue'

const props = defineProps<{ item: TranscriptItem; subagents?: Record<string, SubAgent>; sessionId?: string }>()
// C-TUI-3.b: user rows ask the view for the two checkpoint actions (the view
// owns the transcript order, the confirm, and the composer); assistant rows
// copy themselves straight to the clipboard.
const emit = defineEmits<{
  loadFull: [item: ToolItem]
  rewind: [item: UserItem]
  editResend: [item: UserItem]
}>()

/**
 * The notifications precedent (`stores/settings.ts`): `navigator.clipboard`
 * exists only in secure contexts, so outside one the copy affordance is
 * hidden rather than clicking into a silent failure. `=== false` names the
 * explicit non-secure answer — an environment that reports nothing (tests,
 * embedders that stub the web API surface) is not an announced refusal.
 */
const secureContext = typeof window === 'undefined' || window.isSecureContext !== false
const copyState = ref<'idle' | 'done' | 'failed'>('idle')
const copyLabel = computed(() =>
  copyState.value === 'done' ? 'copied' : copyState.value === 'failed' ? "couldn't copy" : 'copy')

async function copyText(text: string): Promise<void> {
  if (typeof navigator === 'undefined' || !navigator.clipboard) {
    copyState.value = 'failed'
    return
  }
  try {
    await navigator.clipboard.writeText(text)
    copyState.value = 'done'
  } catch {
    copyState.value = 'failed'
  }
}

// Every run, when this call started one: the card grows the tree below its
// own runs, so a run they delegated to (another call id) hangs under them.
const agents = computed(() => {
  const item = props.item
  if (item.kind !== 'tool' || !props.subagents) return []
  const all = Object.values(props.subagents)
  return item.name === 'Task' || all.some((agent) => agent.parentCallId === item.toolCallId) ? all : []
})
</script>

<template>
  <div class="entry" :class="item.kind" :data-kind="item.kind" data-testid="transcript-item">
    <template v-if="item.kind === 'user'">
      <div class="who">you</div>
      <div class="user-text">{{ item.content }}</div>
      <p class="row-actions">
        <button type="button" data-testid="rewind-here" title="Rewind the conversation to just before this prompt" @click="emit('rewind', item)">Rewind to here</button>
        <button type="button" data-testid="edit-resend" title="Rewind to just before this prompt and put its text in the composer" @click="emit('editResend', item)">Edit &amp; resend</button>
      </p>
    </template>
    <template v-else-if="item.kind === 'assistant'">
      <ReasoningFold v-if="item.reasoning" :text="item.reasoning" />
      <MessageMarkdown :text="item.content" />
      <p v-if="secureContext" class="row-actions">
        <button type="button" data-testid="copy-message" title="Copy this reply" @click="copyText(item.content)">{{ copyLabel }}</button>
      </p>
      <p v-if="item.lengthStopped" class="flag">The reply hit the output limit.</p>
      <p v-if="item.stepsTruncated" class="flag">The turn reached its step limit.</p>
    </template>
    <template v-else-if="item.kind === 'notice'">
      <p class="notice" :class="item.level">{{ item.content }}</p>
    </template>
    <ToolCard v-else-if="item.kind === 'tool'" :item="item" :subagents="agents" :session-id="sessionId" @load-full="(tool) => emit('loadFull', tool)" />
  </div>
</template>

<style scoped>
.entry {
  padding: 0.35rem 0;
  min-width: 0;
}
.who {
  font-size: 0.75rem;
  color: var(--accent);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.user-text {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  padding: 0.4rem 0.6rem;
  background: var(--panel);
  border-radius: 6px;
}
.notice {
  margin: 0;
  font-size: 0.875rem;
  color: var(--muted);
  font-style: italic;
}
.notice.warn {
  color: var(--warn);
}
.notice.error {
  color: var(--error);
}
.flag {
  margin: 0.25rem 0 0;
  font-size: 0.8125rem;
  color: var(--warn);
}
.row-actions {
  display: flex;
  gap: 0.5rem;
  margin: 0.25rem 0 0;
  font-size: 0.75rem;
}
.row-actions button {
  padding: 0.1rem 0.45rem;
  font-size: 0.75rem;
  color: var(--muted);
}
</style>
