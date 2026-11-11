<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { diffStats, parseUnifiedDiff } from '../lib/diff'
import { duration, keyArgument } from '../lib/format'
import { stripTerminalSequences } from '../lib/ansi'
import { t } from '../lib/i18n'
import type { SubAgent, ToolItem } from '../stores/reducer'
import { usePanelsStore } from '../stores/agents/panels'
import { useTranscriptViewStore } from '../stores/transcriptView'
import { buildTree, foldRuns, startedBy } from '../stores/agents/tree'
import SubAgentTree from './agents/SubAgentTree.vue'
import CanvasCard from './CanvasCard.vue'
import DiffView from './DiffView.vue'
import MediaCard from './MediaCard.vue'
import ReasoningFold from './ReasoningFold.vue'

const props = defineProps<{ item: ToolItem; subagents?: SubAgent[]; sessionId?: string }>()
const emit = defineEmits<{ loadFull: [item: ToolItem] }>()

// Collapsed when it succeeded, as the TUI shows it; a failure or a refusal opens.
// C-WEB-4: otherwise the card follows the viewer's "expand tool cards" switch
// until they toggle this card themselves — a manual click wins over the
// toolbar, in both directions. Failures and denials still open regardless.
const view = useTranscriptViewStore()
const settledOpenByDefault = props.item.status === 'error' || props.item.status === 'denied'
const toggledByViewer = ref(false)
const open = ref(settledOpenByDefault || view.expandTools)
watch(() => view.expandTools, (expand) => {
  if (toggledByViewer.value) return
  open.value = expand || settledOpenByDefault
})

function toggleCard(): void {
  toggledByViewer.value = true
  open.value = !open.value
}

const summary = computed(() => keyArgument(props.item.arguments) || props.item.description || '')
const stats = computed(() => (props.item.diff ? diffStats(parseUnifiedDiff(props.item.diff)) : null))
const icon = computed(() => {
  switch (props.item.status) {
    case 'running':
      return '◌'
    case 'done':
      return '✔'
    case 'denied':
      return '⊘'
    case 'interrupted':
      return '■'
    default:
      return '✖'
  }
})
const argsJson = computed(() => JSON.stringify(props.item.arguments, null, 2))
// ISS-109: colour codes meant for a terminal are stripped for display only —
// the stored text, which the model re-reads, keeps every byte.
const output = computed(() => (props.item.content === undefined ? null : stripTerminalSequences(props.item.content)))
const reasoning = computed(() => (props.item.reasoning === undefined ? '' : stripTerminalSequences(props.item.reasoning)))
// WEB-4: the live tail is paint — it renders only while the call runs, and
// tool.finished replaces the item, so it clears itself with the old row.
const tail = computed(() =>
  props.item.status === 'running' && props.item.progressTail !== undefined ? stripTerminalSequences(props.item.progressTail) : null,
)
// The runs this call delegated to, live, with what they delegated in turn
// (roadmap O-6c); a row opens the run's agent view in the side panels.
const agentTree = computed(() =>
  props.subagents && props.subagents.length > 0
    ? buildTree(Object.values(foldRuns(props.subagents)), (run) => startedBy(run, props.item))
    : [],
)

function openAgent(id: string): void {
  usePanelsStore().openAgent(id)
}

/**
 * MA-22: `denial.kind` rides the live wire as the server enum's PHP case name
 * (`Refused`, `Unanswered`, `Hook` — TranscriptProjector stamps `->name`) — an
 * identifier, not a word to drop into a human sentence. These nouns are the three
 * kinds' reader-facing names; anything unknown is camel-split instead of echoed
 * verbatim, so a case rename server-side still reads as words client-side.
 *
 * m27: a persisted SNAPSHOT row serialises the enum's backing VALUE instead of the
 * case name (Message.php stamps `->value`), and the camel-split fallback cannot
 * space-punctuate 'Permission denied:' — without these value keys a reloaded
 * refusal labels itself 'permission denied: Permission denied: …'. The three
 * values are frozen server-side (E347/E375), so this is the full pair, not a
 * growing list; an unmapped future spelling still degrades to camel-split words.
 */
const DENIAL_KIND_NOUNS: Record<string, string> = {
  Refused: 'permission',
  Unanswered: 'no answer',
  Hook: 'hook',
  'Permission denied:': 'permission',
  'Permission required:': 'no answer',
  'Hook denied:': 'hook',
}

function humaniseDenialKind(kind: string): string {
  const noun = DENIAL_KIND_NOUNS[kind]
  if (noun !== undefined) return noun
  const spaced = kind.replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase()
  return spaced.trim() === '' ? kind : spaced
}
</script>

