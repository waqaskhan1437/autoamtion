# Cloudflare Worker Control Plane

This folder contains a Cloudflare Worker version of the control plane for:

- admin and user login
- one-time magic login links
- local agent pairing and polling
- automation storage and manual queueing
- optional output metadata tracking

## What this does

The Worker replaces the always-online panel/API layer. The heavy video processing still runs on the customer's PC through the existing PHP local agent.

## Quick start

1. `cd cloudflare-worker`
2. `npm install`
3. Create a D1 database and replace `database_id` in `wrangler.jsonc`
4. Apply the schema:
   - `npm run db:apply:local`
   - or `npm run db:apply:remote`
5. Start local dev:
   - `npm run dev`

For Cloudflare Git deploys from the repository root, use:

- deploy command: `npm run cf:deploy`

Default admin credentials in local dev:

- email: `admin@local`
- password: `ChangeMe@123`

Change these through Wrangler vars or secrets before production.

## Important vars

- `SESSION_SECRET`
- `DEFAULT_ADMIN_EMAIL`
- `DEFAULT_ADMIN_PASSWORD`
- `DEFAULT_PAIRING_TOKEN`
- `AGENT_PACKAGE_URL`
- `GITHUB_REPO_SLUG`
- `GITHUB_REF`
- `PHP_WINDOWS_ZIP_URL`

## Agent package URL

Set `AGENT_PACKAGE_URL` to a zip that contains this repo. If it is blank and `GITHUB_REPO_SLUG` is set, the Worker falls back to:

`https://github.com/<slug>/archive/refs/heads/<ref>.zip`

## Windows install command

After deployment, admins can copy the command from the `/admin/agents` page. The same pattern is:

```powershell
$p=Join-Path $env:TEMP 'video-workflow-agent-install.ps1'
Invoke-WebRequest 'https://your-worker-domain/install/windows.ps1?pairing_token=PAIRING_TOKEN' -OutFile $p
powershell -ExecutionPolicy Bypass -File $p -CreateScheduledTask
```

## Optional output storage

The Worker accepts output upload callbacks even without R2. In that case it stores metadata only. If you want downloadable online outputs, add an R2 binding named `OUTPUTS` and set `R2_PUBLIC_BASE_URL` if you serve files through a public domain.
