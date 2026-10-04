import { createRouter, createWebHashHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router'
import DashboardView from './views/DashboardView.vue'

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'dashboard', component: DashboardView },
  { path: '/:pathMatch(.*)*', redirect: { name: 'dashboard' } },
]

// Hash history: `sugarcrush serve` serves dist/ as plain static files, so a
// deep link must never reach the server as a path it has no file for. The
// one-time login code also travels in the fragment (`/#login=<code>`), which
// LoginView will read before handing the fragment back to the router.
export function createAppRouter(history: RouterHistory = createWebHashHistory()) {
  return createRouter({ history, routes })
}
