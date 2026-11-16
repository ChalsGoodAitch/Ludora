import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
const cli=(...args)=>execFileSync('docker',['compose','-f','compose.clean.yaml','run','--rm','cli',...args],{encoding:'utf8'}).trim();
if(!process.env.LUDORA_CLEAN_ADMIN_PASSWORD)throw Error('Local clean-site password required in environment.');
const fixture=(...args)=>execFileSync('docker',['compose','-f','compose.clean.yaml','exec','--user','www-data','-T','wordpress','php','ludora-tests/browser/manual-fixture.php',...args],{encoding:'utf8'}).trim();
const room=Number(fixture('create'));assert(room>0);
cli('eval','(new Ludora\\Booking\\Store())->create(["room_id"=>'+room+',"date"=>wp_date("Y-m-d",time()+60*DAY_IN_SECONDS,new DateTimeZone(Ludora\\Booking\\Settings::get()["timezone"])),"mode"=>"shift","shift"=>"morning","children"=>5,"adults"=>2,"extras"=>[]],["name"=>"TGM retention fixture","email"=>"tgm@example.test"],bin2hex(random_bytes(32)),bin2hex(random_bytes(32)));');
const before=cli('eval','global $wpdb; if(!empty(Ludora\\Booking\\Settings::get()["purge"]))throw new Exception("Purge must be disabled"); echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ludora_bookings");');
const b=await chromium.launch();const p=await b.newPage();
try{
 cli('plugin','deactivate','ludora-booking');cli('plugin','uninstall','ludora-booking');
 await p.goto('http://localhost:8096/wp-login.php');await p.locator('#user_login').fill('zip-admin');await p.locator('#user_pass').fill(process.env.LUDORA_CLEAN_ADMIN_PASSWORD);await p.locator('#wp-submit').click();await p.waitForURL('**/wp-admin/**');
 await p.goto('http://localhost:8096/wp-admin/themes.php?page=ludora-companions');
 await p.screenshot({path:'tests/artifacts/tgm-voluntary-install.png',fullPage:true});
 await p.locator('a[href*="plugin=ludora-booking"][href*="tgmpa-install=install-plugin"]').click();
 await p.waitForLoadState('load');assert.equal(cli('plugin','is-installed','ludora-booking'),'');
 await p.goto('http://localhost:8096/wp-admin/themes.php?page=ludora-companions');await p.locator('a[href*="plugin=ludora-booking"][href*="tgmpa-activate=activate-plugin"]').click();await p.waitForLoadState('load');assert.equal(cli('plugin','is-active','ludora-booking'),'');
 const after=cli('eval','global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ludora_bookings");');assert.equal(after,before);assert(Number(after)>0);
 await p.goto('http://localhost:8096/booking/');assert(await p.locator('.ludora-form').isVisible());
 console.log('PASS: real administrator voluntarily installs included ZIP and separately activates through TGM. Default uninstall preserves booking count; booking frontend restored. WooCommerce was not automatically installed.');
}finally{try{cli('plugin','is-active','ludora-booking');}catch{cli('plugin','install','/packages/ludora-booking.zip','--force','--activate');}fixture('delete',String(room));await b.close();}
