export interface RecipientProfile {
  id: number
  nickname: string
  story: string
  needs: string
  zone: string
  city: string
  latitude: number | null
  longitude: number | null
  signup_source: 'admin' | 'self'
  onboarding_status: 'new' | 'reviewed' | 'verified'
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

export interface AdminUser {
  id: number
  partner_id: number | null
  partner_name?: string | null
  email: string
  display_name: string
  role: 'admin_owner' | 'admin_outreach' | 'admin_demo'
  status: 'active' | 'disabled'
  created_at?: string
  last_login_at?: string | null
}

export interface AdminRecipient {
  id: number
  nickname: string
  story: string
  needs: string
  zone: string
  city: string
  latitude: number | null
  longitude: number | null
  signup_source: 'admin' | 'self'
  onboarding_status: 'new' | 'reviewed' | 'verified'
  contact_email: string | null
  contact_phone: string | null
  verified_at: string | null
  status: 'active' | 'suspended'
  created_at: string
  updated_at: string
  code_short: string | null
  total_received_cents: number
  supporters_count: number
}
