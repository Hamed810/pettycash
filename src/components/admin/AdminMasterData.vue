<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import {
	createCategory,
	createCurrency,
	createProject,
	createVehicle,
	getCategories,
	getCurrencies,
	getMembers,
	getProjects,
	getVehicles,
	replaceMembers,
	updateCategory,
	updateCurrency,
	updateProject,
	updateVehicle,
	type Category,
	type Currency,
	type Project,
	type ProjectMember,
	type Vehicle,
} from '../../services/api'

const loading = ref(false)
const error = ref('')
const currencies = ref<Currency[]>([])
const categories = ref<Category[]>([])
const projects = ref<Project[]>([])
const vehicles = ref<Vehicle[]>([])
const members = ref<ProjectMember[]>([])
const selectedProjectUuid = ref('')

const currencyForm = reactive({ code: '', name: '', symbol: '', decimalPlaces: 0, isDefault: false })
const categoryForm = reactive({ code: '', name: '', receiptRequired: true, vehicleRequired: false, odometerRequired: false, workerRequired: false, permitRequired: false, attendanceRequired: false })
const projectForm = reactive({ code: '', name: '', description: '', defaultCurrencyId: 0 })
const vehicleForm = reactive({ name: '', plateNumber: '', vehicleType: '', notes: '' })
const memberText = reactive({ PURCHASER: '', MANAGER1: '', MANAGER2: '', ACCOUNTANT: '' })

const selectedProject = computed(() => projects.value.find(p => p.uuid === selectedProjectUuid.value) ?? null)

function explainError(e: any): string {
	return e?.response?.data?.ocs?.data?.message || e?.response?.data?.message || e?.message || t('pettycash', 'Unexpected error')
}

async function refresh(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		[currencies.value, categories.value, projects.value] = await Promise.all([
			getCurrencies(true), getCategories(true), getProjects(true),
		])
		if (!projectForm.defaultCurrencyId) {
			projectForm.defaultCurrencyId = currencies.value.find(c => c.isDefault)?.id ?? currencies.value[0]?.id ?? 0
		}
		if (!selectedProjectUuid.value && projects.value[0]) selectedProjectUuid.value = projects.value[0].uuid
	} catch (e) { error.value = explainError(e) }
	finally { loading.value = false }
}

async function addCurrency(): Promise<void> {
	error.value = ''
	try {
		await createCurrency({ ...currencyForm, symbol: currencyForm.symbol || null, active: true })
		Object.assign(currencyForm, { code: '', name: '', symbol: '', decimalPlaces: 0, isDefault: false })
		await refresh()
	} catch (e) { error.value = explainError(e) }
}

async function makeDefault(currency: Currency): Promise<void> {
	try { await updateCurrency(currency.id, { isDefault: true, active: true }); await refresh() } catch (e) { error.value = explainError(e) }
}

async function toggleCurrency(currency: Currency): Promise<void> {
	try { await updateCurrency(currency.id, { active: !currency.active }); await refresh() } catch (e) { error.value = explainError(e) }
}

async function addCategory(): Promise<void> {
	error.value = ''
	try {
		await createCategory({ ...categoryForm, sortOrder: categories.value.length * 10 + 10, active: true })
		Object.assign(categoryForm, { code: '', name: '', receiptRequired: true, vehicleRequired: false, odometerRequired: false, workerRequired: false, permitRequired: false, attendanceRequired: false })
		await refresh()
	} catch (e) { error.value = explainError(e) }
}

async function toggleCategory(category: Category): Promise<void> {
	try { await updateCategory(category.id, { active: !category.active }); await refresh() } catch (e) { error.value = explainError(e) }
}

async function addProject(): Promise<void> {
	error.value = ''
	try {
		const project = await createProject({ ...projectForm, description: projectForm.description || null, active: true })
		Object.assign(projectForm, { code: '', name: '', description: '', defaultCurrencyId: currencies.value.find(c => c.isDefault)?.id ?? 0 })
		await refresh(); selectedProjectUuid.value = project.uuid
	} catch (e) { error.value = explainError(e) }
}

async function toggleProject(project: Project): Promise<void> {
	try { await updateProject(project.uuid, { active: !project.active }); await refresh() } catch (e) { error.value = explainError(e) }
}

