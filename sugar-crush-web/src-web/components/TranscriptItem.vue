<script setup lang="ts">
import { computed } from 'vue'
import type { SubAgent, ToolItem, TranscriptItem } from '../stores/reducer'
import MessageMarkdown from './MessageMarkdown.vue'
import ReasoningFold from './ReasoningFold.vue'
import ToolCard from './ToolCard.vue'

const props = defineProps<{ item: TranscriptItem; subagents?: Record<string, SubAgent> }>()
const emit = defineEmits<{ loadFull: [item: ToolItem] }>()

const agents = computed(() => {
  const item = props.item
  if (item.kind !== 'tool' || !props.subagents) return []
  return Object.values(props.subagents).filter((agent) => agent.parentCallId === item.toolCallId)
})
</script>

<template>
  <div class="entry" :class="item.kind" :data-kind="item.kind" data-testid="transcript-item">
    <template v-if="item.kind === 'user'">
      <div class="who">you</div>
      <div class="user-text">{{ item.content }}</div>
    </template>
    <template v-else-if="item.kind === 'assistant'">
      <ReasoningFold v-if="item.reasoning" :text="item.reasoning" />
      <MessageMarkdown :text="item.content" />
      <p v-if="item.lengthStopped" class="flag">The reply hit the output limit.</p>
      <p v-if="item.stepsTruncated" class="flag">The turn reached its step limit.</p>
    </template>
    <template v-else-if="item.kind === 'notice'">
      <p class="notice" :class="item.level">{{ item.content }}</p>
    </template>
    <ToolCard v-else-if="item.kind === 'tool'" :item="item" :subagents="agents" @load-full="(tool) => emit('loadFull', tool)" />
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
</style>
