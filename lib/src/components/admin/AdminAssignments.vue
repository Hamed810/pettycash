<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import {
	getDestinationOwner,
	getDestinations,
	getManagerAssignment,
	searchUsers,
	setDestinationOwner,
	setManagerAssignment,
	type Destination,
	type DirectoryUser,
} from '../../services/api'

// v2.0.0: Manager 1 is assigned per purchaser (project-independent);
// Manager 2 is assigned per destination (purchaser-independent). Both
// are chosen from a live Nextcloud user search -- never free text.

const destinations = ref<Destination[]>([])
const loading = ref(false)
const error = ref('')
const message = ref('')

// --- Manager 1 (purchaser -> manager) ---
const purchaserQuery = ref('')
const purchaserResults = ref<DirectoryUser[]>([])
const selectedPurchaser = ref<DirectoryUser | null>(null)
const currentManagerId = ref<string | null>(null)
const managerQuery = ref('')
const managerResults = ref<DirectoryUser[]>([])
const selectedManager = ref<DirectoryUser | null>(null)

// --- Manager 2 (destination -> owner) ---
const selectedDestinationUuid = ref('')
const currentOwnerId = ref<string | null>(null)
const ownerQuery = ref('')
const ownerResults = ref<DirectoryUser[]>([])
const selectedOwner = ref<DirectoryUser | null>(null)

function explainError(e: any): string {
	return e?.response?.data?.ocs?.data?.message || e?.response?.data?.message || e?.message || t('pettycash', 'Unexpected error')
}

async function refresh(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		destinations.value = await getDestinations(true)
	} catch (e) { error.value = explainError(e) }
	finally { loading.value = false }
}

let purchaserSearchTimer: ReturnType<typeof setTimeout> | undefined
watch(purchaserQuery, (q) => {
	clearTimeout(purchaserSearchTimer)
	if (!q.trim()) { purchaserResults.value = []; return }
	purchaserSearchTimer = setTimeout(async () => { try { purchaserResults.value = await searchUsers(q) } catch { purchaserResults.value = [] } }, 250)
})

let managerSearchTimer: ReturnType<typeof setTimeout> | undefined
watch(managerQuery, (q) => {
	clearTimeout(managerSearchTimer)
	if (!q.trim()) { managerResults.value = []; return }
	managerSearchTimer = setTimeout(async () => { try { managerResults.value = await searchUsers(q) } catch { managerResults.value = [] } }, 250)
})

let ownerSearchTimer: ReturnType<typeof setTimeout> | undefined
watch(ownerQuery, (q) => {
	clearTimeout(ownerSearchTimer)
	if (!q.trim()) { ownerResults.value = []; return }
	ownerSearchTimer = setTimeout(async () => { try { ownerResults.value = await searchUsers(q) } catch { ownerResults.value = [] } }, 250)
})

async function pickPurchaser(u: DirectoryUser): Promise<void> {
	selectedPurchaser.value = u
	purchaserQuery.value = ''
	purchaserResults.value = []
	selectedManager.value = null
	try {
		const result = await getManagerAssignment(u.userId)
		currentManagerId.value = result.managerId
	} catch (e) { error.value = explainError(e) }
}

function pickManager(u: DirectoryUser): void {
	selectedManager.value = u
	managerQuery.value = ''
	managerResults.value = []
}

async function saveManagerAssignment(): Promise<void> {
	if (!selectedPurchaser.value || !selectedManager.value) return
	error.value = ''; message.value = ''
	try {
		await setManagerAssignment(selectedPurchaser.value.userId, selectedManager.value.userId)
		currentManagerId.value = selectedManager.value.userId
		selectedManager.value = null
		message.value = t('pettycash', 'Manager 1 assigned.')
	} catch (e) { error.value = explainError(e) }
}

watch(selectedDestinationUuid, async (uuid) => {
	selectedOwner.value = null
	currentOwnerId.value = null
	if (!uuid) return
	try {
		const result = await getDestinationOwner(uuid)
		currentOwnerId.value = result.ownerId
	} catch (e) { error.value = explainError(e) }
})

function pickOwner(u: DirectoryUser): void {
	selectedOwner.value = u
	ownerQuery.value = ''
	ownerResults.value = []
}

async function saveOwnerAssignment(): Promise<void> {
	if (!selectedDestinationUuid.value || !selectedOwner.value) return
	error.value = ''; message.value = ''
	try {
		await setDestinationOwner(selectedDestinationUuid.value, selectedOwner.value.userId)
		currentOwnerId.value = selectedOwner.value.userId
		selectedOwner.value = null
		message.value = t('pettycash', 'Manager 2 (destination owner) assigned.')
	} catch (e) { error.value = explainError(e) }
}

const selectedDestination = computed(() => destinations.value.find(d => d.uuid === selectedDestinationUuid.value) ?? null)

onMounted(refresh)
</script>

