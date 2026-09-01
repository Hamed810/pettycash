import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

export type Currency = { id: number; code: string; name: string; symbol: string | null; decimalPlaces: number; isDefault: boolean; active: boolean }
export type Category = { id: number; code: string; name: string; description: string | null; receiptRequired: boolean; vehicleRequired: boolean; odometerRequired: boolean; workerRequired: boolean; permitRequired: boolean; attendanceRequired: boolean; active: boolean; sortOrder: number }
export type Project = { id: number; uuid: string; code: string; name: string; description: string | null; defaultCurrencyId: number; active: boolean; createdBy: string }
export type ProjectMember = { id: number; projectId: number; userId: string; role: 'PURCHASER'|'MANAGER1'|'MANAGER2'|'ACCOUNTANT'; active: boolean }
export type Vehicle = { id: number; uuid: string; projectId: number; name: string; plateNumber: string; vehicleType: string | null; notes: string | null; active: boolean }
export type AppContext = { app: { id: string; version: string }; user: { id: string; displayName: string; isAdmin: boolean }; business: { timezone: string; calendar: string; defaultCurrency: string }; ocr: { enabled: boolean; primaryLanguage: string; secondaryLanguage: string }; phase: number }

function url(path: string): string { return generateOcsUrl(`/apps/pettycash${path}`) }
function unwrap<T>(response: { data: { ocs: { data: T } } }): T { return response.data.ocs.data }

export async function getContext(): Promise<AppContext> { return unwrap(await axios.get(url('/api/v1/context'))) }
export async function getCurrencies(includeInactive = false): Promise<Currency[]> { return unwrap<{items: Currency[]}>(await axios.get(url('/api/v1/currencies'), { params: { includeInactive } })).items }
export async function createCurrency(data: Record<string, unknown>): Promise<Currency> { return unwrap(await axios.post(url('/api/v1/currencies'), data)) }
export async function updateCurrency(id: number, data: Record<string, unknown>): Promise<Currency> { return unwrap(await axios.patch(url(`/api/v1/currencies/${id}`), data)) }
export async function getCategories(includeInactive = false): Promise<Category[]> { return unwrap<{items: Category[]}>(await axios.get(url('/api/v1/categories'), { params: { includeInactive } })).items }
export async function createCategory(data: Record<string, unknown>): Promise<Category> { return unwrap(await axios.post(url('/api/v1/categories'), data)) }
export async function updateCategory(id: number, data: Record<string, unknown>): Promise<Category> { return unwrap(await axios.patch(url(`/api/v1/categories/${id}`), data)) }
export async function getProjects(includeInactive = false): Promise<Project[]> { return unwrap<{items: Project[]}>(await axios.get(url('/api/v1/projects'), { params: { includeInactive } })).items }
export async function createProject(data: Record<string, unknown>): Promise<Project> { return unwrap(await axios.post(url('/api/v1/projects'), data)) }
export async function updateProject(uuid: string, data: Record<string, unknown>): Promise<Project> { return unwrap(await axios.patch(url(`/api/v1/projects/${uuid}`), data)) }
export async function getMembers(uuid: string): Promise<ProjectMember[]> { return unwrap<{items: ProjectMember[]}>(await axios.get(url(`/api/v1/projects/${uuid}/members`))).items }
export async function replaceMembers(uuid: string, members: Array<{userId:string; role:string}>): Promise<ProjectMember[]> { return unwrap<{items: ProjectMember[]}>(await axios.put(url(`/api/v1/projects/${uuid}/members`), { members })).items }
export async function getVehicles(projectUuid: string, includeInactive = false): Promise<Vehicle[]> { return unwrap<{items: Vehicle[]}>(await axios.get(url(`/api/v1/projects/${projectUuid}/vehicles`), { params: { includeInactive } })).items }
export async function createVehicle(projectUuid: string, data: Record<string, unknown>): Promise<Vehicle> { return unwrap(await axios.post(url(`/api/v1/projects/${projectUuid}/vehicles`), data)) }
export async function updateVehicle(uuid: string, data: Record<string, unknown>): Promise<Vehicle> { return unwrap(await axios.patch(url(`/api/v1/vehicles/${uuid}`), data)) }