<template>
  <article class="tool" :class="item.status" :data-status="item.status" data-testid="tool-card">
    <button type="button" class="head" :aria-expanded="open" @click="toggleCard">
      <span class="icon" aria-hidden="true">{{ icon }}</span>
      <span class="name">{{ item.name }}</span>
      <span class="arg">{{ summary }}</span>
      <span v-if="stats" class="stats"><span class="plus">+{{ stats.added }}</span> <span class="minus">−{{ stats.removed }}</span></span>
      <span class="time numbers">{{ duration(item.durationMs) }}</span>
    </button>
    <ReasoningFold v-if="reasoning !== ''" :text="reasoning" />
    <section v-if="tail !== null" class="progress" data-testid="tool-progress">
      <p class="progress-tag">
        {{ t('web.transcript.tool_tail') }}<template v-if="item.progressTruncated"> {{ t('web.transcript.tool_tail_dropped') }}</template>
      </p>
      <pre class="output">{{ tail }}</pre>
    </section>
    <div v-if="open" class="body">
      <p v-if="item.denial" class="denial">{{ item.denial.reason ? t('web.transcript.refused_reason', { kind: humaniseDenialKind(item.denial.kind), reason: item.denial.reason }) : t('web.transcript.refused', { kind: humaniseDenialKind(item.denial.kind) }) }}</p>
      <details class="args">
        <summary>{{ t('web.transcript.args_summary') }}</summary>
        <pre>{{ argsJson }}</pre>
      </details>
      <DiffView v-if="item.diff" :diff="item.diff" />
      <pre v-if="output !== null && output !== ''" class="output" data-testid="tool-output">{{ output }}</pre>
      <button v-if="item.truncated" type="button" class="more" @click="emit('loadFull', item)">{{ t('web.transcript.tool_load_full') }}</button>
      <!-- Artifact refs ride the event; the bytes are pulled by each card on
           demand (Appendix C-7). Media joins after the output region. -->
      <div v-if="item.media && item.media.length > 0" class="media" data-testid="tool-media">
        <template v-for="artifact in item.media" :key="artifact.artifactId">
          <CanvasCard v-if="artifact.kind === 'html'" :media="artifact" :session-id="sessionId" />
          <MediaCard v-else :media="artifact" :session-id="sessionId" />
        </template>
        <p v-if="item.mediaTruncated" class="media-truncated" data-testid="media-truncated">{{ t('web.media.truncated') }}</p>
      </div>
    </div>
    <SubAgentTree v-if="agentTree.length > 0" class="agents" :nodes="agentTree" compact @open="openAgent" />
  </article>
</template>

<style scoped>
.tool {
  border: 1px solid var(--line);
  border-radius: 6px;
  margin: 0 0 0.5rem;
  background: var(--bg);
}
.head {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  width: 100%;
  padding: 0.4rem 0.6rem;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  min-width: 0;
}
.icon {
  width: 1.2em;
  flex: none;
}
.done .icon {
  color: var(--ok);
}
.error .icon,
.denied .icon {
  color: var(--error);
}
.running .icon {
  color: var(--accent);
  animation: spin 1.2s linear infinite;
  display: inline-block;
}
.name {
  font-weight: 600;
  flex: none;
}
.arg {
  font-family: var(--mono);
  font-size: 0.8125rem;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
  flex: 1;
}
.stats {
  font-family: var(--mono);
  font-size: 0.8125rem;
  flex: none;
}
.plus {
  color: var(--ok);
}
.minus {
  color: var(--error);
}
.time {
  color: var(--muted);
  font-size: 0.8125rem;
  flex: none;
}
.body {
  padding: 0 0.6rem 0.6rem;
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.denial {
  margin: 0;
  color: var(--error);
  font-weight: 600;
}
.denied {
  border-color: var(--error);
}
.args summary {
  cursor: pointer;
  color: var(--muted);
  font-size: 0.8125rem;
}
pre {
  margin: 0;
  padding: 0.5rem;
  max-height: 24rem;
  overflow: auto;
  font-family: var(--mono);
  font-size: 0.8125rem;
  background: var(--panel);
  border-radius: 4px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.more {
  justify-self: start;
}
.media {
  display: grid;
  gap: 0.5rem;
  min-width: 0;
}
.media-truncated {
  margin: 0;
  color: var(--muted);
  font-size: 0.8125rem;
  font-style: italic;
}
.agents {
  margin: 0 0.6rem 0.6rem;
  font-size: 0.875rem;
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
.progress {
  margin: 0 0.6rem 0.4rem;
  min-width: 0;
}
.progress pre {
  margin: 0;
  padding: 0.4rem 0.5rem;
  border-left: 2px solid var(--line);
  background: color-mix(in srgb, var(--bg) 88%, transparent);
  overflow-x: auto;
  white-space: pre-wrap;
  word-break: break-word;
}
.progress-tag {
  margin: 0 0 0.15rem;
  font-size: 0.72rem;
  opacity: 0.65;
}
.numbers {
  font-variant-numeric: tabular-nums;
}
</style>
