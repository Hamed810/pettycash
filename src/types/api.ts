export interface ApiErrorBody { code: string; message: string; commandId?: string; fieldErrors?: Record<string, string> }
export interface Page<T> { items: T[]; page: number; pageSize: number; total: number }
export interface CommandOptions { commandId?: string; expectedVersion?: number }

export class ApiError extends Error {
	public constructor(public readonly status: number, public readonly body: ApiErrorBody) {
		super(body.message)
	}
}
