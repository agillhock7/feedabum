export interface RecipientProfile {
  id: number
  nickname: string
  story: string
  needs: string
  zone: string
  verified_at: string | null
  verified_by_partner: string
  total_received_cents: number
  supporters_count: number
}

export interface Partner {
  id: number
  name: string
  email: string
}

export interface AdminRecipient {
  id: number
  nickname: string
  story: string
  needs: string
  zone: string
  verified_at: string | null
  status: 'active' | 'suspended'
  created_at: string
  updated_at: string
  code_short: string | null
  total_received_cents: number
  supporters_count: number
}
