import { chromium } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { BrowserCoverage } from '../coverage/javascript.mjs';

const wordpress = process.env.SWSL_TEST_WORDPRESS;
const origin = process.env.SWSL_TEST_ORIGIN || 'https://localhost:8443';
if (!wordpress) throw new Error('Set SWSL_TEST_WORDPRESS to the disposable test site.');
execFileSync('php', ['tests/E2E/prepare.php', wordpress, origin], {stdio:'inherit'});
const browser = await chromium.launch({
 executablePath:process.env.SWSL_CHROMIUM || undefined,
 args:['--no-sandbox',...(process.env.SWSL_TEST_CERT_SPKI ? [`--ignore-certificate-errors-spki-list=${process.env.SWSL_TEST_CERT_SPKI}`] : [])],
});
try {
 const context = await browser.newContext({ignoreHTTPSErrors:!process.env.SWSL_TEST_CERT_SPKI});
 const page = await context.newPage();
 const measured=new BrowserCoverage();
 await measured.start(page);
 await context.route('**/*',async route=>{
  if (new URL(route.request().url()).origin!==origin) return route.abort();
  if (route.request().url().includes('/passkeys/login/verify')) {
   try {
    const response=await route.fetch();
    await measured.checkpoint();
    return await route.fulfill({response});
   } catch {
    await route.abort();
    throw new Error('The disposable request or coverage checkpoint failed.');
   }
  }
  return route.continue();
 });
 const navigate=async (url)=>{await measured.checkpoint();return page.goto(url);};
 const errors=[];
 const navigations=[];
 page.on('response',response=>{
  if (response.request().isNavigationRequest()) {
   const navigation=`${response.request().method()} ${new URL(response.url()).pathname}: ${response.status()}`;
   navigations.push(navigation);
   if (process.env.SWSL_E2E_DEBUG) console.log(navigation);
  }
 });
 page.on('pageerror',error=>errors.push(error.message));
 if (process.env.SWSL_E2E_DEBUG) page.on('requestfailed',request=>console.log('Request failed:',new URL(request.url()).pathname,request.failure()?.errorText));
 if (process.env.SWSL_E2E_DEBUG) page.on('response',response=>{ if(response.url().includes('passkeys')) console.log(response.request().method(),response.status(),response.url().split('rest_route=')[1] || 'asset'); });
 await navigate(`${origin}/wp-login.php`);
 await page.locator('#user_login').fill('swsl-test-admin');
 await page.locator('#user_pass').fill('swsl-disposable-test-password');
 try {
  await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 } catch (error) {
  const loginError = await page.locator('#login_error').textContent({timeout:1000}).catch(()=>null);
  throw new Error(`Disposable WordPress login failed: ${loginError || new URL(page.url()).pathname}; ${navigations.join(', ')}`,{cause:error});
 }
 await navigate(`${origin}/wp-admin/admin.php?page=swsl-overview`);
 if (process.env.SWSL_SCREENSHOTS) await page.screenshot({path:`${process.env.SWSL_SCREENSHOTS}/swsl-settings.png`});
 await page.locator('input[name="enabled"]').check();
 await Promise.all([page.waitForURL('**/wp-admin/admin.php?page=swsl-overview'),page.locator('input#submit').click()]);
 await navigate(`${origin}/wp-admin/profile.php`);
 await page.locator('#swsl-passkeys').waitFor();
 const cdp=await context.newCDPSession(page);
 await cdp.send('WebAuthn.enable');
 let authenticator;
 const devices=[];
 for (const name of ['Laptop','Security key']) {
  if (authenticator) await cdp.send('WebAuthn.removeVirtualAuthenticator',{authenticatorId:authenticator});
  ({authenticatorId:authenticator}=await cdp.send('WebAuthn.addVirtualAuthenticator',{options:{protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true}}));
  await page.locator('#swsl-passkey-name').fill(name);
  await page.locator('#swsl-passkey-password').fill('swsl-disposable-test-password');
  await page.locator('#swsl-passkey-add').click();
  await page.waitForFunction(()=>!document.getElementById('swsl-passkey-add').disabled && document.getElementById('swsl-passkey-status').textContent !== '',{},{timeout:150000});
  assert.equal(await page.locator('#swsl-passkey-status').textContent(),'Passkey operation completed.');
  devices.push((await cdp.send('WebAuthn.getCredentials',{authenticatorId:authenticator})).credentials[0]);
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
 // An existing passkey can confirm management changes without creating a session.
 await page.locator('#swsl-passkey-proof').selectOption('passkey');
 await page.locator('#swsl-passkey-list input').first().fill('Renamed laptop');
 await page.locator('#swsl-passkey-list li').first().getByRole('button',{name:'Rename',exact:true}).click();
 await page.waitForFunction(()=>!document.getElementById('swsl-passkey-add').disabled);
 assert.equal(await page.locator('#swsl-passkey-list input').first().inputValue(),'Renamed laptop');
 await page.locator('#swsl-passkey-proof').selectOption('password');
 devices[1]=(await cdp.send('WebAuthn.getCredentials',{authenticatorId:authenticator})).credentials[0];
 let replay;
 page.on('request',request=>{
  if (request.method()==='POST' && request.url().includes('/passkeys/login/verify')) replay=request.postDataJSON();
 });
 for (const [index,device] of devices.entries()) {
  const logout=await page.locator('#wp-admin-bar-logout a').getAttribute('href');
  await navigate(logout);
  await page.locator('#user_login').waitFor();
  assert.equal((await context.cookies()).filter(cookie=>cookie.name.startsWith('wordpress_logged_in_')).length,0);
  if (replay) {
   const status=await page.evaluate(async ({url,body})=>(await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})).status,{url:origin+'/?rest_route=/sw-securelogin/v1/passkeys/login/verify',body:replay});
   assert.equal(status,400,'Replayed assertion must not authenticate a logged-out browser.');
  }
  await cdp.send('WebAuthn.removeVirtualAuthenticator',{authenticatorId:authenticator});
  ({authenticatorId:authenticator}=await cdp.send('WebAuthn.addVirtualAuthenticator',{options:{protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true}}));
  await cdp.send('WebAuthn.addCredential',{authenticatorId:authenticator,credential:device});
  if (index===0) {
   // Simulate a user cancel: the password form remains usable and no session appears.
   await page.locator('#user_login').fill('swsl-test-admin');
   await page.evaluate(()=>{window.swslOriginalGet=navigator.credentials.get.bind(navigator.credentials);navigator.credentials.get=async()=>{throw new DOMException('Cancelled','NotAllowedError');};});
   await page.locator('#swsl-passkey-login').click();
   await page.waitForFunction(()=>document.getElementById('swsl-login-status').textContent!=='' && !document.getElementById('swsl-passkey-login').disabled);
   assert.equal(await page.locator('#wp-submit').isEnabled(),true);
   assert.equal((await context.cookies()).filter(cookie=>cookie.name.startsWith('wordpress_logged_in_')).length,0);
   await page.evaluate(()=>{navigator.credentials.get=window.swslOriginalGet;delete window.swslOriginalGet;});
   // A valid signature cannot authenticate the wrong selected username.
   await page.locator('#user_login').fill('swsl-no-such-user');
   await page.locator('#swsl-passkey-login').click();
   await page.waitForFunction(()=>document.getElementById('swsl-login-status').textContent!=='' && !document.getElementById('swsl-passkey-login').disabled);
   assert.equal((await context.cookies()).filter(cookie=>cookie.name.startsWith('wordpress_logged_in_')).length,0);
  }
  await page.locator('#user_login').fill('swsl-test-admin');
  // Enter in passkey mode starts the ceremony instead of an empty password submission.
  await Promise.all([page.waitForURL('**/wp-admin/**'),index ? page.locator('#user_login').press('Enter') : page.locator('#swsl-passkey-login').click()]);
  const authCookies=(await context.cookies()).filter(cookie=>cookie.name.startsWith('wordpress_logged_in_'));
  assert.equal(authCookies.length,1);
  assert.equal(authCookies[0].secure,true);
  assert.equal(authCookies[0].httpOnly,true);
  const binding=(await context.cookies()).find(cookie=>cookie.name==='__Host-swsl_login');
  assert.equal(binding.secure && binding.httpOnly && binding.sameSite==='Strict' && binding.path==='/',true);
  await navigate(`${origin}/wp-admin/profile.php`);
  await page.locator('#swsl-passkey-list input').first().waitFor();
  assert.equal(await page.locator('#swsl-passkey-list input').count(),2);
 }
 console.log('PASS: login with each passkey, cancellation, wrong username, replay rejection and secure cookies.');
 await page.locator('#swsl-passkey-proof').selectOption('password');
 await page.locator('#swsl-passkey-password').fill('swsl-disposable-test-password');
 await page.locator('#swsl-passkey-list li').first().getByRole('button',{name:'Revoke',exact:true}).click();
 await page.waitForFunction(()=>!document.getElementById('swsl-passkey-add').disabled);
 assert.equal(await page.locator('#swsl-passkey-list input').count(),1);
 assert.equal(await page.locator('#swsl-passkey-list input').first().inputValue(),'Security key');
 assert.equal(await page.locator('#swsl-passkey-password').inputValue(),'');
 assert.deepEqual(errors,[]);
 console.log('PASS: two passkeys, rename, independent revocation, password clearing and no browser errors.');
 const logout=await page.locator('#wp-admin-bar-logout a').getAttribute('href');
 await navigate(logout);
 await cdp.send('WebAuthn.removeVirtualAuthenticator',{authenticatorId:authenticator});
 ({authenticatorId:authenticator}=await cdp.send('WebAuthn.addVirtualAuthenticator',{options:{protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true}}));
 await cdp.send('WebAuthn.addCredential',{authenticatorId:authenticator,credential:devices[0]});
 await page.locator('#user_login').fill('swsl-test-admin');
 await page.locator('#swsl-passkey-login').click();
 await page.waitForFunction(()=>document.getElementById('swsl-login-status').textContent!=='' && !document.getElementById('swsl-passkey-login').disabled);
 assert.equal((await context.cookies()).filter(cookie=>cookie.name.startsWith('wordpress_logged_in_')).length,0);
 // The unchanged password form remains the recovery path after revocation; it is hidden by default.
 assert.equal(await page.locator('#user_pass').isVisible(),false);
 assert.equal(await page.locator('#wp-submit').isVisible(),false);
 await page.locator('#swsl-password-login').click();
 assert.equal(await page.evaluate(()=>document.activeElement.id),'user_pass');
 assert.equal(await page.locator('#swsl-password-login').isVisible(),false);
 await page.locator('#user_pass').fill('swsl-disposable-test-password');
 await Promise.all([page.waitForURL('**/wp-admin/**'),page.locator('#wp-submit').click()]);
 console.log('PASS: revoked passkey cannot log in; password fallback still works.');
 // Disabling must remove routes and profile UI on subsequent requests without logging out.
 await navigate(`${origin}/wp-admin/admin.php?page=swsl-overview`);
 await page.locator('input[name="enabled"]').uncheck();
 await Promise.all([page.waitForURL('**/wp-admin/admin.php?page=swsl-overview'),page.locator('input#submit').click()]);
 await navigate(`${origin}/wp-admin/profile.php`);
 assert.equal(await page.locator('#swsl-passkeys').count(),0);
 const disabled=await context.newPage();
 await disabled.goto(`${origin}/wp-login.php`);
 assert.equal(await disabled.locator('#swsl-passkey-login').count(),0);
 await disabled.close();
 assert.equal(await page.locator('#your-profile').count(),1);
 console.log('PASS: module disable hides enrollment while preserving the WordPress session.');
 await measured.report();
} finally { await browser.close(); }
