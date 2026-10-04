<script setup lang="ts">
import { computed } from 'vue'
import { parseUnifiedDiff } from '../lib/diff'

const props = defineProps<{ diff: string }>()
const lines = computed(() => parseUnifiedDiff(props.diff))
</script>

<template>
  <div class="diff" data-testid="diff" role="table" aria-label="diff">
    <div v-for="(line, i) in lines" :key="i" class="row" :class="line.kind" role="row">
      <span class="no" role="cell">{{ line.oldNo ?? '' }}</span>
      <span class="no" role="cell">{{ line.newNo ?? '' }}</span>
      <span class="sign" role="cell">{{ line.kind === 'add' ? '+' : line.kind === 'del' ? '−' : '' }}</span>
      <span class="text" role="cell">{{ line.text }}</span>
    </div>
  </div>
</template>

<style scoped>
.diff {
  font-family: var(--mono);
  font-size: 0.8125rem;
  overflow-x: auto;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--panel);
}
.row {
  display: grid;
  grid-template-columns: 3.5ch 3.5ch 2ch auto;
  white-space: pre;
  min-width: max-content;
}
.no {
  color: var(--muted);
  text-align: right;
  padding-right: 0.5ch;
  user-select: none;
}
.sign {
  text-align: center;
  user-select: none;
}
.add {
  background: var(--diff-add);
}
.del {
  background: var(--diff-del);
}
.hunk,
.file,
.meta {
  color: var(--muted);
}
.hunk .text,
.file .text,
.meta .text {
  grid-column: 1 / -1;
}
.hunk .no,
.hunk .sign,
.file .no,
.file .sign,
.meta .no,
.meta .sign {
  display: none;
}
</style>
