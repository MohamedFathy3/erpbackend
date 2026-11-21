# Subdomain/Slug Tenant Launch

## Architecture

The primary public domain serves the marketing site and central login:

```text
https://fusionerp.systems
```

New workspaces are created under the primary tenant root. Existing workspaces remain addressable on the legacy root:

```text
https://acme.fusionerp.systems
https://beta.professionalacademyedu.com
```

The API resolves the current Tenant from the request Host using either configured root. `tenant_id` remains an additional database isolation layer and the authenticated user must belong to the Tenant represented by the subdomain. Signup uses `tenancy.root_domain`, whose default is now `fusionerp.systems`, to create future workspace URLs.

## Backend environment

Update the production environment with the following domain-related values. Keep the deployed API host in `APP_URL`; do not infer a new API hostname from the marketing-domain change. `FRONTEND_URL` is used for verification/password-reset links, so it must be the new frontend origin.

```env
APP_URL=https://<existing-api-host>
FRONTEND_URL=https://fusionerp.systems
TENANT_PRIMARY_ROOT_DOMAIN=fusionerp.systems
TENANT_ROOT_DOMAINS=fusionerp.systems,professionalacademyedu.com
# Legacy compatibility alias for deployments still reading the older setting:
TENANT_ROOT_DOMAIN=professionalacademyedu.com
CENTRAL_DOMAINS=fusionerp.systems,www.fusionerp.systems,admin.fusionerp.systems,professionalacademyedu.com,www.professionalacademyedu.com,admin.professionalacademyedu.com,<existing-central-hosts>
SANCTUM_STATEFUL_DOMAINS=fusionerp.systems,*.fusionerp.systems,professionalacademyedu.com,*.professionalacademyedu.com,<existing-stateful-hosts>
# Retain the currently reviewed production value; do not change it as part of the domain-code update.
SESSION_DOMAIN=<current-value-or-unset>
```

`TENANT_PRIMARY_ROOT_DOMAIN` controls new workspace creation. `TENANT_ROOT_DOMAINS` controls the roots accepted by tenant resolution, CORS, and Sanctum; retain both roots so existing workspaces keep working. The older `TENANT_ROOT_DOMAIN` is retained as a compatibility alias and should not override the primary setting. `SESSION_DOMAIN` is intentionally not changed by this migration: a single cookie-domain setting cannot span both registrable domains, and the correct value depends on the deployed login/session flow. Review cookie sharing for both roots before changing it. After domain-related environment updates, clear/rebuild Laravel's configuration cache and restart long-lived PHP/queue processes.

For local testing, the backend accepts `X-Tenant-Slug` only when `APP_ENV=local`. The frontend can send it by setting:

```env
VITE_TENANT_SLUG=acme
VITE_PRIMARY_TENANT_ROOT_DOMAIN=fusionerp.systems
VITE_TENANT_ROOT_DOMAINS=fusionerp.systems,professionalacademyedu.com
```

In production, use real subdomains and do not rely on the header. Keep `VITE_API_URL` pointed at the actual deployed API host; this migration does not change it.

## Request behavior

`ResolveTenant` runs globally on API middleware. Central hosts do not select a Tenant. A single-label subdomain under any configured tenant root resolves `tenants.slug`; unknown slugs return `tenant_not_found`. An authenticated account on a different Tenant returns `tenant_mismatch`. Non-Super-Admin authenticated calls from a central host return `tenant_subdomain_required`.

`EnsureTenantSubscriptionActive` also runs globally for API requests and blocks expired or suspended workspaces. Super Admin requests bypass the subscription lock. CORS and Sanctum stateful matching must allow both the primary root and the legacy root, including tenant subdomains.

## DNS, Cloudflare, and TLS

Configure the apex and wildcard records for both roots to reach the correct frontend ingress/reverse proxy:

```text
fusionerp.systems                  A/CNAME <frontend-host>
*.fusionerp.systems                 A/CNAME <frontend-host>
professionalacademyedu.com          A/CNAME <existing-frontend-host>
*.professionalacademyedu.com        A/CNAME <existing-frontend-host>
```

The TLS certificates must cover the apex and wildcard for both roots. The reverse proxy must forward the original `Host` header to Laravel and serve the React build for all tenant subdomains. Review Cloudflare zone, proxy, WAF, and host/origin rules; DNS resolving to Cloudflare does not prove that the origin is reachable. The last connectivity probe for `fusionerp.systems` and a sample tenant hostname reached Cloudflare but returned HTTP 403, so the new domain is not confirmed ready until the routing/403 is resolved and validated externally.

## Deploy commands

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan queue:work database --queue=default --tries=3 --sleep=3
php artisan schedule:work
```

Run one queue worker and one scheduler under Supervisor/systemd. The Laravel health endpoint is `/up`.

## Frontend behavior

On the primary central host, `/` renders Marketing. On a Tenant subdomain, `/` renders the protected Dashboard. Signup returns a Tenant-specific login URL under `<slug>.fusionerp.systems`. Existing tenant URLs under `professionalacademyedu.com` remain valid, and frontend auth logic stays on an already-open tenant host so its host-only session cookie is not lost.

## Verification checklist

1. Run `php artisan migrate:status` and confirm all migrations are `Ran`.
2. Submit Signup from `https://fusionerp.systems` and confirm the `login_url` uses `<slug>.fusionerp.systems`.
3. Open the new workspace URL and log in.
4. Confirm `/api/me/enabled-modules` and CRM requests resolve the Tenant from Host.
5. Open an existing `professionalacademyedu.com` tenant and confirm its data and sign-in still work.
6. Open another Tenant subdomain with the first account and confirm `tenant_mismatch`.
7. Confirm an expired Trial returns `trial_expired` and Super Admin remains accessible.
8. Confirm queue and scheduler processes are running.
9. Verify the frontend, API, DNS, Cloudflare rules, reverse proxy, and TLS independently; a code change alone is not proof of deployment readiness.
