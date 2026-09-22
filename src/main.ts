import { createPinia } from 'pinia'
import { createApp } from 'vue'
import AppRoot from './AppRoot.vue'
import router from './router'
import './styles/app.scss'

const mount = document.getElementById('pettycash-app')
if (!mount) {
	throw new Error('PettyCash mount element was not found')
}

createApp(AppRoot)
	.use(createPinia())
	.use(router)
	.mount(mount)
