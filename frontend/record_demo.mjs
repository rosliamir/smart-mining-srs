import { chromium } from 'playwright'
import fs from 'fs'
const VDIR = '/private/tmp/claude-501/-Users-rosliamir/a631b6de-d1a5-436b-bc66-d43f1560af84/scratchpad/sm/video'
fs.rmSync(VDIR, { recursive: true, force: true }); fs.mkdirSync(VDIR, { recursive: true })
const B = 'http://localhost:5178'

const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: 1440, height: 810 }, recordVideo: { dir: VDIR, size: { width: 1440, height: 810 } } })
const page = await ctx.newPage()

async function cap(text, sub = '') {
  await page.evaluate(([t, s]) => {
    let el = document.getElementById('__cap')
    if (!el) { el = document.createElement('div'); el.id = '__cap'; document.body.appendChild(el)
      el.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:2147483647;padding:46px 54px 32px;text-align:center;font-family:Inter,system-ui,sans-serif;background:linear-gradient(transparent,rgba(8,15,25,.85));pointer-events:none;transition:opacity .25s' }
    el.innerHTML = `<div style="color:#fff;font-weight:800;font-size:29px;text-shadow:0 2px 8px rgba(0,0,0,.7)">${t}</div>` +
      (s ? `<div style="color:#dbe7f3;font-weight:500;font-size:18px;margin-top:8px;line-height:1.4;text-shadow:0 2px 6px rgba(0,0,0,.7)">${s}</div>` : '')
  }, [text, sub])
}
const wait = ms => page.waitForTimeout(ms)
async function go(path) { await page.goto(B + path, { waitUntil: 'domcontentloaded' }).catch(() => {}); await wait(1600) }

try {
  // 1. LOGIN — show interaction (type credentials) over ~10s
  await go('/login')
  await cap('Skrin Log Masuk', 'Sistem Pemantauan Pintar KSSB — latar belakang ialah video drone tapak sebenar')
  await wait(2500)
  const email = await page.$('input'); const pass = (await page.$$('input'))[1]
  await email.click(); await email.fill(''); await page.keyboard.type('admin@selgem.local', { delay: 90 })
  await wait(700)
  await pass.click(); await pass.fill(''); await page.keyboard.type('password', { delay: 110 })
  await cap('Masukkan E-mel & Kata Laluan', 'Pengguna log masuk dengan akaun mereka untuk mengakses sistem')
  await wait(3200)
  await page.hover('button:has-text("Submit")'); await wait(1200)
  await page.click('button:has-text("Submit")'); await wait(4000)

  // 2. MAP
  await cap('Peta Tapak', 'Paparan "Semua Wilayah" menunjukkan dua kelompok: Wilayah Utara & Selatan'); await wait(5000)
  await page.click('button:has-text("Northern Region")').catch(()=>{}); await wait(1200)
  await cap('Wilayah Utara — 33 Tapak', 'Setiap tapak ada label cuaca & ikon status permit: biru = aktif, kuning = amaran, merah = tamat tempoh'); await wait(5500)
  await page.click('button:has-text("Southern Region")').catch(()=>{}); await wait(1200)
  await cap('Wilayah Selatan — 44 Tapak', 'Klik mana-mana tapak untuk melihat butiran penuh'); await wait(4500)
  await page.click('.leaflet-marker-icon .site-pill').catch(()=>{}); await wait(1200)
  await cap('Butiran Tapak', 'Status permit, kontraktor, lokasi, amaran tamat tempoh & laporan F001 — permit boleh dimuat turun'); await wait(5500)

  // 3. DASHBOARD
  await go('/dashboard'); await wait(2500)
  await cap('Papan Pemuka — Operation Sites', 'Jumlah jualan, prestasi mengikut wilayah, dan carta bulanan/harian — semua data langsung dari pangkalan data'); await wait(6500)

  // 4. MONITORING
  await go('/monitoring')
  await cap('Pemantauan', 'Semua dokumen pematuhan tapak: ISO, pembatalan doket, aduan & laporan F001. Boleh pratonton dan muat turun fail PDF'); await wait(6000)

  // 5. ATTENDANCE
  await go('/attendance')
  await page.evaluate(()=>{const i=document.querySelector('input[type=date]');if(i){i.value='2026-11-10';i.dispatchEvent(new Event('change'))}}); await wait(1600)
  await cap('Kehadiran', 'Rekod masuk & keluar pekerja mengikut tarikh — hadir, lewat, tidak hadir, cuti. Klik "VIEW" untuk kalendar bulanan setiap staf'); await wait(6000)

  // 6. SITE MANAGEMENT (via profile menu)
  await go('/site-management')
  await cap('Pengurusan Tapak', 'Senarai penuh tapak, kontraktor, tarikh permit & ringkasan status permit mengikut wilayah'); await wait(6000)

  // 7. USER MANAGEMENT
  await go('/user-management')
  await cap('Pengurusan Pengguna', 'Pengguna sistem, peranan (Super Admin, Site Officer, dsb.), kawasan tapak & ringkasan bilangan peranan'); await wait(6000)

  // 8. ACCOUNT
  await go('/account')
  await cap('Tetapan Akaun', 'Lihat profil pengguna dan tukar kata laluan dengan selamat'); await wait(5000)

  // 9. LOGOUT
  await page.click('.profile-btn').catch(()=>{}); await wait(1000)
  await cap('Log Keluar', 'Menu profil memaparkan maklumat pengguna & pilihan log keluar'); await wait(2500)
  await page.click('button:has-text("Sign out")').catch(()=>{}); await wait(2500)
  await cap('Terima Kasih', 'Sistem Pemantauan Pintar KSSB — dibina semula sepenuhnya dengan data sebenar'); await wait(3500)
} catch (e) { console.log('ERR', String(e).slice(0, 200)) }

await ctx.close()
await browser.close()
console.log('done:', fs.readdirSync(VDIR).filter(f => f.endsWith('.webm')))
