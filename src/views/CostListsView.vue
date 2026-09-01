<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import {
	createCostList,
	createTransaction,
	deleteAttachment,
	deleteTransaction,
	getCategories,
	getCostList,
	getCostLists,
	getCurrencies,
	getProjects,
	getVehicles,
	submitCostList,
	updateTransaction,
	uploadAttachment,
	type Attachment,
	type Category,
	type CostList,
	type Currency,
	type Project,
	type Transaction,
	type Vehicle,
} from '../services/api'

const lists = ref<CostList[]>([])
const detail = ref<CostList | null>(null)
const projects = ref<Project[]>([])
const currencies = ref<Currency[]>([])
const categories = ref<Category[]>([])
const vehicles = ref<Vehicle[]>([])
const selectedListUuid = ref('')
const loading = ref(false)
const error = ref('')
const message = ref('')
const editing = ref<Transaction | null>(null)
const receiptFile = ref<File | null>(null)
const permitFile = ref<File | null>(null)
const attendanceFile = ref<File | null>(null)
const fileInputKey = ref(0)

function currentJalali(): {year:number;month:number;day:number} {
	try {
		const parts = new Intl.DateTimeFormat('en-US-u-ca-persian', { year:'numeric', month:'numeric', day:'numeric', timeZone:'Asia/Tehran' }).formatToParts(new Date())
		const value = (type:string) => Number(parts.find(p => p.type === type)?.value || 0)
		return { year:value('year'), month:value('month'), day:value('day') }
	} catch { return { year:1405, month:1, day:1 } }
}
const today = currentJalali()
const listForm = reactive({ projectUuid:'', jalaliYear:today.year, jalaliMonth:today.month, currencyId:0 })
const expense = reactive({ categoryId:0, amount:'', purchaseDateJalali:`${today.year}/${String(today.month).padStart(2,'0')}/${String(today.day).padStart(2,'0')}`, description:'', vendor:'', vehicleUuid:'', odometerKm:'', workerName:'', workerReference:'', workDays:'', workHours:'', workDescription:'' })

const activeCategories = computed(() => categories.value.filter(c => c.active))
const chosenCategory = computed(() => categories.value.find(c => c.id === Number(expense.categoryId)) ?? null)
const openList = computed(() => detail.value?.status === 'OPEN')
const returnedStatuses = ['RETURNED_M1','RETURNED_M2']
function canCorrectTxn(txn: Transaction): boolean { return detail.value?.status === 'M1_REVIEW' && returnedStatuses.includes(txn.status) }
const correctionMode = computed(() => editing.value !== null && canCorrectTxn(editing.value))
const canShowExpenseForm = computed(() => openList.value || correctionMode.value)
const monthNames = ['Farvardin','Ordibehesht','Khordad','Tir','Mordad','Shahrivar','Mehr','Aban','Azar','Dey','Bahman','Esfand']

function explainError(e:any):string { return e?.response?.data?.ocs?.data?.message || e?.response?.data?.message || e?.message || t('pettycash','Unexpected error') }
function formatMinor(value:number, currency?: CostList['currency']):string { const d=currency?.decimalPlaces ?? 0; return new Intl.NumberFormat('en-US',{minimumFractionDigits:d,maximumFractionDigits:d}).format(value/(10**d)) + (currency?.code ? ` ${currency.code}`:'') }

async function loadBase():Promise<void>{loading.value=true;error.value='';try{[lists.value,projects.value,currencies.value,categories.value]=await Promise.all([getCostLists(),getProjects(),getCurrencies(),getCategories()]);if(!listForm.projectUuid&&projects.value[0])listForm.projectUuid=projects.value[0].uuid;if(!listForm.currencyId){const p=projects.value.find(x=>x.uuid===listForm.projectUuid);listForm.currencyId=p?.defaultCurrencyId ?? currencies.value.find(c=>c.isDefault)?.id ?? 0}if(!selectedListUuid.value&&lists.value[0])selectedListUuid.value=lists.value[0].uuid;if(expense.categoryId===0&&activeCategories.value[0])expense.categoryId=activeCategories.value[0].id}catch(e){error.value=explainError(e)}finally{loading.value=false}}
async function loadDetail():Promise<void>{if(!selectedListUuid.value){detail.value=null;return}try{detail.value=await getCostList(selectedListUuid.value);if(detail.value?.project?.uuid)vehicles.value=await getVehicles(detail.value.project.uuid);else vehicles.value=[]}catch(e){error.value=explainError(e)}}
watch(selectedListUuid,loadDetail)
watch(()=>listForm.projectUuid,(uuid)=>{const p=projects.value.find(x=>x.uuid===uuid);if(p)listForm.currencyId=p.defaultCurrencyId})
onMounted(loadBase)

