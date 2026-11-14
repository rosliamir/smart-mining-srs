import { chromium } from 'playwright'
const OUT = process.argv[2]
const b = await chromium.launch()
const errs = []
async function run(name, vp, mobile) {
  const ctx = await b.newContext({ viewport: vp, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1 })
  const p = await ctx.newPage()
  p.on('pageerror', e => errs.push(`${name}: ${e.message}`))
  p.on('console', m => { if (m.type() === 'error') errs.push(`${name} console: ${m.text()}`) })
  await p.goto('http://localhost:5178/login', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2500)
  await p.click('button:has-text("Submit")'); await p.waitForURL('**/map', { timeout: 45000 })
  await p.waitForTimeout(4500)
  await p.screenshot({ path: `${OUT}/${name}-1-overview.png` })
  if (!mobile) {
    await p.hover('.chip-warning'); await p.waitForTimeout(500)
    await p.screenshot({ path: `${OUT}/${name}-2-hover-chip.png` })
    await p.mouse.move(700, 600)
  }
  await p.fill('.search-box input', 'BJ04'); await p.waitForTimeout(400)
  await p.screenshot({ path: `${OUT}/${name}-3-search.png` })
  await p.click('.sg-item'); await p.waitForTimeout(3500)
  await p.screenshot({ path: `${OUT}/${name}-4-site.png` })
  if (!mobile) {
    const m = await p.$('.sm-wrap:has(.sm.sel)'); console.log('sel marker:', !!m); if (m) { await m.hover(); await p.waitForTimeout(600); await p.screenshot({ path: `${OUT}/${name}-5-tooltip.png` }) }
  }
  await p.click('.sp-x'); await p.waitForTimeout(400)
  await p.click('.toolbar button[data-tip="Map style"]'); await p.waitForTimeout(300)
  await p.click('.base-opt:nth-child(2)'); await p.waitForTimeout(2500)
  await p.screenshot({ path: `${OUT}/${name}-6-satellite.png` })
  await p.click('.toolbar button[data-tip="Map layers"]'); await p.waitForTimeout(400)
  await p.screenshot({ path: `${OUT}/${name}-7-layers.png` })
  await p.click('.toolbar button[data-tip="Map layers"]')
  await p.click('.s-list'); await p.waitForTimeout(600)
  await p.screenshot({ path: `${OUT}/${name}-8-list.png` })
  await ctx.close()
}
await run('desk', { width: 1440, height: 900 }, false)
await run('mob', { width: 390, height: 844 }, true)
console.log(errs.length ? errs.join('\n') : 'no errors')
await b.close()
