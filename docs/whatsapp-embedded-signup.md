# WhatsApp Embedded Signup (standard Cloud API)

## Server environment

Set these values in the backend environment (never in frontend bundles):

- `WHATSAPP_META_APP_ID`: Meta App ID.
- `WHATSAPP_META_APP_SECRET`: Meta App Secret. Keep it server-only; do not send it to tenants or log it.
- `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID`: Embedded Signup configuration ID.
- `WHATSAPP_GRAPH_VERSION`: Graph API version; defaults to `v27.0`.
- `WHATSAPP_WEBHOOK_VERIFY_TOKEN`: one server-wide, high-entropy verification token shared with the Meta callback configuration.

The authenticated config endpoint reports whether the flow is enabled, plus App ID, configuration ID, and Graph version (never the App Secret). `POST /api/whatsapp/embedded-signup/complete` immediately exchanges the short-lived signup code and accepts only the standard `FINISH` event. Coexistence/other event types are deliberately rejected pending a dedicated implementation.

## Meta dashboard / operational setup still required

Configure the app's WhatsApp product Webhooks callback as the single public URL `https://<backend-host>/api/integrations/whatsapp/webhook`, with the server verify token above. Subscribe the app to the WhatsApp Business Account webhook fields required for account lifecycle (`account_update`) and message delivery (`messages`), and grant the permissions needed by the signup configuration (typically WhatsApp business management and messaging permissions). Use HTTPS. The callback rejects every POST lacking a valid `X-Hub-Signature-256` signed with the server-side Meta App Secret. Existing per-account `/{webhookKey}` callbacks remain supported for legacy setups.

A real end-to-end test may require Meta business verification, app roles/test assets, and/or App Review/advanced access for the relevant permissions. This backend does not assume the app is a Meta Tech Provider or that approval has been granted. Complete signup, subscription, phone registration, and webhook delivery should be verified with an eligible test business before production enablement.

Registration failures leave a non-active `setup_failed` account and encrypted business token/PIN available for a subsequent retry; no token or PIN is returned to the client. No automated reply or LLM behavior is enabled by this integration.