async function addList():Promise<void>{error.value='';message.value='';try{const list=await createCostList({...listForm,currencyId:listForm.currencyId||null});await loadBase();selectedListUuid.value=list.uuid;message.value=t('pettycash','Cost List created.')}catch(e){error.value=explainError(e)}}

function resetExpense():void{editing.value=null;Object.assign(expense,{categoryId:activeCategories.value[0]?.id??0,amount:'',purchaseDateJalali:`${today.year}/${String(today.month).padStart(2,'0')}/${String(today.day).padStart(2,'0')}`,description:'',vendor:'',vehicleUuid:'',odometerKm:'',workerName:'',workerReference:'',workDays:'',workHours:'',workDescription:''});receiptFile.value=null;permitFile.value=null;attendanceFile.value=null;fileInputKey.value++}
function editExpense(txn:Transaction):void{editing.value=txn;Object.assign(expense,{categoryId:txn.category?.id??0,amount:txn.amountFormatted.replace(/,/g,''),purchaseDateJalali:txn.purchaseDateJalali,description:txn.description,vendor:txn.vendor??'',vehicleUuid:txn.vehicle?.uuid??'',odometerKm:txn.odometerKm?.toString()??'',workerName:txn.workerName??'',workerReference:txn.workerReference??'',workDays:txn.workDays?.toString()??'',workHours:txn.workMinutes?String(txn.workMinutes/60):'',workDescription:txn.workDescription??''});window.scrollTo({top:0,behavior:'smooth'})}
function fileFromEvent(e:Event,target:'receipt'|'permit'|'attendance'):void{const f=(e.target as HTMLInputElement).files?.[0]??null;if(target==='receipt')receiptFile.value=f;if(target==='permit')permitFile.value=f;if(target==='attendance')attendanceFile.value=f}

function expensePayload():Record<string,unknown>{return{categoryId:Number(expense.categoryId),amount:expense.amount,purchaseDateJalali:expense.purchaseDateJalali,description:expense.description,vendor:expense.vendor||null,vehicleUuid:expense.vehicleUuid||null,odometerKm:expense.odometerKm===''?null:Number(expense.odometerKm),workerName:expense.workerName||null,workerReference:expense.workerReference||null,workDays:expense.workDays===''?null:Number(expense.workDays),workMinutes:expense.workHours===''?null:Math.round(Number(expense.workHours)*60),workDescription:expense.workDescription||null}}
async function saveExpense():Promise<void>{if(!detail.value)return;error.value='';message.value='';try{let txn:Transaction;if(editing.value)txn=await updateTransaction(editing.value.uuid,editing.value.version,expensePayload());else txn=await createTransaction(detail.value.uuid,expensePayload());const uploads:Array<[Attachment['type'],File|null]>=[['RECEIPT',receiptFile.value],['HIRING_PERMIT',permitFile.value],['ATTENDANCE_EVIDENCE',attendanceFile.value]];for(const [type,file]of uploads)if(file)await uploadAttachment(txn.uuid,type,file);await loadDetail();await loadBase();selectedListUuid.value=detail.value?.uuid??selectedListUuid.value;resetExpense();message.value=t('pettycash','Expense saved.')}catch(e){error.value=explainError(e)}}
async function removeTxn(txn:Transaction):Promise<void>{if(!confirm(t('pettycash','Delete this draft expense?')))return;try{await deleteTransaction(txn.uuid);await loadDetail();await loadBase()}catch(e){error.value=explainError(e)}}
async function removeAttachment(a:Attachment):Promise<void>{try{await deleteAttachment(a.uuid);await loadDetail()}catch(e){error.value=explainError(e)}}
async function submit():Promise<void>{if(!detail.value)return;if(!confirm(t('pettycash','Close and submit this Cost List? You will no longer be able to edit it.')))return;error.value='';try{detail.value=await submitCostList(detail.value.uuid,detail.value.version);await loadBase();message.value=t('pettycash','Cost List submitted to Manager 1.')}catch(e){error.value=explainError(e)}}

resetExpense()
</script>