function toRoleText(role: string): string { return members.value.filter(m => m.role === role).map(m => m.userId).join(', ') }
function parseUsers(text: string): string[] { return [...new Set(text.split(',').map(v => v.trim()).filter(Boolean))] }

async function loadProjectDetail(): Promise<void> {
	if (!selectedProjectUuid.value) { vehicles.value = []; members.value = []; return }
	try {
		[members.value, vehicles.value] = await Promise.all([getMembers(selectedProjectUuid.value), getVehicles(selectedProjectUuid.value, true)])
		memberText.PURCHASER = toRoleText('PURCHASER')
		memberText.MANAGER1 = toRoleText('MANAGER1')
		memberText.MANAGER2 = toRoleText('MANAGER2')
		memberText.ACCOUNTANT = toRoleText('ACCOUNTANT')
	} catch (e) { error.value = explainError(e) }
}

async function saveMembers(): Promise<void> {
	if (!selectedProjectUuid.value) return
	const payload: Array<{userId:string; role:string}> = []
	for (const role of ['PURCHASER','MANAGER1','MANAGER2','ACCOUNTANT'] as const) {
		for (const userId of parseUsers(memberText[role])) payload.push({ userId, role })
	}
	try { members.value = await replaceMembers(selectedProjectUuid.value, payload); await loadProjectDetail() } catch (e) { error.value = explainError(e) }
}

async function addVehicle(): Promise<void> {
	if (!selectedProjectUuid.value) return
	try {
		await createVehicle(selectedProjectUuid.value, { ...vehicleForm, vehicleType: vehicleForm.vehicleType || null, notes: vehicleForm.notes || null, active: true })
		Object.assign(vehicleForm, { name: '', plateNumber: '', vehicleType: '', notes: '' })
		await loadProjectDetail()
	} catch (e) { error.value = explainError(e) }
}

async function toggleVehicle(vehicle: Vehicle): Promise<void> {
	try { await updateVehicle(vehicle.uuid, { active: !vehicle.active }); await loadProjectDetail() } catch (e) { error.value = explainError(e) }
}

watch(selectedProjectUuid, loadProjectDetail)
onMounted(refresh)
</script>

