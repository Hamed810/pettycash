import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

export type Currency = { id: number; code: string; name: string; symbol: string | null; decimalPlaces: number; isDefault: boolean; active: boolean }
export type Category = { id: number; code: string; name: string; description: string | null; receiptRequired: boolean; vehicleRequired: boolean; odometerRequired: boolean; workerRequired: boolean; permitRequired: boolean; attendanceRequired: boolean; active: boolean; sortOrder: number }

// v2.0.0: "Project" now doubles as the general Destination concept.
export type DestinationType = 'PROJECT' | 'OFFICE' | 'MARKETING' | 'OTHER'
export type Project = { id: number; uuid: string; code: string; name: string; description: string | null; defaultCurrencyId: number; active: boolean; createdBy: string; type: DestinationType }
export type Destination = Project

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

// v2.0.0: Projects doubling as Destinations. `type` distinguishes
// PROJECT from OFFICE/MARKETING/OTHER. Regular Cost List transactions
// require the purchaser to be assigned here; Business Trip
// transactions may target any non-PROJECT destination without
// assignment (see createTransaction).
export async function getProjects(includeInactive = false): Promise<Project[]> { return unwrap<{items: Project[]}>(await axios.get(url('/api/v1/projects'), { params: { includeInactive } })).items }
export async function createProject(data: Record<string, unknown>): Promise<Project> { return unwrap(await axios.post(url('/api/v1/projects'), data)) }
export async function updateProject(uuid: string, data: Record<string, unknown>): Promise<Project> { return unwrap(await axios.patch(url(`/api/v1/projects/${uuid}`), data)) }
export async function getDestinations(includeInactive = false): Promise<Destination[]> { return getProjects(includeInactive) }
export function destinationsByType(destinations: Destination[], type: DestinationType | 'ALL'): Destination[] {
	return type === 'ALL' ? destinations : destinations.filter(d => d.type === type)
}

export async function getMembers(uuid: string): Promise<ProjectMember[]> { return unwrap<{items: ProjectMember[]}>(await axios.get(url(`/api/v1/projects/${uuid}/members`))).items }
export async function replaceMembers(uuid: string, members: Array<{userId:string; role:string}>): Promise<ProjectMember[]> { return unwrap<{items: ProjectMember[]}>(await axios.put(url(`/api/v1/projects/${uuid}/members`), { members })).items }
export async function getVehicles(projectUuid: string, includeInactive = false): Promise<Vehicle[]> { return unwrap<{items: Vehicle[]}>(await axios.get(url(`/api/v1/projects/${projectUuid}/vehicles`), { params: { includeInactive } })).items }
export async function createVehicle(projectUuid: string, data: Record<string, unknown>): Promise<Vehicle> { return unwrap(await axios.post(url(`/api/v1/projects/${projectUuid}/vehicles`), data)) }
export async function updateVehicle(uuid: string, data: Record<string, unknown>): Promise<Vehicle> { return unwrap(await axios.patch(url(`/api/v1/vehicles/${uuid}`), data)) }

// v2.0.0: manager (M1) / destination-owner (M2) assignment + live
// Nextcloud user directory search backing the assignment dropdowns.
export type DirectoryUser = { userId: string; displayName: string }
export async function searchUsers(query: string, limit = 20): Promise<DirectoryUser[]> { return unwrap<{items: DirectoryUser[]}>(await axios.get(url('/api/v1/users'), { params: { query, limit } })).items }
export async function getManagerAssignment(purchaserId: string): Promise<{purchaserId: string; managerId: string | null}> { return unwrap(await axios.get(url(`/api/v1/purchasers/${encodeURIComponent(purchaserId)}/manager`))) }
export async function setManagerAssignment(purchaserId: string, managerId: string): Promise<unknown> { return unwrap(await axios.put(url(`/api/v1/purchasers/${encodeURIComponent(purchaserId)}/manager`), { managerId })) }
export async function getDestinationOwner(destinationUuid: string): Promise<{destinationUuid: string; ownerId: string | null}> { return unwrap(await axios.get(url(`/api/v1/destinations/${destinationUuid}/owner`))) }
export async function setDestinationOwner(destinationUuid: string, ownerId: string): Promise<unknown> { return unwrap(await axios.put(url(`/api/v1/destinations/${destinationUuid}/owner`), { ownerId })) }

export type Attachment = { id: number; uuid: string; type: 'RECEIPT'|'HIRING_PERMIT'|'ATTENDANCE_EVIDENCE'|'OTHER'; originalName: string; mimeType: string; fileSize: number; sha256: string; sensitive: boolean; createdAt: number }

// v2.0.0: a Decision is one M1 or M2 verdict, scoped to the revision
// it was made on. A transaction can have an M1 decision that
// disagrees with the final M2 decision -- both are always kept and
// returned together, never collapsed into one status.
export type DecisionRole = 'M1' | 'M2' | 'ACCOUNTANT'
export type Decision = { role: DecisionRole; decision: 'APPROVE'|'REJECT'|'RETURN'; reason: string | null; actorId: string; revisionId: number | null; createdAt: number }

export type Transaction = {
	id: number; uuid: string; listId: number; purchaserId: string
	category: {id:number;code:string;name:string}|null
	destination: {id:number;uuid:string;code:string;name:string;type:DestinationType}|null
	currency: string; amountMinor: number; amountFormatted: string
	purchaseDate: string; purchaseDateJalali: string; description: string; vendor: string|null
	vehicle: {id:number;uuid:string;name:string;plateNumber:string}|null
	odometerKm: number|null; workerName: string|null; workerReference: string|null
	workDays: number|null; workMinutes: number|null; workDescription: string|null
	status: string
	manager1Id: string | null
	manager2Id: string | null
	// true when the same person is resolved as both M1 and M2 -- must
	// always be shown to the reviewer, never silent.
	sameManagerFlag: boolean
	currentRevision: number; version: number
	attachments: Attachment[]
	decisions: Decision[]
	warnings: string[]
}