<template>
	<div class="costlists-layout">
		<section class="list-sidebar panel">
			<header><div><p class="eyebrow">{{ t('pettycash','History') }}</p><h2>{{ t('pettycash','Cost Lists') }}</h2></div></header>
			<div class="new-list">
				<select v-model="listForm.projectUuid"><option value="" disabled>{{ t('pettycash','Project') }}</option><option v-for="p in projects" :key="p.uuid" :value="p.uuid">{{ p.code }} · {{ p.name }}</option></select>
				<div class="period"><input v-model.number="listForm.jalaliYear" type="number" min="1300" max="1700"><select v-model.number="listForm.jalaliMonth"><option v-for="(m,i) in monthNames" :key="m" :value="i+1">{{ i+1 }} · {{ m }}</option></select></div>
				<select v-model.number="listForm.currencyId"><option v-for="c in currencies.filter(x=>x.active)" :key="c.id" :value="c.id">{{ c.code }} · {{ c.name }}</option></select>
				<NcButton variant="primary" :disabled="!listForm.projectUuid" @click="addList">{{ t('pettycash','Open new Cost List') }}</NcButton>
			</div>
			<div class="history">
				<button v-for="list in lists" :key="list.uuid" class="history-item" :class="{selected:selectedListUuid===list.uuid}" @click="selectedListUuid=list.uuid">
					<span><strong>{{ list.project?.code }}</strong> · {{ monthNames[list.jalaliMonth-1] }} {{ list.jalaliYear }}</span><small>{{ list.reference || list.status }}</small><b>{{ formatMinor(list.submittedTotal,list.currency) }}</b>
				</button>
				<p v-if="!loading && lists.length===0" class="muted">{{ t('pettycash','No Cost Lists yet.') }}</p>
			</div>
		</section>

		<section class="workspace">
			<div v-if="error" class="error-box">{{ error }}</div><div v-if="message" class="success-box">{{ message }}</div>
			<article v-if="!detail" class="panel empty"><h2>{{ t('pettycash','Select or create a Cost List') }}</h2><p>{{ t('pettycash','An open Cost List collects your daily expenses until you close and submit it.') }}</p></article>
			<template v-else>
				<article class="panel list-summary">
					<div><p class="eyebrow">{{ detail.reference || t('pettycash','Open Cost List') }}</p><h2>{{ detail.project?.name }}</h2><p>{{ monthNames[detail.jalaliMonth-1] }} {{ detail.jalaliYear }} · {{ detail.currency?.code }}</p></div>
					<div class="summary-metrics"><div><span>{{ t('pettycash','Transactions') }}</span><b>{{ detail.transactions?.length || 0 }}</b></div><div><span>{{ t('pettycash','Current total') }}</span><b>{{ formatMinor(detail.submittedTotal,detail.currency) }}</b></div><div><span>{{ t('pettycash','Status') }}</span><b>{{ detail.status }}</b></div></div>
				</article>

				<article v-if="canShowExpenseForm" class="panel expense-form">
					<header><div><p class="eyebrow">{{ editing ? t('pettycash','Edit') : t('pettycash','New expense') }}</p><h2>{{ editing ? t('pettycash','Edit expense') : t('pettycash','Add expense') }}</h2></div><NcButton v-if="editing" @click="resetExpense">{{ t('pettycash','Cancel edit') }}</NcButton></header>
					<div class="form-grid">
						<label>{{ t('pettycash','Expense category') }}<select v-model.number="expense.categoryId"><option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
						<label>{{ t('pettycash','Amount') }}<input v-model="expense.amount" inputmode="decimal" :placeholder="detail.currency?.code || 'IRR'"></label>
						<label>{{ t('pettycash','Jalali date') }}<input v-model="expense.purchaseDateJalali" placeholder="1405/06/10" dir="ltr"></label>
						<label>{{ t('pettycash','Vendor / shop') }}<input v-model="expense.vendor"></label>
						<label class="wide">{{ t('pettycash','Description') }}<textarea v-model="expense.description" rows="2" /></label>
					</div>

					<div v-if="chosenCategory?.vehicleRequired" class="conditional-box"><h3>{{ t('pettycash','Vehicle information') }}</h3><div class="form-grid"><label>{{ t('pettycash','Vehicle') }}<select v-model="expense.vehicleUuid"><option value="">{{ t('pettycash','Select vehicle') }}</option><option v-for="v in vehicles.filter(x=>x.active)" :key="v.uuid" :value="v.uuid">{{ v.name }} · {{ v.plateNumber }}</option></select></label><label>{{ t('pettycash','Current kilometer') }}<input v-model="expense.odometerKm" type="number" min="0"></label></div></div>

					<div v-if="chosenCategory?.workerRequired" class="conditional-box"><h3>{{ t('pettycash','Temporary / daily employee') }}</h3><div class="form-grid"><label>{{ t('pettycash','Worker name') }}<input v-model="expense.workerName"></label><label>{{ t('pettycash','Reference / National ID') }}<input v-model="expense.workerReference"></label><label>{{ t('pettycash','Work days') }}<input v-model="expense.workDays" type="number" min="0"></label><label>{{ t('pettycash','Work hours') }}<input v-model="expense.workHours" type="number" min="0" step="0.25"></label><label class="wide">{{ t('pettycash','Work description') }}<textarea v-model="expense.workDescription" rows="2" /></label></div></div>

					<div class="attachments"><label v-if="chosenCategory?.receiptRequired || !chosenCategory"><span>{{ t('pettycash','Receipt') }}<b v-if="chosenCategory?.receiptRequired"> *</b></span><input :key="`r-${fileInputKey}`" type="file" accept="image/jpeg,image/png,application/pdf" @change="fileFromEvent($event,'receipt')"></label><label v-if="chosenCategory?.permitRequired"><span>{{ t('pettycash','Hiring Permit') }} *</span><input :key="`p-${fileInputKey}`" type="file" accept="image/jpeg,image/png,application/pdf" @change="fileFromEvent($event,'permit')"></label><label v-if="chosenCategory?.attendanceRequired"><span>{{ t('pettycash','Attendance / Fingerprint Evidence') }} *</span><input :key="`a-${fileInputKey}`" type="file" accept="image/jpeg,image/png,application/pdf" @change="fileFromEvent($event,'attendance')"></label></div>
					<p v-if="openList" class="muted">{{ t('pettycash','Required documents are enforced when the Cost List is submitted. You can save a draft and attach documents later.') }}</p><p v-else class="muted">{{ t('pettycash','This transaction was returned for correction. Saving creates a new revision and sends it back to Manager 1.') }}</p>
					<NcButton variant="primary" @click="saveExpense">{{ editing ? t('pettycash','Save changes') : t('pettycash','Save expense') }}</NcButton>
				</article>

				<article class="panel transactions"><header><div><p class="eyebrow">{{ t('pettycash','Expenses') }}</p><h2>{{ t('pettycash','Transactions') }}</h2></div></header>
					<div v-if="detail.transactions?.length" class="txn-list"><div v-for="txn in detail.transactions" :key="txn.uuid" class="txn-card"><div class="txn-main"><div><strong>{{ txn.category?.name }}</strong><span>{{ txn.purchaseDateJalali }} · {{ txn.description }}</span><small v-if="txn.vehicle">{{ txn.vehicle.name }} · {{ txn.vehicle.plateNumber }} · {{ txn.odometerKm }} km</small><small v-if="txn.workerName">{{ txn.workerName }}</small></div><b>{{ txn.amountFormatted }} {{ txn.currency }}</b></div><div v-if="txn.warnings.length" class="warning-box"><div v-for="w in txn.warnings" :key="w">{{ w }}</div></div><div class="attachment-chips"><span v-for="a in txn.attachments" :key="a.uuid">{{ a.type }} · {{ a.originalName }} <button v-if="openList || canCorrectTxn(txn)" @click="removeAttachment(a)">×</button></span></div><div v-if="txn.actions?.length" class="decision-history"><strong>{{ t('pettycash','Review history') }}</strong><div v-for="(a,i) in txn.actions" :key="i" class="decision-row"><span>{{ a.stage }} · {{ a.action }} · {{ a.actorId }}</span><p v-if="a.comment">{{ a.comment }}</p></div></div><div v-if="openList || canCorrectTxn(txn)" class="txn-actions"><NcButton @click="editExpense(txn)">{{ canCorrectTxn(txn) ? t('pettycash','Correct returned expense') : t('pettycash','Edit') }}</NcButton><NcButton v-if="openList && txn.status === 'DRAFT'" @click="removeTxn(txn)">{{ t('pettycash','Delete draft') }}</NcButton></div></div></div>
					<p v-else class="muted">{{ t('pettycash','No expenses in this Cost List yet.') }}</p>
				</article>

				<article v-if="openList" class="panel submit-panel"><div><h2>{{ t('pettycash','Ready for review?') }}</h2><p>{{ t('pettycash','Closing the Cost List validates every category requirement and sends all transactions to Manager 1.') }}</p></div><NcButton variant="primary" :disabled="!detail.transactions?.length" @click="submit">{{ t('pettycash','Close & Submit Cost List') }}</NcButton></article>
			</template>
		</section>
	</div>
