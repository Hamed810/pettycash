<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import {
	decideTransaction,
	editTransactionAsManager,
	evidenceUrl,
	getApprovalQueue,
	getApprovalTransaction,
	getCategories,
	getDestinations,
	getVehicles,
	type ApprovalQueueItem,
	type Category,
	type Destination,
	type Transaction,
	type Vehicle,
} from '../services/api'

type Stage = 'MANAGER1'|'MANAGER2'|'ACCOUNTANT'
const stage = ref<Stage>('MANAGER1')
const queue = ref<ApprovalQueueItem[]>([])
const selectedUuid = ref('')
const current = ref<Transaction|null>(null)
const loading = ref(false)
const error = ref('')
const message = ref('')
const comment = ref('')
const categories = ref<Category[]>([])
const destinations = ref<Destination[]>([])
const vehicles = ref<Vehicle[]>([])
const editMode = ref(false)
const editReason = ref('')
const editForm = reactive({ categoryId:0, destinationUuid:'', amount:'', purchaseDateJalali:'', description:'', vendor:'', vehicleUuid:'', odometerKm:'', workerName:'', workerReference:'', workDays:'', workHours:'', workDescription:'' })

const expectedStatus = computed(()=>{

        if(stage.value==='MANAGER1'){
                return 'PENDING_M1'
        }

        if(stage.value==='MANAGER2'){
                return 'PENDING_M2'
        }

        return 'PENDING_ACCOUNTANT'
})
const actionable = computed(()=>current.value?.status===expectedStatus.value)
// When M1 already decided but this row is M2's -- surfaced directly
// on the queue row so M2 sees the disagreement before opening it.
function disagreementLabel(item: ApprovalQueueItem): string | null {
	if (stage.value !== 'MANAGER2' || !item.manager1Decision) return null
	const d = item.manager1Decision
	return `${t('pettycash','M1')}: ${d.decision}`
}

function explainError(e:any):string{return e?.response?.data?.ocs?.data?.message||e?.response?.data?.message||e?.message||t('pettycash','Unexpected error')}
function formatMinor(value:number):string{return new Intl.NumberFormat('en-US').format(value)}

async function loadQueue():Promise<void>{loading.value=true;error.value='';current.value=null;editMode.value=false;try{queue.value=await getApprovalQueue(stage.value);if(!queue.value.some(q=>q.uuid===selectedUuid.value))selectedUuid.value=queue.value[0]?.uuid??'';if(selectedUuid.value)await loadDetail()}catch(e){error.value=explainError(e)}finally{loading.value=false}}
async function loadDetail():Promise<void>{if(!selectedUuid.value){current.value=null;return}try{current.value=await getApprovalTransaction(stage.value,selectedUuid.value);if(current.value.destination?.uuid)vehicles.value=await getVehicles(current.value.destination.uuid);editMode.value=false;comment.value=''}catch(e){current.value=null;error.value=explainError(e)}}
watch(stage,()=>{selectedUuid.value='';loadQueue()})
watch(selectedUuid,()=>{if(selectedUuid.value)loadDetail()})
onMounted(async()=>{try{[categories.value,destinations.value]=await Promise.all([getCategories(),getDestinations()])}catch{}await loadQueue()})

async function decision(action:'APPROVE'|'REJECT'|'RETURN'):Promise<void>{if(!current.value)return;if((action==='REJECT'||action==='RETURN')&&!comment.value.trim()){error.value=t('pettycash','Please enter a reason before rejecting or returning.');return}error.value='';try{await decideTransaction(stage.value,current.value.uuid,action,current.value.version,comment.value);message.value=action==='APPROVE'?t('pettycash','Approved.'):action==='REJECT'?t('pettycash','Rejected/Excluded.'):t('pettycash','Returned for correction.');await loadQueue()}catch(e){error.value=explainError(e)}}

