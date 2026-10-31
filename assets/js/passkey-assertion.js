/* Shared browser transport; all identity and signature checks run on the server. */
(() => {
	'use strict';
	const decode = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), (char) => char.charCodeAt(0));
	const encode = (buffer) => {
		let binary = '';
		for (const byte of new Uint8Array(buffer)) binary += String.fromCharCode(byte);
		return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	};
	window.swslAssertion = async (options) => {
		const publicKey = { ...options.publicKey, challenge: decode(options.publicKey.challenge) };
		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 120000);
		try {
			const credential = await navigator.credentials.get({ publicKey, signal: controller.signal });
			if (!credential || credential.type !== 'public-key' || !credential.response.userHandle) throw new Error('No assertion.');
			return {
				transaction: options.transaction, rawId: encode(credential.rawId), userHandle: encode(credential.response.userHandle),
				clientDataJSON: encode(credential.response.clientDataJSON), authenticatorData: encode(credential.response.authenticatorData),
				signature: encode(credential.response.signature),
			};
		} finally { clearTimeout(timeout); }
	};
})();
