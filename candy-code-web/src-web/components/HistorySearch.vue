<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { searchPromptHistory } from '../stores/promptHistory'
import { t } from '../lib/i18n'
import { useDialog } from '../lib/useDialog'

/**
 * C-WEB-4.a: the Ctrl+G history search, the web shape of the TUI's
 * `SugarCraft\Code\Tui\HistorySearch` (plan_comparison TUI-2): the persisted
 * ring newest-first, filtered live by case-insensitive containment (recency
 * IS the rank), ↑/↓ walking the hit list with wrap, a repeated Ctrl+G
 * cycling to the NEXT match — the reverse-i-search idiom.
 *
 * Like the TUI, the overlay never writes the draft while it is open: Enter
 * emits `accept` with the highlighted entry and Escape emits `close` — the
 * parent holds the seed draft it opened on and restores it, and acceptance
 * fills the draft WITHOUT submitting it, so the user edits before sending.
 * With no match, Enter consumes the key and changes nothing.
 */
const props = defineProps<{ entries: string[] }>()
const emit = defineEmits<{ accept: [text: string]; close: [] }>()

const query = ref('')
const selected = ref(0)
const input = ref<HTMLInputElement | null>(null)
const searchEl = ref<HTMLElement | null>(null)
// CW-UX-07: the parent v-ifs this overlay, so mount IS open — the spine
// captures the opener (the composer field) at setup and restores focus when
// the overlay is torn down on accept/close. Escape stays on the input's own
// handler, which also answers arrows and the Ctrl+G cycle.
const dialog = useDialog({ panel: searchEl, open: () => true, handleEscape: false })

const matches = computed(() => searchPromptHistory(props.entries, query.value))

watch(query, () => {
  selected.value = 0
})

function select(step: number): void {
  const count = matches.value.length
  if (count === 0) return
  selected.value = ((selected.value + step) % count + count) % count
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    select(event.key === 'ArrowDown' ? 1 : matches.value.length - 1)
    return
  }
  if (event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'g') {
    // Re-press cycles the highlight to the next match instead of reopening.
    event.preventDefault()
    select(1)
    return
  }
  if (event.key === 'Enter') {
    event.preventDefault()
    const pick = matches.value[selected.value]
    if (pick !== undefined) emit('accept', pick)
    return
  }
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('close')
  }
}

onMounted(async () => {
  await nextTick()
  input.value?.focus()
})
</script>

<template>
  <div class="backdrop" data-testid="history-search-backdrop" @click.self="emit('close')">
    <div ref="searchEl" class="search" role="dialog" aria-modal="true" :aria-label="t('web.composer.history_aria')" data-testid="history-search" @keydown="dialog.onKeydown">
      <input
        ref="input"
        v-model="query"
        type="text"
        :placeholder="t('web.composer.history_placeholder')"
        :aria-label="t('web.composer.history_aria')"
        role="combobox"
        aria-controls="history-search-list"
        :aria-expanded="true"
        data-testid="history-search-input"
        @keydown="onKeydown"
      >
      <ul id="history-search-list" role="listbox" data-testid="history-search-list">
        <li
          v-for="(entry, i) in matches"
          :key="`${entry}-${i}`"
          role="option"
          :aria-selected="i === selected"
          :class="{ on: i === selected }"
          data-testid="history-search-entry"
          @mousemove="selected = i"
          @mousedown.prevent="emit('accept', entry)"
        >
          {{ entry }}
        </li>
        <li v-if="matches.length === 0" class="none" data-testid="history-search-none">{{ t('web.composer.history_none') }}</li>
      </ul>
      <p class="hint">{{ t('web.composer.history_hint') }}</p>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: var(--z-dialog);
  background: rgb(0 0 0 / 0.35);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 12vh 1rem 1rem;
}
.search {
  width: min(36rem, 100%);
  max-height: min(70vh, calc(100dvh - 2rem));
  display: flex;
  flex-direction: column;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 8px;
  box-shadow: 0 0.8rem 2rem rgb(0 0 0 / 0.4);
  overflow: hidden;
}
input {
  font: inherit;
  padding: 0.6rem 0.8rem;
  color: var(--text);
  background: var(--bg);
  border: 0;
  border-bottom: 1px solid var(--line);
  outline: none;
}
/* MA-35: this block deleted the focus ring and nothing replaced it — the global
   tokens.css rule cannot win here (same specificity, injected earlier). */
input:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: -2px;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0.3rem;
  overflow-y: auto;
}
li {
  padding: 0.35rem 0.5rem;
  border-radius: 6px;
  cursor: pointer;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
li.on {
  background: var(--bg);
  outline: 1px solid var(--accent);
}
li.none {
  color: var(--muted);
  cursor: default;
}
.hint {
  margin: 0;
  padding: 0.4rem 0.8rem;
  border-top: 1px solid var(--line);
  font-size: 0.75rem;
  color: var(--muted);
}
/* CW-UX-08: on short viewports the vh gutters eat the screen - collapse them
   to a constant and tighten the cap so the dialog's own scroll stays visible.
   UX-12: short — height axis of the shared scale (App.vue header). */
@media (max-height: 480px) {
  .backdrop {
    padding: 1rem;
  }
  .search {
    max-height: calc(100dvh - 1rem);
  }
}
</style>
