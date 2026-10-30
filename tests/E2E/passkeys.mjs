import { chromium } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

const wordpress = process.env.SWSL_TEST_WORDPRESS;
const origin = process.env.SWSL_TEST_ORIGIN || 'https://localhost:8443';
if (!wordpress) throw new Error('Set SWSL_TEST_WORDPRESS to the disposable test site.');
execFileSync('php', ['tests/E2E/prepare.php', wordpress, origin], {stdio:'inherit'});
const browser = await chromium.launch({
 executablePath:process.env.SWSL_CHROMIUM || undefined,
 args:['--no-sandbox'],
});
try {
 const context = await browser.newContext({ignoreHTTPSErrors:true});
 const page = await context.newPage();
 const errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 if (process.env.SWSL_E2E_DEBUG) page.on('response',response=>{ if(response.url().includes('passkeys')) console.log(response.request().method(),response.status(),response.url().split('rest_route=')[1] || 'asset'); });
 await page.goto(`${origin}/wp-login.php`);
 await page.locator('#user_login').fill('swsl-test-admin');
 await page.locator('#user_pass').fill('swsl-disposable-test-password');
 await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 await page.goto(`${origin}/wp-admin/admin.php?page=swsl-overview`);
 await page.locator('input[name="enabled"]').check();
 await Promise.all([page.waitForURL('**/wp-admin/admin.php?page=swsl-overview'),page.locator('input#submit').click()]);
 await page.goto(`${origin}/wp-admin/profile.php`);
 await page.locator('#swsl-passkeys').waitFor();
 const cdp=await context.newCDPSession(page);
 await cdp.send('WebAuthn.enable');
 let authenticator;
 for (const name of ['Laptop','Security key']) {
  if (authenticator) await cdp.send('WebAuthn.removeVirtualAuthenticator',{authenticatorId:authenticator});
  ({authenticatorId:authenticator}=await cdp.send('WebAuthn.addVirtualAuthenticator',{options:{protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true}}));
  await page.locator('#swsl-passkey-name').fill(name);
  await page.locator('#swsl-passkey-password').fill('swsl-disposable-test-password');
  await page.locator('#swsl-passkey-add').click();
  await page.waitForFunction(()=>!document.getElementById('swsl-passkey-add').disabled && document.getElementById('swsl-passkey-status').textContent !== '',{},{timeout:150000});
  assert.equal(await page.locator('#swsl-passkey-status').textContent(),'Passkey operation completed.');
 }
 assert.equal(await page.locator('#swsl-passkey-list input').count(),2);
 assert.equal(await page.locator('#swsl-passkey-count').textContent(),'2');
 for (const width of [1440, 390]) {
  await page.setViewportSize({width,height:1000});
  const fits = await page.locator('#swsl-passkeys').evaluate(element=>element.scrollWidth <= element.clientWidth + 1);
  assert.equal(fits,true,`Passkey panel overflows at ${width}px`);
  if (process.env.SWSL_SCREENSHOTS) await page.locator('#swsl-passkeys').screenshot({path:`${process.env.SWSL_SCREENSHOTS}/swsl-profile-${width}.png`});
 }
 await page.setViewportSize({width:1440,height:1000});
 await page.locator('#swsl-passkey-list input').first().fill('Renamed laptop');
 await page.locator('#swsl-passkey-password').fill('swsl-disposable-test-password');
 await page.locator('#swsl-passkey-list li').first().getByRole('button',{name:'Rename',exact:true}).click();
 await page.waitForFunction(()=>!document.getElementById('swsl-passkey-add').disabled);
 assert.equal(await page.locator('#swsl-passkey-list input').first().inputValue(),'Renamed laptop');
 await page.locator('#swsl-passkey-password').fill('swsl-disposable-test-password');
 await page.locator('#swsl-passkey-list li').first().getByRole('button',{name:'Revoke',exact:true}).click();
 await page.waitForFunction(()=>!document.getElementById('swsl-passkey-add').disabled);
 assert.equal(await page.locator('#swsl-passkey-list input').count(),1);
 assert.equal(await page.locator('#swsl-passkey-list input').first().inputValue(),'Security key');
 assert.equal(await page.locator('#swsl-passkey-password').inputValue(),'');
 assert.deepEqual(errors,[]);
 console.log('PASS: two passkeys, rename, independent revocation, password clearing and no browser errors.');
 // Disabling must remove routes and profile UI on subsequent requests without logging out.
 await page.goto(`${origin}/wp-admin/admin.php?page=swsl-overview`);
 await page.locator('input[name="enabled"]').uncheck();
 await Promise.all([page.waitForURL('**/wp-admin/admin.php?page=swsl-overview'),page.locator('input#submit').click()]);
 await page.goto(`${origin}/wp-admin/profile.php`);
 assert.equal(await page.locator('#swsl-passkeys').count(),0);
 assert.equal(await page.locator('#your-profile').count(),1);
 console.log('PASS: module disable hides enrollment while preserving the WordPress session.');
} finally { await browser.close(); }
