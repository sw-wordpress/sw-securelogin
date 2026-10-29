/* global swslPasskeys */
(() => {
	'use strict';
	const panel = document.getElementById('swsl-passkeys');
	if (!panel) return;
	const status = document.getElementById('swsl-passkey-status');
	const password = document.getElementById('swsl-passkey-password');
	const list = document.getElementById('swsl-passkey-list');
	let busy = false;

	const decode = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), (char) => char.charCodeAt(0));
	const encode = (buffer) => {
		let binary = '';
		for (const byte of new Uint8Array(buffer)) binary += String.fromCharCode(byte);
		return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	};
	async function request(path = '', method = 'GET', body) {
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 20000);
		try {
			const response = await fetch(swslPasskeys.url + path, {
				method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': swslPasskeys.nonce },
				body: body === undefined ? undefined : JSON.stringify(body),
			});
			if (!response.ok) throw new Error(swslPasskeys.error);
			return await response.json();
		} finally { clearTimeout(timeout); }
	}
	async function proof() {
		const value = password.value;
		password.value = '';
		const result = await request('/reauthenticate', 'POST', { password: value });
		return result.grant;
	}
	async function run(action) {
		if (busy) return;
		busy = true;
		panel.setAttribute('aria-busy', 'true');
		panel.querySelectorAll('button').forEach((button) => { button.disabled = true; });
		status.textContent = '';
		try {
			await action();
			await refresh();
			status.textContent = swslPasskeys.done;
		} catch {
			status.textContent = swslPasskeys.error;
			password.focus();
		} finally {
			busy = false;
			panel.removeAttribute('aria-busy');
			panel.querySelectorAll('button').forEach((button) => { button.disabled = false; });
		}
	}
	async function refresh() {
		const result = await request();
		list.replaceChildren();
		if (!result.credentials.length) {
			const empty = document.createElement('li');
			empty.textContent = swslPasskeys.empty;
			list.append(empty);
		}
		for (const credential of result.credentials) {
			const item = document.createElement('li');
			const name = document.createElement('input');
			name.type = 'text'; name.value = credential.name; name.maxLength = 80;
			name.setAttribute('aria-label', `${swslPasskeys.name}: ${credential.name}`);
			const dates = document.createElement('span');
			dates.textContent = ` ${swslPasskeys.created}: ${credential.created_at}; ${swslPasskeys.lastUsed}: ${credential.last_used_at || swslPasskeys.never} `;
			item.append(name, dates);
			for (const operation of ['rename', 'revoke']) {
				const button = document.createElement('button');
				button.type = 'button'; button.className = 'button'; button.textContent = swslPasskeys[operation];
				button.addEventListener('click', () => run(async () => {
					const grant = await proof();
					const body = operation === 'rename' ? { grant, name: name.value } : { grant };
					await request(`/${credential.id}`, operation === 'rename' ? 'PATCH' : 'DELETE', body);
				}));
				item.append(button);
			}
			list.append(item);
		}
	}
	if (!window.isSecureContext || !window.PublicKeyCredential) {
		status.textContent = swslPasskeys.unsupported;
		document.getElementById('swsl-passkey-add').disabled = true;
		return;
	}
	document.getElementById('swsl-passkey-add').addEventListener('click', () => run(async () => {
		const grant = await proof();
		const options = await request('/registration/options', 'POST', { grant, name: document.getElementById('swsl-passkey-name').value });
		const publicKey = options.publicKey;
		publicKey.challenge = decode(publicKey.challenge);
		publicKey.user.id = decode(publicKey.user.id);
		publicKey.excludeCredentials = publicKey.excludeCredentials.map((credential) => ({ ...credential, id: decode(credential.id) }));
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 120000);
		let credential;
		try { credential = await navigator.credentials.create({ publicKey, signal: controller.signal }); }
		finally { clearTimeout(timeout); }
		if (!credential) throw new Error(swslPasskeys.error);
		await request('/registration/verify', 'POST', {
			transaction: options.transaction, rawId: encode(credential.rawId),
			clientDataJSON: encode(credential.response.clientDataJSON), attestationObject: encode(credential.response.attestationObject),
			transports: credential.response.getTransports ? credential.response.getTransports() : [],
		});
	}));
	refresh().catch(() => { status.textContent = swslPasskeys.error; });
})();
