import { createRouter, createWebHashHistory } from 'vue-router'

const DashboardView = () => import('../views/DashboardView.vue')

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'dashboard', component: DashboardView },
	],
})