</template>

<style scoped>
.costlists-layout{display:grid;grid-template-columns:310px minmax(0,1fr);gap:20px}.panel{border:1px solid var(--color-border);border-radius:var(--border-radius-large);background:var(--color-main-background);padding:20px}.panel header{display:flex;align-items:start;justify-content:space-between;gap:12px}.panel h2,.panel h3{margin:4px 0 10px}.eyebrow{margin:0;color:var(--color-text-maxcontrast);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}.list-sidebar{align-self:start;position:sticky;top:16px}.new-list{display:grid;gap:8px;padding-bottom:16px;border-bottom:1px solid var(--color-border)}.period{display:grid;grid-template-columns:1fr 1.4fr;gap:8px}.history{display:grid;gap:7px;margin-top:12px}.history-item{display:grid;text-align:start;gap:4px;padding:11px;border:1px solid transparent;border-radius:var(--border-radius);background:transparent;color:var(--color-main-text);cursor:pointer}.history-item:hover{background:var(--color-background-hover)}.history-item.selected{border-color:var(--color-primary-element);background:var(--color-background-hover)}.history-item span,.history-item small,.history-item b{overflow:hidden;text-overflow:ellipsis}.history-item small{color:var(--color-text-maxcontrast)}.workspace{display:grid;gap:16px}.list-summary{display:flex;justify-content:space-between;gap:20px}.list-summary p{margin:4px 0;color:var(--color-text-maxcontrast)}.summary-metrics{display:flex;gap:10px;align-items:stretch}.summary-metrics>div{min-width:120px;background:var(--color-background-hover);border-radius:var(--border-radius);padding:10px;display:grid;gap:4px}.summary-metrics span{font-size:11px;color:var(--color-text-maxcontrast)}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.form-grid label,.attachments label{display:grid;gap:5px;font-size:13px;font-weight:600}.form-grid .wide{grid-column:1/-1}input,select,textarea{box-sizing:border-box;width:100%;border:1px solid var(--color-border-maxcontrast);border-radius:var(--border-radius);background:var(--color-main-background);color:var(--color-main-text);padding:8px 10px;min-height:42px}textarea{resize:vertical}.conditional-box{margin-top:16px;padding:15px;border-radius:var(--border-radius-large);background:var(--color-background-hover)}.attachments{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0}.txn-list{display:grid;gap:10px}.txn-card{padding:13px;border:1px solid var(--color-border);border-radius:var(--border-radius-large)}.txn-main{display:flex;justify-content:space-between;gap:16px}.txn-main>div{display:grid;gap:3px}.txn-main span,.txn-main small{color:var(--color-text-maxcontrast)}.attachment-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.attachment-chips span{padding:4px 8px;background:var(--color-background-hover);border-radius:999px;font-size:11px}.attachment-chips button{border:0;background:transparent;cursor:pointer;color:inherit;font-weight:700}.txn-actions{display:flex;gap:6px;margin-top:10px}.decision-history{margin-top:10px;padding-top:10px;border-top:1px solid var(--color-border);display:grid;gap:6px}.decision-history>strong{font-size:12px}.decision-row{padding:7px 9px;border-radius:var(--border-radius);background:var(--color-background-hover);font-size:12px}.decision-row span{color:var(--color-text-maxcontrast)}.decision-row p{margin:4px 0 0;white-space:pre-line}.warning-box{margin-top:10px;padding:8px 10px;border-radius:var(--border-radius);background:var(--color-warning-hover);color:var(--color-warning-text)}.submit-panel{display:flex;justify-content:space-between;align-items:center;gap:20px}.submit-panel p{margin:0;color:var(--color-text-maxcontrast)}.error-box,.success-box{padding:12px 14px;border-radius:var(--border-radius);white-space:pre-line}.error-box{background:var(--color-error-hover);color:var(--color-error-text)}.success-box{background:var(--color-success-hover);color:var(--color-success-text)}.muted{color:var(--color-text-maxcontrast)}.empty{text-align:center;padding:50px 20px}@media(max-width:1050px){.costlists-layout{grid-template-columns:1fr}.list-sidebar{position:static}.history{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}.list-summary{display:grid}.summary-metrics{flex-wrap:wrap}.attachments{grid-template-columns:1fr}}@media(max-width:650px){.form-grid{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}.summary-metrics{display:grid}.submit-panel{display:grid}}
</style>
