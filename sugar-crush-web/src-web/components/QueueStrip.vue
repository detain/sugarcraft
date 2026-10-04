<script setup lang="ts">
import { oneLine } from '../lib/format'
import type { QueueEntry } from '../protocol/generated'

defineProps<{ queue: QueueEntry[] }>()
const emit = defineEmits<{ remove: [queueId: string] }>()
</script>

<template>
  <ol v-if="queue.length > 0" class="queue" data-testid="queue">
    <li v-for="entry in queue" :key="entry.queueId" data-testid="queue-entry">
      <span class="pos">queued {{ entry.position }}</span>
      <span class="text">{{ oneLine(entry.text, 160) }}</span>
      <button type="button" class="link" :aria-label="`Remove queued prompt ${entry.position}`" @click="emit('remove', entry.queueId)">remove</button>
    </li>
  </ol>
</template>

<style scoped>
.queue {
  list-style: none;
  margin: 0 0 0.5rem;
  padding: 0;
  display: grid;
  gap: 0.25rem;
}
li {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  font-size: 0.875rem;
  padding: 0.25rem 0.5rem;
  border: 1px dashed var(--line);
  border-radius: 6px;
  min-width: 0;
}
.pos {
  color: var(--muted);
  flex: none;
}
.text {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
