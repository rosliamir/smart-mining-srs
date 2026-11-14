import { chromium } from 'playwright'
const OUT = process.argv[2]; const errs = []
const b = await chromium.launch({ args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] })
async function login(ctx, id, dest = '**/ess') {
  const p = await ctx.newPage()
  p.on('pageerror', e => errs.push(`${id}: ${e.message}`)); p.on('console', m => { if (m.type() === 'error' && !/favicon|422/i.test(m.text())) errs.push(`${id}: ${m.text()}`) })
  await p.goto('http://localhost:5178/login', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2000)
  await p.fill('.glass input >> nth=0', id); await p.fill('.glass input[type=password]', 'password'); await p.click('button:has-text("Submit")'); await p.waitForURL(dest, { timeout: 45000 }); await p.waitForTimeout(1500); return p
}
async function clockIn(id, geo, shotName) {
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, permissions: ['geolocation', 'camera'], geolocation: geo })
  const p = await login(ctx, id)
  await p.click('.punch-btn'); await p.waitForSelector('.sc video'); await p.waitForTimeout(1500)
  if (shotName) await p.screenshot({ path: `${OUT}/${shotName}-cam.png` })
  await p.click('.sc .btn-pri'); await p.waitForSelector('.sc img', { timeout: 10000 }); await p.waitForTimeout(400)
  if (shotName) await p.screenshot({ path: `${OUT}/${shotName}-shot.png` })
  await p.click('.sc .btn-pri'); await p.waitForSelector('.punch-msg', { timeout: 15000 }); await p.waitForTimeout(800)
  console.log(id, '->', await p.textContent('.punch-msg'))
  if (shotName) await p.screenshot({ path: `${OUT}/${shotName}-done.png` })
  await ctx.close()
}
await clockIn('KS0294', { latitude: 3.4115, longitude: 101.3700, accuracy: 12 }, 'n1')
await clockIn('KS0337', { latitude: 3.41152, longitude: 101.37002, accuracy: 15 })
await clockIn('KS0268', { latitude: 3.1478, longitude: 101.6953, accuracy: 30 }, 'n3')
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); const p = await login(ctx, 'KS0197')
await p.goto('http://localhost:5178/ess/hr?tab=alerts'); await p.waitForSelector('.card-a'); await p.waitForTimeout(1000); await p.screenshot({ path: `${OUT}/n4-alerts.png` })
await p.goto('http://localhost:5178/ess/hr'); await p.waitForSelector('.lm-map'); await p.waitForTimeout(2000)
await p.click('.hra .lm-tools button >> nth=2'); await p.waitForTimeout(500)
const pin = await p.$('.lm-pin'); if (pin) { await pin.click(); await p.waitForTimeout(800) } await p.screenshot({ path: `${OUT}/n5-map.png` })
await p.goto('http://localhost:5178/ess/hr?tab=manpower'); await p.waitForSelector('.tb tbody tr'); await p.waitForTimeout(1500); await p.screenshot({ path: `${OUT}/n6-manpower.png` })
await p.goto('http://localhost:5178/ess/hr?tab=geofence'); await p.waitForSelector('.off'); await p.waitForTimeout(1500); await p.screenshot({ path: `${OUT}/n7-geofence.png` })
const n = await p.evaluate(() => fetch('http://127.0.0.1:8008/api/ess/notifications', { headers: { Authorization: 'Bearer ' + localStorage.getItem('sm_token'), Accept: 'application/json' } }).then(r => r.json()))
console.log('HR notifications:', n.map(x => x.title))
await ctx.close(); console.log(errs.length ? errs.join('\n') : 'no errors'); await b.close()
