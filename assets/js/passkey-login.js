/* global swslLogin, swslAssertion */
(() => {
	'use strict';
	const button = document.getElementById('swsl-passkey-login');
	const status = document.getElementById('swsl-login-status');
	const username = document.getElementById('user_login');
	const form = document.getElementById('loginform');
	const toggle = document.getElementById('swsl-password-login');
	if (!button || !status || !username) return;
	const passkeyMode = () => form?.classList.contains('swsl-passkey-mode');
	const password = document.getElementById('user_pass');
	const showPassword = () => {
		form?.classList.remove('swsl-passkey-mode');
		if (toggle) toggle.hidden = true;
		// Core user-profile.js disables password inputs that are hidden on load.
		if (password) password.disabled = false;
	};
	toggle?.addEventListener('click', () => {
		showPassword();
		(username.value.trim() && password ? password : username).focus();
	});
	// Enter in passkey mode starts the ceremony instead of an empty password submission.
	form?.addEventListener('submit', (event) => {
		if (!passkeyMode()) return;
		event.preventDefault();
		button.click();
	});
	// Core login errors come from the password path; keep that form open for the retry.
	if (form && toggle && !document.getElementById('login_error')) {
		form.classList.add('swsl-passkey-mode');
		toggle.hidden = false;
	}
	if (!window.isSecureContext || !window.PublicKeyCredential) {
		showPassword();
		button.disabled = true;
		status.textContent = swslLogin.unsupported;
		return;
	}
	let busy = false;
	async function request(path, body) {
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 20000);
		try {
			const response = await fetch(swslLogin.url + path, {
				method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
				headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
			});
			if (!response.ok) throw new Error(swslLogin.error);
			return await response.json();
		} finally { clearTimeout(timeout); }
	}
	button.addEventListener('click', async () => {
		if (busy) return;
		if (!username.value.trim()) {
			status.textContent = swslLogin.username;
			username.focus();
			return;
		}
		busy = true;
		button.disabled = true;
		button.setAttribute('aria-busy', 'true');
		status.textContent = '';
		try {
			const options = await request('/options', {
				username: username.value, remember: Boolean(document.getElementById('rememberme')?.checked),
				redirect: document.querySelector('#loginform input[name="redirect_to"]')?.value || '',
			});
			const result = await request('/verify', await swslAssertion(options));
			const target = new URL(result.redirect, window.location.href);
			if (target.origin !== window.location.origin) throw new Error(swslLogin.error);
			window.location.assign(target.href);
		} catch {
			status.textContent = swslLogin.error;
			button.focus();
		} finally {
			busy = false;
			button.disabled = false;
			button.removeAttribute('aria-busy');
		}
	});
})();