export type Attachment = { id: number; uuid: string; type: 'RECEIPT'|'HIRING_PERMIT'|'ATTENDANCE_EVIDENCE'|'OTHER'; originalName: string; mimeType: string; fileSize: number; sha256: string; sensitive: boolean; createdAt: number }
export type Transaction = { id: number; uuid: string; listId: number; category: {id:number;code:string;name:string}|null; currency: string; amountMinor: number; amountFormatted: string; purchaseDate: string; purchaseDateJalali: string; description: string; vendor: string|null; vehicle: {id:number;uuid:string;name:string;plateNumber:string}|null; odometerKm: number|null; workerName: string|null; workerReference: string|null; workDays: number|null; workMinutes: number|null; workDescription: string|null; status: string; currentRevision: number; version: number; attachments: Attachment[]; actions: Array<{stage:string;action:string;actorId:string;comment:string|null;createdAt:number;revisionId:number|null}>; warnings: string[] }
export type CostList = { id: number; uuid: string; reference: string|null; project: {id:number;uuid:string;code:string;name:string}|null; purchaserId: string; currency: {id:number;code:string;name:string;symbol:string|null;decimalPlaces:number}|null; jalaliYear: number; jalaliMonth: number; status: string; submittedTotal: number; manager1Total: number; finalTotal: number; createdAt: number; submittedAt: number|null; version: number; transactions?: Transaction[] }

export async function getCostLists(): Promise<CostList[]> { return unwrap<{items: CostList[]}>(await axios.get(url('/api/v1/lists'))).items }
export async function getCostList(uuid: string): Promise<CostList> { return unwrap(await axios.get(url(`/api/v1/lists/${uuid}`))) }
export async function createCostList(data: {projectUuid:string; jalaliYear:number; jalaliMonth:number; currencyId?:number|null}): Promise<CostList> { return unwrap(await axios.post(url('/api/v1/lists'), data)) }
export async function submitCostList(uuid: string, version: number): Promise<CostList> { return unwrap(await axios.post(url(`/api/v1/lists/${uuid}/submit`), { version })) }
export async function createTransaction(listUuid: string, data: Record<string, unknown>): Promise<Transaction> { return unwrap(await axios.post(url(`/api/v1/lists/${listUuid}/transactions`), { data })) }
export async function updateTransaction(uuid: string, version: number, data: Record<string, unknown>): Promise<Transaction> { return unwrap(await axios.patch(url(`/api/v1/transactions/${uuid}`), { version, data })) }
export async function deleteTransaction(uuid: string): Promise<void> { await axios.delete(url(`/api/v1/transactions/${uuid}`)) }
export async function uploadAttachment(txnUuid: string, type: Attachment['type'], file: File): Promise<Attachment> { const form = new FormData(); form.append('type', type); form.append('file', file); return unwrap(await axios.post(url(`/api/v1/transactions/${txnUuid}/attachments`), form)) }
export async function deleteAttachment(uuid: string): Promise<void> { await axios.delete(url(`/api/v1/attachments/${uuid}`)) }


export type ApprovalQueueItem = { uuid:string; reference:string|null; project:{uuid:string;code:string;name:string}|null; purchaserId:string; jalaliYear:number; jalaliMonth:number; status:string; submittedTotal:number; manager1Total:number; finalTotal:number; transactionCount:number; submittedAt:number|null }
export async function getApprovalQueue(stage: 'MANAGER1'|'MANAGER2'): Promise<ApprovalQueueItem[]> { return unwrap<{items:ApprovalQueueItem[]}>(await axios.get(url(`/api/v1/approvals/${stage}`))).items }
export async function getApprovalList(stage: 'MANAGER1'|'MANAGER2', uuid:string): Promise<CostList> { return unwrap(await axios.get(url(`/api/v1/approvals/${stage}/lists/${uuid}`))) }
export async function decideTransaction(stage:'MANAGER1'|'MANAGER2', uuid:string, action:'APPROVE'|'REJECT'|'RETURN', version:number, comment?:string|null): Promise<Transaction> { return unwrap(await axios.post(url(`/api/v1/approvals/${stage}/transactions/${uuid}/${action}`), { version, comment:comment || null })) }
export async function editTransactionAsManager(stage:'MANAGER1'|'MANAGER2', uuid:string, version:number, data:Record<string,unknown>, reason:string): Promise<Transaction> { return unwrap(await axios.patch(url(`/api/v1/approvals/${stage}/transactions/${uuid}`), { version, data, reason })) }
export function evidenceUrl(uuid:string): string { return generateUrl(`/apps/pettycash/evidence/${encodeURIComponent(uuid)}`) }