<template>
	<section class="assignments">
		<div v-if="error" class="error-box">{{ error }}</div>
		<div v-if="message" class="success-box">{{ message }}</div>

		<article class="panel">
			<header><div><p class="eyebrow">{{ t('pettycash', 'Person-based routing') }}</p><h2>{{ t('pettycash', 'Manager 1 (direct manager)') }}</h2></div></header>
			<p class="muted">{{ t('pettycash', 'Assign each purchaser their direct manager. This is project-independent -- the same manager can be responsible for purchasers across different projects.') }}</p>

			<div class="picker-row">
				<div class="picker">
					<label>{{ t('pettycash', 'Purchaser') }}</label>
					<input v-model="purchaserQuery" :placeholder="t('pettycash', 'Search Nextcloud users…')">
					<ul v-if="purchaserResults.length" class="results">
						<li v-for="u in purchaserResults" :key="u.userId" @click="pickPurchaser(u)">{{ u.displayName }} <small>({{ u.userId }})</small></li>
					</ul>
					<div v-if="selectedPurchaser" class="chosen">{{ selectedPurchaser.displayName }} <button @click="selectedPurchaser=null">×</button></div>
				</div>

				<div v-if="selectedPurchaser" class="current-value">
					<span>{{ t('pettycash', 'Current Manager 1') }}</span>
					<b>{{ currentManagerId || t('pettycash', 'Not assigned') }}</b>
				</div>

				<div v-if="selectedPurchaser" class="picker">
					<label>{{ t('pettycash', 'New Manager 1') }}</label>
					<input v-model="managerQuery" :placeholder="t('pettycash', 'Search Nextcloud users…')">
					<ul v-if="managerResults.length" class="results">
						<li v-for="u in managerResults" :key="u.userId" @click="pickManager(u)">{{ u.displayName }} <small>({{ u.userId }})</small></li>
					</ul>
					<div v-if="selectedManager" class="chosen">{{ selectedManager.displayName }} <button @click="selectedManager=null">×</button></div>
				</div>

				<NcButton v-if="selectedPurchaser && selectedManager" variant="primary" @click="saveManagerAssignment">{{ t('pettycash', 'Save assignment') }}</NcButton>
			</div>
		</article>

		<article class="panel">
			<header><div><p class="eyebrow">{{ t('pettycash', 'Destination-based routing') }}</p><h2>{{ t('pettycash', 'Manager 2 (destination owner)') }}</h2></div></header>
			<p class="muted">{{ t('pettycash', 'Assign each destination (project, office, marketing, etc.) its budget owner. This is purchaser-independent -- whoever posts an expense to this destination routes to the same Manager 2.') }}</p>

			<div class="picker-row">
				<div class="picker">
					<label>{{ t('pettycash', 'Destination') }}</label>
					<select v-model="selectedDestinationUuid">
						<option value="" disabled>{{ t('pettycash', 'Select destination') }}</option>
						<option v-for="d in destinations" :key="d.uuid" :value="d.uuid">{{ d.code }} · {{ d.name }} ({{ d.type }})</option>
					</select>
				</div>

				<div v-if="selectedDestination" class="current-value">
					<span>{{ t('pettycash', 'Current Manager 2') }}</span>
					<b>{{ currentOwnerId || t('pettycash', 'Not assigned') }}</b>
				</div>

				<div v-if="selectedDestination" class="picker">
					<label>{{ t('pettycash', 'New Manager 2') }}</label>
					<input v-model="ownerQuery" :placeholder="t('pettycash', 'Search Nextcloud users…')">
					<ul v-if="ownerResults.length" class="results">
						<li v-for="u in ownerResults" :key="u.userId" @click="pickOwner(u)">{{ u.displayName }} <small>({{ u.userId }})</small></li>
					</ul>
					<div v-if="selectedOwner" class="chosen">{{ selectedOwner.displayName }} <button @click="selectedOwner=null">×</button></div>
				</div>

				<NcButton v-if="selectedDestination && selectedOwner" variant="primary" @click="saveOwnerAssignment">{{ t('pettycash', 'Save assignment') }}</NcButton>
			</div>

			<p class="muted note">{{ t('pettycash', 'A purchaser cannot Close & Submit a Cost List until they have a Manager 1 assigned and every destination used has a Manager 2 owner.') }}</p>
		</article>
	</section>
</template>

<style scoped>
.assignments{display:grid;gap:20px}.panel{border:1px solid var(--color-border);border-radius:var(--border-radius-large);background:var(--color-main-background);padding:22px}.panel header{margin-bottom:8px}.panel h2{margin:3px 0 8px}.eyebrow{margin:0;color:var(--color-text-maxcontrast);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}.muted{color:var(--color-text-maxcontrast)}.picker-row{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-top:14px}.picker{position:relative;min-width:220px;display:grid;gap:5px}.picker label{font-size:12px;font-weight:600}input,select{box-sizing:border-box;width:100%;min-height:42px;border:1px solid var(--color-border-maxcontrast);border-radius:var(--border-radius);padding:8px 10px;background:var(--color-main-background);color:var(--color-main-text)}.results{position:absolute;top:100%;left:0;right:0;z-index:10;margin:4px 0 0;padding:4px;list-style:none;background:var(--color-main-background);border:1px solid var(--color-border);border-radius:var(--border-radius);max-height:220px;overflow:auto}.results li{padding:8px;border-radius:var(--border-radius);cursor:pointer}.results li:hover{background:var(--color-background-hover)}.results li small{color:var(--color-text-maxcontrast)}.chosen{margin-top:4px;padding:8px 10px;border-radius:var(--border-radius);background:var(--color-background-hover);display:flex;justify-content:space-between;align-items:center}.chosen button{border:0;background:transparent;cursor:pointer;font-weight:700}.current-value{display:grid;gap:4px;padding:10px 14px;background:var(--color-background-hover);border-radius:var(--border-radius);min-width:180px}.current-value span{font-size:11px;color:var(--color-text-maxcontrast)}.note{margin-top:14px}.error-box,.success-box{padding:12px 14px;border-radius:var(--border-radius)}.error-box{background:var(--color-error-hover);color:var(--color-error-text)}.success-box{background:var(--color-success-hover);color:var(--color-success-text)}
</style>
