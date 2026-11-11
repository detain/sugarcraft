<script setup lang="ts">
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, nextTick, ref, watch, type ComponentPublicInstance } from 'vue'
import type { SubAgent, ToolItem, TranscriptItem as Item, UserItem } from '../stores/reducer'
import { useTranscriptViewStore } from '../stores/transcriptView'
import TranscriptItem from './TranscriptItem.vue'
import { t } from '../lib/i18n'

/**
 * The transcript, virtualised (Appendix O §7.2): only the rows near the
 * viewport are in the DOM, each measured as it renders, so a session of
 * thousands of rows scrolls like a short one. What is still happening — the
 * streaming reply, the open questions — renders after the list through the
 * `after` slot, inside the same scroller. The view follows the bottom while
 * the reader is there, and stays put once they scroll up.
 */
const props = defineProps<{
  items: Item[]
  subagents?: Record<string, SubAgent>
  tick?: unknown
  sessionId?: string
  /** ISS-058 / MA-29: the store says the server named an older cursor; the top-edge
   *  sentinel only ever pulls while this holds. */
  canLoadOlder?: boolean
  /** A page walk is in flight (drives `aria-busy` and short-circuits the sentinel). */
  loadingOlder?: boolean
  /** Pull one older page; resolves to the server-failure text to surface, or null. */
  fetchOlder?: () => Promise<string | null>
  /**
   * M-21: the pane's snapshot is still in flight (session.phase is
   * 'subscribing'). An empty `items` then carries no information about the
   * session, so the empty claim must not paint — the host passes this
   * through from the store, never from a local guess.
   */
  subscribing?: boolean
  /**
   * M-21: the session pane has a standing load/subscribe failure (the host
   * mirrors `session.error !== null` here). The empty claim would describe
   * an unknown state as an empty transcript; it goes silent and the error
   * row beside it carries the truth alone.
   */
  failed?: boolean
}>()
// C-TUI-3.b: the two checkpoint row actions ride through to the view, which
// owns the transcript order, the confirm guard, and the composer draft.
const emit = defineEmits<{ loadFull: [item: ToolItem]; rewind: [item: UserItem]; editResend: [item: UserItem] }>()

const scroller = ref<HTMLElement | null>(null)
const following = ref(true)
// The two reading switches (C-WEB-4) live in the per-viewer store, so rows
// the virtualiser recycles and remounts pick the same settings up.
const view = useTranscriptViewStore()

const virtualizer = useVirtualizer(computed(() => ({
  count: props.items.length,
  getScrollElement: () => scroller.value,
  estimateSize: () => 64,
  overscan: 8,
  getItemKey: (index: number) => props.items[index]?.key ?? index,
  initialRect: { width: 800, height: 800 },
})))

const rows = computed(() => virtualizer.value.getVirtualItems())
const total = computed(() => virtualizer.value.getTotalSize())

function measure(element: Element | ComponentPublicInstance | null): void {
  if (element instanceof Element) virtualizer.value.measureElement(element)
}

/** Distance from the top (px) at which the walk pulls one more page. */
const OLDER_TRIGGER_PX = 48
/** A page that adds no visible height (all rows hidden or compacted) counts against
 *  this budget; at the limit the walk rests until the reader scrolls away and back. */
const OLDER_STABLE_LIMIT = 3

const olderBusy = ref(false)
const olderProblem = ref<string | null>(null)
let olderArmed = true
let olderStableTries = 0

function onScroll(): void {
  const el = scroller.value
  if (!el) return
  following.value = el.scrollHeight - el.scrollTop - el.clientHeight < 48
  // The top-edge sentinel (MA-29): paging older history is armed once per visit to the
  // edge, and re-armed only when the reader leaves it — nothing ever hammers the socket.
  if (el.scrollTop > OLDER_TRIGGER_PX) {
    olderArmed = true
    olderStableTries = 0
    return
  }
  void tickOlder()
}

/** Pull one page of older rows while the reader sits on the top edge (MA-29: the store's
 *  loadOlder was reachable only from tests — no surface called it). On a page that grew
 *  the list, the distance-from-bottom is restored so the view stays anchored to what the
 *  reader was looking at, instead of jumping them through the new rows; the retry cap
 *  covers the pathological case the store's stuck-page law cannot see from inside one
 *  call (every page present in the transcript, none adding height).
 */
async function tickOlder(): Promise<void> {
  const el = scroller.value
  const fetchOlder = props.fetchOlder
  if (el === null || fetchOlder === undefined) return
  if (!props.canLoadOlder || props.loadingOlder === true || olderBusy.value || !olderArmed) return
  olderArmed = false
  olderBusy.value = true
  olderProblem.value = null
  const heightBefore = el.scrollHeight
  const scrollTopBefore = el.scrollTop
  try {
    olderProblem.value = await fetchOlder()
  } finally {
    olderBusy.value = false
  }
  if (olderProblem.value !== null) return
  await nextTick()
  const node = scroller.value
  if (node === null) return
  if (node.scrollHeight > heightBefore) {
    node.scrollTop = scrollTopBefore + (node.scrollHeight - heightBefore)
    olderStableTries = 0
    return
  }
  olderStableTries += 1
  // The retry re-arms deliberately: each try stands for "the reader is still on the
  // edge and the last page changed nothing visible", the one case the sentinel latch
  // was not meant to suppress.
  olderArmed = true
  if (olderStableTries < OLDER_STABLE_LIMIT) void tickOlder()
}

