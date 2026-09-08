# Subdomain/Slug Tenant Launch

## Architecture

The central domain serves the public marketing website:

```text
https://example.com
```

Each workspace is served from its Tenant slug:

```text
https://acme.example.com
https://beta.example.com
```

The API resolves the current Tenant from the request Host. `tenant_id` remains an additional database isolation layer and the authenticated user must belong to the Tenant represented by the subdomain.

## Backend environment

```env
APP_URL=https://api.example.com
FRONTEND_URL=https://example.com
TENANT_ROOT_DOMAIN=example.com
CENTRAL_DOMAINS=example.com,www.example.com,admin.example.com
```

For local testing, the backend accepts `X-Tenant-Slug` only when `APP_ENV=local`. The Frontend can send it by setting:

```env
VITE_TENANT_SLUG=acme
VITE_TENANT_ROOT_DOMAIN=example.com
```

In production, use real subdomains and do not rely on the header.

## Request behavior

`ResolveTenant` runs globally on API middleware. Central hosts do not select a Tenant. A subdomain host resolves `tenants.slug`; unknown slugs return `tenant_not_found`. An authenticated account on a different Tenant returns `tenant_mismatch`. Non-Super-Admin authenticated calls from a central host return `tenant_subdomain_required`.

`EnsureTenantSubscriptionActive` also runs globally for API requests and blocks expired or suspended workspaces. Super Admin requests bypass the subscription lock.

## DNS and TLS

Create both records:

```text
example.com       A/CNAME <frontend-host>
*.example.com     A/CNAME <frontend-host>
```

The TLS certificate must cover `example.com` and `*.example.com`. The reverse proxy must forward the original `Host` header to Laravel and serve the same React build for all Tenant subdomains.

## Deploy commands

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan queue:work database --queue=default --tries=3 --sleep=3
php artisan schedule:work
```

Run one queue worker and one scheduler under Supervisor/systemd. The Laravel health endpoint is `/up`.

## Frontend behavior

On the central host, `/` renders Marketing. On a Tenant subdomain, `/` renders the protected Dashboard. Signup returns a Tenant-specific login URL and redirects the browser to `<slug>.<root-domain>/auth`.

## Verification checklist

1. Run `php artisan migrate:status` and confirm all migrations are `Ran`.
2. Submit Signup from the central domain.
3. Confirm the response contains a Tenant-specific `login_url`.
4. Open that URL and log in.
5. Confirm `/api/me/enabled-modules` and CRM requests use the Tenant from Host.
6. Open another Tenant subdomain with the first account and confirm `tenant_mismatch`.
7. Confirm an expired Trial returns `trial_expired` and Super Admin remains accessible.
8. Confirm queue and scheduler processes are running.
