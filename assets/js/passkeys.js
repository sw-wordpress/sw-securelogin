/* global swslPasskeys, swslAssertion */
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
		if (document.getElementById('swsl-passkey-proof').value === 'passkey') {
			password.value = '';
			const options = await request('/reauthentication/options', 'POST', {});
			const result = await request('/reauthentication/verify', 'POST', await swslAssertion(options));
			return result.grant;
		}
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
		status.dataset.state = 'busy';
		try {
			await action();
			await refresh();
			status.textContent = swslPasskeys.done;
			status.dataset.state = 'success';
		} catch {
			status.textContent = swslPasskeys.error;
			status.dataset.state = 'error';
			if (!document.getElementById('swsl-passkey-password-fields').hidden) password.focus();
			else document.getElementById('swsl-passkey-proof').focus();
		} finally {
			busy = false;
			panel.removeAttribute('aria-busy');
			panel.querySelectorAll('button').forEach((button) => { button.disabled = false; });
		}
	}
	async function refresh() {
		const result = await request();
		list.replaceChildren();
		document.getElementById('swsl-passkey-count').textContent = String(result.credentials.length);
		if (!result.credentials.length) {
			const empty = document.createElement('li');
			empty.className = 'swsl-passkeys-empty';
			const icon = document.createElement('span');
			icon.className = 'dashicons dashicons-lock'; icon.setAttribute('aria-hidden', 'true');
			const message = document.createElement('p'); message.textContent = swslPasskeys.empty;
			empty.append(icon, message);
			list.append(empty);
		}
		for (const credential of result.credentials) {
			const item = document.createElement('li');
			item.className = 'swsl-passkeys-item';
			const details = document.createElement('div'); details.className = 'swsl-passkeys-details';
			const name = document.createElement('input');
			name.type = 'text'; name.value = credential.name; name.maxLength = 80;
			name.setAttribute('aria-label', `${swslPasskeys.name}: ${credential.name}`);
			const dates = document.createElement('dl'); dates.className = 'swsl-passkeys-dates';
			for (const [label, value] of [[swslPasskeys.created, credential.created_at], [swslPasskeys.lastUsed, credential.last_used_at || swslPasskeys.never]]) {
				const term = document.createElement('dt'); term.textContent = label;
				const description = document.createElement('dd'); description.textContent = value;
				dates.append(term, description);
			}
			details.append(name, dates);
			const actions = document.createElement('div'); actions.className = 'swsl-passkeys-actions';
			item.append(details, actions);
			for (const operation of ['rename', 'revoke']) {
				const button = document.createElement('button');
				button.type = 'button'; button.className = operation === 'revoke' ? 'button swsl-passkey-revoke' : 'button'; button.textContent = swslPasskeys[operation];
				button.addEventListener('click', () => run(async () => {
					const grant = await proof();
					const body = operation === 'rename' ? { grant, name: name.value } : { grant };
					await request(`/${credential.id}`, operation === 'rename' ? 'PATCH' : 'DELETE', body);
				}));
				actions.append(button);
			}
			list.append(item);
		}
	}
	if (!window.isSecureContext || !window.PublicKeyCredential) {
		status.textContent = swslPasskeys.unsupported;
		document.getElementById('swsl-passkey-add').disabled = true;
		return;
	}
	document.getElementById('swsl-passkey-proof').addEventListener('change', (event) => {
		password.value = '';
		document.getElementById('swsl-passkey-password-fields').hidden = event.target.value === 'passkey';
	});
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
	refresh().catch(() => {
		status.textContent = swslPasskeys.error;
		status.dataset.state = 'error';
	});
})();
