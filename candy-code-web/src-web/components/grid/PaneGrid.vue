<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import SessionTile from './SessionTile.vue'
import { sessionLabel } from '../approvals/attention'
import { useConnectionStore } from '../../stores/connection'
import { MAX_TILES, useLayoutStore } from '../../stores/layout'
import { describe } from '../../stores/session'
import { useNewSessionStore } from '../../stores/newSession'
import { useSessionsStore } from '../../stores/sessions'

/**
 * The tiled view (Appendix O §7.4): the open tabs as up to 3×3 tiles, every
 * one live. The focused tile streams in full; the others are narrated by the
 * server — a tail every 2 s instead of a delta stream — and catch up at once
 * when focused. The last cell opens another session, or starts one.
 */
const layout = useLayoutStore()
const sessions = useSessionsStore()
const connection = useConnectionStore()
const newSession = useNewSessionStore()
const router = useRouter()
const picked = ref('')
const error = ref<string | null>(null)
const creating = ref(false)

const front = computed(() => layout.focused !== null && layout.tiles.includes(layout.focused) ? layout.focused : layout.tiles[0] ?? null)
const canAdd = computed(() => layout.tiles.length < MAX_TILES)
const candidates = computed(() => sessions.items.filter((summary) => !layout.tiles.includes(summary.id)))
const style = computed(() => ({ '--columns': String(layout.columns) }))

onMounted(() => layout.setMode('grid'))

function add(): void {
  if (picked.value === '') return
  layout.focus(picked.value)
  picked.value = ''
}

async function create(): Promise<void> {
  creating.value = true
  error.value = null
  try {
    const summary = await newSession.start()
    if (summary) layout.focus(summary.id)
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    creating.value = false
  }
}

function maximize(id: string): void {
  layout.focus(id)
  layout.setMode('tabs')
  void router.push({ name: 'session', params: { id } })
}
</script>

<template>
  <section class="grid" :style="style" data-testid="pane-grid" :data-columns="layout.columns">
    <SessionTile
      v-for="id in layout.tiles"
      :id="id"
      :key="id"
      :focused="id === front"
      @focus="layout.focus(id)"
      @maximize="maximize(id)"
      @close="layout.closeTab(id)"
    />
    <div v-if="canAdd" class="add" data-testid="grid-add">
      <p v-if="layout.tiles.length === 0" class="hint">Watch several sessions at once: open them here.</p>
      <form class="pick" @submit.prevent="add">
        <select v-model="picked" aria-label="Session to open in the grid" data-testid="grid-pick">
          <option value="" disabled>open a session…</option>
          <option v-for="summary in candidates" :key="summary.id" :value="summary.id">{{ sessionLabel(summary, summary.id) }}</option>
        </select>
        <button type="submit" :disabled="picked === ''" data-testid="grid-open">Open</button>
      </form>
      <button type="button" class="primary" :disabled="creating || !connection.connected" data-testid="grid-new" @click="create">+ New session</button>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
    </div>
  </section>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: repeat(var(--columns, 2), minmax(0, 1fr));
  grid-auto-rows: minmax(14rem, 1fr);
  gap: 0.6rem;
  padding: 0.6rem;
  min-height: 0;
  overflow-y: auto;
}
.add {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  gap: 0.6rem;
  padding: 1rem;
  border: 1px dashed var(--line);
  border-radius: 8px;
  min-width: 0;
}
.pick {
  display: flex;
  gap: 0.4rem;
  max-width: 100%;
}
select {
  font: inherit;
  font-size: 0.875rem;
  min-width: 0;
  max-width: 16rem;
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 0.25rem 0.4rem;
}
.hint {
  margin: 0;
  color: var(--muted);
  text-align: center;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
@media (max-width: 760px) {
  .grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