<template>
	<section class="master-data">
		<div v-if="error" class="error-box">{{ error }}</div>
		<p v-if="loading" class="muted">{{ t('pettycash', 'Loading master data…') }}</p>

		<article class="panel">
			<header><div><p class="eyebrow">{{ t('pettycash', 'Finance') }}</p><h2>{{ t('pettycash', 'Currencies') }}</h2></div></header>
			<div class="form-row currency-form">
				<input v-model="currencyForm.code" maxlength="8" :placeholder="t('pettycash', 'Code, e.g. GBP')">
				<input v-model="currencyForm.name" :placeholder="t('pettycash', 'Currency name')">
				<input v-model="currencyForm.symbol" :placeholder="t('pettycash', 'Symbol')">
				<input v-model.number="currencyForm.decimalPlaces" type="number" min="0" max="6" :placeholder="t('pettycash', 'Decimals')">
				<NcButton variant="primary" @click="addCurrency">{{ t('pettycash', 'Add currency') }}</NcButton>
			</div>
			<div class="table-wrap"><table><thead><tr><th>{{ t('pettycash','Code') }}</th><th>{{ t('pettycash','Name') }}</th><th>{{ t('pettycash','Decimals') }}</th><th>{{ t('pettycash','Status') }}</th><th></th></tr></thead><tbody>
				<tr v-for="currency in currencies" :key="currency.id"><td><strong>{{ currency.code }}</strong></td><td>{{ currency.name }}</td><td>{{ currency.decimalPlaces }}</td><td>{{ currency.isDefault ? t('pettycash','Default') : (currency.active ? t('pettycash','Active') : t('pettycash','Inactive')) }}</td><td class="actions"><NcButton v-if="!currency.isDefault" @click="makeDefault(currency)">{{ t('pettycash','Make default') }}</NcButton><NcButton v-if="!currency.isDefault" @click="toggleCurrency(currency)">{{ currency.active ? t('pettycash','Disable') : t('pettycash','Enable') }}</NcButton></td></tr>
			</tbody></table></div>
		</article>

		<article class="panel">
			<header><div><p class="eyebrow">{{ t('pettycash', 'Rules') }}</p><h2>{{ t('pettycash', 'Expense categories') }}</h2></div></header>
			<div class="form-row category-head"><input v-model="categoryForm.code" :placeholder="t('pettycash','Code')"><input v-model="categoryForm.name" :placeholder="t('pettycash','Category name')"><NcButton variant="primary" @click="addCategory">{{ t('pettycash','Add category') }}</NcButton></div>
			<div class="rule-grid">
				<label><input v-model="categoryForm.receiptRequired" type="checkbox"> {{ t('pettycash','Receipt') }}</label>
				<label><input v-model="categoryForm.vehicleRequired" type="checkbox"> {{ t('pettycash','Vehicle') }}</label>
				<label><input v-model="categoryForm.odometerRequired" type="checkbox"> {{ t('pettycash','Odometer') }}</label>
				<label><input v-model="categoryForm.workerRequired" type="checkbox"> {{ t('pettycash','Worker info') }}</label>
				<label><input v-model="categoryForm.permitRequired" type="checkbox"> {{ t('pettycash','Hiring permit') }}</label>
				<label><input v-model="categoryForm.attendanceRequired" type="checkbox"> {{ t('pettycash','Attendance evidence') }}</label>
			</div>
			<div class="category-list"><div v-for="category in categories" :key="category.id" class="category-row"><div><strong>{{ category.name }}</strong><span>{{ category.code }}</span></div><div class="chips"><span v-if="category.receiptRequired">Receipt</span><span v-if="category.vehicleRequired">Vehicle</span><span v-if="category.odometerRequired">KM</span><span v-if="category.workerRequired">Worker</span><span v-if="category.permitRequired">Permit</span><span v-if="category.attendanceRequired">Attendance</span></div><NcButton @click="toggleCategory(category)">{{ category.active ? t('pettycash','Disable') : t('pettycash','Enable') }}</NcButton></div></div>
		</article>

		<article class="panel">
			<header><div><p class="eyebrow">{{ t('pettycash', 'Organization') }}</p><h2>{{ t('pettycash', 'Projects') }}</h2></div></header>
			<div class="form-row project-form"><input v-model="projectForm.code" :placeholder="t('pettycash','Project code')"><input v-model="projectForm.name" :placeholder="t('pettycash','Project name')"><select v-model.number="projectForm.defaultCurrencyId"><option v-for="currency in currencies.filter(c => c.active)" :key="currency.id" :value="currency.id">{{ currency.code }}</option></select><input v-model="projectForm.description" :placeholder="t('pettycash','Description')"><NcButton variant="primary" @click="addProject">{{ t('pettycash','Create project') }}</NcButton></div>
			<div class="project-grid"><button v-for="project in projects" :key="project.uuid" class="project-card" :class="{selected: selectedProjectUuid === project.uuid}" @click="selectedProjectUuid = project.uuid"><strong>{{ project.code }}</strong><span>{{ project.name }}</span><small>{{ project.active ? t('pettycash','Active') : t('pettycash','Inactive') }}</small></button></div>
			<div v-if="selectedProject" class="project-detail">
				<div class="detail-title"><div><h3>{{ selectedProject.name }}</h3><p>{{ selectedProject.code }}</p></div><NcButton @click="toggleProject(selectedProject)">{{ selectedProject.active ? t('pettycash','Archive project') : t('pettycash','Activate project') }}</NcButton></div>
				<h4>{{ t('pettycash','Project roles') }}</h4><p class="muted">{{ t('pettycash','Enter Nextcloud user IDs separated by commas.') }}</p>
				<div class="role-grid"><label>{{ t('pettycash','Purchasers') }}<input v-model="memberText.PURCHASER"></label><label>{{ t('pettycash','Manager 1') }}<input v-model="memberText.MANAGER1"></label><label>{{ t('pettycash','Manager 2') }}<input v-model="memberText.MANAGER2"></label><label>{{ t('pettycash','Accountants') }}<input v-model="memberText.ACCOUNTANT"></label></div><NcButton variant="primary" @click="saveMembers">{{ t('pettycash','Save project roles') }}</NcButton>
				<h4>{{ t('pettycash','Vehicles') }}</h4><div class="form-row vehicle-form"><input v-model="vehicleForm.name" :placeholder="t('pettycash','Vehicle name')"><input v-model="vehicleForm.plateNumber" :placeholder="t('pettycash','Plate number')"><input v-model="vehicleForm.vehicleType" :placeholder="t('pettycash','Type')"><NcButton variant="primary" @click="addVehicle">{{ t('pettycash','Add vehicle') }}</NcButton></div>
				<div class="vehicle-list"><div v-for="vehicle in vehicles" :key="vehicle.uuid" class="vehicle-row"><div><strong>{{ vehicle.name }}</strong><span>{{ vehicle.plateNumber }}<template v-if="vehicle.vehicleType"> · {{ vehicle.vehicleType }}</template></span></div><NcButton @click="toggleVehicle(vehicle)">{{ vehicle.active ? t('pettycash','Disable') : t('pettycash','Enable') }}</NcButton></div></div>
			</div>
		</article>
	</section>
