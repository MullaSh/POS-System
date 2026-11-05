/**
 * Klien API. Memakai cookie sesi Sanctum (satu domain dengan backend),
 * sehingga tidak ada token yang disimpan di browser (dokumen 12 §6).
 */

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

const BASE = '/api/v1'

function readCookie(name: string): string | undefined {
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : undefined
}

/** Pesan yang dapat dipahami pengguna untuk setiap jenis kegagalan. */
export function friendlyMessage(status: number, serverMessage?: string): string {
  if (status === 0) return 'Tidak dapat terhubung ke server. Periksa koneksi internet lalu coba lagi.'
  if (status === 401) return 'Sesi Anda sudah berakhir. Silakan masuk kembali.'
  if (status === 403) return 'Anda tidak memiliki izin untuk tindakan ini.'
  if (status === 404) return 'Fitur ini belum tersedia di server.'
  if (status === 419) return 'Halaman sudah terlalu lama terbuka. Muat ulang halaman lalu coba lagi.'
  if (status === 422) return serverMessage ?? 'Data yang diisi belum benar.'
  if (status === 429) return 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.'
  if (status >= 500) return 'Terjadi gangguan di server. Coba lagi beberapa saat lagi.'
  return serverMessage ?? 'Terjadi kesalahan.'
}

let csrfReady = false

async function ensureCsrf(): Promise<void> {
  if (csrfReady && readCookie('XSRF-TOKEN')) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  csrfReady = true
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  if (method !== 'GET') await ensureCsrf()

  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json')
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers.set('X-XSRF-TOKEN', xsrf)

  let response: Response
  try {
    response = await fetch(`${BASE}${path}`, { ...init, headers, credentials: 'include' })
  } catch {
    throw new ApiError(0, friendlyMessage(0))
  }

  const isJson = response.headers.get('Content-Type')?.includes('application/json')
  const body = isJson ? await response.json() : null

  if (!response.ok) {
    throw new ApiError(
      response.status,
      friendlyMessage(response.status, body?.message),
      body?.errors ?? {},
    )
  }
  return body as T
}
