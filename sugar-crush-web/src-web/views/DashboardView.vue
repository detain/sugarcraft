<script setup lang="ts">
const version = __APP_VERSION__
</script>

<template>
  <section class="dashboard">
    <h1>SugarCrush web</h1>
    <p>
      The browser face of <code>sugarcrush serve</code>. This build is the
      scaffold: sessions, approvals and settings arrive with the protocol client.
    </p>
    <p class="version">sugar-crush-web {{ version }}</p>
  </section>
</template>

<style scoped>
.dashboard h1 {
  margin: 0 0 0.75rem;
  font-size: 1.5rem;
}
.dashboard p {
  margin: 0 0 0.75rem;
  line-height: 1.5;
}
.version {
  color: var(--muted);
  font-size: 0.875rem;
}
code {
  font-family: var(--mono);
  background: var(--panel);
  padding: 0.1rem 0.3rem;
  border-radius: 4px;
}
</style>