function beginEdit():void{const txn=current.value;if(!txn)return;editMode.value=true;editReason.value='';Object.assign(editForm,{categoryId:txn.category?.id??0,destinationUuid:txn.destination?.uuid??'',amount:txn.amountFormatted.replace(/,/g,''),purchaseDateJalali:txn.purchaseDateJalali,description:txn.description,vendor:txn.vendor??'',vehicleUuid:txn.vehicle?.uuid??'',odometerKm:txn.odometerKm?.toString()??'',workerName:txn.workerName??'',workerReference:txn.workerReference??'',workDays:txn.workDays?.toString()??'',workHours:txn.workMinutes?String(txn.workMinutes/60):'',workDescription:txn.workDescription??''})}
async function saveEdit():Promise<void>{if(!current.value)return;if(!editReason.value.trim()){error.value=t('pettycash','A reason is required for a manager edit.');return}const data={categoryId:Number(editForm.categoryId),destinationId:editForm.destinationUuid,amount:editForm.amount,purchaseDateJalali:editForm.purchaseDateJalali,description:editForm.description,vendor:editForm.vendor||null,vehicleUuid:editForm.vehicleUuid||null,odometerKm:editForm.odometerKm===''?null:Number(editForm.odometerKm),workerName:editForm.workerName||null,workerReference:editForm.workerReference||null,workDays:editForm.workDays===''?null:Number(editForm.workDays),workMinutes:editForm.workHours===''?null:Math.round(Number(editForm.workHours)*60),workDescription:editForm.workDescription||null};try{await editTransactionAsManager(stage.value,current.value.uuid,current.value.version,data,editReason.value);message.value=t('pettycash','Transaction edited. Routing was re-resolved and it re-enters review.');await loadQueue()}catch(e){error.value=explainError(e)}}
</script>

