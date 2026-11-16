import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
const cli=(...args)=>execFileSync('docker',['compose','-f','compose.clean.yaml','run','--rm','cli',...args],{encoding:'utf8'}).trim();
const browser=await chromium.launch();const page=await browser.newPage();const observations=[];
const original=cli('option','get','WPLANG');const theme=cli('option','get','stylesheet');
try{
 await page.goto('http://localhost:8096/about/');
 // Asset load evidence comes from actual response HTML, not bundle sizes alone.
 const about=await page.content();assert(!about.includes('booking.js'));observations.push('Ordinary about page does not enqueue booking.js.');
 await page.goto('http://localhost:8096/booking/');assert(await page.locator('.ludora-form').isVisible());assert((await page.content()).includes('booking.js'));observations.push('Booking page enqueues functional booking.js.');
 cli('option','update','WPLANG','ar');
 for(const width of [360,1440]){await page.setViewportSize({width,height:1000});await page.goto('http://localhost:8096/booking/');assert.equal(await page.locator('html').getAttribute('dir'),'rtl');assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:'tests/artifacts/rtl-booking-'+width+'.png',fullPage:true});}
 observations.push('Actual Arabic core locale: dir=rtl and no horizontal overflow at360/1440. Product Arabic translation not advertised.');
 cli('option','update','WPLANG',original);
 cli('theme','activate','twentytwentyfive');await page.goto('http://localhost:8096/booking/');assert(await page.locator('.ludora-form').isVisible());observations.push('Plugin form renders with stock Twenty Twenty-Five.');
 cli('theme','activate','ludora-child');await page.goto('http://localhost:8096/');assert(await page.locator('main').isVisible());assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));observations.push('ZIP-installed child renders real home.');
 cli('theme','activate','ludora');cli('plugin','deactivate','ludora-booking');await page.goto('http://localhost:8096/journal/');assert(await page.locator('main').isVisible());assert.equal(await page.locator('.ludora-form').count(),0);observations.push('Parent native journal remains usable with booking plugin inactive.');
 cli('plugin','activate','ludora-booking');
 console.log(observations.join('\n'));writeFileSync('tests/artifacts/final-clean-checks.json',JSON.stringify(observations,null,2));
}finally{cli('option','update','WPLANG',original);cli('theme','activate',theme);cli('plugin','activate','ludora-booking');await browser.close();}
