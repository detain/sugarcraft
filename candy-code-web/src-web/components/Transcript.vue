<script setup lang="ts">
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, nextTick, ref, watch, type ComponentPublicInstance } from 'vue'
import type { SubAgent, ToolItem, TranscriptItem as Item, UserItem } from '../stores/reducer'
import { useTranscriptViewStore } from '../stores/transcriptView'
import TranscriptItem from './TranscriptItem.vue'

/**
 * The transcript, virtualised (Appendix O §7.2): only the rows near the
 * viewport are in the DOM, each measured as it renders, so a session of
 * thousands of rows scrolls like a short one. What is still happening — the
 * streaming reply, the open questions — renders after the list through the
 * `after` slot, inside the same scroller. The view follows the bottom while
 * the reader is there, and stays put once they scroll up.
 */
const props = defineProps<{ items: Item[]; subagents?: Record<string, SubAgent>; tick?: unknown; sessionId?: string }>()
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

function onScroll(): void {
  const el = scroller.value
  if (!el) return
  following.value = el.scrollHeight - el.scrollTop - el.clientHeight < 48
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

defineExpose({ jumpToEnd })
</script>

<template>
  <div ref="scroller" class="scroller" data-testid="transcript" @scroll.passive="onScroll">
    <div class="toolbar" data-testid="transcript-toggles">
      <button type="button" :aria-pressed="view.showThinking" data-testid="toggle-thinking" @click="view.toggleThinking()">Show thinking</button>
      <button type="button" :aria-pressed="view.expandTools" data-testid="toggle-tools" @click="view.toggleExpandTools()">Expand tool cards</button>
    </div>
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
    <button v-if="!following" type="button" class="jump" @click="jumpToEnd">Jump to latest</button>
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
  float: right;
}
.toolbar {
  position: sticky;
  top: 0;
  z-index: 2;
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.35rem 1rem 0;
  font-size: 0.8125rem;
}
</style>