<template>
	<div class="approval-layout">
		<aside class="panel queue-panel">
			<div class="stage-tabs">
			<button 
			:class="{active:stage==='MANAGER1'}"
			@click="stage='MANAGER1'">
			{{ t('pettycash','Manager 1') }}
			</button>

			<button 
			:class="{active:stage==='MANAGER2'}"
			@click="stage='MANAGER2'">
			{{ t('pettycash','Manager 2') }}
			</button>

			<button 
			:class="{active:stage==='ACCOUNTANT'}"
			@click="stage='ACCOUNTANT'">
			{{ t('pettycash','Accountant') }}
			</button>

			</div>
			<header><div><p class="eyebrow">{{ t('pettycash','Waiting for review') }}</p><h2>{{ queue.length }}</h2></div></header>
			<div class="queue-list">
				<button v-for="item in queue" :key="item.uuid" :class="['queue-item',{selected:selectedUuid===item.uuid}]" @click="selectedUuid=item.uuid">
					<strong>{{ item.destination?.name ?? '—' }}</strong>
					<span>{{ item.purchaserId }}</span>
					<b>{{ formatMinor(item.amountMinor) }}</b>
					<span v-if="item.sameManagerFlag" class="flag same-manager">{{ t('pettycash','You are also the destination owner') }}</span>
					<span v-if="disagreementLabel(item)" class="flag disagreement">{{ disagreementLabel(item) }}</span>
				</button>
				<p v-if="!loading&&queue.length===0" class="muted">{{ t('pettycash','Nothing is waiting at this stage.') }}</p>
			</div>
		</aside>

		<section class="review-workspace">
			<div v-if="error" class="error-box">{{ error }}</div><div v-if="message" class="success-box">{{ message }}</div>
			<article v-if="!current" class="panel empty"><h2>{{ t('pettycash','No transaction selected') }}</h2><p>{{ t('pettycash','Choose an item from the queue.') }}</p></article>
			<template v-else>
				<article class="panel transaction-review">
					<div class="status-line">
						<span class="status">{{ current.status }}</span>
						<span>{{ t('pettycash','Revision') }} {{ current.currentRevision }}</span>
						<span v-if="current.sameManagerFlag" class="flag same-manager">{{ t('pettycash','You are also the destination owner for this transaction') }}</span>
					</div>

					<div v-if="!editMode" class="review-grid">
						<div class="facts">
							<h2>{{ current.category?.name }}</h2>
							<span v-if="current.destination" class="dest-badge">{{ current.destination.name }}</span>
							<div class="amount">{{ current.amountFormatted }} {{ current.currency }}</div>
							<dl>
								<div><dt>{{ t('pettycash','Date') }}</dt><dd>{{ current.purchaseDateJalali }}</dd></div>
								<div><dt>{{ t('pettycash','Purchaser') }}</dt><dd>{{ current.purchaserId }}</dd></div>
								<div><dt>{{ t('pettycash','Vendor') }}</dt><dd>{{ current.vendor || '—' }}</dd></div>
								<div v-if="current.vehicle"><dt>{{ t('pettycash','Vehicle') }}</dt><dd>{{ current.vehicle.name }} · {{ current.vehicle.plateNumber }}</dd></div>
								<div v-if="current.odometerKm"><dt>{{ t('pettycash','Kilometer') }}</dt><dd>{{ current.odometerKm }}</dd></div>
								<div v-if="current.workerName"><dt>{{ t('pettycash','Worker') }}</dt><dd>{{ current.workerName }}</dd></div>
							</dl>
							<h3>{{ t('pettycash','Description') }}</h3><p>{{ current.description }}</p>
							<p v-if="current.workDescription" class="work-desc">{{ current.workDescription }}</p>
						</div>
						<div class="evidence"><h3>{{ t('pettycash','Evidence') }}</h3><a v-for="a in current.attachments" :key="a.uuid" :href="evidenceUrl(a.uuid)" target="_blank" rel="noopener"><span>{{ a.type }}</span><strong>{{ a.originalName }}</strong><small>{{ a.mimeType }}</small></a><p v-if="current.attachments.length===0" class="muted">{{ t('pettycash','No attachments') }}</p></div>
					</div>

					<div v-else class="manager-edit">
						<div class="form-grid">
							<label>{{ t('pettycash','Destination') }}<select v-model="editForm.destinationUuid"><option v-for="d in destinations.filter(x=>x.active)" :key="d.uuid" :value="d.uuid">{{ d.name }}</option></select></label>
							<label>{{ t('pettycash','Category') }}<select v-model.number="editForm.categoryId"><option v-for="c in categories.filter(x=>x.active)" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
							<label>{{ t('pettycash','Amount') }}<input v-model="editForm.amount"></label>
							<label>{{ t('pettycash','Jalali date') }}<input v-model="editForm.purchaseDateJalali" dir="ltr"></label>
							<label>{{ t('pettycash','Vendor') }}<input v-model="editForm.vendor"></label>
							<label class="wide">{{ t('pettycash','Description') }}<textarea v-model="editForm.description" rows="2" /></label>
							<label>{{ t('pettycash','Vehicle') }}<select v-model="editForm.vehicleUuid"><option value="">—</option><option v-for="v in vehicles" :key="v.uuid" :value="v.uuid">{{ v.name }} · {{ v.plateNumber }}</option></select></label>
							<label>{{ t('pettycash','Kilometer') }}<input v-model="editForm.odometerKm" type="number"></label>
							<label>{{ t('pettycash','Worker') }}<input v-model="editForm.workerName"></label>
							<label>{{ t('pettycash','Worker reference') }}<input v-model="editForm.workerReference"></label>
							<label>{{ t('pettycash','Work days') }}<input v-model="editForm.workDays" type="number"></label>
							<label>{{ t('pettycash','Work hours') }}<input v-model="editForm.workHours" type="number" step=".25"></label>
							<label class="wide">{{ t('pettycash','Work description') }}<textarea v-model="editForm.workDescription" rows="2" /></label>
							<label class="wide">{{ t('pettycash','Reason for edit') }} *<textarea v-model="editReason" rows="2" /></label>
						</div>
						<div class="edit-warning">{{ t('pettycash','Editing financial data re-resolves routing (Manager 1/Manager 2) and re-enters review from this revision.') }}</div>
						<div class="edit-actions"><NcButton @click="editMode=false">{{ t('pettycash','Cancel') }}</NcButton><NcButton variant="primary" @click="saveEdit">{{ t('pettycash','Save manager edit') }}</NcButton></div>
					</div>

					<div v-if="current.warnings.length" class="warning-box"><div v-for="w in current.warnings" :key="w">{{ w }}</div></div>

					<div class="history-actions">
						<h3>{{ t('pettycash','Decision history') }}</h3>
						<div v-for="(d,i) in current.decisions" :key="i" class="action-row"><strong>{{ d.role }} · {{ d.decision }}</strong><span>{{ d.actorId }}</span><p v-if="d.reason">{{ d.reason }}</p></div>
						<p v-if="current.decisions.length===0" class="muted">{{ t('pettycash','No previous decisions.') }}</p>
					</div>

					<div v-if="actionable&&!editMode" class="decision-panel">
						<textarea v-model="comment" rows="2" :placeholder="t('pettycash','Comment / reason (required for Return or Reject)')" />
						<div class="decision-actions"><NcButton v-if="stage!=='ACCOUNTANT'" @click="beginEdit"> {{ t('pettycash','Edit') }}</NcButton><NcButton @click="decision('RETURN')">{{ t('pettycash','Return') }}</NcButton><NcButton @click="decision('REJECT')">{{ t('pettycash','Reject / Exclude') }}</NcButton><NcButton variant="primary" @click="decision('APPROVE')">{{ t('pettycash','Approve') }}</NcButton></div>
					</div>
				</article>
			</template>
		</section>
	</div>
