import { chromium } from 'playwright'
const OUT = process.argv[2]; const b = await chromium.launch(); const errs = []
async function login(ctx, id, dest) {
  const p = await ctx.newPage()
  p.on('pageerror', e => errs.push(`${id}: ${e.message}`)); p.on('console', m => { if (m.type() === 'error' && !/geolocation|favicon|422/i.test(m.text())) errs.push(`${id} console: ${m.text()}`) })
  await p.goto('http://localhost:5178/login', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2000)
  await p.fill('.glass input >> nth=0', id); await p.fill('.glass input[type=password]', 'password')
  await p.click('button:has-text("Submit")'); await p.waitForURL(dest, { timeout: 45000 }); await p.waitForTimeout(1500); return p
}
const KL = { latitude: 3.1478, longitude: 101.6953 }
// staff: GPS clock-in in KL city, then own attendance page
let ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, permissions: ['geolocation'], geolocation: KL }); let p = await login(ctx, 'KS0337', '**/ess')
await p.click('.punch-btn'); await p.waitForTimeout(1500)
await p.goto('http://localhost:5178/ess/attendance'); await p.waitForSelector('.lm-map'); await p.waitForTimeout(2500)
await p.evaluate(() => document.querySelector('.ess-grid.g2')?.scrollIntoView()); await p.waitForTimeout(600)
await p.screenshot({ path: `${OUT}/g1-self.png` })
await p.click('.io-head .ess-tabs >> nth=0 >> button >> nth=2'); await p.waitForTimeout(1200); await p.click('.io-head .ess-tabs >> nth=1 >> button >> nth=1'); await p.waitForTimeout(800)
await p.screenshot({ path: `${OUT}/g2-self-weekly.png` }); await ctx.close()
// HR
ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); p = await login(ctx, 'KS0197', '**/ess')
await p.goto('http://localhost:5178/ess/hr'); await p.waitForSelector('.lm-map'); await p.waitForTimeout(2500); await p.screenshot({ path: `${OUT}/g3-hr-map.png` })
await p.click('.hra .lm-tools button >> nth=1'); await p.waitForTimeout(1200); await p.screenshot({ path: `${OUT}/g4-hr-heatlayer.png` })
await p.click('.hra .bar .ess-tabs >> nth=0 >> button >> nth=1'); await p.waitForSelector('.hm'); await p.waitForTimeout(1200)
await p.screenshot({ path: `${OUT}/g5-heat.png` }); await p.screenshot({ path: `${OUT}/g5-heat-full.png`, fullPage: true })
await p.click('.hra .bar .ess-tabs >> nth=0 >> button >> nth=2'); await p.waitForSelector('.io-chart canvas'); await p.waitForTimeout(1500); await p.screenshot({ path: `${OUT}/g6-trend.png` })
await ctx.close()
// admin console tab
ctx = await b.newContext({ viewport: { width: 1440, height: 900 } }); p = await login(ctx, 'admin@kssb.local', '**/map')
await p.goto('http://localhost:5178/attendance'); await p.waitForTimeout(2500); await p.click('.utab:has-text("Lokasi")'); await p.waitForSelector('.lm-map'); await p.waitForTimeout(2500)
await p.evaluate(() => document.querySelector('.hra')?.scrollIntoView()); await p.waitForTimeout(500); await p.screenshot({ path: `${OUT}/g7-admin.png` }); await ctx.close()
// mobile staff
ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }); p = await login(ctx, 'KS0337', '**/ess')
await p.goto('http://localhost:5178/ess/attendance'); await p.waitForSelector('.lm-map'); await p.waitForTimeout(2000)
await p.evaluate(() => document.querySelector('.io-chart')?.scrollIntoView()); await p.waitForTimeout(500); await p.screenshot({ path: `${OUT}/g8-mob.png` })
console.log('mobile width', await p.evaluate(() => [document.documentElement.scrollWidth, innerWidth])); await ctx.close()
console.log(errs.length ? errs.join('\n') : 'no errors'); await b.close()
