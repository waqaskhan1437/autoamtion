# Online Deployment

This repo now supports two online deployment models:

1. `PHP + MySQL` full panel on a normal 24/7 host
2. `Cloudflare Worker + D1` control-plane for online auth/queueing, while the heavy automation still runs on the customer's PC through the local agent

Do not use GitHub Pages for this repository because GitHub Pages is for static sites only.

## Recommended setup

1. Push code to GitHub.
2. Deploy the repo to a 24/7 host that supports Docker.
3. Attach a MySQL database.
4. Point a domain/subdomain to the app.
5. In the app, set `Settings -> GitHub Runner -> Public Panel Base URL`.

## Environment variables

Set these on your host:

- `VW_DB_HOST`
- `VW_DB_NAME`
- `VW_DB_USER`
- `VW_DB_PASS`
- `VW_APP_ACCESS_PASSWORD`
- `VW_DEFAULT_ADMIN_EMAIL`
- `VW_DEFAULT_ADMIN_PASSWORD`
- `VW_BASE_DATA_DIR`
- `VW_OPENAI_API_KEY` if needed

Example:

```env
VW_DB_HOST=your-mysql-host
VW_DB_NAME=video_workflow
VW_DB_USER=video_workflow_user
VW_DB_PASS=change-me
VW_APP_ACCESS_PASSWORD=change-me-now
VW_DEFAULT_ADMIN_EMAIL=admin@example.com
VW_DEFAULT_ADMIN_PASSWORD=change-me-now
VW_BASE_DATA_DIR=/var/app/data
```

## Railway

Recommended simple stack:

1. Create a new Railway project from this GitHub repo.
2. Deploy the app using the included `Dockerfile`.
3. Add a MySQL database service.
4. Copy the MySQL connection values into the app environment variables above.
5. Add a custom domain to the web service.

## Render / VPS

You can also deploy the same Docker image on Render, DigitalOcean, Hetzner, Hostinger VPS, or any server with Docker.

## Cloudflare Worker

If you want a mostly free online control-plane:

1. Go to [cloudflare-worker](C:\xampp\htdocs\autoamtion-main\cloudflare-worker)
2. `cd cloudflare-worker`
3. `npm install`
4. Create/bind a D1 database in `wrangler.jsonc`
5. Run `npm run db:apply:remote`
6. From repo root, deploy with `npm run cf:deploy`
7. Set `SESSION_SECRET`, `AGENT_PACKAGE_URL` or `GITHUB_REPO_SLUG`, and admin vars in Wrangler

Important:

- this Worker panel handles login, users, magic links, agent pairing, queueing, and status
- local video processing still happens through the PHP local agent on the target Windows PC
- if you want online downloadable outputs, add an R2 binding named `OUTPUTS`

Detailed Worker notes are in [README.md](C:\xampp\htdocs\autoamtion-main\cloudflare-worker\README.md).

## Important note

GitHub should be your source code host.
The live panel should run on a real host, not on GitHub Pages.
