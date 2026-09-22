export type Role = 'PURCHASER' | 'COST_CENTER_MANAGER' | 'SENIOR_MANAGER' | 'FINANCE' | 'ADMINISTRATOR'
export interface Capabilities { roles: Role[]; canAdminister: boolean; canFinance: boolean; costCenterIds: string[] }
