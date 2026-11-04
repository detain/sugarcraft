<script setup lang="ts">
import { oneLine } from '../lib/format'
import { t } from '../lib/i18n'
import type { QueueEntry } from '../protocol/generated'

/**
 * CW-EN-EU02: `flashKey` is the newest steer-notice key SessionView has
 * seen. Each change remounts the flash node (:key below), restarting the
 * one-shot fade; it rides beside the queue strip, not inside its v-if, so an
 * already-drained queue still shows the steer landing.
 */
defineProps<{ queue: QueueEntry[]; flashKey?: string | null }>()
const emit = defineEmits<{ remove: [queueId: string]; refresh: [] }>()
</script>

<template>
  <p v-if="flashKey" :key="flashKey" class="steer-flash" data-testid="steer-flash" aria-hidden="true">⇥</p>
  <section v-if="queue.length > 0" class="strip">
    <div class="head">
      <span class="count">{{ t('web.composer.queue_count', { n: queue.length }) }}</span>
      <button type="button" class="link" :title="t('web.composer.queue_reload_title')" data-testid="queue-refresh" @click="emit('refresh')">{{ t('web.composer.queue_reload') }}</button>
    </div>
    <ol class="queue" data-testid="queue">
      <li v-for="entry in queue" :key="entry.queueId" data-testid="queue-entry">
        <span class="pos">{{ t('web.composer.queue_pos', { position: entry.position }) }}</span>
        <span class="text">{{ oneLine(entry.text, 160) }}</span>
        <button type="button" class="link" :aria-label="t('web.composer.queue_remove_aria', { position: entry.position })" @click="emit('remove', entry.queueId)">{{ t('web.composer.queue_remove') }}</button>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.steer-flash {
  margin: 0 0 0.25rem;
  text-align: right;
  font-size: 0.875rem;
  color: var(--accent);
  animation: steer-fade 2.4s ease-out forwards;
}
@keyframes steer-fade {
  from { opacity: 1; }
  to { opacity: 0; }
}
.head {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  justify-content: flex-end;
  font-size: 0.75rem;
  color: var(--muted);
}
.count {
  margin-right: auto;
}
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
