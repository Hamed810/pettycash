<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import AdminMasterData from './components/admin/AdminMasterData.vue'
import CostListsView from './views/CostListsView.vue'
import ApprovalQueueView from './views/ApprovalQueueView.vue'
import { getContext, type AppContext } from './services/api'

type Section = 'dashboard' | 'lists' | 'approvals' | 'reports' | 'admin'
const active = ref<Section>('dashboard')
const context = ref<AppContext | null>(null)
const contextError = ref('')

const isAdmin = computed(() => context.value?.user?.isAdmin === true)
const title = computed(() => ({ dashboard: t('pettycash','Dashboard'), lists: t('pettycash','Cost Lists'), approvals: t('pettycash','Approvals'), reports: t('pettycash','Reports'), admin: t('pettycash','Administration') })[active.value])

onMounted(async () => {
	try { context.value = await getContext() } catch (e: any) { contextError.value = e?.message ?? t('pettycash','Could not load application context.') }
})
</script>

<template>
	<div id="pettycash-app">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :name="t('pettycash','Dashboard')" :active="active === 'dashboard'" @click="active = 'dashboard'" />
				<NcAppNavigationItem :name="t('pettycash','Cost Lists')" :active="active === 'lists'" @click="active = 'lists'" />
				<NcAppNavigationItem :name="t('pettycash','Approvals')" :active="active === 'approvals'" @click="active = 'approvals'" />
				<NcAppNavigationItem :name="t('pettycash','Reports')" :active="active === 'reports'" @click="active = 'reports'" />
				<NcAppNavigationItem v-if="isAdmin" :name="t('pettycash','Administration')" :active="active === 'admin'" @click="active = 'admin'" />
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<main class="pettycash-content">
				<header class="pettycash-header">
					<div><p class="eyebrow">{{ t('pettycash','Project Petty Cash') }}</p><h1>{{ title }}</h1></div>
					<span class="phase-badge">{{ t('pettycash','Phase 4 approval workflow') }}</span>
				</header>

				<div v-if="contextError" class="error-box">{{ contextError }}</div>

				<section v-if="active === 'dashboard'" class="dashboard-grid">
					<article class="hero-card">
						<p class="eyebrow">{{ t('pettycash','Business defaults') }}</p>
						<h2>{{ t('pettycash','Purchaser and manager workflows are connected') }}</h2>
						<p>{{ t('pettycash','Purchasers can submit validated Cost Lists, Manager 1 and Manager 2 can review transactions one by one, and returned items can be corrected without losing financial history.') }}</p>
						<dl class="defaults">
							<div><dt>{{ t('pettycash','Timezone') }}</dt><dd>{{ context?.business.timezone || 'Asia/Tehran' }}</dd></div>
							<div><dt>{{ t('pettycash','Calendar') }}</dt><dd>Jalali / Persian</dd></div>
							<div><dt>{{ t('pettycash','Default currency') }}</dt><dd>{{ context?.business.defaultCurrency || 'IRR' }}</dd></div>
							<div><dt>{{ t('pettycash','OCR languages') }}</dt><dd>Persian + English</dd></div>
						</dl>
					</article>
					<article class="status-card">
						<h3>{{ t('pettycash','Implementation status') }}</h3>
						<ul>
							<li>✓ {{ t('pettycash','Database foundation') }}</li>
							<li>✓ {{ t('pettycash','Currencies and IRR default seed') }}</li>
							<li>✓ {{ t('pettycash','Expense categories with dynamic rules') }}</li>
							<li>✓ {{ t('pettycash','Project and role assignments') }}</li>
							<li>✓ {{ t('pettycash','Vehicle master data') }}</li>
							<li>✓ {{ t('pettycash','Purchaser Cost Lists and transaction entry') }}</li>
							<li>✓ {{ t('pettycash','Manager 1 and Manager 2 approvals') }}</li>
							<li>✓ {{ t('pettycash','Returned transaction correction and approval history') }}</li>
							<li>→ {{ t('pettycash','Next: Accounting reports and exports') }}</li>
						</ul>
					</article>
				</section>

				<CostListsView v-else-if="active === 'lists'" />

				<ApprovalQueueView v-else-if="active === 'approvals'" />

				<AdminMasterData v-else-if="active === 'admin' && isAdmin" />

				<section v-else class="placeholder-card">
					<p class="eyebrow">{{ title }}</p>
					<h2>{{ t('pettycash','This workflow module is scheduled for the next implementation phase.') }}</h2>
					<p>{{ t('pettycash','Purchaser and manager approval workflows are available. Accounting reports and OCR are the next modules.') }}</p>
				</section>
			</main>
		</NcAppContent>
	</div>
</template>

<style scoped>
#pettycash-app{height:100%;display:flex}.pettycash-content{box-sizing:border-box;width:100%;max-width:1240px;margin:0 auto;padding:32px}.pettycash-header{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:28px}.pettycash-header h1{margin:4px 0 0;font-size:32px;line-height:1.15}.eyebrow{margin:0;font-size:13px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--color-text-maxcontrast)}.phase-badge{border-radius:999px;padding:7px 12px;background:var(--color-background-hover);font-size:13px;font-weight:600}.dashboard-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:20px}.hero-card,.status-card,.placeholder-card{border:1px solid var(--color-border);border-radius:var(--border-radius-large);background:var(--color-main-background);padding:24px}.hero-card h2,.placeholder-card h2{font-size:24px;margin:6px 0 10px}.hero-card>p:last-of-type,.placeholder-card>p:last-child{color:var(--color-text-maxcontrast);max-width:760px}.defaults{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:28px 0 0}.defaults>div{padding:16px;border-radius:var(--border-radius-large);background:var(--color-background-hover)}.defaults dt{font-size:12px;color:var(--color-text-maxcontrast)}.defaults dd{margin:5px 0 0;font-weight:700}.status-card h3{margin-top:0}.status-card ul{list-style:none;padding:0;margin:18px 0 0}.status-card li{padding:9px 0;border-bottom:1px solid var(--color-border)}.status-card li:last-child{border-bottom:0}.error-box{padding:12px 14px;margin-bottom:16px;border-radius:var(--border-radius);background:var(--color-error-hover);color:var(--color-error-text)}@media(max-width:900px){.pettycash-content{padding:20px}.dashboard-grid{grid-template-columns:1fr}.defaults{grid-template-columns:1fr}}
</style>