export type ListType = 'REGULAR' | 'BUSINESS_TRIP'
export type CostList = { id: number; uuid: string; reference: string|null; purchaserId: string; listType: ListType; currency: {id:number;code:string;name:string;symbol:string|null;decimalPlaces:number}|null; jalaliYear: number; jalaliMonth: number; status: string; submittedTotal: number; manager1Total: number; finalTotal: number; createdAt: number; submittedAt: number|null; version: number; deleted: boolean; transactions?: Transaction[] }

export async function getCostLists(): Promise<CostList[]> { return unwrap<{items: CostList[]}>(await axios.get(url('/api/v1/lists'))).items }
export async function getCostList(uuid: string): Promise<CostList> { return unwrap(await axios.get(url(`/api/v1/lists/${uuid}`))) }
// v2.0.0: no project on creation -- destination is chosen per
// transaction. listType decided first and locked once the first
// transaction is added (enforce this in the UI, not just the API).
export async function createCostList(data: {jalaliYear:number; jalaliMonth:number; listType: ListType; currencyId?:number|null}): Promise<CostList> { return unwrap(await axios.post(url('/api/v1/lists'), data)) }
export async function submitCostList(uuid: string, version: number): Promise<CostList> { return unwrap(await axios.post(url(`/api/v1/lists/${uuid}/submit`), { version })) }
export async function deleteCostList(uuid: string): Promise<void> { await axios.delete(url(`/api/v1/lists/${uuid}`)) }

// Transaction payloads now require `destinationId` (a destination
// uuid) alongside `categoryId` -- both required, entered together.
export async function createTransaction(listUuid: string, data: Record<string, unknown>): Promise<Transaction> { return unwrap(await axios.post(url(`/api/v1/lists/${listUuid}/transactions`), { data })) }
export async function updateTransaction(uuid: string, version: number, data: Record<string, unknown>): Promise<Transaction> { return unwrap(await axios.patch(url(`/api/v1/transactions/${uuid}`), { version, data })) }
export async function deleteTransaction(uuid: string): Promise<void> { await axios.delete(url(`/api/v1/transactions/${uuid}`)) }
export async function uploadAttachment(txnUuid: string, type: Attachment['type'], file: File): Promise<Attachment> { const form = new FormData(); form.append('type', type); form.append('file', file); return unwrap(await axios.post(url(`/api/v1/transactions/${txnUuid}/attachments`), form)) }
export async function deleteAttachment(uuid: string): Promise<void> { await axios.delete(url(`/api/v1/attachments/${uuid}`)) }


// v2.0.0: queues are transaction-level, not Cost-List-level, since a
// single list's transactions can route to different M2s. Each queue
// item IS the transaction to review (not a list summary).
export type ApprovalQueueItem = {
	uuid: string
	destination: {uuid:string;code:string;name:string;type:DestinationType} | null
	purchaserId: string
	amountMinor: number
	status: string
	sameManagerFlag: boolean
	// present only on the M2 queue: M1's decision on this transaction,
	// so the disagreement is visible on the row itself.
	manager1Decision: Decision | null
	version: number
}
export type ApprovalStage = 'MANAGER1' | 'MANAGER2' | 'ACCOUNTANT'
export async function getApprovalQueue(
  stage: ApprovalStage
): Promise<ApprovalQueueItem[]> {
  return unwrap<{ items: ApprovalQueueItem[] }>(
    await axios.get(url(`/api/v1/approvals/${stage}`))
  ).items
}

export async function getApprovalTransaction(
  stage: ApprovalStage,
  uuid: string
): Promise<Transaction> {
  return unwrap(
    await axios.get(url(`/api/v1/approvals/${stage}/lists/${uuid}`))
  )
}

export async function decideTransaction(
  stage: ApprovalStage,
  uuid: string,
  action: 'APPROVE' | 'REJECT' | 'RETURN',
  version: number,
  comment?: string | null
): Promise<Transaction> {
  return unwrap(
    await axios.post(
      url(`/api/v1/approvals/${stage}/transactions/${uuid}/${action}`),
      {
        version,
        comment: comment || null,
      }
    )
  )
}

export async function editTransactionAsManager(stage:'MANAGER1'|'MANAGER2', uuid:string, version:number, data:Record<string,unknown>, reason:string): Promise<Transaction> { return unwrap(await axios.patch(url(`/api/v1/approvals/${stage}/transactions/${uuid}`), { version, data, reason })) }
export function evidenceUrl(uuid:string): string { return generateUrl(`/apps/pettycash/evidence/${encodeURIComponent(uuid)}`) }

export type AdminSettings = {
    allowMultipleOpenCostLists: boolean
    allowUserDeleteOpenCostLists: boolean
    requireVehicleKilometer: boolean
    requireHiringPermit: boolean
    requireFingerprint: boolean
    ocrEnabled: boolean
    ocrLanguage: string
    timezone: string
    defaultCurrency: string
}


export async function getAdminSettings(): Promise<AdminSettings> {
    return unwrap(
        await axios.get(
            url('/api/v1/admin/settings')
        )
    )
}


export async function saveAdminSettings(
    data: Partial<AdminSettings>
): Promise<AdminSettings> {
    return unwrap(
        await axios.put(
            url('/api/v1/admin/settings'),
            data
        )
    )
}