</template>

<style scoped>
.approval-layout{display:grid;grid-template-columns:320px minmax(0,1fr);gap:20px}.panel{border:1px solid var(--color-border);border-radius:var(--border-radius-large);background:var(--color-main-background);padding:20px}.queue-panel{align-self:start;position:sticky;top:16px}.stage-tabs{display:grid;grid-template-columns:1fr 1fr;background:var(--color-background-hover);border-radius:var(--border-radius);padding:3px;margin-bottom:18px}.stage-tabs button{border:0;border-radius:var(--border-radius);padding:8px;background:transparent;color:var(--color-main-text);cursor:pointer}.stage-tabs button.active{background:var(--color-main-background);font-weight:700}.queue-list{display:grid;gap:7px}.queue-item{text-align:start;display:grid;gap:3px;border:1px solid transparent;border-radius:var(--border-radius);background:transparent;color:var(--color-main-text);padding:11px;cursor:pointer}.queue-item.selected{border-color:var(--color-primary-element);background:var(--color-background-hover)}.queue-item span{color:var(--color-text-maxcontrast)}.flag{display:inline-block;width:fit-content;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700}.flag.disagreement{background:var(--color-warning-hover);color:var(--color-warning-text)}.flag.same-manager{background:var(--color-info-hover,var(--color-background-hover));color:var(--color-main-text)}.review-workspace{display:grid;gap:16px}.status-line{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.status{font-weight:700;background:var(--color-background-hover);border-radius:999px;padding:4px 9px}.dest-badge{display:inline-block;width:fit-content;margin:6px 0;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;background:var(--color-primary-element-light);color:var(--color-primary-element-text)}.review-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(260px,1fr);gap:20px}.facts h2{margin:6px 0 0}.amount{font-size:26px;font-weight:800;margin:5px 0 16px}.facts dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.facts dl>div{padding:9px;background:var(--color-background-hover);border-radius:var(--border-radius)}dt{font-size:11px;color:var(--color-text-maxcontrast)}dd{margin:3px 0 0;font-weight:600}.evidence{border-inline-start:1px solid var(--color-border);padding-inline-start:18px}.evidence a{display:grid;gap:2px;text-decoration:none;color:var(--color-main-text);padding:10px;border:1px solid var(--color-border);border-radius:var(--border-radius);margin-bottom:8px}.evidence a:hover{background:var(--color-background-hover)}.evidence a span,.evidence a small{font-size:11px;color:var(--color-text-maxcontrast)}.warning-box,.edit-warning{margin-top:14px;padding:10px;border-radius:var(--border-radius);background:var(--color-warning-hover);color:var(--color-warning-text)}.history-actions{margin-top:18px;border-top:1px solid var(--color-border);padding-top:14px}.action-row{display:grid;grid-template-columns:1fr auto;gap:4px 10px;padding:8px 0;border-bottom:1px solid var(--color-border)}.action-row span{color:var(--color-text-maxcontrast)}.action-row p{grid-column:1/-1;margin:0}.decision-panel{margin-top:18px;border-top:1px solid var(--color-border);padding-top:16px}.decision-panel textarea{width:100%;box-sizing:border-box}.decision-actions,.edit-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.form-grid label{display:grid;gap:4px;font-size:12px;font-weight:600}.form-grid .wide{grid-column:1/-1}input,select,textarea{box-sizing:border-box;width:100%;min-height:42px;border:1px solid var(--color-border-maxcontrast);border-radius:var(--border-radius);background:var(--color-main-background);color:var(--color-main-text);padding:8px 10px}.eyebrow{margin:0;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--color-text-maxcontrast)}.muted{color:var(--color-text-maxcontrast)}.error-box,.success-box{padding:12px 14px;border-radius:var(--border-radius);white-space:pre-line}.error-box{background:var(--color-error-hover);color:var(--color-error-text)}.success-box{background:var(--color-success-hover);color:var(--color-success-text)}.empty{text-align:center;padding:50px}.work-desc{padding:9px;background:var(--color-background-hover);border-radius:var(--border-radius)}@media(max-width:1050px){.approval-layout{grid-template-columns:1fr}.queue-panel{position:static}.queue-list{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}}@media(max-width:700px){.review-grid,.form-grid,.facts dl{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}.evidence{border-inline-start:0;padding-inline-start:0;border-top:1px solid var(--color-border);padding-top:14px}.decision-actions{flex-wrap:wrap;justify-content:start}}
</style>
