<script setup lang="ts">
import { computed, ref } from 'vue'
import type { NoticeItem, SubAgent, ToolItem, TranscriptItem, UserItem } from '../stores/reducer'
import MessageMarkdown from './MessageMarkdown.vue'
import ReasoningFold from './ReasoningFold.vue'
import ToolCard from './ToolCard.vue'
import { t } from '../lib/i18n'

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
  copyState.value === 'done' ? t('web.transcript.copy_copied') : copyState.value === 'failed' ? t('web.transcript.copy_failed') : t('web.transcript.copy_idle'))

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

/**
 * CW-EN-EU02 / LANG REQUEST web.transcript.steered (G5-C): the reducer keeps
 * the steer row's content a locale-free glyph marker — prose is resolved at
 * presentation so a locale switch repaints rows already on screen, and the
 * replayed state never freezes whichever language the fold happened in.
 * m25 (CW-COVER-B F3): the dequeued-removal row follows the same split — the
 * key names the outcome, content carries the server's queue text verbatim
 * (never client-translated), and the prose below is chosen here so switching
 * language repaints removals already on screen.
 */
function noticeText(item: NoticeItem): string {
  if (item.key.startsWith('steered:')) return t('web.transcript.steered')
  // m24: the askResolutionOutcome class rides the key prefix; the stored
  // content is the server's note verbatim (never client-translated).
  if (item.key.startsWith('ask-resolved:unanswered:'))
    return item.content === ''
      ? t('web.transcript.ask_unanswered')
      : t('web.transcript.ask_unanswered_note', { note: item.content })
  if (item.key.startsWith('ask-resolved:answered:'))
    return t('web.transcript.ask_answered_note', { note: item.content })
  if (item.key.startsWith('dequeued:removed:')) {
    return item.content === ''
      ? t('web.transcript.prompt_removed')
      : t('web.transcript.prompt_removed_text', { text: item.content })
  }
  return item.content
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
      <div class="who">{{ t('web.transcript.who_user') }}</div>
      <div class="user-text">{{ item.content }}</div>
      <p class="row-actions">
        <button type="button" data-testid="rewind-here" :title="t('web.transcript.rewind_here_title')" @click="emit('rewind', item)">{{ t('web.transcript.rewind_here') }}</button>
        <button type="button" data-testid="edit-resend" :title="t('web.transcript.edit_resend_title')" @click="emit('editResend', item)">{{ t('web.transcript.edit_resend') }}</button>
      </p>
    </template>
    <template v-else-if="item.kind === 'assistant'">
      <ReasoningFold v-if="item.reasoning" :text="item.reasoning" />
      <MessageMarkdown :text="item.content" />
      <p v-if="secureContext" class="row-actions">
        <button type="button" data-testid="copy-message" :title="t('web.transcript.copy_aria')" @click="copyText(item.content)">{{ copyLabel }}</button>
      </p>
      <p v-if="item.lengthStopped" class="flag">{{ t('web.transcript.flag_output_limit') }}</p>
      <p v-if="item.stepsTruncated" class="flag">{{ t('web.transcript.flag_step_limit') }}</p>
    </template>
    <template v-else-if="item.kind === 'notice'">
      <p class="notice" :class="item.level">{{ noticeText(item) }}</p>
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
