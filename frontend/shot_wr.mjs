import { chromium } from 'playwright'
const OUT='/private/tmp/claude-501/-Users-rosliamir/a631b6de-d1a5-436b-bc66-d43f1560af84/scratchpad/sm/capture'
const b=await chromium.launch();const p=await b.newPage({viewport:{width:1440,height:900}})
await p.goto('http://127.0.0.1:8008/admin/login',{waitUntil:'networkidle',timeout:30000})
await p.fill('input[type=email]','admin@kssb.local'); await p.fill('input[type=password]','password')
await p.click('button[type=submit]'); await p.waitForTimeout(3000)
await p.goto('http://127.0.0.1:8008/admin/weekly-reports',{waitUntil:'networkidle',timeout:30000}).catch(()=>{})
await p.waitForTimeout(2500)
const err=await p.evaluate(()=>document.body.innerText.includes('Internal Server Error')||document.body.innerText.includes('FatalError'))
console.log('error:',err)
await p.screenshot({path:`${OUT}/admin-weekly.png`})
await b.close()
