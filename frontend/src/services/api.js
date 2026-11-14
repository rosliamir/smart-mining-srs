// Talks to the Laravel API when a token is present; falls back to the bundled
// mock JSON (public/data/*.json) so the prototype still runs offline.
const API = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8008/api'
const cache = {}

export function token() { return localStorage.getItem('sm_token') }

export async function login(identifier, password) {
  const r = await fetch(`${API}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: identifier, identifier, password }),
  })
  if (!r.ok) throw new Error('Invalid credentials')
  const d = await r.json()
  localStorage.setItem('sm_token', d.token)
  localStorage.setItem('sm_user', JSON.stringify(d.user))
  localStorage.setItem('sm_auth', '1')
  return d
}

export function currentUser() {
  try { return JSON.parse(localStorage.getItem('sm_user') || 'null') } catch { return null }
}

async function fromApi(name) {
  const t = token(); if (!t) return null
  const r = await fetch(`${API}/${name}?per_page=500`, {
    headers: { Authorization: `Bearer ${t}`, Accept: 'application/json' },
  })
  if (!r.ok) return null
  const d = await r.json()
  return Array.isArray(d) ? d : (d.data ?? d.roles ?? null)   // unwrap pagination
}

async function fromMock(name) {
  try {
    const r = await fetch(`/data/${name}.json`)
    if (!r.ok) throw new Error()
    const d = await r.json()
    return Array.isArray(d) ? d : (d.roles || d)
  } catch { return [] }
}

export async function load(name) {
  if (cache[name]) return cache[name]
  const live = await fromApi(name).catch(() => null)
  cache[name] = live ?? await fromMock(name)
  return cache[name]
}

// Generic authenticated GET returning parsed JSON (null if unauth/offline).
export async function apiGet(path) {
  const t = token(); if (!t) return null
  try {
    const r = await fetch(`${API}/${path}`, { headers: { Authorization: `Bearer ${t}`, Accept: 'application/json' } })
    return r.ok ? await r.json() : null
  } catch { return null }
}

export async function apiPost(path, body) {
  const t = token(); if (!t) throw new Error('Not authenticated')
  const r = await fetch(`${API}/${path}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${t}`, Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  })
  const d = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(d.message || d.error || 'Request failed')
  return d
}

export async function dashboard() {
  const t = token(); if (!t) return null
  const r = await fetch(`${API}/dashboard`, { headers: { Authorization: `Bearer ${t}`, Accept: 'application/json' } })
  return r.ok ? r.json() : null
}

export function fmtDate(v) {
  if (!v) return '—'
  const d = new Date(v)
  return isNaN(d) ? String(v) : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}
export function rel(o, key) {
  const v = o?.[key]; if (!v) return '—'
  if (typeof v === 'object') return v.name || v.username || v.title || v.id || '—'
  return v
}

// Authenticated request with any method (PUT / DELETE / POST); throws with the API message on error.
export async function apiSend(method, path, body) {
  const t = token(); if (!t) throw new Error('Not authenticated')
  const r = await fetch(`${API}/${path}`, {
    method,
    headers: { Authorization: `Bearer ${t}`, Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  })
  const d = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(d.message || d.error || 'Request failed')
  return d
}
