import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
const base = process.env.LUDORA_TEST_URL || 'http://localhost:8095';
await mkdir('tests/artifacts', {recursive:true});
await mkdir('marketplace/previews', {recursive:true});
const browser = await chromium.launch();
try {
  const page = await browser.newPage();
  for (const width of [360,768,1440]) {
    await page.setViewportSize({width,height:1000});
    for (const [name,path] of [['home','/'],['booking','/booking/'],['celebration','/celebration-room/'],['experiences','/birthday-experiences/']]) {
      await page.goto(base+path); await page.evaluate(()=>document.fonts.ready);
      if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('Overflow: '+path+' at '+width);
      await page.screenshot({path:`tests/artifacts/${name}-${width}.png`,fullPage:true});
    }
  }
  await page.setViewportSize({width:1200,height:900});await page.goto(base+'/');
  await page.screenshot({path:process.env.LUDORA_CHILD_CAPTURE?'themes/ludora-child/screenshot.png':'themes/ludora/screenshot.png'});
  if(!process.env.LUDORA_CHILD_CAPTURE){
    await page.setViewportSize({width:1170,height:780});await page.screenshot({path:'marketplace/previews/cover-1170x780.png'});
    await page.setViewportSize({width:590,height:300});await page.screenshot({path:'marketplace/previews/01_ludora.jpg',type:'jpeg',quality:90});
    for(const [name,path] of [['02_booking','/booking/'],['03_celebration','/celebration-room/'],['04_experiences','/birthday-experiences/']]){
      await page.setViewportSize({width:1170,height:1000});await page.goto(base+path);await page.screenshot({path:'marketplace/previews/'+name+'.png'});
    }
  }
  console.log('Real browser captures saved. No UI or promotional results were synthesized.');
}finally{await browser.close();}