function toBottom(): void {
  const el = scroller.value
  if (!el) return
  el.scrollTop = el.scrollHeight
}

async function follow(): Promise<void> {
  if (!following.value) return
  await nextTick()
  toBottom()
  // Rows are measured after they render; settle once more on the next frame.
  if (typeof requestAnimationFrame === 'function') requestAnimationFrame(toBottom)
}

watch(() => [props.items.length, props.tick, total.value], () => void follow(), { flush: 'post' })

function jumpToEnd(): void {
  following.value = true
  void follow()
}

/**
 * MA-29 asked for an explicit button as well as the sentinel: the scroll trigger is
 * pointer-only, and keyboard and screen-reader readers deserve the same door. It rides
 * the same path — re-arming the latch makes one pull, guards and anchoring unchanged.
 * Label reuses web.transcript.file_load_more ("load more"); a dedicated "older
 * messages" key is a catalog seam (see PROGRESS.md).
 */
function loadOlderFromButton(): void {
  olderArmed = true
  void tickOlder()
}

defineExpose({ jumpToEnd })
</script>

<template>
  <div ref="scroller" class="scroller" data-testid="transcript" :aria-busy="olderBusy || loadingOlder === true" @scroll.passive="onScroll">
    <p v-if="olderProblem !== null" class="older-problem" role="alert" data-testid="transcript-older-error">{{ olderProblem }}</p>
    <button
      v-if="canLoadOlder"
      type="button"
      class="older-more"
      :disabled="loadingOlder === true || olderBusy"
      data-testid="transcript-load-older"
      @click="loadOlderFromButton"
    >{{ t('web.transcript.file_load_more') }}</button>
    <div class="toolbar" data-testid="transcript-toggles">
      <button type="button" :aria-pressed="view.showThinking" data-testid="toggle-thinking" @click="view.toggleThinking()">{{ t('web.transcript.show_thinking') }}</button>
      <button type="button" :aria-pressed="view.expandTools" data-testid="toggle-tools" @click="view.toggleExpandTools()">{{ t('web.transcript.expand_tools') }}</button>
    </div>
    <!-- CW-UX-09: a blank scroller is not an answer. M-21: which honest row
         paints depends on WHY the list is empty — a snapshot still in
         flight says 'loading' (role=status), and a standing subscribe/load
         failure says nothing here at all (the error row beside it carries
         the truth). Only a settled pane may claim emptiness; the wording
         reuses the agents-view empty catalog string verbatim. -->
    <p v-if="items.length === 0 && subscribing === true" class="empty" role="status" data-testid="transcript-loading">{{ t('web.transcript.loading') }}</p>
    <p v-else-if="items.length === 0 && failed !== true" class="empty" data-testid="transcript-empty">{{ t('web.agents.transcript_empty') }}</p>
    <div class="inner">
      <div class="list" :style="{ height: `${total}px` }">
        <div
          v-for="row in rows"
          :key="String(row.key)"
          :ref="measure"
          class="row"
          :data-index="row.index"
          :style="{ transform: `translateY(${row.start}px)` }"
        >
          <TranscriptItem v-if="items[row.index]" :item="items[row.index]!" :subagents="subagents" :session-id="sessionId" @load-full="(tool) => emit('loadFull', tool)" @rewind="(user) => emit('rewind', user)" @edit-resend="(user) => emit('editResend', user)" />
        </div>
      </div>
      <slot name="after" />
    </div>
    <button v-if="!following" type="button" class="jump" @click="jumpToEnd">{{ t('web.transcript.jump_latest') }}</button>
  </div>
</template>

<style scoped>
.scroller {
  position: relative;
  overflow-y: auto;
  overflow-x: hidden;
  min-height: 0;
  flex: 1;
  overscroll-behavior: contain;
}
.inner {
  padding: 0.75rem 1rem;
  max-width: 60rem;
  margin: 0 auto;
}
.list {
  position: relative;
  width: 100%;
}
.row {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
}
.jump {
  position: sticky;
  bottom: 0.75rem;
  left: 100%;
  margin-right: 1rem;
  width: max-content;
  margin-left: auto;
}
/* CW-UX-10: a sticky bar with no paint is a window — rows scrolled through the
   toggles. It must occlude what passes beneath it. */
.toolbar {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--bg);
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.35rem 1rem 0;
  font-size: 0.8125rem;
}
/* CW-UX-09: the empty-state line (mirrors the agents view's `.empty` shape). */
.empty {
  margin: 0;
  padding: 0.35rem 1rem;
  color: var(--muted);
  font-size: 0.8125rem;
}
/* MA-29: the paging failure line. The text is the server's own describe() output
   and is rendered verbatim (the app's law: failure strings are not re-translated
   client-side), so it only needs a shape, not a translation. */
.older-problem {
  margin: 0;
  padding: 0.35rem 1rem;
  color: var(--error);
  font-size: 0.8125rem;
}
/* MA-29: the explicit affordance at the head of the transcript; the label reuses the
   catalog's "load more" verb (a dedicated load-older key is a protocol seam). */
.older-more {
  align-self: flex-start;
  margin: 0.25rem 1rem 0;
}
</style>
