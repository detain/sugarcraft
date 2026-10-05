import { createRouter, createWebHashHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router'
import PaneGrid from './components/grid/PaneGrid.vue'
import DashboardView from './views/DashboardView.vue'
import LoginView from './views/LoginView.vue'
import SessionView from './views/SessionView.vue'
import SettingsView from './components/settings/SettingsView.vue'

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'dashboard', component: DashboardView },
  { path: '/s/:id', name: 'session', component: SessionView, props: true },
  { path: '/grid', name: 'grid', component: PaneGrid },
  { path: '/login', name: 'login', component: LoginView },
  { path: '/settings', name: 'settings', component: SettingsView },
  { path: '/:pathMatch(.*)*', redirect: { name: 'dashboard' } },
]

// Hash history: `sugarcrush serve` serves dist/ as plain static files, so a
// deep link must never reach the server as a path it has no file for. The
// one-time sign-in code also travels in the fragment (`/#code=<code>`); main.ts
// takes it out before the router reads the fragment as a route.
export function createAppRouter(history: RouterHistory = createWebHashHistory()) {
  return createRouter({ history, routes })
}
