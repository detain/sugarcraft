<script setup lang="ts">
import { useApprovalsStore } from '../../stores/approvals'
import { useLayoutStore } from '../../stores/layout'
import { t } from '../../lib/i18n'

const approvals = useApprovalsStore()
const layout = useLayoutStore()
</script>

<template>
  <button
    type="button"
    class="approvals-button"
    :class="{ waiting: approvals.total > 0 }"
    :aria-expanded="layout.approvalsOpen"
    aria-controls="approvals-drawer"
    :title="approvals.total > 0 ? (approvals.total === 1 ? t('web.approvals.button_one', {n: approvals.total}) : t('web.approvals.button_other', {n: approvals.total})) : t('web.approvals.drawer_title')"
    data-testid="approvals-button"
    @click="layout.toggleApprovals()"
  >
    <span aria-hidden="true">&#9888;</span>
    <span class="count" data-testid="approvals-count">{{ approvals.total }}</span>
    <span class="sr-only">{{ t('web.approvals.sr_open_questions') }}</span>
  </button>
</template>

<style scoped>
.approvals-button {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.15rem 0.55rem;
  color: var(--muted);
  white-space: nowrap;
}
.approvals-button.waiting {
  color: var(--bg);
  background: var(--warn);
  border-color: var(--warn);
  font-weight: 700;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
