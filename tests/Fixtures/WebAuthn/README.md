# Chromium public WebAuthn fixture

chromium.json was captured with Chromium's CDP virtual CTAP2 authenticator (internal
transport, resident keys and user verification enabled). The test page at
https://example.test was fulfilled locally by Playwright, without contacting a server.
Creation used a random 32-byte challenge/handle, RP example.test, ES256 and attestation
none. A subsequent get() used the same challenge and credential ID and required UV.
Binary response fields are unpadded base64url. No private authenticator key is included.

Unit tests mutate these public bytes to exercise rejected enrollments. The staged
Mozart build checks the real signed assertion and rejects a modified signature.
The live E2E suite independently creates fresh credentials through actual WordPress
REST routes and two separate virtual authenticators; it does not replay this fixture.