</template>

<style scoped>
.master-data{display:grid;gap:20px}.panel{border:1px solid var(--color-border);border-radius:var(--border-radius-large);background:var(--color-main-background);padding:22px}.panel header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.panel h2,.panel h3,.panel h4{margin:3px 0 8px}.eyebrow{margin:0;color:var(--color-text-maxcontrast);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}.form-row{display:grid;gap:10px;align-items:center;margin-bottom:16px}.currency-form{grid-template-columns:120px 1.5fr 100px 100px auto}.category-head{grid-template-columns:180px 1fr auto}.project-form{grid-template-columns:150px 1fr 120px 1.2fr auto}.vehicle-form{grid-template-columns:1fr 180px 180px auto}input,select{box-sizing:border-box;width:100%;min-height:42px;border:1px solid var(--color-border-maxcontrast);border-radius:var(--border-radius);padding:8px 10px;background:var(--color-main-background);color:var(--color-main-text)}.rule-grid,.role-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:10px 0 16px}.rule-grid label{padding:10px;border:1px solid var(--color-border);border-radius:var(--border-radius)}.role-grid label{display:grid;gap:5px;font-weight:600}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:start;padding:10px;border-bottom:1px solid var(--color-border)}th{font-size:12px;color:var(--color-text-maxcontrast)}.actions{display:flex;gap:6px;justify-content:flex-end}.category-list,.vehicle-list{display:grid;gap:8px}.category-row,.vehicle-row{display:grid;grid-template-columns:minmax(200px,1fr) 2fr auto;align-items:center;gap:12px;border-top:1px solid var(--color-border);padding:10px 0}.category-row>div:first-child,.vehicle-row>div:first-child{display:grid}.category-row span,.vehicle-row span{color:var(--color-text-maxcontrast);font-size:13px}.chips{display:flex;gap:5px;flex-wrap:wrap}.chips span{padding:3px 7px;border-radius:999px;background:var(--color-background-hover);font-size:11px}.project-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:14px 0}.project-card{text-align:start;border:1px solid var(--color-border);border-radius:var(--border-radius-large);padding:14px;background:var(--color-main-background);color:var(--color-main-text);cursor:pointer;display:grid;gap:4px}.project-card.selected{border-color:var(--color-primary-element);box-shadow:inset 0 0 0 1px var(--color-primary-element)}.project-card small,.muted{color:var(--color-text-maxcontrast)}.project-detail{margin-top:18px;padding-top:18px;border-top:1px solid var(--color-border)}.detail-title{display:flex;justify-content:space-between;gap:16px;align-items:start}.detail-title p{margin:0;color:var(--color-text-maxcontrast)}.error-box{padding:12px 14px;border-radius:var(--border-radius);background:var(--color-error-hover);color:var(--color-error-text);font-weight:600}@media(max-width:1000px){.currency-form,.project-form,.vehicle-form,.category-head{grid-template-columns:1fr 1fr}.rule-grid,.role-grid{grid-template-columns:1fr 1fr}.category-row{grid-template-columns:1fr}.actions{justify-content:start}}@media(max-width:650px){.currency-form,.project-form,.vehicle-form,.category-head,.rule-grid,.role-grid{grid-template-columns:1fr}}
</style>
