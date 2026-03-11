import { renderWindowsInstallScript } from './install-script.js'
import { bootstrapSchemaStatements } from './schema.js'

const textEncoder = new TextEncoder()
const sessionCookieName = 'vw_session'
const defaultSessionTtlSeconds = 60 * 60 * 24 * 7
const maxPasswordIterations = 100000
const passwordIterations = maxPasswordIterations
let schemaReadyPromise = null

const settingsTabFieldMap = {
  bunny: ['bunny_api_key', 'bunny_library_id', 'bunny_storage_zone', 'bunny_storage_password'],
  stream: [
    'youtube_api_key', 'youtube_client_id', 'youtube_client_secret',
    'tiktok_client_key', 'tiktok_client_secret',
    'instagram_app_id', 'instagram_app_secret',
    'facebook_app_id', 'facebook_app_secret'
  ],
  ftp: ['ftp_host', 'ftp_port', 'ftp_username', 'ftp_password', 'ftp_path'],
  openai: ['ai_provider', 'gemini_api_key', 'openai_api_key', 'default_language'],
  ffmpeg: ['ffmpeg_path', 'auto_install_local_runtime', 'ffmpeg_auto_download_url_windows'],
  storage: ['storage_base_path', 'panel_public_base_url', 'ytdlp_cookies_file', 'ytdlp_cookies_browser', 'ytdlp_cookies_browser_profile'],
  github_runner: [
    'github_runner_enabled', 'github_runner_owner', 'github_runner_repo', 'github_runner_workflow', 'github_runner_ref',
    'github_runner_token', 'github_runner_callback_secret', 'github_runner_inputs_json', 'panel_public_base_url'
  ],
  postforme: ['postforme_api_key', 'postforme_project_type']
}

const settingsCheckboxFields = new Set(['auto_install_local_runtime', 'github_runner_enabled'])
const legacyRouteAliases = new Map([
  ['/index.php', '/dashboard'],
  ['/automation.php', '/automation'],
  ['/settings.php', '/settings'],
  ['/api-keys.php', '/api-keys'],
  ['/users.php', '/admin/users'],
  ['/agents.php', '/admin/agents'],
  ['/player.php', '/player'],
  ['/jobs.php', '/jobs'],
  ['/magic-login.php', '/magic-login'],
  ['/login.php', '/login'],
  ['/logout.php', '/logout'],
  ['/api/scheduled-posts.php', '/api/scheduled-posts'],
  ['/api/delete-scheduled-post.php', '/api/delete-scheduled-post'],
  ['/api/delete-all-scheduled-posts.php', '/api/delete-all-scheduled-posts']
])

export default {
  async fetch(request, env) {
    try {
      const url = new URL(request.url)
      const rawPath = normalizePath(url.pathname)
      const path = resolveWorkerPath(rawPath)

      await ensureSchema(env)
      await ensureDefaultAdmin(env)
      await ensurePairingToken(env)

      if (path === '/api/health') {
        return jsonResponse({ success: true, now: isoNow() })
      }

      if (
        path === '/api/agent/install-manifest' ||
        path === '/api/agent/install-manifest.php' ||
        path === '/api/agent-install-manifest.php'
      ) {
        return handleInstallManifest(request, env)
      }

      if (path === '/install/windows.ps1') {
        return handleWindowsInstaller(request, env)
      }

      if (
        path === '/api/agent/register' ||
        path === '/api/agent/register.php' ||
        path === '/api/agent-register.php' ||
        path === '/api/agent/poll' ||
        path === '/api/agent/poll.php' ||
        path === '/api/agent-poll.php' ||
        path === '/api/agent/report' ||
        path === '/api/agent/report.php' ||
        path === '/api/agent-report.php' ||
        path === '/api/agent/complete' ||
        path === '/api/agent/complete.php' ||
        path === '/api/agent-complete.php' ||
        path === '/api/agent-upload-output.php'
      ) {
        return handleAgentApi(request, env, path)
      }

      const session = await getSessionContext(request, env)

      if (path === '/login' && request.method === 'GET') {
        if (session.user) {
          return redirectResponse(session.user.role === 'admin' ? legacyPageHref('/dashboard') : legacyPageHref('/automation'))
        }
        return renderLoginPage(request, env, null)
      }

      if (path === '/login' && request.method === 'POST') {
        return handleLogin(request, env)
      }

      if (path === '/logout' && request.method === 'POST') {
        return handleLogout(request)
      }

      if (path === '/magic-login' && request.method === 'GET') {
        return handleMagicLogin(request, env)
      }

      if (path === '/api/automation-run' && request.method === 'POST') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleAutomationRunApi(request, env, session.user)
      }

      if ((rawPath === '/api/start-automation.php' || rawPath === '/run-automation-ajax.php') && ['GET', 'POST'].includes(request.method)) {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleLegacyStartAutomationApi(request, env, session.user)
      }

      if (path === '/api/automation-status' && request.method === 'GET') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleAutomationStatusApi(request, env, session.user)
      }

      if (rawPath === '/api/check-progress.php' && request.method === 'GET') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleLegacyCheckProgressApi(request, env, session.user)
      }

      if (rawPath === '/api/list-output-videos.php' && request.method === 'GET') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleLegacyListOutputVideosApi(request, env, session.user)
      }

      if (rawPath === '/api/stream-github-video.php' && request.method === 'GET') {
        if (!session.user) {
          return textResponse('Authentication required.', 401, { 'Content-Type': 'text/plain; charset=utf-8' })
        }
        return handleLegacyStreamGithubVideoApi(request, env, session.user)
      }

      if (rawPath === '/api/delete-all-output-videos.php' && request.method === 'POST') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleLegacyDeleteAllOutputVideosApi(request, env, session.user)
      }

      if (path === '/api/scheduled-posts' && request.method === 'GET') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleScheduledPostsApi(request, env, session.user)
      }

      if (path === '/api/delete-scheduled-post' && request.method === 'POST') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleDeleteScheduledPostApi(request, env, session.user)
      }

      if (path === '/api/delete-all-scheduled-posts' && request.method === 'POST') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleDeleteAllScheduledPostsApi(request, env, session.user)
      }

      if (rawPath === '/api/cron.php' && ['GET', 'POST'].includes(request.method)) {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleLegacyCronApi(request, env, session.user)
      }

      if (rawPath === '/api/seed-demo.php' && request.method === 'GET') {
        if (!session.user) {
          return redirectResponse('/login?next=' + encodeURIComponent('/index.php'))
        }
        return handleSeedDemoApi(request, env, session.user)
      }

      if (path === '/' && !session.user) {
        return redirectResponse('/login')
      }

      if (path === '/') {
        return redirectResponse(session.user.role === 'admin' ? legacyPageHref('/dashboard') : legacyPageHref('/automation'))
      }

      if (!session.user) {
        return redirectResponse('/login?next=' + encodeURIComponent(path))
      }

      if (path === '/dashboard' && request.method === 'GET') {
        if (session.user.role !== 'admin') {
          return redirectResponse(legacyPageHref('/automation'))
        }
        return renderDashboardPage(request, env, session.user, null)
      }

      if (path === '/dashboard' && request.method === 'POST') {
        if (session.user.role !== 'admin') {
          return redirectResponse(legacyPageHref('/automation'))
        }
        return handleAutomationAction(request, env, session.user)
      }

      if (path === '/jobs' && request.method === 'GET') {
        return renderJobsPage(request, env, session.user, null)
      }

      if (path === '/automation' && request.method === 'GET') {
        return renderAutomationPage(request, env, session.user, null)
      }

      if (path === '/automation' && request.method === 'POST') {
        return handleAutomationAction(request, env, session.user)
      }

      if (path === '/settings' && request.method === 'GET') {
        requireAdmin(session.user)
        return renderSettingsPage(request, env, session.user, null)
      }

      if (path === '/settings' && request.method === 'POST') {
        requireAdmin(session.user)
        return handleSettingsAction(request, env, session.user)
      }

      if (path === '/api-keys' && request.method === 'GET') {
        requireAdmin(session.user)
        return renderApiKeysPage(request, env, session.user, null)
      }

      if (path === '/api-keys' && request.method === 'POST') {
        requireAdmin(session.user)
        return handleApiKeysAction(request, env, session.user)
      }

      if (path === '/admin/users' && request.method === 'GET') {
        requireAdmin(session.user)
        return renderUsersPage(request, env, session.user, null)
      }

      if (path === '/admin/users' && request.method === 'POST') {
        requireAdmin(session.user)
        return handleUsersAction(request, env, session.user)
      }

      if (path === '/admin/agents' && request.method === 'GET') {
        requireAdmin(session.user)
        return renderAgentsPage(request, env, session.user, null)
      }

      if (path === '/admin/agents' && request.method === 'POST') {
        requireAdmin(session.user)
        return handleAgentsAction(request, env, session.user)
      }

      if (path === '/player' && request.method === 'GET') {
        return renderPlayerPage(request, env, session.user, null)
      }

      const outputMatch = path.match(/^\/outputs\/(\d+)$/)
      if (outputMatch && request.method === 'GET') {
        return handleOutputDownload(request, env, session.user, toInt(outputMatch[1]))
      }

      return htmlResponse(renderPage({
        title: 'Not Found',
        user: session.user,
        body: `<section class="panel"><h1>Not Found</h1><p class="muted">The requested route does not exist.</p></section>`
      }), 404)
    } catch (error) {
      return handleTopLevelError(request, error)
    }
  }
}

function handleTopLevelError(request, error) {
  const message = error instanceof Error ? error.message : 'Unexpected error'
  const acceptsJson = (request.headers.get('accept') || '').includes('application/json')
  if (acceptsJson || normalizePath(new URL(request.url).pathname).startsWith('/api/')) {
    return jsonResponse({ success: false, error: message }, 500)
  }
  return htmlResponse(renderPage({
    title: 'Error',
    user: null,
    body: `<section class="panel"><h1>Error</h1><p class="muted">${escapeHtml(message)}</p></section>`
  }), 500)
}

async function handleLogin(request, env) {
  const form = await request.formData()
  const email = normalizeEmail(form.get('email'))
  const password = String(form.get('password') || '')
  const next = sanitizeRedirectPath(String(form.get('next') || '/dashboard'))

  const user = await getUserByEmail(env, email)
  if (!user || user.status !== 'active' || !await verifyPassword(password, user.password_hash)) {
    return renderLoginPage(request, env, 'Email or password is invalid.')
  }

  await env.DB.prepare('UPDATE app_users SET last_login_at = ? WHERE id = ?').bind(isoNow(), user.id).run()
  return createLoginRedirect(request, env, user, next)
}

async function handleLogout(request) {
  const next = sanitizeRedirectPath(new URL(request.url).searchParams.get('next') || '/login')
  return redirectResponse(next, 303, {
    'Set-Cookie': buildExpiredSessionCookie(shouldUseSecureCookies(request))
  })
}

async function handleMagicLogin(request, env) {
  const url = new URL(request.url)
  const token = String(url.searchParams.get('token') || '').trim()
  const client = String(url.searchParams.get('client') || '').trim()

  if (token === '') {
    return htmlResponse(renderPage({
      title: 'Magic Login',
      user: null,
      body: `<section class="panel"><h1>Magic Link Invalid</h1><p class="muted">Token is missing.</p></section>`
    }), 400)
  }

  const result = await consumeMagicLink(env, token, client)
  if (!result.success) {
    return htmlResponse(renderPage({
      title: 'Magic Login',
      user: null,
      body: `<section class="panel"><h1>Magic Link Invalid</h1><p class="muted">${escapeHtml(result.error)}</p></section>`
    }), 400)
  }

  return createLoginRedirect(request, env, result.user, sanitizeRedirectPath(result.redirectPath || '/dashboard'))
}

async function createLoginRedirect(request, env, user, nextPath) {
  const token = await signSessionCookie({
    user_id: user.id,
    role: user.role,
    exp: Math.floor(Date.now() / 1000) + defaultSessionTtlSeconds
  }, env)
  const requestedNext = sanitizeRedirectPath(nextPath || '/dashboard')
  const effectiveNext = user.role === 'admin'
    ? requestedNext
    : (resolveWorkerPath(normalizePath(requestedNext.split('?')[0])) === '/dashboard'
        ? legacyPageHref('/automation')
        : requestedNext)

  return redirectResponse(effectiveNext, 303, {
    'Set-Cookie': buildSessionCookie(token, shouldUseSecureCookies(request))
  })
}

async function handleUsersAction(request, env, adminUser) {
  const form = await request.formData()
  const action = String(form.get('action') || '')

  if (action === 'create_user') {
    const email = normalizeEmail(form.get('email'))
    const password = String(form.get('password') || '').trim() || randomHex(6)
    const displayName = String(form.get('display_name') || '').trim()
    const role = String(form.get('role') || 'user') === 'admin' ? 'admin' : 'user'
    const status = String(form.get('status') || 'active') === 'disabled' ? 'disabled' : 'active'
    const canUseGithubRunner = checkboxValue(form.get('can_use_github_runner'))
    const assignedLocalAgentId = toInt(form.get('assigned_local_agent_id'))
    const requestedSlug = String(form.get('client_slug') || '').trim()

    if (email === '') {
      return renderUsersPage(request, env, adminUser, { error: 'Email and password are required.' })
    }

    const userId = await createUser(env, {
      email,
      password,
      displayName,
      role,
      status,
      canUseGithubRunner,
      assignedLocalAgentId,
      clientSlug: requestedSlug
    })

    return renderUsersPage(request, env, adminUser, { success: `User #${userId} created. Client login: ${email} / ${password}` })
  }

  if (action === 'update_user') {
    const userId = toInt(form.get('user_id'))
    const user = await getUserById(env, userId)
    if (!user) {
      return renderUsersPage(request, env, adminUser, { error: 'User not found.' })
    }

    const email = normalizeEmail(form.get('email'))
    const displayName = String(form.get('display_name') || '').trim()
    const role = String(form.get('role') || user.role) === 'admin' ? 'admin' : 'user'
    const status = String(form.get('status') || user.status) === 'disabled' ? 'disabled' : 'active'
    const canUseGithubRunner = checkboxValue(form.get('can_use_github_runner'))
    const assignedLocalAgentId = toNullableInt(form.get('assigned_local_agent_id'))
    const requestedSlug = String(form.get('client_slug') || '').trim()
    const password = String(form.get('password') || '').trim()

    if (email === '') {
      return renderUsersPage(request, env, adminUser, { error: 'Email is required.' })
    }

    const existing = await getUserByEmail(env, email)
    if (existing && Number(existing.id) !== Number(userId)) {
      return renderUsersPage(request, env, adminUser, { error: 'Another user already uses this email.' })
    }

    const slug = await ensureUniqueClientSlug(env, requestedSlug || displayName || email.split('@')[0], userId)
    const now = isoNow()
    await env.DB.prepare(`
      UPDATE app_users
      SET email = ?, display_name = ?, client_slug = ?, role = ?, status = ?,
          can_use_github_runner = ?, assigned_local_agent_id = ?, updated_at = ?
      WHERE id = ?
    `).bind(
      email,
      displayName || null,
      slug,
      role,
      status,
      canUseGithubRunner ? 1 : 0,
      assignedLocalAgentId,
      now,
      userId
    ).run()

    if (password !== '') {
      await env.DB.prepare('UPDATE app_users SET password_hash = ?, updated_at = ? WHERE id = ?').bind(
        await hashPassword(password),
        now,
        userId
      ).run()
    }

    return renderUsersPage(request, env, adminUser, {
      success: password !== '' ? `User ${email} updated and password reset.` : `User ${email} updated.`
    })
  }

  if (action === 'toggle_user_status') {
    const userId = toInt(form.get('user_id'))
    const user = await getUserById(env, userId)
    if (!user) {
      return renderUsersPage(request, env, adminUser, { error: 'User not found.' })
    }
    const nextStatus = user.status === 'active' ? 'disabled' : 'active'
    await env.DB.prepare('UPDATE app_users SET status = ?, updated_at = ? WHERE id = ?').bind(nextStatus, isoNow(), userId).run()
    return renderUsersPage(request, env, adminUser, { success: `User ${user.email} marked ${nextStatus}.` })
  }

  if (action === 'toggle_runner') {
    const userId = toInt(form.get('user_id'))
    const user = await getUserById(env, userId)
    if (!user) {
      return renderUsersPage(request, env, adminUser, { error: 'User not found.' })
    }
    const nextFlag = user.can_use_github_runner ? 0 : 1
    await env.DB.prepare('UPDATE app_users SET can_use_github_runner = ?, updated_at = ? WHERE id = ?').bind(nextFlag, isoNow(), userId).run()
    return renderUsersPage(request, env, adminUser, { success: `Runner access updated for ${user.email}.` })
  }

  if (action === 'generate_magic_link') {
    return renderUsersPage(request, env, adminUser, { error: 'Magic links are disabled. Create a user and share the email/password instead.' })
  }

  if (action === 'revoke_magic_links') {
    return renderUsersPage(request, env, adminUser, { success: 'Magic links are disabled in Worker mode. No active token changes were needed.' })
  }

  if (action === 'assign_agent') {
    const userId = toInt(form.get('user_id'))
    const agentId = toNullableInt(form.get('assigned_local_agent_id'))
    await env.DB.prepare('UPDATE app_users SET assigned_local_agent_id = ?, updated_at = ? WHERE id = ?').bind(agentId, isoNow(), userId).run()
    return renderUsersPage(request, env, adminUser, { success: 'Assigned agent updated.' })
  }

  return renderUsersPage(request, env, adminUser, { error: 'Unknown users action.' })
}

async function handleAgentsAction(request, env, adminUser) {
  const form = await request.formData()
  const action = String(form.get('action') || '')

  if (action === 'regenerate_pairing_token') {
    await setSetting(env, 'local_agent_pairing_token', randomHex(16))
    return renderAgentsPage(request, env, adminUser, { success: 'Pairing token regenerated.' })
  }

  if (action === 'set_agent_status') {
    const agentId = toInt(form.get('agent_id'))
    const status = sanitizeAgentStatus(String(form.get('status') || 'offline'))
    await env.DB.prepare('UPDATE local_agents SET status = ?, updated_at = ? WHERE id = ?').bind(status, isoNow(), agentId).run()
    return renderAgentsPage(request, env, adminUser, { success: 'Agent status updated.' })
  }

  if (action === 'save_panel_url') {
    await setSetting(env, 'panel_public_base_url', String(form.get('panel_public_base_url') || '').trim())
    return renderAgentsPage(request, env, adminUser, { success: 'Hosted panel URL saved.' })
  }

  return renderAgentsPage(request, env, adminUser, { error: 'Unknown agents action.' })
}

async function handleSettingsAction(request, env, adminUser) {
  const form = await request.formData()
  const action = String(form.get('action') || 'save_settings')
  const actionTabMap = {
    save_bunny: 'bunny',
    test_bunny: 'bunny',
    save_stream: 'stream',
    save_ftp: 'ftp',
    test_ftp: 'ftp',
    save_openai: 'openai',
    test_openai: 'openai',
    save_ffmpeg: 'ffmpeg',
    install_ffmpeg_runtime: 'ffmpeg',
    test_ffmpeg: 'ffmpeg',
    save_storage: 'storage',
    clear_temp: 'storage',
    open_folder: 'storage',
    save_github_runner: 'github_runner',
    test_github_runner: 'github_runner',
    save_postforme: 'postforme',
    test_postforme: 'postforme',
    sync_postforme: 'postforme'
  }
  const tab = sanitizeSettingsTab(String(form.get('tab') || actionTabMap[action] || 'bunny'))

  const saveActions = new Set([
    'save_settings', 'save_bunny', 'save_stream', 'save_ftp', 'save_openai',
    'save_ffmpeg', 'save_storage', 'save_github_runner', 'save_postforme'
  ])

  if (!saveActions.has(action)) {
    const infoMessages = {
      test_bunny: 'Worker saved Bunny settings. Direct connection tests still run on the paired/local machine.',
      test_ftp: 'Worker saved FTP settings. Direct FTP reachability is verified during the next local-agent run.',
      test_openai: 'Worker saved AI settings. API usage is validated during tagline/transcription tasks on the agent.',
      install_ffmpeg_runtime: 'FFmpeg auto-install stays enabled in Worker mode. The paired PC will bootstrap runtime on next job.',
      test_ffmpeg: 'FFmpeg test is deferred to the paired/local machine because the Worker cannot execute binaries.',
      clear_temp: 'Temporary runtime cleanup happens on the paired machine. Worker-side metadata remains intact.',
      open_folder: 'Worker panel is online-only. Use the Player page or the paired machine output folder to inspect generated videos.',
      test_github_runner: 'GitHub Runner settings were kept. Dispatch is tested when a GitHub Runner automation is queued.',
      test_postforme: 'Post for Me credentials were kept. Posting/scheduling is exercised during real automation runs.',
      sync_postforme: 'Connected account sync is not yet mirrored on the Worker. Save the API key here, then sync from the legacy/local panel if needed.'
    }
    return renderSettingsPage(
      appendQueryToRequest(request, { tab }),
      env,
      adminUser,
      infoMessages[action]
        ? { success: infoMessages[action] }
        : { error: 'Unknown settings action.' }
    )
  }

  const fields = getSettingsTabFields(tab)
  for (const key of fields) {
    if (settingsCheckboxFields.has(key)) {
      await setSetting(env, key, checkboxValue(form.get(key)) ? '1' : '0')
      continue
    }
    await setSetting(env, key, String(form.get(key) || '').trim())
  }

  return renderSettingsPage(appendQueryToRequest(request, { tab }), env, adminUser, { success: `Settings saved for ${settingsTabLabel(tab)}.` })
}

async function handleApiKeysAction(request, env, adminUser) {
  const form = await request.formData()
  const action = String(form.get('action') || '')

  if (action === 'create') {
    const name = String(form.get('name') || '').trim()
    const apiKey = String(form.get('api_key') || '').trim()
    const libraryId = String(form.get('library_id') || '').trim()
    if (name === '' || apiKey === '' || libraryId === '') {
      return renderApiKeysPage(request, env, adminUser, { error: 'Connection name, API key, and library ID are required.' })
    }
    await env.DB.prepare(`
      INSERT INTO api_keys (
        name, api_key, library_id, storage_zone, ftp_host, ftp_username,
        ftp_password, ftp_port, cdn_hostname, pull_zone_id, status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
    `).bind(
      name,
      apiKey,
      libraryId,
      String(form.get('storage_zone') || '').trim() || null,
      String(form.get('ftp_host') || '').trim() || null,
      String(form.get('ftp_username') || '').trim() || null,
      String(form.get('ftp_password') || '').trim() || null,
      toInt(form.get('ftp_port')) || 21,
      String(form.get('cdn_hostname') || '').trim() || null,
      String(form.get('pull_zone_id') || '').trim() || null,
      isoNow()
    ).run()
    return renderApiKeysPage(request, env, adminUser, { success: `Connection ${name} created.` })
  }

  if (action === 'update') {
    const id = toInt(form.get('id'))
    const name = String(form.get('name') || '').trim()
    const apiKey = String(form.get('api_key') || '').trim()
    const libraryId = String(form.get('library_id') || '').trim()
    if (id <= 0 || name === '' || apiKey === '' || libraryId === '') {
      return renderApiKeysPage(request, env, adminUser, { error: 'Update requires id, connection name, API key, and library ID.' })
    }
    await env.DB.prepare(`
      UPDATE api_keys
      SET name = ?, api_key = ?, library_id = ?, storage_zone = ?, ftp_host = ?, ftp_username = ?,
          ftp_password = ?, ftp_port = ?, cdn_hostname = ?, pull_zone_id = ?, status = ?
      WHERE id = ?
    `).bind(
      name,
      apiKey,
      libraryId,
      String(form.get('storage_zone') || '').trim() || null,
      String(form.get('ftp_host') || '').trim() || null,
      String(form.get('ftp_username') || '').trim() || null,
      String(form.get('ftp_password') || '').trim() || null,
      toInt(form.get('ftp_port')) || 21,
      String(form.get('cdn_hostname') || '').trim() || null,
      String(form.get('pull_zone_id') || '').trim() || null,
      String(form.get('status') || 'active') === 'inactive' ? 'inactive' : 'active',
      id
    ).run()
    return renderApiKeysPage(request, env, adminUser, { success: `Connection ${name} updated.` })
  }

  if (action === 'toggle') {
    const id = toInt(form.get('id'))
    const key = await getApiKeyById(env, id)
    if (!key) {
      return renderApiKeysPage(request, env, adminUser, { error: 'Connection not found.' })
    }
    const nextStatus = key.status === 'active' ? 'inactive' : 'active'
    await env.DB.prepare('UPDATE api_keys SET status = ? WHERE id = ?').bind(nextStatus, id).run()
    return renderApiKeysPage(request, env, adminUser, { success: `Connection ${key.name} marked ${nextStatus}.` })
  }

  if (action === 'delete') {
    const id = toInt(form.get('id'))
    const key = await getApiKeyById(env, id)
    if (!key) {
      return renderApiKeysPage(request, env, adminUser, { error: 'Connection not found.' })
    }
    await env.DB.prepare('DELETE FROM api_keys WHERE id = ?').bind(id).run()
    return renderApiKeysPage(request, env, adminUser, { success: `Connection ${key.name} deleted.` })
  }

  return renderApiKeysPage(request, env, adminUser, { error: 'Unknown API key action.' })
}

async function handleAutomationAction(request, env, user) {
  const form = await request.formData()
  const action = String(form.get('action') || '')

  if (action === 'save_automation') {
    const automationId = toNullableInt(form.get('automation_id'))
    const editorRequest = appendQueryToRequest(request, automationId ? { edit: automationId } : { create: 1 })
    let payload
    try {
      payload = extractAutomationPayloadFromForm(form)
    } catch (error) {
      return renderAutomationPage(editorRequest, env, user, {
        error: error instanceof Error ? error.message : 'Automation form is invalid.'
      })
    }

    const {
      name,
      runMode,
      localAgentId,
      enabled,
      automationJson,
      apiKeyJson,
      settingsJson
    } = payload
    const effectiveLocalAgentId = user.role !== 'admin' && runMode === 'local'
      ? (user.assigned_local_agent_id || null)
      : localAgentId

    if (name === '') {
      return renderAutomationPage(editorRequest, env, user, { error: 'Automation name is required.' })
    }

    if (runMode === 'github_runner' && !user.can_use_github_runner && user.role !== 'admin') {
      return renderAutomationPage(editorRequest, env, user, { error: 'This user is not allowed to use GitHub Runner.' })
    }

    if (runMode === 'local' && user.role !== 'admin' && !user.assigned_local_agent_id) {
      return renderAutomationPage(editorRequest, env, user, { error: 'Admin must assign a local agent before local automation can be saved.' })
    }

    if (runMode === 'local' && !effectiveLocalAgentId) {
      return renderAutomationPage(editorRequest, env, user, { error: 'Select a local agent for local automation.' })
    }

    const scheduleType = String(automationJson.schedule_type || 'daily')
    const scheduleHour = toInt(automationJson.schedule_hour) || 9
    const scheduleEveryMinutes = toInt(automationJson.schedule_every_minutes) || 10
    const nextRunAt = enabled ? calculateAutomationNextRunAt(scheduleType, scheduleHour, scheduleEveryMinutes) : null
    const now = isoNow()

    if (automationId) {
      const existing = await getAutomationById(env, automationId)
      if (!existing || !canAccessAutomation(user, existing)) {
        return renderAutomationPage(editorRequest, env, user, { error: 'Automation not found.' })
      }
      const mergedAutomationJson = {
        ...parseJsonMaybe(existing.automation_json, {}),
        ...automationJson
      }
      const mergedApiKeyJson = apiKeyJson
        ? { ...parseJsonMaybe(existing.api_key_json, {}), ...apiKeyJson }
        : (existing.api_key_json ? parseJsonMaybe(existing.api_key_json, {}) : null)
      const mergedSettingsJson = settingsJson
        ? { ...parseJsonMaybe(existing.settings_json, {}), ...settingsJson }
        : (existing.settings_json ? parseJsonMaybe(existing.settings_json, {}) : null)
      const nextStatus = resolveSavedAutomationStatus(existing.status, enabled)
      if (!enabled) {
        await cancelPendingJobsForAutomation(env, existing.id, 'Disabled while saving automation.')
      }
      await env.DB.prepare(`
        UPDATE automations
        SET name = ?, run_mode = ?, local_agent_id = ?, enabled = ?, status = ?, next_run_at = ?,
            automation_json = ?, api_key_json = ?, settings_json = ?, updated_at = ?
        WHERE id = ?
      `).bind(
        name,
        runMode,
        effectiveLocalAgentId,
        enabled,
        nextStatus,
        nextRunAt,
        JSON.stringify(mergedAutomationJson),
        mergedApiKeyJson ? JSON.stringify(mergedApiKeyJson) : null,
        mergedSettingsJson ? JSON.stringify(mergedSettingsJson) : null,
        now,
        automationId
      ).run()
      return renderAutomationPage(request, env, user, { success: `Automation ${name} updated.` })
    }

    await env.DB.prepare(`
      INSERT INTO automations (
        owner_user_id, name, run_mode, local_agent_id, enabled, status,
        progress_percent, next_run_at, automation_json, api_key_json, settings_json, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)
    `).bind(
      user.id,
      name,
      runMode,
      effectiveLocalAgentId,
      enabled,
      enabled ? 'running' : 'inactive',
      nextRunAt,
      JSON.stringify(automationJson),
      apiKeyJson ? JSON.stringify(apiKeyJson) : null,
      settingsJson ? JSON.stringify(settingsJson) : null,
      now,
      now
    ).run()

    return renderAutomationPage(request, env, user, { success: `Automation ${name} created.` })
  }

  if (action === 'queue_automation') {
    const automationId = toInt(form.get('automation_id'))
    const automation = await getAutomationById(env, automationId)
    if (!automation || !canAccessAutomation(user, automation)) {
      return renderAutomationPage(request, env, user, { error: 'Automation not found.' })
    }

    const result = await queueAutomation(env, automation, 'manual')
    if (!result.success) {
      return renderAutomationPage(request, env, user, { error: result.error })
    }

    const message = result.alreadyQueued
      ? `Automation is already queued on ${result.agentName}.`
      : `Automation queued on ${result.agentName}.`
    return renderAutomationPage(request, env, user, { success: message })
  }

  if (action === 'toggle_automation') {
    const automationId = toInt(form.get('automation_id'))
    const automation = await getAutomationById(env, automationId)
    if (!automation || !canAccessAutomation(user, automation)) {
      return renderAutomationPage(request, env, user, { error: 'Automation not found.' })
    }

    const config = parseJsonMaybe(automation.automation_json, {})
    const nextEnabled = automation.enabled ? 0 : 1
    const nextStatus = nextEnabled ? 'running' : 'stopped'
    const nextRunAt = nextEnabled
      ? calculateAutomationNextRunAt(
        String(config.schedule_type || 'daily'),
        toInt(config.schedule_hour) || 9,
        toInt(config.schedule_every_minutes) || 10
      )
      : null
    const now = isoNow()
    const progressPayload = JSON.stringify({
      step: 'scheduler',
      status: nextEnabled ? 'info' : 'warning',
      event_status: nextEnabled ? 'info' : 'warning',
      message: nextEnabled
        ? `Automation enabled. Next scheduled run ${formatDisplayDateTime(nextRunAt)}.`
        : 'Automation disabled.',
      progress: nextEnabled ? Number(automation.progress_percent || 0) : 0,
      stats: {},
      outputs: [],
      time: now
    })

    if (!nextEnabled) {
      await cancelPendingJobsForAutomation(env, automation.id, 'Disabled by user.')
    }

    await env.DB.batch([
      env.DB.prepare(`
        UPDATE automations
        SET enabled = ?, status = ?, next_run_at = ?, progress_percent = ?, progress_data = ?, last_progress_at = ?, updated_at = ?
        WHERE id = ?
      `).bind(
        nextEnabled,
        nextStatus,
        nextRunAt,
        nextEnabled ? Number(automation.progress_percent || 0) : 0,
        progressPayload,
        now,
        now,
        automation.id
      ),
      env.DB.prepare(`
        INSERT INTO automation_logs (automation_id, action, status, message, created_at)
        VALUES (?, 'toggle', ?, ?, ?)
      `).bind(
        automation.id,
        nextEnabled ? 'success' : 'warning',
        nextEnabled ? 'Automation enabled.' : 'Automation disabled.',
        now
      )
    ])

    return renderAutomationPage(request, env, user, {
      success: nextEnabled ? `Automation ${automation.name} enabled.` : `Automation ${automation.name} disabled.`
    })
  }

  if (action === 'stop_automation') {
    const automationId = toInt(form.get('automation_id'))
    const automation = await getAutomationById(env, automationId)
    if (!automation || !canAccessAutomation(user, automation)) {
      return renderAutomationPage(request, env, user, { error: 'Automation not found.' })
    }

    const now = isoNow()
    await cancelPendingJobsForAutomation(env, automation.id, 'Stopped by user.')
    await env.DB.batch([
      env.DB.prepare(`
        UPDATE automations
        SET enabled = 0, status = 'stopped', next_run_at = NULL, progress_data = ?, last_progress_at = ?, updated_at = ?
        WHERE id = ?
      `).bind(
        JSON.stringify({
          step: 'manual_stop',
          status: 'warning',
          event_status: 'warning',
          message: 'Automation stopped by user.',
          progress: Number(automation.progress_percent || 0),
          stats: {},
          outputs: [],
          time: now
        }),
        now,
        now,
        automation.id
      ),
      env.DB.prepare(`
        INSERT INTO automation_logs (automation_id, action, status, message, created_at)
        VALUES (?, 'manual_stop', 'warning', ?, ?)
      `).bind(automation.id, 'Automation stopped by user.', now)
    ])

    return renderAutomationPage(request, env, user, { success: `Automation ${automation.name} stopped.` })
  }

  if (action === 'stop_all_automations') {
    requireAdmin(user)
    const now = isoNow()
    const rows = await env.DB.prepare(`
      SELECT id, name, progress_percent
      FROM automations
      WHERE status IN ('queued', 'running', 'processing', 'claimed')
         OR enabled = 1
    `).all()
    const automations = rows.results || []

    for (const automation of automations) {
      await cancelPendingJobsForAutomation(env, Number(automation.id), 'Stopped by admin from Stop All.')
      await env.DB.batch([
        env.DB.prepare(`
          UPDATE automations
          SET enabled = 0, status = 'stopped', next_run_at = NULL, progress_data = ?, last_progress_at = ?, updated_at = ?
          WHERE id = ?
        `).bind(
          JSON.stringify({
            step: 'manual_stop',
            status: 'warning',
            event_status: 'warning',
            message: 'Automation stopped by admin from Stop All.',
            progress: Number(automation.progress_percent || 0),
            stats: {},
            outputs: [],
            time: now
          }),
          now,
          now,
          Number(automation.id)
        ),
        env.DB.prepare(`
          INSERT INTO automation_logs (automation_id, action, status, message, created_at)
          VALUES (?, 'manual_stop_all', 'warning', ?, ?)
        `).bind(Number(automation.id), 'Automation stopped by admin from Stop All.', now)
      ])
    }

    return renderAutomationPage(request, env, user, {
      success: automations.length
        ? `Stopped ${automations.length} automation(s).`
        : 'No running or enabled automations were found.'
    })
  }

  if (action === 'reset_rotation') {
    const automationId = toInt(form.get('automation_id'))
    const automation = await getAutomationById(env, automationId)
    if (!automation || !canAccessAutomation(user, automation)) {
      return renderAutomationPage(request, env, user, { error: 'Automation not found.' })
    }

    const config = parseJsonMaybe(automation.automation_json, {})
    config.rotation_cycle = 1
    config.rotation_cursor = 0
    config.last_processed_video_id = null
    config.processed_video_ids = []
    config.processed_videos = []
    const now = isoNow()
    await env.DB.batch([
      env.DB.prepare(`
        UPDATE automations
        SET automation_json = ?, updated_at = ?
        WHERE id = ?
      `).bind(JSON.stringify(config), now, automation.id),
      env.DB.prepare(`
        INSERT INTO automation_logs (automation_id, action, status, message, created_at)
        VALUES (?, 'reset_rotation', 'info', ?, ?)
      `).bind(automation.id, 'Rotation tracking reset.', now)
    ])

    return renderAutomationPage(request, env, user, { success: `Rotation reset for ${automation.name}.` })
  }

  if (action === 'delete_automation') {
    const automationId = toInt(form.get('automation_id'))
    const automation = await getAutomationById(env, automationId)
    if (!automation || !canAccessAutomation(user, automation)) {
      return renderAutomationPage(request, env, user, { error: 'Automation not found.' })
    }
    await cancelPendingJobsForAutomation(env, automationId, 'Automation deleted.')
    await env.DB.prepare('DELETE FROM scheduled_posts WHERE automation_id = ?').bind(automationId).run()
    await env.DB.prepare('DELETE FROM automations WHERE id = ?').bind(automationId).run()
    await env.DB.prepare('DELETE FROM automation_logs WHERE automation_id = ?').bind(automationId).run()
    return renderAutomationPage(request, env, user, { success: `Automation ${automation.name} deleted.` })
  }

  return renderAutomationPage(request, env, user, { error: 'Unknown automation action.' })
}

async function handleInstallManifest(request, env) {
  const authorized = await canAccessInstallManifest(request, env)
  if (!authorized) {
    return jsonResponse({ success: false, error: 'Pairing token is invalid.' }, 403)
  }

  const origin = new URL(request.url).origin
  return jsonResponse(buildInstallManifest(env, origin))
}

async function handleWindowsInstaller(request, env) {
  const authorized = await canAccessInstallManifest(request, env)
  if (!authorized) {
    return textResponse('Pairing token is invalid.', 403)
  }

  const url = new URL(request.url)
  const origin = url.origin
  const manifest = buildInstallManifest(env, origin)
  const script = renderWindowsInstallScript({
    serverUrl: manifest.server_url,
    pairingToken: String(url.searchParams.get('pairing_token') || ''),
    installDir: manifest.install_dir,
    workerDbName: manifest.worker_db_name,
    workerBaseDir: manifest.worker_base_dir,
    pollInterval: manifest.poll_interval,
    manifestUrl: `${origin}/api/agent/install-manifest?pairing_token=${encodeURIComponent(String(url.searchParams.get('pairing_token') || ''))}`
  })

  return textResponse(script, 200, {
    'Content-Type': 'text/plain; charset=utf-8',
    'Content-Disposition': 'attachment; filename="video-workflow-agent-install.ps1"'
  })
}

async function handleAgentApi(request, env, path) {
  if (request.method !== 'POST') {
    return jsonResponse({ success: false, error: 'Method not allowed.' }, 405)
  }

  if (path === '/api/agent/register' || path === '/api/agent/register.php' || path === '/api/agent-register.php') {
    const payload = await readJsonBody(request)
    const ip = request.headers.get('CF-Connecting-IP') || request.headers.get('x-forwarded-for') || ''
    const result = await registerAgent(env, payload, ip)
    const status = result.success ? 200 : 403
    return jsonResponse(result, status)
  }

  if (path === '/api/agent/poll' || path === '/api/agent/poll.php' || path === '/api/agent-poll.php') {
    const payload = await readJsonBody(request)
    const result = await claimNextJob(env, payload, request)
    const status = result.success ? 200 : (result.http_status || 400)
    return jsonResponse(result, status)
  }

  if (path === '/api/agent/report' || path === '/api/agent/report.php' || path === '/api/agent-report.php') {
    const payload = await readJsonBody(request)
    const result = await receiveProgress(env, payload, request)
    return jsonResponse(result, result.success ? 200 : 400)
  }

  if (path === '/api/agent/complete' || path === '/api/agent/complete.php' || path === '/api/agent-complete.php') {
    const payload = await readJsonBody(request)
    const result = await completeJob(env, payload, request)
    return jsonResponse(result, result.success ? 200 : 400)
  }

  if (path === '/api/agent-upload-output.php') {
    const form = await request.formData()
    const result = await storeAgentOutput(env, form)
    return jsonResponse(result, result.success ? 200 : 400)
  }

  return jsonResponse({ success: false, error: 'Unknown agent endpoint.' }, 404)
}

async function registerAgent(env, payload, ipAddress) {
  const agentKey = String(payload.agent_key || '').trim()
  const agentSecret = String(payload.agent_secret || '').trim()
  const pairingToken = String(payload.pairing_token || '').trim()
  const displayName = String(payload.display_name || '').trim()
  const machineName = String(payload.machine_name || '').trim()
  const hostName = String(payload.host_name || '').trim()
  const platform = String(payload.platform || '').trim()
  const agentVersion = String(payload.agent_version || '').trim()
  const capabilities = isPlainObject(payload.capabilities) ? payload.capabilities : {}

  if (agentKey && agentSecret) {
    const agent = await authenticateAgent(env, agentKey, agentSecret)
    if (!agent) {
      return { success: false, error: 'Agent credentials are invalid.' }
    }

    await env.DB.prepare(`
      UPDATE local_agents
      SET display_name = ?, machine_name = ?, host_name = ?, platform = ?, agent_version = ?,
          capabilities_json = ?, status = 'online', last_seen_at = ?, last_ip = ?, updated_at = ?
      WHERE id = ?
    `).bind(
      displayName || agent.display_name,
      machineName || agent.machine_name,
      hostName || agent.host_name,
      platform || agent.platform,
      agentVersion || agent.agent_version,
      JSON.stringify(capabilities),
      isoNow(),
      ipAddress,
      isoNow(),
      agent.id
    ).run()

    return {
      success: true,
      agent_key: agentKey,
      agent_secret: agentSecret,
      agent: await getAgentById(env, agent.id),
      base_url: '',
      poll_url: '/api/agent/poll.php',
      report_url: '/api/agent/report.php',
      complete_url: '/api/agent/complete.php',
      upload_url: '/api/agent-upload-output.php'
    }
  }

  const validPairingToken = await getPairingToken(env)
  if (pairingToken === '' || !timingSafeEqual(await sha256Hex(pairingToken), await sha256Hex(validPairingToken))) {
    return { success: false, error: 'Pairing token is invalid.' }
  }

  const nextAgentKey = 'ag_' + randomHex(12)
  const nextAgentSecret = randomHex(24)
  const now = isoNow()
  const result = await env.DB.prepare(`
    INSERT INTO local_agents (
      owner_user_id, agent_key, agent_secret_hash, display_name, machine_name, host_name,
      platform, agent_version, status, last_seen_at, last_ip, capabilities_json, created_at, updated_at
    ) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'online', ?, ?, ?, ?, ?)
  `).bind(
    nextAgentKey,
    await hashPassword(nextAgentSecret),
    displayName || machineName || 'Local Agent',
    machineName || null,
    hostName || null,
    platform || null,
    agentVersion || null,
    now,
    ipAddress,
    JSON.stringify(capabilities),
    now,
    now
  ).run()

  const agentId = Number(result.meta?.last_row_id || 0)
  return {
    success: true,
    agent_key: nextAgentKey,
    agent_secret: nextAgentSecret,
    agent: await getAgentById(env, agentId),
    base_url: '',
    poll_url: '/api/agent/poll.php',
    report_url: '/api/agent/report.php',
    complete_url: '/api/agent/complete.php',
    upload_url: '/api/agent-upload-output.php'
  }
}

async function claimNextJob(env, payload, request) {
  const agentKey = String(payload.agent_key || request.headers.get('x-agent-key') || '').trim()
  const agentSecret = String(payload.agent_secret || request.headers.get('x-agent-secret') || '').trim()
  const agent = await authenticateAgent(env, agentKey, agentSecret)
  if (!agent) {
    return { success: false, error: 'Agent authentication failed.', http_status: 403 }
  }
  if (agent.status === 'disabled') {
    return { success: false, error: 'Agent is disabled.', http_status: 403 }
  }

  const now = isoNow()
  const ipAddress = request.headers.get('CF-Connecting-IP') || request.headers.get('x-forwarded-for') || ''
  await env.DB.prepare(`
    UPDATE local_agents
    SET status = 'online', last_seen_at = ?, last_ip = ?, updated_at = ?
    WHERE id = ?
  `).bind(now, ipAddress, now, agent.id).run()

  const job = await env.DB.prepare(`
    SELECT * FROM local_agent_jobs
    WHERE agent_id = ? AND status = 'queued'
    ORDER BY queued_at ASC, id ASC
    LIMIT 1
  `).bind(agent.id).first()

  if (!job) {
    return { success: true, job: null, agent: await getAgentById(env, agent.id) }
  }

  const claimToken = randomHex(16)
  const claimed = await env.DB.prepare(`
    UPDATE local_agent_jobs
    SET status = 'claimed', claim_token = ?, claimed_at = ?, last_heartbeat_at = ?
    WHERE id = ? AND status = 'queued'
  `).bind(claimToken, now, now, job.id).run()

  if (Number(claimed.meta?.changes || 0) === 0) {
    return { success: true, job: null, agent: await getAgentById(env, agent.id) }
  }

  const automation = await getAutomationById(env, job.automation_id)
  if (!automation) {
    await env.DB.prepare(`
      UPDATE local_agent_jobs SET status = 'error', error_message = ?, completed_at = ? WHERE id = ?
    `).bind('Automation no longer exists.', now, job.id).run()
    return { success: false, error: 'Automation not found for payload snapshot.' }
  }

  const payloadB64 = await buildCompressedPayload(env, automation)
  return {
    success: true,
    agent: await getAgentById(env, agent.id),
    job: {
      id: job.id,
      automation_id: job.automation_id,
      trigger_source: job.trigger_source,
      claim_token: claimToken,
      payload_gzip_b64: payloadB64,
      snapshot_at: isoNow()
    }
  }
}

async function receiveProgress(env, payload, request) {
  const jobId = toInt(payload.job_id)
  const claimToken = String(payload.claim_token || '').trim()
  const progressPayload = isPlainObject(payload.payload) ? payload.payload : payload
  const job = await authorizeJobClaim(env, jobId, claimToken, ['claimed', 'running', 'completed'])
  if (!job) {
    return { success: false, error: 'Job claim is invalid.' }
  }

  const now = isoNow()
  await env.DB.prepare(`
    UPDATE local_agent_jobs
    SET status = CASE WHEN status = 'claimed' THEN 'running' ELSE status END,
        started_at = COALESCE(started_at, ?),
        last_heartbeat_at = ?
    WHERE id = ?
  `).bind(now, now, jobId).run()

  await applyAutomationProgress(env, job.automation_id, progressPayload, 'local_agent_progress')
  await touchAgentByJob(env, job, request)
  return { success: true }
}

async function completeJob(env, payload, request) {
  const jobId = toInt(payload.job_id)
  const claimToken = String(payload.claim_token || '').trim()
  const resultPayload = isPlainObject(payload.payload) ? payload.payload : payload
  const job = await authorizeJobClaim(env, jobId, claimToken, ['claimed', 'running'])
  if (!job) {
    return { success: false, error: 'Job claim is invalid.' }
  }

  const status = normalizeRemoteStatus(String(resultPayload.status || 'completed'))
  const now = isoNow()
  await applyAutomationProgress(env, job.automation_id, { ...resultPayload, status }, 'local_agent_complete')
  await env.DB.prepare(`
    UPDATE local_agent_jobs
    SET status = ?, completed_at = ?, last_heartbeat_at = ?, result_json = ?, error_message = ?
    WHERE id = ?
  `).bind(
    status,
    now,
    now,
    JSON.stringify(resultPayload),
    status === 'error' ? String(resultPayload.message || 'Agent execution failed.') : null,
    jobId
  ).run()
  await syncOutputFilesFromJobResult(env, job, resultPayload, now)
  await syncScheduledPostsFromJobResult(env, job, { ...resultPayload, status }, now)
  await touchAgentByJob(env, job, request)
  return { success: true }
}

async function storeAgentOutput(env, form) {
  const jobId = toInt(form.get('job_id'))
  const claimToken = String(form.get('claim_token') || '').trim()
  const job = await authorizeJobClaim(env, jobId, claimToken, ['claimed', 'running', 'completed'])
  if (!job) {
    return { success: false, error: 'Invalid job claim.' }
  }

  const file = form.get('output_file')
  if (!(file instanceof File)) {
    return { success: false, error: 'Missing output file upload.' }
  }

  const safeName = sanitizeFileName(file.name || `agent_output_${jobId}.mp4`)
  const createdAt = isoNow()
  const localPath = sanitizeLocalFilePath(String(form.get('local_path') || '').trim()) || null

  await env.DB.prepare(`
    INSERT INTO output_files (
      automation_id, job_id, filename, object_key, local_path, content_type, size_bytes, stored_in, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).bind(
    job.automation_id,
    jobId,
    safeName,
    null,
    localPath,
    file.type || 'application/octet-stream',
    Number(file.size || 0),
    'metadata',
    createdAt
  ).run()

  return {
    success: true,
    filename: safeName,
    stored_in: 'metadata',
    local_path: localPath
  }
}

async function renderDashboardPage(request, env, user, feedback) {
  const automations = await listAutomationsForUser(env, user)
  const agents = await listVisibleAgents(env, user)
  const outputs = await listRecentOutputsForUser(env, user, 24)
  const stats = await getDashboardStats(env, user)
  const recentJobs = await listRecentJobsForUser(env, user, 10)
  const scheduledPosts = await listScheduledPostsForUser(env, user, { limit: 12, activeOnly: true })
  const body = renderDashboardBody({
    user,
    automations,
    agents,
    outputs,
    stats,
    recentJobs,
    scheduledPosts,
    feedback
  })
  return htmlResponse(renderPage({
    title: 'Dashboard',
    user,
    body,
    currentPath: '/dashboard'
  }))
}

async function renderJobsPage(request, env, user, feedback) {
  const jobs = await listRecentJobsForUser(env, user, 40)
  return htmlResponse(renderPage({
    title: 'Jobs',
    user,
    body: renderJobsBody({ jobs, feedback }),
    currentPath: '/jobs'
  }))
}

async function renderAutomationPage(request, env, user, feedback) {
  const url = new URL(request.url)
  const automations = await listAutomationsForUser(env, user)
  const agents = await listVisibleAgents(env, user)
  const apiKeys = await listApiKeys(env)
  const outputSummary = await getOutputSummaryForUser(env, user)
  const scheduledPosts = await listScheduledPostsForUser(env, user, { limit: 40, activeOnly: true })
  const scheduledCounts = await countScheduledPostsByAutomation(env, user)
  const editId = toInt(url.searchParams.get('edit'))
  const createMode = url.searchParams.get('create') === '1'
  const logAutomationId = toInt(url.searchParams.get('logs'))
  let editingAutomation = null
  if (editId > 0) {
    const candidate = await getAutomationById(env, editId)
    if (candidate && canAccessAutomation(user, candidate)) {
      editingAutomation = candidate
    }
  }
  let logAutomation = null
  if (logAutomationId > 0) {
    const candidate = await getAutomationById(env, logAutomationId)
    if (candidate && canAccessAutomation(user, candidate)) {
      logAutomation = candidate
    }
  }

  return htmlResponse(renderPage({
    title: 'Automation',
    user,
    body: renderAutomationBody({
      user,
      automations,
      agents,
      apiKeys,
      outputSummary,
      scheduledPosts,
      scheduledCounts,
      feedback,
      editor: buildAutomationEditorState(editingAutomation),
      editorOpen: createMode || !!editingAutomation,
      logAutomation
    }),
    currentPath: '/automation'
  }))
}

async function renderSettingsPage(request, env, adminUser, feedback) {
  const url = new URL(request.url)
  const tab = sanitizeSettingsTab(url.searchParams.get('tab') || 'bunny')
  const settings = await getSettingsMap(env)
  return htmlResponse(renderPage({
    title: 'Settings',
    user: adminUser,
    body: renderSettingsBody({ tab, settings, feedback }),
    currentPath: '/settings'
  }))
}

async function renderApiKeysPage(request, env, adminUser, feedback) {
  const keys = await listApiKeys(env, true)
  return htmlResponse(renderPage({
    title: 'API Keys',
    user: adminUser,
    body: renderApiKeysBody({ keys, feedback }),
    currentPath: '/api-keys'
  }))
}

async function renderUsersPage(request, env, adminUser, feedback) {
  const users = await listUsers(env)
  const agents = await listAgents(env)
  return htmlResponse(renderPage({
    title: 'Users',
    user: adminUser,
    body: renderUsersBody({ users, agents, feedback }),
    currentPath: '/admin/users'
  }))
}

async function renderAgentsPage(request, env, adminUser, feedback) {
  const agents = await listAgents(env)
  const pairingToken = await getPairingToken(env)
  const manifest = buildInstallManifest(env, new URL(request.url).origin)
  const panelBaseUrl = await getSetting(env, 'panel_public_base_url', new URL(request.url).origin)
  const agentJobCounts = await listAgentJobCounts(env)
  return htmlResponse(renderPage({
    title: 'Agents',
    user: adminUser,
    body: renderAgentsBody({
      agents,
      pairingToken,
      feedback,
      panelBaseUrl,
      agentJobCounts,
      installScriptUrl: `${new URL(request.url).origin}/install/windows.ps1?pairing_token=${encodeURIComponent(pairingToken)}`,
      installManifest: manifest
    }),
    currentPath: '/admin/agents'
  }))
}

async function renderPlayerPage(request, env, user, feedback) {
  const outputs = await listRecentOutputsForUser(env, user, 60)
  const summary = await getOutputSummaryForUser(env, user)
  return htmlResponse(renderPage({
    title: 'Player',
    user,
    body: renderPlayerBody({ user, outputs, summary, feedback }),
    currentPath: '/player'
  }))
}

async function renderLoginPage(request, env, errorMessage) {
  const url = new URL(request.url)
  const next = sanitizeRedirectPath(url.searchParams.get('next') || '/dashboard')
  return htmlResponse(renderPage({
    title: 'Login',
    user: null,
    body: renderLoginBody({ errorMessage, next, appName: env.APP_NAME || 'Video Workflow Control' })
  }), errorMessage ? 401 : 200)
}

async function handleOutputDownload(request, env, user, outputId) {
  const url = new URL(request.url)
  const forceDownload = url.searchParams.get('download') === '1'
  const output = await env.DB.prepare(`
    SELECT o.*, a.owner_user_id
    FROM output_files o
    JOIN automations a ON a.id = o.automation_id
    WHERE o.id = ?
    LIMIT 1
  `).bind(outputId).first()
  if (!output) {
    return htmlResponse(renderPage({
      title: 'Output',
      user,
      body: `<section class="panel"><h1>Not Found</h1><p class="muted">Output file does not exist.</p></section>`
    }), 404)
  }
  if (user.role !== 'admin' && output.owner_user_id !== user.id) {
    return htmlResponse(renderPage({
      title: 'Output',
      user,
      body: `<section class="panel"><h1>Forbidden</h1><p class="muted">You cannot access this output.</p></section>`
    }), 403)
  }

  const safePath = output.local_path ? escapeHtml(String(output.local_path)) : ''
  const dispositionLabel = forceDownload ? 'download' : 'open'

  return htmlResponse(renderPage({
    title: 'Output',
    user,
    body: `
      <section class="panel stack">
        <h1>Local Output Only</h1>
        <p class="muted">This Worker keeps output metadata only. The actual file stays on the paired PC output folder.</p>
        <div class="note-card">
          <strong>${escapeHtml(String(output.filename || 'output.mp4'))}</strong>
          <div class="muted compact">Requested action: ${escapeHtml(dispositionLabel)}</div>
          <div class="muted compact">${safePath ? `Local path: <code>${safePath}</code>` : 'Local path was not reported by the agent.'}</div>
        </div>
      </section>
    `
  }), 409)
}

async function handleAutomationRunApi(request, env, user) {
  const payload = await readJsonBody(request)
  const automationId = toInt(payload.automation_id || payload.id)
  const automation = await getAutomationById(env, automationId)
  if (!automation || !canAccessAutomation(user, automation)) {
    return jsonResponse({ success: false, error: 'Automation not found.' }, 404)
  }

  const result = await queueAutomation(env, automation, 'manual')
  if (!result.success) {
    return jsonResponse(result, 400)
  }

  const refreshed = await getAutomationById(env, automation.id)
  return jsonResponse({
    success: true,
    message: result.alreadyQueued
      ? `Automation is already queued on ${result.agentName}.`
      : `Automation queued on ${result.agentName}.`,
    status: refreshed?.status || 'queued',
    automation_id: automation.id,
    job_id: result.jobId || 0
  })
}

async function handleLegacyStartAutomationApi(request, env, user) {
  const url = new URL(request.url)
  let automationId = toInt(url.searchParams.get('id') || url.searchParams.get('automation_id'))
  if (automationId <= 0 && request.method !== 'GET') {
    const payload = await readJsonBody(request)
    automationId = toInt(payload.automation_id || payload.id)
  }
  if (automationId <= 0) {
    return jsonResponse({ success: false, error: 'No automation ID' }, 400)
  }

  const automation = await getAutomationById(env, automationId)
  if (!automation || !canAccessAutomation(user, automation)) {
    return jsonResponse({ success: false, error: 'Automation not found' }, 404)
  }

  const result = await queueAutomation(env, automation, 'manual_run')
  if (!result.success) {
    return jsonResponse({ success: false, error: result.error || 'Unable to queue automation' }, 400)
  }

  const refreshed = await getAutomationById(env, automation.id)
  return jsonResponse({
    success: true,
    mode: refreshed?.run_mode || automation.run_mode || 'local',
    status: refreshed?.status || 'queued',
    message: result.alreadyQueued ? 'Already in queue.' : `Queued on ${result.agentName}.`,
    automationId: automation.id,
    job_id: result.jobId || 0
  })
}

async function handleAutomationStatusApi(request, env, user) {
  const url = new URL(request.url)
  const automationId = toInt(url.searchParams.get('automation_id'))
  const automation = await getAutomationById(env, automationId)
  if (!automation || !canAccessAutomation(user, automation)) {
    return jsonResponse({ success: false, error: 'Automation not found.' }, 404)
  }

  const [logs, outputs, job] = await Promise.all([
    listAutomationLogs(env, automation.id, 60),
    listOutputsForAutomation(env, automation.id, 12),
    getLatestJobForAutomation(env, automation.id)
  ])

  return jsonResponse({
    success: true,
    automation: {
      id: automation.id,
      name: automation.name,
      status: automation.status,
      enabled: automation.enabled,
      run_mode: automation.run_mode,
      local_agent_id: automation.local_agent_id,
      progress_percent: automation.progress_percent,
      next_run_at: automation.next_run_at,
      last_run_at: automation.last_run_at,
      last_progress_at: automation.last_progress_at
    },
    progress: parseJsonMaybe(automation.progress_data, {}),
    logs,
    outputs: outputs.map((output) => ({
      ...output,
      storage_label: getOutputStorageLabel(output),
      download_url: null
    })),
    job
  })
}

async function handleLegacyCheckProgressApi(request, env, user) {
  const url = new URL(request.url)
  const automationId = toInt(url.searchParams.get('id') || url.searchParams.get('automation_id'))
  const withLogs = url.searchParams.get('with_logs') === '1'
  const automation = await getAutomationById(env, automationId)
  if (!automation || !canAccessAutomation(user, automation)) {
    return jsonResponse({ success: false, error: 'Automation not found' }, 404)
  }

  const [logs, outputs, job] = await Promise.all([
    listAutomationLogs(env, automation.id, withLogs ? 120 : 15),
    listOutputsForAutomation(env, automation.id, 20),
    getLatestJobForAutomation(env, automation.id)
  ])

  const progressData = parseJsonMaybe(automation.progress_data, {})
  const progress = clampInt(progressData.progress ?? automation.progress_percent ?? 0, 0, 100)
  const nextRunTs = toUnixTimestamp(automation.next_run_at)
  const outputNames = outputs.map((output) => String(output.filename || '')).filter(Boolean)
  const payload = {
    step: String(progressData.step || 'local_agent'),
    status: String(progressData.event_status || progressData.status || automation.status || 'info'),
    message: String(progressData.message || defaultAutomationMessage(automation.status)),
    progress,
    stats: isPlainObject(progressData.stats) ? progressData.stats : {},
    outputs: outputNames,
    time: String(progressData.time || automation.last_progress_at || automation.updated_at || ''),
    job_id: Number(progressData.job_id || job?.id || 0) || null
  }
  if (withLogs) {
    payload.logs = logs
  }

  return jsonResponse({
    success: true,
    status: String(automation.status || 'inactive'),
    progress,
    nextRunTs,
    data: payload
  })
}

async function handleLegacyListOutputVideosApi(request, env, user) {
  const outputs = await listRecentOutputsForUser(env, user, 200)
  const videos = outputs.map((output) => {
    const modifiedTs = toUnixTimestamp(output.created_at)
    const source = 'local'
    const localPath = String(output.local_path || '').trim()
    return {
      name: String(output.filename || `output_${output.id}.mp4`),
      path: localPath || `local://paired-agent/${output.id}`,
      size: output.size_bytes ? Number(output.size_bytes) / 1024 / 1024 : null,
      size_formatted: formatBytes(output.size_bytes || 0),
      modified: String(output.created_at || ''),
      modified_ago: formatTimeAgo(output.created_at),
      modified_ts: modifiedTs,
      url: null,
      source,
      storage_label: getOutputStorageLabel(output),
      automation_id: Number(output.automation_id || 0),
      run_id: Number(output.job_id || 0) || null
    }
  })

  return jsonResponse({
    success: true,
    folder: {
      output_folder: deriveOutputDirectoryHint(outputs, env),
      temp_folder: 'Paired PC runtime temp folder',
      output_exists: true,
      temp_exists: true,
      local_count: videos.length,
      github_count: 0
    },
    videos,
    total: videos.length
  })
}

async function handleLegacyStreamGithubVideoApi(request, env, user) {
  const url = new URL(request.url)
  const automationId = toInt(url.searchParams.get('automation_id'))
  const file = sanitizeFileName(String(url.searchParams.get('file') || '').trim())
  if (automationId <= 0 || file === '') {
    return textResponse('Missing automation_id or file', 400, { 'Content-Type': 'text/plain; charset=utf-8' })
  }

  const output = await env.DB.prepare(`
    SELECT o.*, a.owner_user_id
    FROM output_files o
    JOIN automations a ON a.id = o.automation_id
    WHERE o.automation_id = ? AND LOWER(o.filename) = LOWER(?)
    ORDER BY o.id DESC
    LIMIT 1
  `).bind(automationId, file).first()

  if (!output) {
    return textResponse('Video not found', 404, { 'Content-Type': 'text/plain; charset=utf-8' })
  }

  return handleOutputDownload(request, env, user, Number(output.id))
}

async function handleLegacyDeleteAllOutputVideosApi(request, env, user) {
  requireAdmin(user)
  const form = await request.formData()
  const mode = String(form.get('mode') || 'all').trim().toLowerCase()
  const allowedModes = new Set(['all', 'local', 'github'])
  const effectiveMode = allowedModes.has(mode) ? mode : 'all'

  const rows = await env.DB.prepare(`
    SELECT o.id, o.object_key, o.stored_in, a.run_mode, a.id AS automation_id
    FROM output_files o
    JOIN automations a ON a.id = o.automation_id
  `).all()

  let deleted = 0
  let remoteDeleted = 0
  const automationIds = new Set()
  for (const row of rows.results || []) {
    const source = String(row.run_mode || '') === 'github_runner' ? 'github' : 'local'
    if (effectiveMode !== 'all' && effectiveMode !== source) {
      continue
    }
    if (row.stored_in === 'r2' && row.object_key && env.OUTPUTS && typeof env.OUTPUTS.delete === 'function') {
      await env.OUTPUTS.delete(String(row.object_key))
      remoteDeleted += 1
    }
    await env.DB.prepare('DELETE FROM output_files WHERE id = ?').bind(Number(row.id)).run()
    automationIds.add(Number(row.automation_id))
    deleted += 1
  }

  for (const automationId of automationIds) {
    const automation = await getAutomationById(env, automationId)
    if (!automation) {
      continue
    }
    const progressData = parseJsonMaybe(automation.progress_data, {})
    if (Array.isArray(progressData.outputs) && progressData.outputs.length) {
      progressData.outputs = []
      await env.DB.prepare('UPDATE automations SET progress_data = ?, updated_at = ? WHERE id = ?').bind(
        JSON.stringify(progressData),
        isoNow(),
        automationId
      ).run()
    }
  }

  return jsonResponse({
    success: true,
    deleted,
    remote_deleted: remoteDeleted,
    message: `${deleted} output video(s) removed from Worker metadata${remoteDeleted ? ` and ${remoteDeleted} object(s)` : ''}.`
  })
}

async function handleScheduledPostsApi(request, env, user) {
  const url = new URL(request.url)
  const automationId = toInt(url.searchParams.get('automation_id'))
  const posts = await listScheduledPostsForUser(env, user, {
    automationId: automationId > 0 ? automationId : null,
    limit: Math.min(100, Math.max(1, toInt(url.searchParams.get('limit')) || 100)),
    activeOnly: !url.searchParams.has('all')
  })

  return jsonResponse({
    success: true,
    posts,
    server_time: isoNow()
  })
}

async function handleDeleteScheduledPostApi(request, env, user) {
  requireAdmin(user)
  const form = await request.formData()
  const postId = toInt(form.get('id'))
  if (postId <= 0) {
    return jsonResponse({ success: false, error: 'Missing scheduled post id.' }, 400)
  }

  const now = isoNow()
  const result = await env.DB.prepare(`
    UPDATE scheduled_posts
    SET status = 'cancelled', updated_at = ?
    WHERE id = ? AND status IN ('queued', 'scheduled', 'processing')
  `).bind(now, postId).run()

  if (Number(result.meta?.changes || 0) === 0) {
    return jsonResponse({ success: false, error: 'Scheduled post not found or already inactive.' }, 404)
  }

  return jsonResponse({ success: true, message: 'Scheduled post cancelled.' })
}

async function handleDeleteAllScheduledPostsApi(request, env, user) {
  requireAdmin(user)
  const form = await request.formData()
  const automationId = toNullableInt(form.get('automation_id'))
  const now = isoNow()
  const bindArgs = [now]
  let sql = `
    UPDATE scheduled_posts
    SET status = 'cancelled', updated_at = ?
    WHERE status IN ('queued', 'scheduled', 'processing')
  `

  if (automationId) {
    sql += ' AND automation_id = ?'
    bindArgs.push(automationId)
  }

  const result = await env.DB.prepare(sql).bind(...bindArgs).run()
  return jsonResponse({
    success: true,
    message: `${Number(result.meta?.changes || 0)} scheduled post(s) cancelled.`
  })
}

async function handleLegacyCronApi(request, env, user) {
  const url = new URL(request.url)
  const limit = Math.min(25, Math.max(1, toInt(url.searchParams.get('limit')) || 10))
  const rows = await env.DB.prepare(`
    SELECT *
    FROM automations
    WHERE enabled = 1
      AND status NOT IN ('queued', 'claimed', 'running', 'processing')
      AND next_run_at IS NOT NULL
      AND next_run_at <= ?
    ORDER BY next_run_at ASC, id ASC
    LIMIT ?
  `).bind(isoNow(), limit).all()

  const queued = []
  for (const row of rows.results || []) {
    const automation = normalizeAutomationRow(row)
    const result = await queueAutomation(env, automation, 'cron')
    if (result.success) {
      queued.push({
        id: automation.id,
        name: automation.name,
        job_id: result.jobId || 0,
        alreadyQueued: !!result.alreadyQueued
      })
    }
  }

  return jsonResponse({
    success: true,
    queued: queued.length,
    items: queued,
    server_time: isoNow()
  })
}

async function handleSeedDemoApi(request, env, user) {
  requireAdmin(user)

  let apiKey = await env.DB.prepare(`SELECT * FROM api_keys WHERE name = 'Demo Bunny Account' LIMIT 1`).first()
  if (!apiKey) {
    await env.DB.prepare(`
      INSERT INTO api_keys (
        name, api_key, library_id, storage_zone, ftp_host, ftp_username,
        ftp_password, ftp_port, cdn_hostname, pull_zone_id, status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
    `).bind(
      'Demo Bunny Account',
      'demo-api-key-xxxxx',
      '12345',
      'demo-storage',
      'storage.bunnycdn.com',
      'demo-user',
      'demo-pass',
      21,
      'demo.b-cdn.net',
      '12345',
      isoNow()
    ).run()
    apiKey = await env.DB.prepare(`SELECT * FROM api_keys WHERE name = 'Demo Bunny Account' LIMIT 1`).first()
  }

  let agent = await env.DB.prepare(`SELECT * FROM local_agents WHERE display_name = 'Demo Agent' LIMIT 1`).first()
  if (!agent) {
    const now = isoNow()
    const agentKey = randomHex(16)
    const agentSecret = randomHex(24)
    await env.DB.prepare(`
      INSERT INTO local_agents (
        display_name, machine_name, host_name, platform, status, agent_key,
        agent_secret_hash, last_seen_at, last_ip, capabilities_json, created_at, updated_at
      ) VALUES (?, ?, ?, ?, 'online', ?, ?, ?, ?, ?, ?, ?)
    `).bind(
      'Demo Agent',
      'DEMOBOX',
      'demo-host',
      'windows',
      agentKey,
      await hashPassword(agentSecret),
      now,
      '127.0.0.1',
      JSON.stringify({ ffmpeg: true, yt_dlp: true }),
      now,
      now
    ).run()
    agent = await env.DB.prepare(`SELECT * FROM local_agents WHERE display_name = 'Demo Agent' LIMIT 1`).first()
  }

  const sampleAutomations = [
    { name: 'Product Launch Video 2024', status: 'completed', progress: 100, outputs: ['product_launch_short.mp4'], scheduled: 0, posted: 1 },
    { name: 'Customer Testimonial - John', status: 'queued', progress: 15, outputs: [], scheduled: 1, posted: 0 },
    { name: 'Behind the Scenes Tour', status: 'error', progress: 34, outputs: [], scheduled: 0, posted: 0 },
    { name: 'How To Use Our App', status: 'completed', progress: 100, outputs: ['how_to_use_app_short.mp4'], scheduled: 2, posted: 0 }
  ]

  for (const sample of sampleAutomations) {
    let automation = await env.DB.prepare('SELECT * FROM automations WHERE name = ? LIMIT 1').bind(sample.name).first()
    if (!automation) {
      const now = isoNow()
      const automationJson = {
        video_source: 'bunny',
        api_key_id: Number(apiKey?.id || 0),
        schedule_type: 'daily',
        schedule_hour: 9,
        schedule_every_minutes: 10,
        videos_per_run: 1,
        short_duration: 60,
        playback_speed: '1.0',
        video_selection_method: 'days',
        video_days_filter: 30,
        source_shorts_mode: 'single',
        postforme_enabled: sample.scheduled > 0 || sample.posted > 0,
        postforme_schedule_mode: sample.scheduled > 0 ? 'offset' : 'immediate',
        postforme_schedule_offset_minutes: 60,
        postforme_schedule_spread_minutes: 15,
        postforme_account_ids_csv: 'demo-account-1,demo-account-2'
      }
      const progressData = {
        step: sample.status === 'error' ? 'ffmpeg' : 'complete',
        status: sample.status === 'error' ? 'error' : 'success',
        event_status: sample.status === 'error' ? 'error' : 'success',
        message: sample.status === 'error' ? 'Demo failure captured for dashboard parity.' : 'Demo automation finished.',
        progress: sample.progress,
        stats: {
          fetched: 1,
          downloaded: 1,
          processed: sample.outputs.length,
          scheduled: sample.scheduled,
          posted: sample.posted
        },
        outputs: sample.outputs,
        time: now
      }
      const insert = await env.DB.prepare(`
        INSERT INTO automations (
          owner_user_id, name, run_mode, local_agent_id, enabled, status,
          progress_percent, next_run_at, last_run_at, last_progress_at,
          automation_json, api_key_json, settings_json, progress_data, created_at, updated_at
        ) VALUES (?, ?, 'local', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      `).bind(
        user.id,
        sample.name,
        Number(agent?.id || 0),
        sample.status,
        sample.progress,
        calculateAutomationNextRunAt('daily', 9, 10),
        now,
        now,
        JSON.stringify(automationJson),
        apiKey ? JSON.stringify(apiKey) : null,
        JSON.stringify({ panel_public_base_url: '' }),
        JSON.stringify(progressData),
        now,
        now
      ).run()
      const automationId = Number(insert.meta?.last_row_id || 0)
      const jobStatus = sample.status === 'completed' ? 'completed' : (sample.status === 'error' ? 'error' : 'queued')
      const jobInsert = await env.DB.prepare(`
        INSERT INTO local_agent_jobs (
          agent_id, automation_id, trigger_source, status, queued_at, started_at, completed_at, error_message
        ) VALUES (?, ?, 'manual_run', ?, ?, ?, ?, ?)
      `).bind(
        Number(agent?.id || 0),
        automationId,
        jobStatus,
        now,
        now,
        jobStatus === 'queued' ? null : now,
        sample.status === 'error' ? 'Demo failure.' : null
      ).run()
      const jobId = Number(jobInsert.meta?.last_row_id || 0)

      for (const filename of sample.outputs) {
        await env.DB.prepare(`
          INSERT INTO output_files (
            automation_id, job_id, filename, stored_in, object_key, local_path, content_type, size_bytes, created_at
          ) VALUES (?, ?, ?, 'metadata', NULL, ?, 'video/mp4', ?, ?)
        `).bind(automationId, jobId, filename, `C:/VideoWorkflowAgentData/output/${filename}`, 0, now).run()
      }

      if (sample.scheduled > 0) {
        for (let index = 0; index < sample.scheduled; index += 1) {
          await env.DB.prepare(`
            INSERT INTO scheduled_posts (
              automation_id, job_id, filename, caption, account_ids_json, remote_post_id,
              status, scheduled_at, published_at, error_message, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, NULL, 'scheduled', ?, NULL, NULL, ?, ?)
          `).bind(
            automationId,
            jobId,
            sample.outputs[index] || `demo_${automationId}_${index + 1}.mp4`,
            `${sample.name} Demo Post ${index + 1}`,
            JSON.stringify(['demo-account-1', 'demo-account-2']),
            new Date(Date.now() + ((index + 1) * 60 * 60 * 1000)).toISOString(),
            now,
            now
          ).run()
        }
      }
    }
  }

  return redirectResponse('/index.php')
}

function renderLoginBody({ errorMessage, next, appName }) {
  return `
    <section class="panel auth-panel">
      <div class="eyebrow">Cloudflare Worker Control Plane</div>
      <h1>${escapeHtml(appName)}</h1>
      <p class="lead">Login for admins and customers. Local automations keep running on the paired PC while this panel stays online 24/7.</p>
      ${errorMessage ? `<div class="flash error">${escapeHtml(errorMessage)}</div>` : ''}
      <form method="POST" action="/login" class="stack">
        <input type="hidden" name="next" value="${escapeHtml(next)}">
        <label class="field">
          <span>Email</span>
          <input type="email" name="email" required autocomplete="username">
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" class="button primary">Login</button>
      </form>
      <p class="muted compact">Admins create users manually and clients sign in with the email/password you provide.</p>
    </section>
  `
}

function renderFeedback(feedback) {
  if (!feedback) {
    return ''
  }
  const notices = []
  if (feedback.error) {
    notices.push(`<div class="flash error">${escapeHtml(feedback.error)}</div>`)
  }
  if (feedback.success) {
    notices.push(`<div class="flash success">${escapeHtml(feedback.success)}</div>`)
  }
  return notices.join('')
}

function renderScheduledPostEntries(posts, { emptyTitle = 'No scheduled posts', emptyMessage = 'Nothing is waiting in the queue.', manageable = false } = {}) {
  if (!posts.length) {
    return `
      <div class="text-center py-12 text-gray-400">
        <p class="text-lg mb-1">${escapeHtml(emptyTitle)}</p>
        <p class="text-sm">${escapeHtml(emptyMessage)}</p>
      </div>
    `
  }

  return posts.map((post) => `
    <div class="job-row scheduled-row" data-scheduled-post="${post.id}" data-automation-id="${post.automation_id}">
      <div class="job-main">
        <div class="font-medium">${escapeHtml(post.caption || post.filename || `Scheduled Post #${post.id}`)}</div>
        <div class="text-sm text-gray-400">${escapeHtml(post.automation_name || `Automation #${post.automation_id}`)} | ${post.account_count} account(s)</div>
        <div class="text-xs text-gray-500">${escapeHtml(post.scheduled_at ? formatDisplayDateTime(post.scheduled_at) : 'Waiting for remote schedule time')}</div>
      </div>
      <div class="job-side">
        <div class="status-pill ${automationStatusClass(post.status)}">${escapeHtml(post.status)}</div>
        ${manageable ? `<button type="button" class="button ghost danger-button" onclick="workerDeleteScheduledPost(${post.id})">Delete</button>` : ''}
      </div>
    </div>
  `).join('')
}

function renderDashboardBody({ user, outputs, stats, recentJobs, scheduledPosts, feedback }) {
  const totalJobs = Number(stats.totalJobs || 0)
  const completedJobs = Number(stats.completedJobs || 0)
  const processingJobs = Number(stats.processingJobs || 0)
  const failedJobs = Number(stats.failedJobs || 0)
  const activeKeys = Number(stats.activeKeys || 0)
  const activeAutomations = Number(stats.automations || 0)
  const scheduledPostCount = Number(stats.scheduledPosts || 0)
  const postedPosts = Number(stats.postedPosts || 0)

  const recentJobsMarkup = recentJobs.length
    ? recentJobs.map((job) => `
      <div class="job-row">
        <div class="job-main">
          <div class="font-medium">${escapeHtml(job.name || `Job #${job.id}`)}</div>
          <div class="text-sm text-gray-400 font-mono">${escapeHtml(job.type || job.trigger_source || 'local_agent')}</div>
        </div>
        <div class="job-side">
          ${['queued', 'claimed', 'running', 'processing'].includes(String(job.status || '').toLowerCase()) ? `
            <div class="mini-progress">
              <div class="mini-progress-bar" style="width:${clampInt(job.progress_percent || 0, 0, 100)}%"></div>
            </div>
          ` : ''}
          <div class="text-sm font-mono w-12 text-right">${clampInt(job.progress_percent || 0, 0, 100)}%</div>
          <div class="status-pill ${automationStatusClass(job.status)}">${escapeHtml(job.status || 'unknown')}</div>
        </div>
      </div>
    `).join('')
    : `
      <div class="text-center py-12 text-gray-400">
        No jobs yet. Click "Load Demo Data" to see sample jobs, or create an automation.
      </div>
    `

  return `
    ${renderFeedback(feedback)}
    <div class="page-head">
      <div>
        <h2 class="text-xl font-semibold">Dashboard Overview</h2>
        <p class="text-sm text-gray-400 mt-1">Monitor your video workflows and automations</p>
      </div>
      <div class="toolbar wrap">
        ${user.role === 'admin' ? `<a href="/api/seed-demo.php" class="button ghost">Load Demo Data</a>` : ''}
        ${user.role === 'admin' ? `<a href="${legacyPageHref('/automation', { create: 1 })}" class="button">Create Automation</a>` : ''}
      </div>
    </div>

    <div class="stats-grid stats-grid-six">
      <div class="card rounded-lg p-4 stat-card"><div class="stat-head"><span class="text-sm text-gray-400">Total Jobs</span></div><div class="stat-value">${totalJobs}</div></div>
      <div class="card rounded-lg p-4 stat-card"><div class="stat-head"><span class="text-sm text-gray-400">Completed</span></div><div class="stat-value text-green-500">${completedJobs}</div></div>
      <div class="card rounded-lg p-4 stat-card"><div class="stat-head"><span class="text-sm text-gray-400">Processing</span></div><div class="stat-value text-indigo-500">${processingJobs}</div></div>
      <div class="card rounded-lg p-4 stat-card"><div class="stat-head"><span class="text-sm text-gray-400">Failed</span></div><div class="stat-value text-red-500">${failedJobs}</div></div>
      <div class="card rounded-lg p-4 stat-card"><div class="stat-head"><span class="text-sm text-gray-400">Active Keys</span></div><div class="stat-value">${activeKeys}</div></div>
      <div class="card rounded-lg p-4 stat-card"><div class="stat-head"><span class="text-sm text-gray-400">Active Automations</span></div><div class="stat-value text-yellow-500">${activeAutomations}</div></div>
    </div>

    <div class="stats-grid stats-grid-three">
      <div class="card rounded-lg p-4 stat-card clickable-card" onclick="workerSwitchDashboardTab('scheduled')">
        <div class="stat-head"><span class="text-sm text-orange-400 font-medium">Pending Scheduled</span></div>
        <div class="stat-value text-orange-500">${scheduledPostCount}</div>
        <div class="text-xs text-gray-500 mt-1">Click to view upcoming posts</div>
      </div>
      <div class="card rounded-lg p-4 stat-card clickable-card" onclick="workerSwitchDashboardTab('scheduled')">
        <div class="stat-head"><span class="text-sm text-pink-400 font-medium">Posted Posts</span></div>
        <div class="stat-value text-pink-500">${postedPosts}</div>
        <div class="text-xs text-gray-500 mt-1">Counts uploaded outputs reported by agents</div>
      </div>
      <div class="card rounded-lg p-4 stat-card">
        <div class="stat-head"><span class="text-sm text-sky-400 font-medium">Recent Outputs</span></div>
        <div class="stat-value text-sky-400">${outputs.length}</div>
        <div class="text-xs text-gray-500 mt-1">Open full list from Player</div>
      </div>
    </div>

    <div class="tab-inline-nav">
      <button onclick="workerSwitchDashboardTab('jobs')" id="tab-jobs" class="tab-inline active">Recent Jobs</button>
      <button onclick="workerSwitchDashboardTab('scheduled')" id="tab-scheduled" class="tab-inline">
        Scheduled Posts
        ${scheduledPostCount > 0 ? `<span class="tab-count">${scheduledPostCount}</span>` : ''}
      </button>
    </div>

    <div id="panel-jobs">
      <div class="card rounded-lg">
        <div class="p-4 border-b border-gray-800">
          <h3 class="text-lg font-semibold">Recent Jobs</h3>
        </div>
        <div class="p-4">
          <div class="space-y-2">${recentJobsMarkup}</div>
        </div>
      </div>
    </div>

    <div id="panel-scheduled" class="hidden">
      <div class="card rounded-lg">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
          <h3 class="text-lg font-semibold flex items-center gap-2">Scheduled Posts</h3>
          <button type="button" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded-lg text-sm" onclick="window.location.reload()">Refresh</button>
        </div>
        <div class="p-4">
          <div class="space-y-2">
            ${renderScheduledPostEntries(scheduledPosts, {
              emptyTitle: 'No scheduled posts',
              emptyMessage: user.role === 'admin'
                ? 'Scheduled queue will appear here when Post for Me jobs are queued from automations.'
                : 'No scheduled items are visible for this account yet.'
            })}
          </div>
        </div>
      </div>
    </div>

    <script>
      function workerSwitchDashboardTab(tab) {
        document.getElementById('panel-jobs')?.classList.toggle('hidden', tab !== 'jobs');
        document.getElementById('panel-scheduled')?.classList.toggle('hidden', tab !== 'scheduled');
        document.getElementById('tab-jobs')?.classList.toggle('active', tab === 'jobs');
        document.getElementById('tab-scheduled')?.classList.toggle('active', tab === 'scheduled');
      }
    </script>
  `
}

function renderAutomationBody({ user, automations, agents, apiKeys, outputSummary, scheduledPosts, scheduledCounts, feedback, editor, editorOpen, logAutomation }) {
  const cards = automations.map((automation) => {
    const config = parseJsonMaybe(automation.automation_json, {})
    const progressState = parseJsonMaybe(automation.progress_data, {})
    const automationNameJs = JSON.stringify(String(automation.name || ''))
    const editorPayload = escapeHtml(JSON.stringify(buildAutomationEditorState(automation)))
    const schedule = String(config.schedule_type || 'daily')
    const videosPerRun = config.videos_per_run ?? '-'
    const progressPercent = clampInt(progressState.progress ?? automation.progress_percent ?? 0, 0, 100)
    const progressMessage = String(progressState.message || (automation.enabled ? 'Waiting for next run.' : 'Automation disabled.'))
    const nextRunLabel = automation.next_run_at ? formatDisplayDateTime(automation.next_run_at) : 'Not scheduled'
    const nextRunTs = toUnixTimestamp(automation.next_run_at)
    const selectionLabel = config.video_selection_method === 'date_range' && (config.video_start_date || config.video_end_date)
      ? `${String(config.video_start_date || '-')} to ${String(config.video_end_date || '-')}`
      : `Last ${String(config.video_days_filter || 30)} days`
    const shortsLabel = String(config.source_shorts_mode || 'single') === 'single'
      ? '1 short/source'
      : `up to ${String(config.source_shorts_max_count || 1)} shorts/source`
    const scheduledCount = Number(scheduledCounts.get(Number(automation.id)) || 0)
    const statusClass = automationStatusClass(automation.status)
    const isRunning = ['queued', 'processing', 'running'].includes(String(automation.status || '').toLowerCase())
    const outputs = Array.isArray(progressState.outputs) ? progressState.outputs.filter(Boolean).slice(-5).reverse() : []
    const stats = { fetched: 0, downloaded: 0, processed: 0, scheduled: 0, posted: 0, ...(progressState.stats || {}) }
    const assignedAgent = agents.find((agent) => Number(agent.id) === Number(automation.local_agent_id || 0))
    const agentLabel = assignedAgent ? (assignedAgent.display_name || assignedAgent.machine_name || `Agent #${assignedAgent.id}`) : 'Current host'

    return `
      <article class="card rounded-lg automation-card-shell" data-automation-card="${automation.id}" data-automation-name="${escapeHtml(automation.name)}">
        <div class="p-4 flex items-center justify-between border-b border-gray-800">
          <div class="flex items-start gap-3 min-w-0">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center ${isRunning ? 'bg-green-500/10' : 'bg-gray-700'}">
              <svg class="w-5 h-5 ${isRunning ? 'text-green-500' : 'text-gray-400'}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
            </div>
            <div class="min-w-0">
              <strong>${escapeHtml(automation.name)}</strong>
              <div class="text-sm text-gray-400">
                ${escapeHtml(automation.run_mode === 'github_runner' ? 'GitHub Runner' : 'Local Runner')} |
                ${escapeHtml(automation.run_mode === 'local' ? `Agent: ${agentLabel}` : 'Remote workflow')} |
                ${escapeHtml(schedule)} |
                ${escapeHtml(selectionLabel)} |
                Process ${escapeHtml(String(videosPerRun))}/run
              </div>
              <div class="text-sm text-gray-500">
                ${escapeHtml(shortsLabel)} | Speed ${escapeHtml(String(config.playback_speed || '1.0'))}x | Next run ${escapeHtml(nextRunLabel)}
              </div>
              <div class="text-xs text-gray-500 mt-1">
                <span class="text-gray-400">Countdown:</span>
                ${nextRunTs
                  ? `<span class="countdown-timer" data-target="${nextRunTs}" data-automation-id="${automation.id}">${escapeHtml(nextRunLabel)}</span>`
                  : '<span> Not scheduled</span>'}
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" class="p-2 hover:bg-gray-700 rounded text-sky-400" title="Test Fetch Videos" onclick="return testFetch(${automation.id}, ${JSON.stringify(String(config.video_source || 'ftp'))})">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8m-8 5h5m-7 8h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </button>
            <button type="button" class="p-2 hover:bg-gray-700 rounded" title="View Logs" data-open-runtime data-automation-id="${automation.id}" data-automation-name="${escapeHtml(automation.name)}">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </button>
            ${isRunning ? `
              <form method="POST" action="/automation" class="inline" onsubmit="return confirm('Stop this running job?')">
                <input type="hidden" name="action" value="stop_automation">
                <input type="hidden" name="automation_id" value="${automation.id}">
                <button type="submit" class="p-2 hover:bg-gray-700 rounded text-red-400" title="Stop">
                  <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                </button>
              </form>
            ` : `
              <button type="button" class="p-2 hover:bg-gray-700 rounded text-green-400" title="Run Now" data-run-automation data-automation-id="${automation.id}" data-automation-name="${escapeHtml(automation.name)}">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
              </button>
            `}
            <form method="POST" action="/automation" class="inline">
              <input type="hidden" name="action" value="toggle_automation">
              <input type="hidden" name="automation_id" value="${automation.id}">
              <button type="submit" class="p-2 hover:bg-gray-700 rounded" title="${automation.enabled ? 'Disable' : 'Enable'}">
                ${automation.enabled
                  ? '<svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                  : '<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'}
              </button>
            </form>
            ${truthyValue(config.rotation_enabled, false) ? `
              <form method="POST" action="/automation" class="inline" onsubmit="return confirm('Reset rotation tracking for this automation?')">
                <input type="hidden" name="action" value="reset_rotation">
                <input type="hidden" name="automation_id" value="${automation.id}">
                <button type="submit" class="p-2 hover:bg-gray-700 rounded text-indigo-400" title="Reset Rotation">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
              </form>
            ` : ''}
            <form method="POST" action="/automation" class="inline" onsubmit="return confirm('Delete this automation?')">
              <input type="hidden" name="action" value="delete_automation">
              <input type="hidden" name="automation_id" value="${automation.id}">
              <button type="submit" class="p-2 hover:bg-gray-700 rounded text-red-500" title="Delete">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
              </button>
            </form>
            <button type="button" class="p-2 hover:bg-gray-700 rounded text-blue-400" title="Edit Automation" data-edit-automation="${editorPayload}">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
            </button>
          </div>
        </div>

        <div class="p-4">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
              <div class="text-gray-400 mb-1">Status</div>
              <span id="automation-status-${automation.id}" class="px-2 py-1 rounded text-xs font-medium ${statusClass}">${escapeHtml(automation.status)}</span>
            </div>
            <div>
              <div class="text-gray-400 mb-1">Short Duration</div>
              <div class="font-mono">${escapeHtml(String(config.short_duration || 60))}s</div>
            </div>
            <div>
              <div class="text-gray-400 mb-1">Run Mode</div>
              <div class="text-xs">${escapeHtml(automation.run_mode)}</div>
            </div>
            <div>
              <div class="text-gray-400 mb-1">Scheduled</div>
              <button type="button" class="text-indigo-400 text-xs hover:underline" onclick='workerOpenScheduledModal(${automation.id}, ${automationNameJs})'>
                ${scheduledCount ? `${scheduledCount} in queue` : 'Open queue'}
              </button>
            </div>
          </div>

          <div class="flex flex-wrap gap-2 mt-4">
            <span class="px-2 py-1 bg-emerald-500/10 rounded text-xs text-emerald-300 border border-emerald-500/20">${escapeHtml(automation.run_mode === 'github_runner' ? 'GitHub Runner' : 'Local Runner')}</span>
            ${truthyValue(config.postforme_enabled, false) ? '<span class="px-2 py-1 bg-gradient-to-r from-pink-500/10 to-purple-500/10 rounded text-xs text-pink-400 border border-pink-500/20">Post for Me</span>' : ''}
            ${truthyValue(config.youtube_enabled, false) ? '<span class="px-2 py-1 bg-red-500/10 rounded text-xs text-red-500">YouTube</span>' : ''}
            ${truthyValue(config.instagram_enabled, false) ? '<span class="px-2 py-1 bg-pink-500/10 rounded text-xs text-pink-500">Instagram</span>' : ''}
            ${truthyValue(config.facebook_enabled, false) ? '<span class="px-2 py-1 bg-blue-500/10 rounded text-xs text-blue-500">Facebook</span>' : ''}
            ${truthyValue(config.tiktok_enabled, false) ? '<span class="px-2 py-1 bg-gray-500/10 rounded text-xs text-gray-300">TikTok</span>' : ''}
            ${truthyValue(config.rotation_enabled, false) ? '<span class="px-2 py-1 bg-indigo-500/10 rounded text-xs text-indigo-400 border border-indigo-500/20">Rotation Enabled</span>' : ''}
          </div>

          <div class="mt-4 grid grid-cols-5 gap-2 text-center text-xs">
            <div class="p-2 bg-gray-800 rounded">
              <div class="text-lg font-bold text-blue-400" id="stat-fetched-${automation.id}">${Number(stats.fetched || 0)}</div>
              <div class="text-gray-500">Fetched</div>
            </div>
            <div class="p-2 bg-gray-800 rounded">
              <div class="text-lg font-bold text-yellow-400" id="stat-downloaded-${automation.id}">${Number(stats.downloaded || 0)}</div>
              <div class="text-gray-500">Downloaded</div>
            </div>
            <div class="p-2 bg-gray-800 rounded">
              <div class="text-lg font-bold text-green-400" id="stat-processed-${automation.id}">${Number(stats.processed || 0)}</div>
              <div class="text-gray-500">Processed</div>
            </div>
            <div class="p-2 bg-gradient-to-r from-indigo-900/30 to-blue-900/30 rounded border border-indigo-500/20 cursor-pointer hover:bg-indigo-900/50 transition-colors" onclick='workerOpenScheduledModal(${automation.id}, ${automationNameJs})'>
              <div class="text-lg font-bold text-indigo-400" id="stat-scheduled-${automation.id}">${Number(stats.scheduled || 0)}</div>
              <div class="text-gray-500">Scheduled</div>
            </div>
            <div class="p-2 bg-gradient-to-r from-pink-900/30 to-purple-900/30 rounded border border-pink-500/20">
              <div class="text-lg font-bold text-pink-400" id="stat-posted-${automation.id}">${Number(stats.posted || 0)}</div>
              <div class="text-gray-500">Posted</div>
            </div>
          </div>

          <div id="progress-${automation.id}" class="mt-3 p-3 bg-gray-800/30 rounded-lg${isRunning ? '' : ' hidden'}">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-medium text-green-400">Processing...</span>
              <span class="text-xs text-gray-400" id="progress-percent-${automation.id}">${progressPercent}%</span>
            </div>
            <div class="h-2 bg-gray-700 rounded-full overflow-hidden">
              <div id="progress-bar-${automation.id}" class="h-full bg-gradient-to-r from-green-500 to-emerald-400 transition-all duration-300" style="width:${progressPercent}%"></div>
            </div>
            <div class="mt-2 text-xs text-gray-300" id="automation-message-${automation.id}">${escapeHtml(progressMessage)}</div>
            <div class="mt-3 border-t border-gray-700/60 pt-2">
              <div class="text-xs text-gray-400 mb-1">Edited Outputs</div>
              <div id="progress-outputs-${automation.id}" class="space-y-1 text-xs">
                ${outputs.length
                  ? outputs.map((item) => `<div class="block text-cyan-300 truncate">${escapeHtml(String(item))}</div>`).join('')
                  : '<div class="text-gray-500">No edited output yet</div>'}
              </div>
            </div>
          </div>
        </div>
      </article>
    `
  }).join('')

  return `
    ${renderFeedback(feedback)}
    <div class="page-head">
      <div>
        <h2 class="text-xl font-semibold">Video Automations</h2>
        <p class="text-sm text-gray-400 mt-1">Auto-convert videos to shorts and post to social media</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <a href="${legacyPageHref('/player')}" class="px-4 py-2 bg-green-600 hover:bg-green-700 rounded-lg flex items-center gap-2" title="View processed videos">View Processed Videos</a>
        <button type="button" onclick="workerOpenScheduledModal(0, 'All Automations')" class="px-3 py-2 bg-amber-600 hover:bg-amber-700 rounded-lg flex items-center gap-2 text-sm" title="View/Delete scheduled posts across all automations">Scheduled Queue</button>
        ${user.role === 'admin' ? `
          <form method="POST" action="/automation" onsubmit="return confirm('Stop all running and enabled automations?')">
            <input type="hidden" name="action" value="stop_all_automations">
            <button class="px-3 py-2 bg-red-600 hover:bg-red-700 rounded-lg flex items-center gap-2 text-sm" type="submit">Stop All</button>
          </form>
        ` : ''}
        <button type="button" data-open-create class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg flex items-center gap-2 ${(!user.assigned_local_agent_id && user.role !== 'admin') ? 'opacity-50 cursor-not-allowed' : ''}" ${(!user.assigned_local_agent_id && user.role !== 'admin') ? 'disabled title="Admin must assign a local agent first"' : ''}>Create Automation</button>
      </div>
    </div>

    <div class="card p-4 mb-6 bg-gradient-to-r from-gray-800 to-gray-900 border border-gray-700 output-summary-card">
      <div class="flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
          <span class="text-gray-400">Output Folder:</span>
          <code class="bg-gray-900 px-3 py-1 rounded text-green-400 text-sm font-mono">${escapeHtml(String(outputSummary.outputFolder || getDefaultLocalOutputDirectory()))}</code>
        </div>
        <div class="flex items-center gap-2 text-sm text-gray-400">
          Processed videos stay on the paired PC. The Worker only tracks local output metadata and status.
        </div>
        <div class="ml-auto flex items-center gap-2">
          ${Number(outputSummary.total || 0) > 0
            ? `<a href="${legacyPageHref('/player')}" class="px-3 py-1 bg-green-600 rounded-lg text-sm hover:bg-green-700">${Number(outputSummary.total || 0)} videos ready to view</a>`
            : '<span class="text-gray-500 text-sm">No processed videos yet</span>'}
        </div>
      </div>
    </div>

    <section class="dashboard-grid">
      <section class="panel span-two">
        ${cards || `
          <div class="note-card empty-state">
            <strong>No automations yet</strong>
            <div class="muted compact">Create your first automation to restore the old local-runner workflow on the Worker control plane.</div>
          </div>
        `}
      </section>

      <section class="panel">
        <div class="section-head">
          <div>
            <div class="eyebrow">Queue Snapshot</div>
            <h2>Scheduled Posts</h2>
          </div>
          <button type="button" class="button ghost" onclick="workerOpenScheduledModal(0, 'All Automations')">Manage</button>
        </div>
        <div class="list-stack compact-stack">
          ${renderScheduledPostEntries(scheduledPosts.slice(0, 6), {
            emptyTitle: 'No scheduled posts',
            emptyMessage: 'When automations queue delayed posts they will appear here.'
          })}
        </div>
      </section>
    </section>
    ${renderAutomationEditorModal({ user, agents, apiKeys, editor, editorOpen })}
    ${renderAutomationRuntimeModal(logAutomation)}
    ${renderScheduledPostsModal({ user, posts: scheduledPosts })}
    ${renderAutomationEditorScript({ user, defaultEditor: buildAutomationEditorState(null), initialEditor: editor, initialEditorOpen: editorOpen })}
  `
}

function renderAutomationEditorModal({ user, agents, apiKeys, editor, editorOpen }) {
  const title = editor.id ? 'Edit Automation' : 'Create Automation'
  return `
    <div class="modal-backdrop${editorOpen ? '' : ' hidden'}" id="automation-editor-modal" data-editor-open="${editorOpen ? '1' : '0'}">
      <div class="modal-panel modal-wide">
        <div class="section-head">
          <div>
            <div class="eyebrow">Automation Editor</div>
            <h2 id="automation-editor-title">${escapeHtml(title)}</h2>
            <p class="muted compact">Popup-based create and edit flow for local and GitHub automation runs.</p>
          </div>
          <div class="toolbar wrap">
            <button type="button" class="button ghost" onclick="workerCloseAutomationEditor()">Close</button>
          </div>
        </div>
        ${renderAutomationEditorForm({ user, agents, apiKeys, editor })}
      </div>
    </div>
  `
}

function renderAutomationRuntimeModal(logAutomation) {
  const automationId = logAutomation ? Number(logAutomation.id) : 0
  const automationName = logAutomation ? String(logAutomation.name || `Automation #${automationId}`) : ''
  return `
    <div
      class="modal-backdrop hidden"
      id="automation-runtime-modal"
      data-initial-open="${automationId > 0 ? '1' : '0'}"
      data-automation-id="${automationId}"
      data-automation-name="${escapeHtml(automationName)}"
    >
      <div class="modal-panel modal-wide">
        <div class="section-head">
          <div>
            <div class="eyebrow">Runtime Monitor</div>
            <h2 id="runtime-modal-title">Automation Runtime</h2>
            <p class="muted compact" id="runtime-modal-subtitle">Queue jobs, inspect progress, and follow logs from the local agent.</p>
          </div>
          <div class="toolbar wrap">
            <div class="badge status-neutral" id="runtime-modal-status">idle</div>
            <button type="button" class="button ghost" onclick="workerCloseRuntimeModal()">Close</button>
          </div>
        </div>

        <div class="progress-bar runtime-progress">
          <span id="runtime-modal-progress" style="width:0%"></span>
        </div>
        <div class="progress-meta">
          <span id="runtime-modal-progress-text">0%</span>
          <span id="runtime-modal-message">Waiting for runtime activity.</span>
        </div>

        <div class="stats-row runtime-stats">
          <div class="metric"><span id="runtime-stat-fetched">0</span><small>Fetched</small></div>
          <div class="metric"><span id="runtime-stat-downloaded">0</span><small>Downloaded</small></div>
          <div class="metric"><span id="runtime-stat-processed">0</span><small>Processed</small></div>
          <div class="metric"><span id="runtime-stat-scheduled">0</span><small>Scheduled</small></div>
          <div class="metric"><span id="runtime-stat-posted">0</span><small>Posted</small></div>
          <div class="metric"><span id="runtime-stat-job">-</span><small>Job</small></div>
        </div>

        <div class="grid two runtime-grid">
          <section class="subpanel">
            <div class="section-head">
              <div><h2>Recent Outputs</h2></div>
            </div>
            <div id="runtime-modal-outputs" class="runtime-list muted compact">No output reported yet.</div>
          </section>
          <section class="subpanel">
            <div class="section-head">
              <div><h2>Recent Logs</h2></div>
            </div>
            <div id="runtime-modal-logs" class="runtime-list muted compact">No log activity yet.</div>
          </section>
        </div>
      </div>
    </div>
  `
}

function renderScheduledPostsModal({ user, posts }) {
  return `
    <div class="modal-backdrop hidden" id="scheduled-posts-modal" data-can-manage="${user.role === 'admin' ? '1' : '0'}">
      <div class="modal-panel modal-wide">
        <div class="section-head">
          <div>
            <div class="eyebrow">Scheduled Queue</div>
            <h2 id="scheduled-posts-title">Scheduled Posts</h2>
            <p class="muted compact" id="scheduled-posts-subtitle">Upcoming delayed posts across your automations.</p>
          </div>
          <div class="toolbar wrap">
            ${user.role === 'admin' ? `<button type="button" class="button ghost danger-button" onclick="workerDeleteAllScheduledPosts()">Delete All</button>` : ''}
            <button type="button" class="button ghost" onclick="workerRefreshScheduledPosts()">Refresh</button>
            <button type="button" class="button ghost" onclick="workerCloseScheduledModal()">Close</button>
          </div>
        </div>
        <div id="scheduled-posts-list" class="list-stack compact-stack">
          ${renderScheduledPostEntries(posts.slice(0, 12), {
            emptyTitle: 'No scheduled posts',
            emptyMessage: 'Queue items will appear here after delayed posting jobs are created.',
            manageable: user.role === 'admin'
          })}
        </div>
      </div>
    </div>
  `
}

function renderSettingsBody({ tab, settings, feedback }) {
  const tabs = [
    ['bunny', 'Bunny CDN', 'bg-orange-600'],
    ['stream', 'Stream APIs', 'bg-red-600'],
    ['ftp', 'FTP Server', 'bg-blue-600'],
    ['openai', 'AI Settings', 'bg-purple-600'],
    ['ffmpeg', 'FFmpeg', 'bg-violet-600'],
    ['storage', 'Storage', 'bg-yellow-600'],
    ['github_runner', 'GitHub Runner', 'bg-sky-600'],
    ['postforme', 'Post for Me', 'bg-pink-600']
  ]

  const saveActionByTab = {
    bunny: 'save_bunny',
    stream: 'save_stream',
    ftp: 'save_ftp',
    openai: 'save_openai',
    ffmpeg: 'save_ffmpeg',
    storage: 'save_storage',
    github_runner: 'save_github_runner',
    postforme: 'save_postforme'
  }
  const testActionByTab = {
    bunny: 'test_bunny',
    ftp: 'test_ftp',
    openai: 'test_openai',
    ffmpeg: 'test_ffmpeg',
    github_runner: 'test_github_runner',
    postforme: 'test_postforme'
  }
  const saveLabelByTab = {
    bunny: 'Save Bunny Settings',
    stream: 'Save Stream API Settings',
    ftp: 'Save FTP Settings',
    openai: 'Save OpenAI Settings',
    ffmpeg: 'Save FFmpeg Settings',
    storage: 'Save Storage Settings',
    github_runner: 'Save GitHub Runner Settings',
    postforme: 'Save Post for Me Settings'
  }
  const tabLinks = tabs.map(([key, label, activeColor]) => `
    <a href="${legacyPageHref('/settings', { tab: key })}" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors ${tab === key ? `${activeColor} text-white` : 'bg-gray-800 text-gray-400 hover:text-white'}">
      ${label}
    </a>
  `).join('')

  return `
    ${renderFeedback(feedback)}
    <div class="flex items-center justify-between mb-6">
      <div>
        <h2 class="text-xl font-semibold">Settings</h2>
        <p class="text-sm text-gray-400 mt-1">Configure all API keys, runtime settings, cookies, and publishing integrations.</p>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-800 pb-4">
      ${tabLinks}
    </div>

    <form method="POST" action="/settings">
      <input type="hidden" name="action" value="${saveActionByTab[tab] || 'save_settings'}">
      <input type="hidden" name="tab" value="${escapeHtml(tab)}">
      <div class="card rounded-lg mb-6">
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
          <h3 class="font-semibold">${escapeHtml(settingsTabLabel(tab))}</h3>
          <span class="px-2 py-1 rounded text-xs ${Object.values(getSettingsTabFields(tab)).length ? 'bg-green-500/10 text-green-400' : 'bg-yellow-500/10 text-yellow-400'}">
            Worker D1
          </span>
        </div>
        <div class="p-4 space-y-4">
          ${renderSettingsTabFields(tab, settings)}
        </div>
      </div>

      <div class="flex gap-3 flex-wrap">
        <button type="submit" class="flex-1 min-w-[220px] py-3 bg-indigo-600 hover:bg-indigo-700 rounded-lg font-medium">
          ${escapeHtml(saveLabelByTab[tab] || `Save ${settingsTabLabel(tab)}`)}
        </button>
        ${testActionByTab[tab] ? `
          <button type="submit" formaction="${legacyPageHref('/settings', { tab })}" name="action" value="${testActionByTab[tab]}" class="px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg font-medium">
            Test Connection
          </button>
        ` : ''}
        ${tab === 'ffmpeg' ? `
          <button type="submit" formaction="${legacyPageHref('/settings', { tab: 'ffmpeg' })}" name="action" value="install_ffmpeg_runtime" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 rounded-lg font-medium">
            Install FFmpeg
          </button>
        ` : ''}
        ${tab === 'storage' ? `
          <button type="submit" formaction="${legacyPageHref('/settings', { tab: 'storage' })}" name="action" value="clear_temp" class="px-6 py-3 bg-red-600 hover:bg-red-700 rounded-lg font-medium">
            Clear Temp
          </button>
          <button type="submit" formaction="${legacyPageHref('/settings', { tab: 'storage' })}" name="action" value="open_folder" class="px-6 py-3 bg-yellow-600 hover:bg-yellow-700 rounded-lg font-medium">
            Open Output Folder
          </button>
        ` : ''}
      </div>
    </form>
  `
}

function renderApiKeysBody({ keys, feedback }) {
  const cards = keys.map((key) => `
    <article class="list-card">
      <div class="list-card-head">
        <div>
          <strong>${escapeHtml(key.name)}</strong>
          <div class="muted compact">Library ${escapeHtml(key.library_id)} | ${escapeHtml(key.cdn_hostname || '-') } | FTP ${escapeHtml(key.ftp_host || '-')}</div>
        </div>
        <div class="badge">${escapeHtml(key.status)}</div>
      </div>
      <form method="POST" action="/api-keys" class="stack compact-form">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="${key.id}">
        <div class="grid two">
          <label class="field"><span>Name</span><input type="text" name="name" value="${escapeHtml(key.name)}" required></label>
          <label class="field"><span>Library ID</span><input type="text" name="library_id" value="${escapeHtml(key.library_id)}" required></label>
        </div>
        <label class="field"><span>API Key</span><input type="password" name="api_key" value="${escapeHtml(key.api_key)}" required></label>
        <div class="grid two">
          <label class="field"><span>Storage Zone</span><input type="text" name="storage_zone" value="${escapeHtml(key.storage_zone || '')}"></label>
          <label class="field"><span>CDN Hostname</span><input type="text" name="cdn_hostname" value="${escapeHtml(key.cdn_hostname || '')}"></label>
        </div>
        <div class="grid two">
          <label class="field"><span>FTP Host</span><input type="text" name="ftp_host" value="${escapeHtml(key.ftp_host || '')}"></label>
          <label class="field"><span>FTP Username</span><input type="text" name="ftp_username" value="${escapeHtml(key.ftp_username || '')}"></label>
        </div>
        <div class="grid two">
          <label class="field"><span>FTP Password</span><input type="password" name="ftp_password" value="${escapeHtml(key.ftp_password || '')}"></label>
          <label class="field"><span>FTP Port</span><input type="number" name="ftp_port" value="${escapeHtml(String(key.ftp_port || 21))}"></label>
        </div>
        <div class="grid two">
          <label class="field"><span>Pull Zone ID</span><input type="text" name="pull_zone_id" value="${escapeHtml(key.pull_zone_id || '')}"></label>
          <label class="field">
            <span>Status</span>
            <select name="status">
              <option value="active"${key.status === 'active' ? ' selected' : ''}>Active</option>
              <option value="inactive"${key.status === 'inactive' ? ' selected' : ''}>Inactive</option>
            </select>
          </label>
        </div>
        <button class="button" type="submit">Save</button>
      </form>
      <div class="toolbar wrap">
        <form method="POST" action="/api-keys">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="${key.id}">
          <button class="button" type="submit">${key.status === 'active' ? 'Disable' : 'Enable'}</button>
        </form>
        <form method="POST" action="/api-keys" onsubmit="return confirm('Delete this connection?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="${key.id}">
          <button class="button ghost" type="submit">Delete</button>
        </form>
      </div>
    </article>
  `).join('')

  return `
    ${renderFeedback(feedback)}
    <section class="dashboard-grid">
      <section class="panel">
        <div class="section-head">
          <div>
            <div class="eyebrow">Bunny Connections</div>
            <h1>Add API Key</h1>
          </div>
        </div>
        <form method="POST" action="/api-keys" class="stack">
          <input type="hidden" name="action" value="create">
          <label class="field"><span>Connection Name</span><input type="text" name="name" required></label>
          <div class="grid two">
            <label class="field"><span>API Key</span><input type="password" name="api_key" required></label>
            <label class="field"><span>Library ID</span><input type="text" name="library_id" required></label>
          </div>
          <div class="grid two">
            <label class="field"><span>Storage Zone</span><input type="text" name="storage_zone"></label>
            <label class="field"><span>CDN Hostname</span><input type="text" name="cdn_hostname"></label>
          </div>
          <div class="grid two">
            <label class="field"><span>FTP Host</span><input type="text" name="ftp_host"></label>
            <label class="field"><span>FTP Username</span><input type="text" name="ftp_username"></label>
          </div>
          <div class="grid two">
            <label class="field"><span>FTP Password</span><input type="password" name="ftp_password"></label>
            <label class="field"><span>FTP Port</span><input type="number" name="ftp_port" value="21"></label>
          </div>
          <label class="field"><span>Pull Zone ID</span><input type="text" name="pull_zone_id"></label>
          <button type="submit" class="button primary">Create Connection</button>
        </form>
      </section>
      <section class="panel span-two">
        <div class="section-head">
          <div>
            <div class="eyebrow">Saved Connections</div>
            <h2>API Keys</h2>
          </div>
        </div>
        ${cards || '<p class="muted">No Bunny connections configured yet.</p>'}
      </section>
    </section>
  `
}

function renderJobsBody({ jobs, feedback }) {
  const rows = jobs.map((job) => `
    <div class="job-row">
      <div class="job-main">
        <div class="font-medium">${escapeHtml(job.name || `Job #${job.id}`)}</div>
        <div class="text-sm text-gray-400 font-mono">${escapeHtml(job.type || job.trigger_source || 'local_agent')}</div>
        <div class="text-xs text-gray-500">${escapeHtml(job.created_at || job.queued_at || '')}</div>
      </div>
      <div class="job-side">
        ${['queued', 'claimed', 'running', 'processing'].includes(String(job.status || '').toLowerCase()) ? `
          <div class="mini-progress">
            <div class="mini-progress-bar" style="width:${clampInt(job.progress_percent || 0, 0, 100)}%"></div>
          </div>
        ` : ''}
        <div class="text-sm font-mono w-12 text-right">${clampInt(job.progress_percent || 0, 0, 100)}%</div>
        <div class="status-pill ${automationStatusClass(job.status)}">${escapeHtml(job.status || 'unknown')}</div>
      </div>
    </div>
  `).join('')

  return `
    ${renderFeedback(feedback)}
    <div class="page-head">
      <div>
        <h2 class="text-xl font-semibold">Video Jobs</h2>
        <p class="text-sm text-gray-400 mt-1">Track automation job history and runtime status</p>
      </div>
    </div>
    <div class="card rounded-lg">
      <div class="p-4 border-b border-gray-800">
        <h3 class="text-lg font-semibold">Recent Jobs</h3>
      </div>
      <div class="p-4">
        <div class="space-y-2">
          ${rows || '<div class="text-center py-12 text-gray-400">No jobs yet.</div>'}
        </div>
      </div>
    </div>
  `
}

function renderPlayerBody({ user, outputs, summary, feedback }) {
  const cards = outputs.map((output) => {
    const localPath = String(output.local_path || '').trim()
    const storageLabel = getOutputStorageLabel(output)
    return `
    <article class="player-card">
      <div class="player-card-preview">
        <div class="player-placeholder">${localPath ? 'Local File' : 'Metadata Only'}</div>
      </div>
      <div class="player-card-body">
        <div class="list-card-head">
          <div>
            <strong>${escapeHtml(output.filename)}</strong>
            <div class="muted compact">${escapeHtml(formatDisplayDateTime(output.created_at))} | ${escapeHtml(storageLabel)}</div>
          </div>
          <div class="status-pill ${localPath ? 'status-success' : 'status-neutral'}">${escapeHtml(storageLabel)}</div>
        </div>
        <div class="muted compact">${localPath ? `Local path: ${escapeHtml(localPath)}` : 'Local path not reported by the paired agent.'}</div>
        <div class="toolbar wrap">
          <span class="muted compact">Open this file from the paired PC output folder.</span>
        </div>
      </div>
    </article>
  `}).join('')

  return `
    ${renderFeedback(feedback)}
    <div class="page-head">
      <div>
        <h2 class="text-xl font-semibold">Processed Shorts</h2>
        <p class="text-sm text-gray-400 mt-1">View and manage your generated short videos</p>
      </div>
      <div class="toolbar wrap">
        <a class="button ghost" href="${legacyPageHref('/automation')}">Back to Automations</a>
        ${user?.role === 'admin' ? `
          <button type="button" class="button ghost danger-button" onclick="workerDeleteAllOutputVideos()">
            Delete All Local Videos
          </button>
        ` : ''}
        <button type="button" class="button" onclick="window.location.reload()">Refresh</button>
      </div>
    </div>

    <div class="card rounded-lg p-4 output-summary-card">
      <div class="section-head">
        <div>
          <h3 class="text-lg font-semibold">Output Directory Snapshot</h3>
          <p class="text-sm text-gray-400 mt-1">Local output metadata reported by paired agents.</p>
        </div>
      </div>
      <div class="muted compact mb-3">Primary folder: <code>${escapeHtml(String(summary.outputFolder || getDefaultLocalOutputDirectory()))}</code></div>
      <div class="stats-row">
        <div class="metric"><span>${Number(summary.total || 0)}</span><small>Total</small></div>
        <div class="metric"><span>${Number(summary.localCount || 0)}</span><small>Local</small></div>
        <div class="metric"><span>${Number(summary.pathCount || 0)}</span><small>With Path</small></div>
      </div>
    </div>

    <div class="player-grid">
      ${cards || '<div class="card rounded-lg p-12 text-center text-gray-400">No outputs yet.</div>'}
    </div>
    <script>
      async function workerDeleteAllOutputVideos() {
        if (!confirm('Delete all videos from output folder?')) return false;
        try {
          const response = await fetch('/api/delete-all-output-videos.php', {
            method: 'POST',
            body: new URLSearchParams({ mode: 'all' }),
            headers: { 'Accept': 'application/json' }
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Unable to delete output videos.');
          }
          showToast(data.message || 'Output videos deleted.', 'success');
          window.setTimeout(() => window.location.reload(), 300);
        } catch (error) {
          showToast(error.message || 'Unable to delete output videos.', 'error');
        }
        return false;
      }
    </script>
  `
}

function renderAutomationEditorForm({ user, agents, apiKeys, editor }) {
  const apiKeyOptions = ['<option value="">Select Bunny connection</option>', ...apiKeys.map((key) => `<option value="${key.id}"${selectedAttr(editor.api_key_id, key.id)}>${escapeHtml(key.name)}</option>`)].join('')
  const selectedAgentId = editor.local_agent_id || (user.role !== 'admin' ? (user.assigned_local_agent_id || '') : '')
  const agentOptions = [
    `<option value="">${user.role === 'admin' ? 'Select local agent' : 'Assigned device'}</option>`,
    ...agents.map((agent) => `<option value="${agent.id}"${selectedAttr(selectedAgentId, agent.id)}>${escapeHtml(agent.display_name || `Agent #${agent.id}`)}</option>`)
  ].join('')
  const canUseGithubRunner = user.role === 'admin' || !!user.can_use_github_runner

  return `
    <form method="POST" action="/automation" class="stack" id="automation-editor-form">
      <input type="hidden" name="action" value="save_automation">
      <input type="hidden" name="automation_id" value="${editor.id || ''}">
      <div class="tab-strip">
        <button type="button" class="tab-button active" data-tab-button="basic" onclick="workerShowAutomationTab('basic')">1. Basic</button>
        <button type="button" class="tab-button" data-tab-button="video" onclick="workerShowAutomationTab('video')">2. Video</button>
        <button type="button" class="tab-button" data-tab-button="taglines" onclick="workerShowAutomationTab('taglines')">3. Taglines</button>
        <button type="button" class="tab-button" data-tab-button="publish" onclick="workerShowAutomationTab('publish')">4. Social</button>
      </div>

      <section class="tab-pane active" data-tab-pane="basic">
        <div class="stack">
          <label class="field"><span>Automation Name</span><input type="text" name="name" value="${escapeHtml(editor.name)}" required></label>
          <label class="field">
            <span>Video Source</span>
            <select name="video_source" id="automation_video_source" onchange="workerToggleAutomationSource()">
              <option value="ftp"${selectedAttr(editor.video_source, 'ftp')}>FTP Server</option>
              <option value="bunny"${selectedAttr(editor.video_source, 'bunny')}>Bunny CDN</option>
              <option value="manual_links"${selectedAttr(editor.video_source, 'manual_links')}>Manual Links</option>
              <option value="youtube_channel"${selectedAttr(editor.video_source, 'youtube_channel')}>YouTube Channel</option>
            </select>
          </label>
          <div id="automation_api_key_group"${editor.video_source === 'bunny' ? '' : ' class="hidden"'}>
            <label class="field"><span>Bunny Connection</span><select name="api_key_id">${apiKeyOptions}</select></label>
          </div>
          <div id="automation_manual_group"${editor.video_source === 'manual_links' ? '' : ' class="hidden"'}>
            <label class="field"><span>Manual Video Links</span><textarea name="manual_video_links" rows="4" placeholder="One direct URL per line">${escapeHtml(editor.manual_video_links)}</textarea></label>
          </div>
          <div id="automation_youtube_group"${editor.video_source === 'youtube_channel' ? '' : ' class="hidden"'}>
            <label class="field"><span>YouTube Channel URL</span><input type="url" name="youtube_channel_url" value="${escapeHtml(editor.youtube_channel_url)}" placeholder="https://www.youtube.com/@channel/videos"></label>
          </div>
          <div class="grid two">
            <label class="field">
              <span>Runner Mode</span>
              <select name="run_mode">
                <option value="local"${selectedAttr(editor.run_mode, 'local')}>Local Runner</option>
                <option value="github_runner"${selectedAttr(editor.run_mode, 'github_runner')}${canUseGithubRunner ? '' : ' disabled'}>GitHub Runner</option>
              </select>
            </label>
            <label class="field"><span>Local Agent Device</span><select name="local_agent_id">${agentOptions}</select></label>
          </div>
          <div class="grid two">
            <label class="field">
              <span>Schedule</span>
              <select name="schedule_type">
                <option value="minutes"${selectedAttr(editor.schedule_type, 'minutes')}>Every X Minutes</option>
                <option value="hourly"${selectedAttr(editor.schedule_type, 'hourly')}>Hourly</option>
                <option value="daily"${selectedAttr(editor.schedule_type, 'daily')}>Daily</option>
                <option value="weekly"${selectedAttr(editor.schedule_type, 'weekly')}>Weekly</option>
              </select>
            </label>
            <label class="field"><span>Hour</span><input type="number" name="schedule_hour" min="0" max="23" value="${escapeHtml(String(editor.schedule_hour))}"></label>
          </div>
          <label class="field"><span>Every (minutes)</span><input type="number" name="schedule_every_minutes" min="1" max="1440" value="${escapeHtml(String(editor.schedule_every_minutes))}"></label>
          <label class="toggle"><input type="checkbox" name="enabled"${checkedAttr(editor.enabled)}> <span>Start automation immediately</span></label>
        </div>
      </section>

      <section class="tab-pane" data-tab-pane="video">
        <div class="stack">
          <input type="hidden" name="video_selection_method_hidden" id="video_selection_method_hidden" value="${escapeHtml(editor.video_selection_method)}">
          <div class="toolbar wrap">
            <label class="toggle"><input type="radio" name="video_selection_method" value="days"${checkedAttr(editor.video_selection_method === 'days')} onchange="workerToggleVideoSelection()"> <span>Last X days</span></label>
            <label class="toggle"><input type="radio" name="video_selection_method" value="date_range"${checkedAttr(editor.video_selection_method === 'date_range')} onchange="workerToggleVideoSelection()"> <span>Date range</span></label>
          </div>
          <div id="video_days_section">
            <label class="field"><span>Fetch videos from last (days)</span><input type="number" name="video_days_filter" value="${escapeHtml(String(editor.video_days_filter))}" min="1"></label>
          </div>
          <div id="video_date_range_section" class="grid two${editor.video_selection_method === 'date_range' ? '' : ' hidden'}">
            <label class="field"><span>From Date</span><input type="date" name="video_start_date" value="${escapeHtml(editor.video_start_date)}"></label>
            <label class="field"><span>To Date</span><input type="date" name="video_end_date" value="${escapeHtml(editor.video_end_date)}"></label>
          </div>
          <div class="grid two">
            <label class="toggle"><input type="checkbox" name="rotation_enabled"${checkedAttr(editor.rotation_enabled)}> <span>Smart rotation</span></label>
            <label class="toggle"><input type="checkbox" name="rotation_shuffle"${checkedAttr(editor.rotation_shuffle)}> <span>Shuffle order</span></label>
          </div>
          <label class="toggle"><input type="checkbox" name="rotation_auto_reset"${checkedAttr(editor.rotation_auto_reset)}> <span>Auto reset after full cycle</span></label>
          <div class="grid two">
            <label class="field"><span>Videos per run</span><input type="number" name="videos_per_run" value="${escapeHtml(String(editor.videos_per_run))}" min="1" max="500"></label>
            <label class="field"><span>Short Duration (sec)</span><input type="number" name="short_duration" value="${escapeHtml(String(editor.short_duration))}" min="1"></label>
          </div>
          <div class="grid two">
            <label class="field"><span>Playback Speed</span><input type="number" name="playback_speed" value="${escapeHtml(String(editor.playback_speed))}" step="0.1" min="0.1" max="3"></label>
            <label class="field">
              <span>Aspect Ratio</span>
              <select name="short_aspect_ratio">
                <option value="9:16"${selectedAttr(editor.short_aspect_ratio, '9:16')}>9:16 Vertical</option>
                <option value="1:1"${selectedAttr(editor.short_aspect_ratio, '1:1')}>1:1 Square</option>
                <option value="16:9"${selectedAttr(editor.short_aspect_ratio, '16:9')}>16:9 Horizontal</option>
                <option value="9:16-fit"${selectedAttr(editor.short_aspect_ratio, '9:16-fit')}>9:16 Fit</option>
                <option value="1:1-fit"${selectedAttr(editor.short_aspect_ratio, '1:1-fit')}>1:1 Fit</option>
                <option value="16:9-fit"${selectedAttr(editor.short_aspect_ratio, '16:9-fit')}>16:9 Fit</option>
              </select>
            </label>
          </div>
          <div class="grid two">
            <label class="field">
              <span>Shorts Per Source Video</span>
              <select name="source_shorts_mode" id="source_shorts_mode" onchange="workerToggleSourceShortsMode()">
                <option value="single"${selectedAttr(editor.source_shorts_mode, 'single')}>Single short</option>
                <option value="duration_based"${selectedAttr(editor.source_shorts_mode, 'duration_based')}>Auto by duration</option>
                <option value="fixed_count"${selectedAttr(editor.source_shorts_mode, 'fixed_count')}>Fixed count</option>
              </select>
            </label>
            <div id="source_shorts_max_wrap"${editor.source_shorts_mode === 'fixed_count' ? '' : ' class="hidden"'}>
              <label class="field"><span>Fixed short count</span><input type="number" name="source_shorts_max_count" value="${escapeHtml(String(editor.source_shorts_max_count))}" min="1" max="20"></label>
            </div>
          </div>
        </div>
      </section>

      <section class="tab-pane" data-tab-pane="taglines">
        <div class="stack">
          <label class="toggle"><input type="checkbox" name="ai_taglines_enabled"${checkedAttr(editor.ai_taglines_enabled)}> <span>Enable taglines</span></label>
          <label class="field"><span>AI Tagline Prompt</span><textarea name="ai_tagline_prompt" rows="4">${escapeHtml(editor.ai_tagline_prompt)}</textarea></label>
          <div class="grid two">
            <label class="field"><span>Branding Text Top</span><input type="text" name="branding_text_top" value="${escapeHtml(editor.branding_text_top)}"></label>
            <label class="field"><span>Branding Text Bottom</span><input type="text" name="branding_text_bottom" value="${escapeHtml(editor.branding_text_bottom)}"></label>
          </div>
          <label class="field"><span>Random Words / Taglines CSV</span><textarea name="random_words" rows="3" placeholder="word1, word2, word3">${escapeHtml(editor.random_words)}</textarea></label>
          <div class="grid two">
            <label class="toggle"><input type="checkbox" name="whisper_enabled"${checkedAttr(editor.whisper_enabled)}> <span>Enable Whisper captions</span></label>
            <label class="field"><span>Whisper Language</span><input type="text" name="whisper_language" value="${escapeHtml(editor.whisper_language)}" placeholder="en"></label>
          </div>
        </div>
      </section>

      <section class="tab-pane" data-tab-pane="publish">
        <div class="stack">
          <label class="toggle"><input type="checkbox" name="postforme_enabled" id="postforme_enabled"${checkedAttr(editor.postforme_enabled)} onchange="workerTogglePostForMe()"> <span>Enable Post for Me</span></label>
          <div id="postforme_settings"${editor.postforme_enabled ? '' : ' class="hidden"'}>
            <div class="stack">
              <label class="field"><span>Post for Me Account IDs</span><input type="text" name="postforme_account_ids_csv" value="${escapeHtml(editor.postforme_account_ids_csv)}" placeholder="comma,separated,account,ids"></label>
              <div class="grid two">
                <label class="field">
                  <span>Schedule Mode</span>
                  <select name="postforme_schedule_mode" onchange="workerToggleScheduleMode()">
                    <option value="immediate"${selectedAttr(editor.postforme_schedule_mode, 'immediate')}>Immediate</option>
                    <option value="scheduled"${selectedAttr(editor.postforme_schedule_mode, 'scheduled')}>Specific date/time</option>
                    <option value="offset"${selectedAttr(editor.postforme_schedule_mode, 'offset')}>Delay after processing</option>
                  </select>
                </label>
                <div id="schedule_datetime_section"${editor.postforme_schedule_mode === 'scheduled' ? '' : ' class="hidden"'}>
                  <label class="field"><span>Schedule Date/Time</span><input type="datetime-local" name="postforme_schedule_datetime" value="${escapeHtml(editor.postforme_schedule_datetime)}"></label>
                </div>
              </div>
              <div class="grid two">
                <label class="field"><span>Timezone</span><input type="text" name="postforme_schedule_timezone" value="${escapeHtml(editor.postforme_schedule_timezone)}" placeholder="Asia/Karachi"></label>
                <div id="schedule_offset_section"${editor.postforme_schedule_mode === 'offset' ? '' : ' class="hidden"'}>
                  <label class="field"><span>Delay after processing (minutes)</span><input type="number" name="postforme_schedule_offset_minutes" value="${escapeHtml(String(editor.postforme_schedule_offset_minutes))}" min="0"></label>
                </div>
              </div>
              <div id="schedule_spread_section"${editor.postforme_schedule_mode === 'immediate' ? ' class="hidden"' : ''}>
                <label class="field"><span>Spread between posts (minutes)</span><input type="number" name="postforme_schedule_spread_minutes" value="${escapeHtml(String(editor.postforme_schedule_spread_minutes))}" min="0"></label>
              </div>
            </div>
          </div>
          <div class="grid two">
            <label class="toggle"><input type="checkbox" name="youtube_enabled"${checkedAttr(editor.youtube_enabled)}> <span>YouTube Shorts</span></label>
            <label class="toggle"><input type="checkbox" name="tiktok_enabled"${checkedAttr(editor.tiktok_enabled)}> <span>TikTok</span></label>
            <label class="toggle"><input type="checkbox" name="instagram_enabled"${checkedAttr(editor.instagram_enabled)}> <span>Instagram Reels</span></label>
            <label class="toggle"><input type="checkbox" name="facebook_enabled"${checkedAttr(editor.facebook_enabled)}> <span>Facebook Reels</span></label>
          </div>
        </div>
      </section>

      <div class="toolbar wrap">
        <button type="submit" class="button primary">${editor.id ? 'Update Automation' : 'Create Automation'}</button>
      </div>
    </form>
  `
}

function renderAutomationEditorScript({ user, defaultEditor, initialEditor, initialEditorOpen }) {
  return `
    <script>
      const workerDefaultAutomationEditor = ${JSON.stringify(defaultEditor || buildAutomationEditorState(null))};
      const workerEditorContext = ${JSON.stringify({
        assigned_local_agent_id: user?.role !== 'admin' ? (user?.assigned_local_agent_id || '') : '',
        role: user?.role || 'user'
      })};

      const workerRuntimeState = {
        automationId: 0,
        automationName: '',
        pollHandle: null
      };

      function workerShowAutomationTab(tab) {
        document.querySelectorAll('[data-tab-pane]').forEach((el) => el.classList.toggle('active', el.getAttribute('data-tab-pane') === tab));
        document.querySelectorAll('[data-tab-button]').forEach((el) => el.classList.toggle('active', el.getAttribute('data-tab-button') === tab));
      }

      function workerToggleAutomationSource() {
        const source = document.getElementById('automation_video_source')?.value || 'ftp';
        document.getElementById('automation_api_key_group')?.classList.toggle('hidden', source !== 'bunny');
        document.getElementById('automation_manual_group')?.classList.toggle('hidden', source !== 'manual_links');
        document.getElementById('automation_youtube_group')?.classList.toggle('hidden', source !== 'youtube_channel');
      }
      function workerToggleVideoSelection() {
        const selected = document.querySelector('input[name=\"video_selection_method\"]:checked')?.value || 'days';
        const hidden = document.getElementById('video_selection_method_hidden');
        if (hidden) hidden.value = selected;
        document.getElementById('video_days_section')?.classList.toggle('hidden', selected !== 'days');
        document.getElementById('video_date_range_section')?.classList.toggle('hidden', selected !== 'date_range');
      }
      function workerTogglePostForMe() {
        const enabled = !!document.getElementById('postforme_enabled')?.checked;
        document.getElementById('postforme_settings')?.classList.toggle('hidden', !enabled);
      }
      function workerToggleScheduleMode() {
        const mode = document.querySelector('#automation-editor-form select[name="postforme_schedule_mode"]')?.value || 'immediate';
        document.getElementById('schedule_datetime_section')?.classList.toggle('hidden', mode !== 'scheduled');
        document.getElementById('schedule_offset_section')?.classList.toggle('hidden', mode !== 'offset');
        document.getElementById('schedule_spread_section')?.classList.toggle('hidden', mode === 'immediate');
      }
      function workerToggleSourceShortsMode() {
        const mode = document.getElementById('source_shorts_mode')?.value || 'single';
        document.getElementById('source_shorts_max_wrap')?.classList.toggle('hidden', mode !== 'fixed_count');
      }

      function workerCloseAutomationEditor() {
        document.getElementById('automation-editor-modal')?.classList.add('hidden');
      }

      function workerOpenCreateAutomationEditor() {
        const state = { ...workerDefaultAutomationEditor };
        if (!state.local_agent_id && workerEditorContext.role !== 'admin' && workerEditorContext.assigned_local_agent_id) {
          state.local_agent_id = workerEditorContext.assigned_local_agent_id;
        }
        workerApplyAutomationEditorState(state, false);
      }

      function workerOpenEditAutomationEditor(rawState) {
        try {
          const state = typeof rawState === 'string' ? JSON.parse(rawState) : (rawState || {});
          workerApplyAutomationEditorState(state, true);
        } catch (error) {
          alert('Unable to open editor for this automation.');
        }
      }

      function workerSetEditorField(name, value) {
        const form = document.getElementById('automation-editor-form');
        if (!form) return;
        const field = form.querySelector('[name="' + name + '"]');
        if (!field) return;
        if (field.type === 'checkbox') {
          field.checked = !!value && value !== '0';
          return;
        }
        field.value = value == null ? '' : String(value);
      }

      function workerApplyAutomationEditorState(state, isEdit) {
        const modal = document.getElementById('automation-editor-modal');
        const form = document.getElementById('automation-editor-form');
        if (!modal || !form) return;
        const data = state || {};
        const title = document.getElementById('automation-editor-title');
        if (title) title.textContent = isEdit ? 'Edit Automation' : 'Create Automation';

        workerSetEditorField('automation_id', isEdit ? (data.id || '') : '');
        workerSetEditorField('name', data.name || '');
        workerSetEditorField('video_source', data.video_source || 'ftp');
        workerSetEditorField('api_key_id', data.api_key_id || '');
        workerSetEditorField('manual_video_links', data.manual_video_links || '');
        workerSetEditorField('youtube_channel_url', data.youtube_channel_url || '');
        workerSetEditorField('run_mode', data.run_mode || 'local');
        workerSetEditorField('local_agent_id', data.local_agent_id || '');
        workerSetEditorField('schedule_type', data.schedule_type || 'daily');
        workerSetEditorField('schedule_hour', data.schedule_hour ?? 9);
        workerSetEditorField('schedule_every_minutes', data.schedule_every_minutes ?? 10);
        workerSetEditorField('enabled', data.enabled);
        workerSetEditorField('video_selection_method_hidden', data.video_selection_method || 'days');
        const method = data.video_selection_method || 'days';
        const radio = form.querySelector('input[name="video_selection_method"][value="' + method + '"]');
        if (radio) radio.checked = true;
        workerSetEditorField('video_days_filter', data.video_days_filter ?? 30);
        workerSetEditorField('video_start_date', data.video_start_date || '');
        workerSetEditorField('video_end_date', data.video_end_date || '');
        workerSetEditorField('rotation_enabled', data.rotation_enabled);
        workerSetEditorField('rotation_shuffle', data.rotation_shuffle);
        workerSetEditorField('rotation_auto_reset', data.rotation_auto_reset);
        workerSetEditorField('videos_per_run', data.videos_per_run ?? 5);
        workerSetEditorField('short_duration', data.short_duration ?? 60);
        workerSetEditorField('playback_speed', data.playback_speed ?? '1.0');
        workerSetEditorField('short_aspect_ratio', data.short_aspect_ratio || '9:16');
        workerSetEditorField('source_shorts_mode', data.source_shorts_mode || 'single');
        workerSetEditorField('source_shorts_max_count', data.source_shorts_max_count ?? 1);
        workerSetEditorField('ai_taglines_enabled', data.ai_taglines_enabled);
        workerSetEditorField('ai_tagline_prompt', data.ai_tagline_prompt || 'Generate universal greeting taglines');
        workerSetEditorField('branding_text_top', data.branding_text_top || '');
        workerSetEditorField('branding_text_bottom', data.branding_text_bottom || '');
        workerSetEditorField('random_words', data.random_words || '');
        workerSetEditorField('whisper_enabled', data.whisper_enabled);
        workerSetEditorField('whisper_language', data.whisper_language || 'en');
        workerSetEditorField('postforme_enabled', data.postforme_enabled);
        workerSetEditorField('postforme_account_ids_csv', data.postforme_account_ids_csv || '');
        workerSetEditorField('postforme_schedule_mode', data.postforme_schedule_mode || 'immediate');
        workerSetEditorField('postforme_schedule_datetime', data.postforme_schedule_datetime || '');
        workerSetEditorField('postforme_schedule_timezone', data.postforme_schedule_timezone || 'UTC');
        workerSetEditorField('postforme_schedule_offset_minutes', data.postforme_schedule_offset_minutes ?? 0);
        workerSetEditorField('postforme_schedule_spread_minutes', data.postforme_schedule_spread_minutes ?? 0);
        workerSetEditorField('youtube_enabled', data.youtube_enabled);
        workerSetEditorField('tiktok_enabled', data.tiktok_enabled);
        workerSetEditorField('instagram_enabled', data.instagram_enabled);
        workerSetEditorField('facebook_enabled', data.facebook_enabled);

        workerToggleAutomationSource();
        workerToggleVideoSelection();
        workerTogglePostForMe();
        workerToggleScheduleMode();
        workerToggleSourceShortsMode();
        workerShowAutomationTab('basic');
        modal.classList.remove('hidden');
      }

      function workerOpenRuntimeModal(automationId, automationName) {
        const modal = document.getElementById('automation-runtime-modal');
        if (!modal) return false;
        workerRuntimeState.automationId = Number(automationId || 0);
        workerRuntimeState.automationName = automationName || ('Automation #' + workerRuntimeState.automationId);
        document.getElementById('runtime-modal-title').textContent = workerRuntimeState.automationName;
        modal.classList.remove('hidden');
        workerSetRuntimeStatus('queued');
        workerSetRuntimeProgress(0, 'Loading runtime details...');
        workerRenderRuntimeLogs([]);
        workerRenderRuntimeOutputs([]);
        workerRenderRuntimeStats({}, null);
        workerPollAutomationStatus(true);
        workerStartRuntimePolling();
        return false;
      }

      function workerCloseRuntimeModal() {
        const modal = document.getElementById('automation-runtime-modal');
        if (modal) {
          modal.classList.add('hidden');
        }
        workerStopRuntimePolling();
        if (window.location.search.indexOf('logs=') !== -1) {
          window.location.href = '${legacyPageHref('/automation')}';
        }
      }

      function workerStartRuntimePolling() {
        workerStopRuntimePolling();
        workerRuntimeState.pollHandle = window.setInterval(() => {
          workerPollAutomationStatus(false);
        }, 2000);
      }

      function workerStopRuntimePolling() {
        if (workerRuntimeState.pollHandle) {
          window.clearInterval(workerRuntimeState.pollHandle);
          workerRuntimeState.pollHandle = null;
        }
      }

      async function workerQueueAutomation(automationId, automationName) {
        workerOpenRuntimeModal(automationId, automationName);
        workerSetRuntimeProgress(5, 'Queueing automation...');
        workerSetRuntimeStatus('queued');
        try {
          const response = await fetch('/api/automation-run', {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({ automation_id: String(automationId) }).toString()
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Unable to queue automation.');
          }
          workerSetRuntimeProgress(5, data.message || 'Automation queued.');
          workerSetRuntimeStatus(data.status || 'queued');
          await workerPollAutomationStatus(true);
        } catch (error) {
          workerSetRuntimeStatus('error');
          workerSetRuntimeProgress(0, error.message || 'Unable to queue automation.');
          workerRenderRuntimeLogs([{ status: 'error', action: 'queue', message: error.message || 'Unable to queue automation.', created_at: new Date().toISOString() }]);
        }
        return false;
      }

      async function workerPollAutomationStatus(force) {
        if (!workerRuntimeState.automationId) return;
        const modal = document.getElementById('automation-runtime-modal');
        if (!force && modal && modal.classList.contains('hidden')) return;
        try {
          const response = await fetch('/api/automation-status?automation_id=' + encodeURIComponent(String(workerRuntimeState.automationId)), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Unable to load runtime status.');
          }
          workerRenderRuntimeState(data);
          const status = String((data.automation && data.automation.status) || '').toLowerCase();
          if (['completed', 'error', 'stopped', 'inactive'].includes(status)) {
            workerStopRuntimePolling();
          }
        } catch (error) {
          workerSetRuntimeStatus('error');
          workerSetRuntimeProgress(0, error.message || 'Unable to load runtime status.');
        }
      }

      function workerRenderRuntimeState(data) {
        const automation = data.automation || {};
        const progress = data.progress || {};
        const progressPercent = Number(progress.progress ?? automation.progress_percent ?? 0) || 0;
        const message = progress.message || 'Waiting for runtime activity.';
        workerSetRuntimeStatus(automation.status || 'inactive');
        workerSetRuntimeProgress(progressPercent, message);
        workerRenderRuntimeStats(progress.stats || {}, data.job || null);
        workerRenderRuntimeLogs(Array.isArray(data.logs) ? data.logs : []);
        workerRenderRuntimeOutputs(Array.isArray(data.outputs) ? data.outputs : []);
        workerUpdateAutomationCard(automation.id, automation.status, progressPercent, message);
        workerUpdateAutomationStats(automation.id, progress.stats || {}, Array.isArray(data.outputs) ? data.outputs : []);
      }

      function workerSetRuntimeStatus(status) {
        const badge = document.getElementById('runtime-modal-status');
        if (!badge) return;
        badge.className = 'badge ' + workerStatusClass(status);
        badge.textContent = String(status || 'idle');
      }

      function workerSetRuntimeProgress(progress, message) {
        const value = Math.max(0, Math.min(100, Number(progress || 0)));
        const bar = document.getElementById('runtime-modal-progress');
        const text = document.getElementById('runtime-modal-progress-text');
        const messageNode = document.getElementById('runtime-modal-message');
        if (bar) bar.style.width = value + '%';
        if (text) text.textContent = Math.round(value) + '%';
        if (messageNode) messageNode.textContent = message || 'Waiting for runtime activity.';
      }

      function workerRenderRuntimeStats(stats, job) {
        const safeStats = stats || {};
        document.getElementById('runtime-stat-fetched').textContent = String(safeStats.fetched || 0);
        document.getElementById('runtime-stat-downloaded').textContent = String(safeStats.downloaded || 0);
        document.getElementById('runtime-stat-processed').textContent = String(safeStats.processed || 0);
        document.getElementById('runtime-stat-scheduled').textContent = String(safeStats.scheduled || 0);
        document.getElementById('runtime-stat-posted').textContent = String(safeStats.posted || 0);
        document.getElementById('runtime-stat-job').textContent = job && job.id ? ('#' + String(job.id)) : '-';
      }

      function workerRenderRuntimeLogs(logs) {
        const container = document.getElementById('runtime-modal-logs');
        if (!container) return;
        if (!logs.length) {
          container.innerHTML = '<div class="muted compact">No log activity yet.</div>';
          return;
        }
        container.innerHTML = logs.map((log) => {
          return '<article class="runtime-entry ' + workerStatusClass(log.status || 'info') + '">' +
            '<strong>' + workerEscapeHtml(log.action || 'log') + '</strong>' +
            '<div class="muted compact">' + workerEscapeHtml(log.message || '') + '</div>' +
            '<div class="muted compact">' + workerEscapeHtml(workerFormatDate(log.created_at || '')) + '</div>' +
          '</article>';
        }).join('');
      }

      function workerRenderRuntimeOutputs(outputs) {
        const container = document.getElementById('runtime-modal-outputs');
        if (!container) return;
        if (!outputs.length) {
          container.innerHTML = '<div class="muted compact">No output reported yet.</div>';
          return;
        }
        container.innerHTML = outputs.map((output) => {
          const label = workerEscapeHtml(output.filename || ('Output #' + String(output.id || '')));
          const meta = workerEscapeHtml((output.stored_in || 'metadata') + ' | ' + workerFormatDate(output.created_at || ''));
          if (output.download_url) {
            return '<article class="runtime-entry"><a class="inline-link" href="' + workerEscapeHtml(output.download_url) + '" target="_blank" rel="noopener">' + label + '</a><div class="muted compact">' + meta + '</div></article>';
          }
          return '<article class="runtime-entry"><strong>' + label + '</strong><div class="muted compact">' + meta + '</div></article>';
        }).join('');
      }

      function workerUpdateAutomationCard(automationId, status, progress, message) {
        if (!automationId) return;
        const badge = document.getElementById('automation-status-' + automationId);
        const bar = document.getElementById('automation-progress-' + automationId) || document.getElementById('progress-bar-' + automationId);
        const text = document.getElementById('automation-progress-text-' + automationId) || document.getElementById('progress-percent-' + automationId);
        const messageNode = document.getElementById('automation-message-' + automationId);
        const progressWrap = document.getElementById('progress-' + automationId);
        if (badge) {
          badge.className = (badge.className.indexOf('badge') !== -1 ? 'badge ' : 'px-2 py-1 rounded text-xs font-medium ') + workerStatusClass(status);
          badge.textContent = String(status || 'inactive');
        }
        if (bar) {
          bar.style.width = Math.max(0, Math.min(100, Number(progress || 0))) + '%';
        }
        if (text) {
          text.textContent = Math.round(Number(progress || 0)) + '%';
        }
        if (messageNode) {
          messageNode.textContent = message || 'Waiting for next run.';
        }
        if (progressWrap) {
          progressWrap.classList.toggle('hidden', !['queued', 'processing', 'running', 'claimed', 'completed', 'error'].includes(String(status || '').toLowerCase()));
        }
      }

      function workerUpdateAutomationStats(automationId, stats, outputs) {
        if (!automationId) return;
        const safeStats = stats || {};
        const setText = (id, value) => {
          const node = document.getElementById(id + '-' + automationId);
          if (node) node.textContent = String(value || 0);
        };
        setText('stat-fetched', safeStats.fetched || 0);
        setText('stat-downloaded', safeStats.downloaded || 0);
        setText('stat-processed', safeStats.processed || 0);
        setText('stat-scheduled', safeStats.scheduled || 0);
        setText('stat-posted', safeStats.posted || 0);
        const outputWrap = document.getElementById('progress-outputs-' + automationId);
        if (outputWrap) {
          if (!outputs.length) {
            outputWrap.innerHTML = '<div class="text-gray-500">No edited output yet</div>';
          } else {
            outputWrap.innerHTML = outputs.slice(0, 5).map((output) => {
              const label = workerEscapeHtml(output.filename || ('Output #' + String(output.id || '')));
              return output.download_url
                ? '<a href="' + workerEscapeHtml(output.download_url) + '" target="_blank" rel="noopener" class="block text-cyan-300 truncate">' + label + '</a>'
                : '<div class="text-cyan-300 truncate">' + label + '</div>';
            }).join('');
          }
        }
      }

      function workerStatusClass(status) {
        switch (String(status || '').toLowerCase()) {
          case 'running':
          case 'completed':
          case 'success':
            return 'status-success';
          case 'queued':
          case 'processing':
          case 'claimed':
            return 'status-queued';
          case 'warning':
          case 'stopped':
            return 'status-warning';
          case 'error':
          case 'failed':
          case 'cancelled':
            return 'status-error';
          default:
            return 'status-neutral';
        }
      }

      function workerEscapeHtml(value) {
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function workerFormatDate(value) {
        if (!value) return 'Unknown time';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString();
      }

      const workerScheduledState = {
        automationId: 0,
        automationName: 'All Automations'
      };

      function workerOpenScheduledModal(automationId, automationName) {
        const modal = document.getElementById('scheduled-posts-modal');
        if (!modal) return false;
        workerScheduledState.automationId = Number(automationId || 0);
        workerScheduledState.automationName = automationName || 'All Automations';
        const title = document.getElementById('scheduled-posts-title');
        const subtitle = document.getElementById('scheduled-posts-subtitle');
        if (title) title.textContent = workerScheduledState.automationName + ' Scheduled Queue';
        if (subtitle) subtitle.textContent = workerScheduledState.automationId
          ? 'Upcoming delayed posts for this automation.'
          : 'Upcoming delayed posts across all automations.';
        modal.classList.remove('hidden');
        workerRefreshScheduledPosts();
        return false;
      }

      function workerCloseScheduledModal() {
        const modal = document.getElementById('scheduled-posts-modal');
        if (modal) {
          modal.classList.add('hidden');
        }
      }

      async function workerRefreshScheduledPosts() {
        const list = document.getElementById('scheduled-posts-list');
        if (!list) return;
        list.innerHTML = '<div class="muted compact">Loading scheduled posts...</div>';
        const query = workerScheduledState.automationId
          ? ('?automation_id=' + encodeURIComponent(String(workerScheduledState.automationId)))
          : '';
        try {
          const response = await fetch('/api/scheduled-posts' + query, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Unable to load scheduled posts.');
          }
          workerRenderScheduledPosts(Array.isArray(data.posts) ? data.posts : []);
        } catch (error) {
          list.innerHTML = '<div class="flash error">' + workerEscapeHtml(error.message || 'Unable to load scheduled posts.') + '</div>';
        }
      }

      function workerRenderScheduledPosts(posts) {
        const list = document.getElementById('scheduled-posts-list');
        if (!list) return;
        const canManage = document.getElementById('scheduled-posts-modal')?.dataset.canManage === '1';
        if (!posts.length) {
          list.innerHTML = '<div class="text-center py-12 text-gray-400"><p class="text-lg mb-1">No scheduled posts</p><p class="text-sm">Nothing is currently waiting in the queue.</p></div>';
          return;
        }
        list.innerHTML = posts.map((post) => {
          const title = workerEscapeHtml(post.caption || post.filename || ('Scheduled Post #' + String(post.id || '')));
          const meta = workerEscapeHtml((post.automation_name || ('Automation #' + String(post.automation_id || ''))) + ' | ' + String(post.account_count || 0) + ' account(s)');
          const when = workerEscapeHtml(post.scheduled_at ? workerFormatDate(post.scheduled_at) : 'Waiting for remote schedule time');
          const manage = canManage
            ? '<button type="button" class="button ghost danger-button" onclick="workerDeleteScheduledPost(' + String(post.id || 0) + ')">Delete</button>'
            : '';
          return '<div class="job-row scheduled-row">' +
            '<div class="job-main"><div class="font-medium">' + title + '</div><div class="text-sm text-gray-400">' + meta + '</div><div class="text-xs text-gray-500">' + when + '</div></div>' +
            '<div class="job-side"><div class="status-pill ' + workerStatusClass(post.status || 'scheduled') + '">' + workerEscapeHtml(post.status || 'scheduled') + '</div>' + manage + '</div>' +
          '</div>';
        }).join('');
      }

      async function workerDeleteScheduledPost(postId) {
        if (!postId || !confirm('Delete this scheduled post?')) return false;
        try {
          const response = await fetch('/api/delete-scheduled-post', {
            method: 'POST',
            body: new URLSearchParams({ id: String(postId) }),
            headers: { 'Accept': 'application/json' }
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Unable to delete scheduled post.');
          }
          await workerRefreshScheduledPosts();
        } catch (error) {
          alert(error.message || 'Unable to delete scheduled post.');
        }
        return false;
      }

      async function workerDeleteAllScheduledPosts() {
        if (!confirm('Delete all visible scheduled posts?')) return false;
        try {
          const body = new URLSearchParams();
          if (workerScheduledState.automationId) {
            body.set('automation_id', String(workerScheduledState.automationId));
          }
          const response = await fetch('/api/delete-all-scheduled-posts', {
            method: 'POST',
            body,
            headers: { 'Accept': 'application/json' }
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error((data && data.error) ? data.error : 'Unable to delete scheduled posts.');
          }
          await workerRefreshScheduledPosts();
        } catch (error) {
          alert(error.message || 'Unable to delete scheduled posts.');
        }
        return false;
      }

      function workerFindAutomationName(automationId) {
        return document.querySelector('[data-automation-card="' + String(automationId) + '"]')?.getAttribute('data-automation-name') || ('Automation #' + String(automationId || ''));
      }

      function showFormTab(tab) {
        workerShowAutomationTab(tab === 'social' ? 'publish' : tab);
      }

      function toggleVideoSource(select) {
        const sourceField = document.getElementById('automation_video_source');
        if (sourceField && select && typeof select.value !== 'undefined') {
          sourceField.value = select.value;
        }
        workerToggleAutomationSource();
      }

      function togglePostForMe() {
        workerTogglePostForMe();
      }

      function toggleScheduleMode(mode) {
        const field = document.querySelector('#automation-editor-form select[name="postforme_schedule_mode"]');
        if (field && mode != null) {
          field.value = String(mode);
        }
        workerToggleScheduleMode();
      }

      function toggleSourceShortsMode(mode) {
        const field = document.getElementById('source_shorts_mode');
        if (field && mode != null) {
          field.value = String(mode);
        }
        workerToggleSourceShortsMode();
      }

      function openEditModal(automationData) {
        workerOpenEditAutomationEditor(automationData);
      }

      function openScheduledModal(id, name) {
        return workerOpenScheduledModal(id, name);
      }

      function openAllScheduledModal() {
        return workerOpenScheduledModal(0, 'All Automations');
      }

      function runAutomationSmart(automationId) {
        return workerQueueAutomation(automationId, workerFindAutomationName(automationId));
      }

      function testFetch(automationId, source) {
        const mode = String(source || 'ftp');
        showToast('Fetch validation for ' + mode + ' runs on the paired agent during the next job.', 'success');
        return false;
      }

      function switchTab(tab) {
        if (typeof workerSwitchDashboardTab === 'function') {
          workerSwitchDashboardTab(tab);
        }
      }

      async function loadOutputVideoCount() {
        const outputCard = document.querySelector('.output-summary-card');
        if (!outputCard) return;
        try {
          const response = await fetch('/api/list-output-videos.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
          const data = await response.json();
          if (!response.ok || !data.success) return;
          const target = outputCard.querySelector('.ml-auto');
          if (!target) return;
          if (Number(data.total || 0) > 0) {
            target.innerHTML = '<a href="${legacyPageHref('/player')}" class="px-3 py-1 bg-green-600 rounded-lg text-sm hover:bg-green-700">' + String(data.total) + ' videos ready to view</a>';
          } else {
            target.innerHTML = '<span class="text-gray-500 text-sm">No processed videos yet</span>';
          }
        } catch (_) {}
      }

      function updateCountdownTimers() {
        const timers = document.querySelectorAll('.countdown-timer');
        const now = Math.floor(Date.now() / 1000);
        timers.forEach((timer) => {
          const target = parseInt(timer.dataset.target || '0', 10);
          const automationId = timer.dataset.automationId || '';
          if (!target) {
            timer.textContent = 'Not scheduled';
            return;
          }
          const remaining = target - now;
          if (remaining <= 0) {
            timer.innerHTML = '<span class="text-yellow-400">Overdue - checking scheduler...</span>';
            const lastTrigger = parseInt(timer.dataset.lastTrigger || '0', 10);
            if (!lastTrigger || (now - lastTrigger) >= 60) {
              timer.dataset.lastTrigger = String(now);
              fetch('/api/cron.php', { cache: 'no-store' }).catch(() => {});
            }
            fetch('/api/check-progress.php?id=' + encodeURIComponent(automationId), { cache: 'no-store' })
              .then((r) => r.json())
              .then((data) => {
                const nextTs = parseInt((data && data.nextRunTs) ? data.nextRunTs : '0', 10);
                if (nextTs && nextTs > now) {
                  timer.dataset.target = String(nextTs);
                }
              })
              .catch(() => {});
            return;
          }
          const hours = Math.floor(remaining / 3600);
          const minutes = Math.floor((remaining % 3600) / 60);
          const seconds = remaining % 60;
          const pad = (value) => String(value).padStart(2, '0');
          if (hours > 24) {
            const days = Math.floor(hours / 24);
            timer.innerHTML = '<span class="text-green-400">' + days + 'd ' + (hours % 24) + 'h ' + pad(minutes) + 'm</span>';
          } else if (hours > 0) {
            timer.innerHTML = '<span class="text-green-400">' + hours + 'h ' + pad(minutes) + 'm ' + pad(seconds) + 's</span>';
          } else if (minutes > 0) {
            timer.innerHTML = '<span class="text-green-400">' + pad(minutes) + 'm ' + pad(seconds) + 's</span>';
          } else {
            timer.innerHTML = '<span class="text-yellow-400">' + pad(seconds) + 's</span>';
          }
        });
      }

      document.addEventListener('click', (event) => {
        const createButton = event.target.closest('[data-open-create]');
        if (createButton) {
          event.preventDefault();
          workerOpenCreateAutomationEditor();
          return;
        }

        const editButton = event.target.closest('[data-edit-automation]');
        if (editButton) {
          event.preventDefault();
          workerOpenEditAutomationEditor(editButton.getAttribute('data-edit-automation') || '{}');
          return;
        }

        const runButton = event.target.closest('[data-run-automation]');
        if (runButton) {
          event.preventDefault();
          workerQueueAutomation(runButton.getAttribute('data-automation-id'), runButton.getAttribute('data-automation-name') || '');
          return;
        }

        const logButton = event.target.closest('[data-open-runtime]');
        if (logButton) {
          event.preventDefault();
          workerOpenRuntimeModal(logButton.getAttribute('data-automation-id'), logButton.getAttribute('data-automation-name') || '');
        }
      });

      document.addEventListener('DOMContentLoaded', () => {
        workerToggleAutomationSource();
        workerToggleVideoSelection();
        workerTogglePostForMe();
        workerToggleScheduleMode();
        workerToggleSourceShortsMode();
        loadOutputVideoCount();
        updateCountdownTimers();
        window.setInterval(updateCountdownTimers, 1000);
        const editorModal = document.getElementById('automation-editor-modal');
        if (editorModal && editorModal.dataset.editorOpen === '1') {
          editorModal.classList.remove('hidden');
        }
        const runtimeModal = document.getElementById('automation-runtime-modal');
        if (runtimeModal && runtimeModal.dataset.initialOpen === '1') {
          workerOpenRuntimeModal(runtimeModal.dataset.automationId || '0', runtimeModal.dataset.automationName || '');
        }
      });

      window.addEventListener('beforeunload', () => {
        workerStopRuntimePolling();
      });
    </script>
  `
}

function renderSettingsTabFields(tab, settings) {
  if (tab === 'bunny') {
    return `
      <label class="field"><span>API Key</span><input type="password" name="bunny_api_key" value="${escapeHtml(settings.bunny_api_key || '')}"></label>
      <label class="field"><span>Library ID</span><input type="text" name="bunny_library_id" value="${escapeHtml(settings.bunny_library_id || '')}"></label>
      <div class="grid two">
        <label class="field"><span>Storage Zone</span><input type="text" name="bunny_storage_zone" value="${escapeHtml(settings.bunny_storage_zone || '')}"></label>
        <label class="field"><span>Storage Password</span><input type="password" name="bunny_storage_password" value="${escapeHtml(settings.bunny_storage_password || '')}"></label>
      </div>
    `
  }
  if (tab === 'stream') {
    return `
      <div class="subpanel">
        <h2>YouTube API</h2>
        <div class="stack">
          <label class="field"><span>API Key</span><input type="password" name="youtube_api_key" value="${escapeHtml(settings.youtube_api_key || '')}"></label>
          <div class="grid two">
            <label class="field"><span>OAuth Client ID</span><input type="text" name="youtube_client_id" value="${escapeHtml(settings.youtube_client_id || '')}"></label>
            <label class="field"><span>OAuth Client Secret</span><input type="password" name="youtube_client_secret" value="${escapeHtml(settings.youtube_client_secret || '')}"></label>
          </div>
        </div>
      </div>
      <div class="subpanel">
        <h2>TikTok API</h2>
        <div class="grid two">
          <label class="field"><span>Client Key</span><input type="text" name="tiktok_client_key" value="${escapeHtml(settings.tiktok_client_key || '')}"></label>
          <label class="field"><span>Client Secret</span><input type="password" name="tiktok_client_secret" value="${escapeHtml(settings.tiktok_client_secret || '')}"></label>
        </div>
      </div>
      <div class="subpanel">
        <h2>Instagram + Facebook</h2>
        <div class="grid two">
          <label class="field"><span>Instagram App ID</span><input type="text" name="instagram_app_id" value="${escapeHtml(settings.instagram_app_id || '')}"></label>
          <label class="field"><span>Instagram App Secret</span><input type="password" name="instagram_app_secret" value="${escapeHtml(settings.instagram_app_secret || '')}"></label>
          <label class="field"><span>Facebook App ID</span><input type="text" name="facebook_app_id" value="${escapeHtml(settings.facebook_app_id || '')}"></label>
          <label class="field"><span>Facebook App Secret</span><input type="password" name="facebook_app_secret" value="${escapeHtml(settings.facebook_app_secret || '')}"></label>
        </div>
      </div>
    `
  }
  if (tab === 'ftp') {
    return `
      <div class="grid two">
        <label class="field"><span>FTP Host</span><input type="text" name="ftp_host" value="${escapeHtml(settings.ftp_host || '')}"></label>
        <label class="field"><span>Port</span><input type="number" name="ftp_port" value="${escapeHtml(settings.ftp_port || '21')}"></label>
        <label class="field"><span>Username</span><input type="text" name="ftp_username" value="${escapeHtml(settings.ftp_username || '')}"></label>
        <label class="field"><span>Password</span><input type="password" name="ftp_password" value="${escapeHtml(settings.ftp_password || '')}"></label>
      </div>
      <label class="field"><span>Remote Path</span><input type="text" name="ftp_path" value="${escapeHtml(settings.ftp_path || '/')}"></label>
    `
  }
  if (tab === 'openai') {
    return `
      <label class="field">
        <span>AI Provider</span>
        <select name="ai_provider">
          <option value="gemini"${selectedAttr(settings.ai_provider || 'gemini', 'gemini')}>Google Gemini</option>
          <option value="openai"${selectedAttr(settings.ai_provider || '', 'openai')}>OpenAI</option>
        </select>
      </label>
      <label class="field"><span>Gemini API Key</span><input type="password" name="gemini_api_key" value="${escapeHtml(settings.gemini_api_key || '')}"></label>
      <label class="field"><span>OpenAI API Key</span><input type="password" name="openai_api_key" value="${escapeHtml(settings.openai_api_key || '')}"></label>
      <label class="field"><span>Default Language</span><input type="text" name="default_language" value="${escapeHtml(settings.default_language || 'en')}"></label>
    `
  }
  if (tab === 'ffmpeg') {
    return `
      <label class="field"><span>FFmpeg Path</span><input type="text" name="ffmpeg_path" value="${escapeHtml(settings.ffmpeg_path || 'ffmpeg')}"></label>
      <label class="toggle"><input type="checkbox" name="auto_install_local_runtime"${truthySetting(settings.auto_install_local_runtime, true) ? ' checked' : ''}> <span>Auto-install FFmpeg locally when Local Runner starts</span></label>
      <label class="field"><span>Windows Auto-Install URL</span><input type="text" name="ffmpeg_auto_download_url_windows" value="${escapeHtml(settings.ffmpeg_auto_download_url_windows || 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip')}"></label>
    `
  }
  if (tab === 'storage') {
    return `
      <label class="field"><span>Storage Base Path</span><input type="text" name="storage_base_path" value="${escapeHtml(settings.storage_base_path || '')}"></label>
      <label class="field"><span>Public Panel Base URL</span><input type="text" name="panel_public_base_url" value="${escapeHtml(settings.panel_public_base_url || '')}"></label>
      <label class="field"><span>yt-dlp Cookies File</span><input type="text" name="ytdlp_cookies_file" value="${escapeHtml(settings.ytdlp_cookies_file || '')}"></label>
      <div class="grid two">
        <label class="field"><span>yt-dlp Browser</span><input type="text" name="ytdlp_cookies_browser" value="${escapeHtml(settings.ytdlp_cookies_browser || '')}"></label>
        <label class="field"><span>Browser Profile</span><input type="text" name="ytdlp_cookies_browser_profile" value="${escapeHtml(settings.ytdlp_cookies_browser_profile || '')}"></label>
      </div>
    `
  }
  if (tab === 'github_runner') {
    return `
      <label class="toggle"><input type="checkbox" name="github_runner_enabled"${truthySetting(settings.github_runner_enabled, false) ? ' checked' : ''}> <span>Enable GitHub Runner mode</span></label>
      <div class="grid two">
        <label class="field"><span>GitHub Owner</span><input type="text" name="github_runner_owner" value="${escapeHtml(settings.github_runner_owner || '')}"></label>
        <label class="field"><span>Repository</span><input type="text" name="github_runner_repo" value="${escapeHtml(settings.github_runner_repo || '')}"></label>
        <label class="field"><span>Workflow File</span><input type="text" name="github_runner_workflow" value="${escapeHtml(settings.github_runner_workflow || 'automation-runner.yml')}"></label>
        <label class="field"><span>Branch / Ref</span><input type="text" name="github_runner_ref" value="${escapeHtml(settings.github_runner_ref || 'main')}"></label>
      </div>
      <label class="field"><span>GitHub Token</span><input type="password" name="github_runner_token" value="${escapeHtml(settings.github_runner_token || '')}"></label>
      <label class="field"><span>Callback Secret</span><input type="text" name="github_runner_callback_secret" value="${escapeHtml(settings.github_runner_callback_secret || '')}"></label>
      <label class="field"><span>Extra Inputs JSON</span><textarea name="github_runner_inputs_json" rows="4">${escapeHtml(settings.github_runner_inputs_json || '')}</textarea></label>
      <label class="field"><span>Public Panel Base URL</span><input type="text" name="panel_public_base_url" value="${escapeHtml(settings.panel_public_base_url || '')}"></label>
    `
  }
  return `
    <label class="field"><span>Post for Me API Key</span><input type="password" name="postforme_api_key" value="${escapeHtml(settings.postforme_api_key || '')}"></label>
    <label class="field">
      <span>Project Type</span>
      <select name="postforme_project_type">
        <option value="quickstart"${selectedAttr(settings.postforme_project_type || 'quickstart', 'quickstart')}>Quickstart</option>
        <option value="whitelabel"${selectedAttr(settings.postforme_project_type || '', 'whitelabel')}>White Label</option>
      </select>
    </label>
  `
}

function buildAutomationEditorState(automation) {
  const config = automation ? parseJsonMaybe(automation.automation_json, {}) : {}
  const postformeAccounts = Array.isArray(config.postforme_account_ids)
    ? config.postforme_account_ids.map((item) => String(item)).join(',')
    : String(config.postforme_account_ids || '')
  return {
    id: automation ? Number(automation.id) : 0,
    name: automation?.name || '',
    run_mode: automation?.run_mode || 'local',
    local_agent_id: automation?.local_agent_id || '',
    enabled: automation ? Number(automation.enabled || 0) === 1 : true,
    video_source: String(config.video_source || 'ftp'),
    api_key_id: config.api_key_id || '',
    manual_video_links: String(config.manual_video_links || ''),
    youtube_channel_url: String(config.youtube_channel_url || ''),
    schedule_type: String(config.schedule_type || 'daily'),
    schedule_hour: config.schedule_hour ?? 9,
    schedule_every_minutes: config.schedule_every_minutes ?? 10,
    video_selection_method: String(config.video_selection_method_hidden || config.video_selection_method || ((config.video_start_date || config.video_end_date) ? 'date_range' : 'days')),
    video_days_filter: config.video_days_filter ?? 30,
    video_start_date: String(config.video_start_date || ''),
    video_end_date: String(config.video_end_date || ''),
    rotation_enabled: truthyValue(config.rotation_enabled, true),
    rotation_shuffle: truthyValue(config.rotation_shuffle, true),
    rotation_auto_reset: truthyValue(config.rotation_auto_reset, true),
    videos_per_run: config.videos_per_run ?? 5,
    short_duration: config.short_duration ?? 60,
    playback_speed: config.playback_speed ?? 1.0,
    short_aspect_ratio: String(config.short_aspect_ratio || '9:16'),
    source_shorts_mode: String(config.source_shorts_mode || 'single'),
    source_shorts_max_count: config.source_shorts_max_count ?? 1,
    ai_taglines_enabled: truthyValue(config.ai_taglines_enabled, false),
    ai_tagline_prompt: String(config.ai_tagline_prompt || 'Generate universal greeting taglines'),
    branding_text_top: String(config.branding_text_top || ''),
    branding_text_bottom: String(config.branding_text_bottom || ''),
    random_words: Array.isArray(config.random_words) ? config.random_words.join(', ') : String(config.random_words || ''),
    whisper_enabled: truthyValue(config.whisper_enabled, false),
    whisper_language: String(config.whisper_language || 'en'),
    postforme_enabled: truthyValue(config.postforme_enabled, false),
    postforme_account_ids_csv: postformeAccounts,
    postforme_schedule_mode: String(config.postforme_schedule_mode || 'immediate'),
    postforme_schedule_datetime: formatDatetimeLocal(config.postforme_schedule_datetime || ''),
    postforme_schedule_timezone: String(config.postforme_schedule_timezone || 'UTC'),
    postforme_schedule_offset_minutes: config.postforme_schedule_offset_minutes ?? 0,
    postforme_schedule_spread_minutes: config.postforme_schedule_spread_minutes ?? 0,
    youtube_enabled: truthyValue(config.youtube_enabled, false),
    tiktok_enabled: truthyValue(config.tiktok_enabled, false),
    instagram_enabled: truthyValue(config.instagram_enabled, false),
    facebook_enabled: truthyValue(config.facebook_enabled, false)
  }
}

function extractAutomationPayloadFromForm(form) {
  const name = String(form.get('name') || '').trim()
  const runMode = sanitizeRunMode(String(form.get('run_mode') || 'local'))
  const localAgentId = toNullableInt(form.get('local_agent_id'))
  const enabled = checkboxValue(form.get('enabled')) ? 1 : 0

  if (form.has('automation_json')) {
    const automationJsonText = String(form.get('automation_json') || '{}').trim() || '{}'
    const apiKeyJsonText = String(form.get('api_key_json') || '').trim()
    const settingsJsonText = String(form.get('settings_json') || '').trim()
    return {
      name,
      runMode,
      localAgentId,
      enabled,
      automationJson: parseJsonObject(automationJsonText, 'Automation JSON'),
      apiKeyJson: apiKeyJsonText !== '' ? parseJsonObject(apiKeyJsonText, 'API Key JSON') : null,
      settingsJson: settingsJsonText !== '' ? parseJsonObject(settingsJsonText, 'Settings JSON') : null
    }
  }

  const selectionMethod = String(form.get('video_selection_method_hidden') || form.get('video_selection_method') || 'days')
  return {
    name,
    runMode,
    localAgentId,
    enabled,
    automationJson: {
      video_source: String(form.get('video_source') || 'ftp'),
      api_key_id: toNullableInt(form.get('api_key_id')) || 0,
      manual_video_links: String(form.get('manual_video_links') || '').trim(),
      youtube_channel_url: String(form.get('youtube_channel_url') || '').trim(),
      schedule_type: String(form.get('schedule_type') || 'daily'),
      schedule_hour: toInt(form.get('schedule_hour')) || 9,
      schedule_every_minutes: toInt(form.get('schedule_every_minutes')) || 10,
      video_selection_method: selectionMethod,
      video_selection_method_hidden: selectionMethod,
      video_days_filter: toInt(form.get('video_days_filter')) || 30,
      video_start_date: String(form.get('video_start_date') || '').trim(),
      video_end_date: String(form.get('video_end_date') || '').trim(),
      rotation_enabled: checkboxValue(form.get('rotation_enabled')) ? 1 : 0,
      rotation_shuffle: checkboxValue(form.get('rotation_shuffle')) ? 1 : 0,
      rotation_auto_reset: checkboxValue(form.get('rotation_auto_reset')) ? 1 : 0,
      videos_per_run: toInt(form.get('videos_per_run')) || 5,
      short_duration: toInt(form.get('short_duration')) || 60,
      playback_speed: String(form.get('playback_speed') || '1.0').trim(),
      short_aspect_ratio: String(form.get('short_aspect_ratio') || '9:16'),
      source_shorts_mode: String(form.get('source_shorts_mode') || 'single'),
      source_shorts_max_count: toInt(form.get('source_shorts_max_count')) || 1,
      ai_taglines_enabled: checkboxValue(form.get('ai_taglines_enabled')) ? 1 : 0,
      ai_tagline_prompt: String(form.get('ai_tagline_prompt') || '').trim(),
      branding_text_top: String(form.get('branding_text_top') || '').trim(),
      branding_text_bottom: String(form.get('branding_text_bottom') || '').trim(),
      random_words: splitCsvList(String(form.get('random_words') || '')),
      whisper_enabled: checkboxValue(form.get('whisper_enabled')) ? 1 : 0,
      whisper_language: String(form.get('whisper_language') || 'en').trim(),
      postforme_enabled: checkboxValue(form.get('postforme_enabled')) ? 1 : 0,
      postforme_account_ids: splitCsvList(String(form.get('postforme_account_ids_csv') || '')),
      postforme_schedule_mode: String(form.get('postforme_schedule_mode') || 'immediate'),
      postforme_schedule_datetime: String(form.get('postforme_schedule_datetime') || '').trim(),
      postforme_schedule_timezone: String(form.get('postforme_schedule_timezone') || 'UTC').trim(),
      postforme_schedule_offset_minutes: toInt(form.get('postforme_schedule_offset_minutes')) || 0,
      postforme_schedule_spread_minutes: toInt(form.get('postforme_schedule_spread_minutes')) || 0,
      youtube_enabled: checkboxValue(form.get('youtube_enabled')) ? 1 : 0,
      tiktok_enabled: checkboxValue(form.get('tiktok_enabled')) ? 1 : 0,
      instagram_enabled: checkboxValue(form.get('instagram_enabled')) ? 1 : 0,
      facebook_enabled: checkboxValue(form.get('facebook_enabled')) ? 1 : 0
    },
    apiKeyJson: null,
    settingsJson: null
  }
}

function sanitizeSettingsTab(tab) {
  return Object.prototype.hasOwnProperty.call(settingsTabFieldMap, tab) ? tab : 'bunny'
}

function getSettingsTabFields(tab) {
  return settingsTabFieldMap[sanitizeSettingsTab(tab)] || settingsTabFieldMap.bunny
}

function settingsTabLabel(tab) {
  const map = {
    bunny: 'Bunny Settings',
    stream: 'Stream API Settings',
    ftp: 'FTP Settings',
    openai: 'AI Settings',
    ffmpeg: 'FFmpeg Settings',
    storage: 'Storage Settings',
    github_runner: 'GitHub Runner Settings',
    postforme: 'Post for Me Settings'
  }
  return map[sanitizeSettingsTab(tab)]
}

function selectedAttr(actual, expected) {
  return String(actual ?? '') === String(expected ?? '') ? ' selected' : ''
}

function checkedAttr(value) {
  return value ? ' checked' : ''
}

function truthySetting(value, defaultValue) {
  if (value === undefined || value === null || value === '') {
    return defaultValue
  }
  return !['0', 'false', 'off', 'no'].includes(String(value).toLowerCase())
}

function truthyValue(value, defaultValue) {
  if (value === undefined || value === null || value === '') {
    return defaultValue
  }
  return !['0', 'false', 'off', 'no'].includes(String(value).toLowerCase())
}

function splitCsvList(value) {
  return String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function formatDatetimeLocal(value) {
  const raw = String(value || '').trim()
  if (raw === '') {
    return ''
  }
  return raw.replace(' ', 'T').slice(0, 16)
}

function appendQueryToRequest(request, params) {
  const url = new URL(request.url)
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, String(value))
  }
  return new Request(url.toString(), {
    method: 'GET',
    headers: request.headers
  })
}

function renderUsersBody({ users, agents, feedback }) {
  const agentNameById = new Map(agents.map((agent) => [Number(agent.id), String(agent.display_name || agent.machine_name || (`Agent #${agent.id}`))]))
  return `
    ${renderFeedback(feedback)}
    <div class="flex items-center justify-between mb-6">
      <div>
        <h2 class="text-xl font-semibold">Users</h2>
        <p class="text-sm text-gray-400 mt-1">Create customer logins, assign their paired PC, and control runner access from the Worker panel.</p>
      </div>
    </div>

    <div class="card rounded-lg p-5 mb-6">
      <h3 class="text-lg font-semibold mb-4">Create User</h3>
      <form method="POST" action="/admin/users" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
        <input type="hidden" name="action" value="create_user">
        <div>
          <label class="block text-sm text-gray-400 mb-1">Email</label>
          <input type="email" name="email" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" required>
        </div>
        <div>
          <label class="block text-sm text-gray-400 mb-1">Password</label>
          <input type="text" name="password" placeholder="Leave blank to auto-generate" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
        </div>
        <div>
          <label class="block text-sm text-gray-400 mb-1">Display Name</label>
          <input type="text" name="display_name" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
        </div>
        <div>
          <label class="block text-sm text-gray-400 mb-1">Client Slug</label>
          <input type="text" name="client_slug" placeholder="auto from name/email" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
        </div>
        <div>
          <label class="block text-sm text-gray-400 mb-1">Role</label>
          <select name="role" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            <option value="user">User</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-400 mb-1">Status</label>
          <select name="status" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            <option value="active">Active</option>
            <option value="disabled">Disabled</option>
          </select>
        </div>
        <div>
          <label class="block text-sm text-gray-400 mb-1">Assigned Local Agent</label>
          <select name="assigned_local_agent_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
            <option value="">None</option>
            ${agents.map((agent) => `<option value="${agent.id}">${escapeHtml(agent.display_name || agent.machine_name || `Agent #${agent.id}`)}</option>`).join('')}
          </select>
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm text-gray-300">
            <input type="checkbox" name="can_use_github_runner" value="1" class="rounded border-gray-600 bg-gray-800">
            Allow GitHub Runner
          </label>
        </div>
        <div class="flex items-end xl:col-span-2">
          <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Create User</button>
        </div>
      </form>
      <div class="text-xs text-gray-500 mt-3">
        Local-only customer profile: leave <code>Allow GitHub Runner</code> unchecked and assign the paired local agent.
      </div>
    </div>

    <div class="space-y-4">
      ${users.map((user) => {
        const assignedAgentName = user.assigned_local_agent_id ? (agentNameById.get(Number(user.assigned_local_agent_id)) || `Agent #${user.assigned_local_agent_id}`) : 'Not assigned'
        return `
          <div class="card rounded-lg p-5">
            <div class="flex items-center justify-between mb-4">
              <div>
                <div class="font-semibold">${escapeHtml(user.display_name || user.email)}</div>
                <div class="text-sm text-gray-400">${escapeHtml(user.email)}</div>
                <div class="text-xs text-gray-500 mt-1">Client Slug: <code class="bg-gray-900 px-2 py-1 rounded">${escapeHtml(user.client_slug || '')}</code></div>
              </div>
              <div class="flex items-center gap-2 text-xs">
                <span class="px-2 py-1 rounded ${user.role === 'admin' ? 'bg-indigo-500/15 text-indigo-300' : 'bg-gray-700 text-gray-200'}">${escapeHtml(user.role)}</span>
                <span class="px-2 py-1 rounded ${user.status === 'active' ? 'bg-green-500/15 text-green-300' : 'bg-red-500/15 text-red-300'}">${escapeHtml(user.status)}</span>
                <span class="px-2 py-1 rounded ${user.can_use_github_runner ? 'bg-amber-500/15 text-amber-300' : 'bg-blue-500/15 text-blue-300'}">
                  ${user.can_use_github_runner ? 'Runner + Local' : 'Local Only'}
                </span>
              </div>
            </div>

            <form method="POST" action="/admin/users" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
              <input type="hidden" name="action" value="update_user">
              <input type="hidden" name="user_id" value="${user.id}">
              <div>
                <label class="block text-sm text-gray-400 mb-1">Email</label>
                <input type="email" name="email" value="${escapeHtml(user.email)}" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg" required>
              </div>
              <div>
                <label class="block text-sm text-gray-400 mb-1">Display Name</label>
                <input type="text" name="display_name" value="${escapeHtml(user.display_name || '')}" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
              </div>
              <div>
                <label class="block text-sm text-gray-400 mb-1">Client Slug</label>
                <input type="text" name="client_slug" value="${escapeHtml(user.client_slug || '')}" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
              </div>
              <div>
                <label class="block text-sm text-gray-400 mb-1">New Password</label>
                <input type="text" name="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
              </div>
              <div>
                <label class="block text-sm text-gray-400 mb-1">Role</label>
                <select name="role" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                  <option value="user"${user.role === 'user' ? ' selected' : ''}>User</option>
                  <option value="admin"${user.role === 'admin' ? ' selected' : ''}>Admin</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-gray-400 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                  <option value="active"${user.status === 'active' ? ' selected' : ''}>Active</option>
                  <option value="disabled"${user.status === 'disabled' ? ' selected' : ''}>Disabled</option>
                </select>
              </div>
              <div>
                <label class="block text-sm text-gray-400 mb-1">Assigned Local Agent</label>
                <select name="assigned_local_agent_id" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
                  <option value="">None</option>
                  ${agents.map((agent) => `<option value="${agent.id}"${Number(user.assigned_local_agent_id || 0) === Number(agent.id) ? ' selected' : ''}>${escapeHtml(agent.display_name || agent.machine_name || `Agent #${agent.id}`)}</option>`).join('')}
                </select>
              </div>
              <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm text-gray-300">
                  <input type="checkbox" name="can_use_github_runner" value="1" class="rounded border-gray-600 bg-gray-800"${user.can_use_github_runner ? ' checked' : ''}>
                  Allow GitHub Runner
                </label>
              </div>
              <div class="flex items-end text-sm text-gray-400">
                Agent: ${escapeHtml(assignedAgentName)}
              </div>
              <div class="flex items-end xl:col-span-3">
                <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg">Save User</button>
              </div>
            </form>
          </div>
        `
      }).join('')}
    </div>
  `
}

function renderAgentsBody({ agents, pairingToken, feedback, panelBaseUrl, agentJobCounts, installScriptUrl, installManifest }) {
  const command = `$p=Join-Path $env:TEMP 'video-workflow-agent-install.ps1'; Invoke-WebRequest '${installScriptUrl}' -OutFile $p; powershell -ExecutionPolicy Bypass -File $p -CreateScheduledTask`
  return `
    ${renderFeedback(feedback)}
    <div class="flex items-center justify-between mb-6">
      <div>
        <h2 class="text-xl font-semibold">Local Agents</h2>
        <p class="text-sm text-gray-400 mt-1">Pair remote PCs with this hosted panel and dispatch local jobs securely.</p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
      <div class="card rounded-lg p-5 space-y-4">
        <div>
          <h3 class="font-semibold">Pairing</h3>
          <p class="text-sm text-gray-400 mt-1">Install the worker on the target PC once, then use this token to pair it.</p>
        </div>
        <div class="mono-block">${escapeHtml(pairingToken)}</div>
        <div class="bg-gray-900 rounded-lg p-3 font-mono text-sm break-all">${escapeHtml(pairingToken)}</div>
        <form method="POST" action="/admin/agents">
          <input type="hidden" name="action" value="regenerate_pairing_token">
          <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 rounded-lg text-sm">Regenerate Token</button>
        </form>
      </div>

      <div class="card rounded-lg p-5 space-y-4">
        <div>
          <h3 class="font-semibold">Hosted Panel URL</h3>
          <p class="text-sm text-gray-400 mt-1">Public URL that agents will use to hit register, poll, report, and complete endpoints.</p>
        </div>
        <form method="POST" action="/admin/agents" class="space-y-3">
          <input type="hidden" name="action" value="save_panel_url">
          <input type="text" name="panel_public_base_url" value="${escapeHtml(panelBaseUrl || '')}" placeholder="https://your-domain.com" class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg">
          <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 rounded-lg text-sm">Save URL</button>
        </form>
      </div>
    </div>

    <div class="card rounded-lg p-5 mb-6 space-y-4">
      <div>
        <h3 class="font-semibold">Installer Command</h3>
        <p class="text-sm text-gray-400 mt-1">Use this one-liner on the client PC. It fetches the agent package, bootstraps PHP if required, pairs the machine, and starts polling.</p>
      </div>
      <pre class="bg-gray-900 rounded-lg p-4 overflow-x-auto text-xs text-green-400"><code>${escapeHtml(command)}</code></pre>
      <div class="text-xs text-gray-500">Manifest snapshot served to installer:</div>
      <pre class="bg-gray-900 rounded-lg p-4 overflow-x-auto text-xs text-gray-300"><code>${escapeHtml(JSON.stringify(installManifest, null, 2))}</code></pre>
    </div>

    <div class="card rounded-lg overflow-hidden">
      <div class="p-4 border-b border-gray-800">
        <h3 class="font-semibold">Registered Agents</h3>
      </div>
      <div class="p-4">
        ${agents.length ? `
          <div class="space-y-3">
            ${agents.map((agent) => {
              const counts = agentJobCounts.get(Number(agent.id)) || {}
              const status = String(agent.status || 'offline')
              const statusClass = status === 'online'
                ? 'bg-green-500/10 text-green-400'
                : (status === 'disabled' ? 'bg-red-500/10 text-red-400' : 'bg-yellow-500/10 text-yellow-400')
              return `
                <div class="border border-gray-800 rounded-lg p-4">
                  <div class="flex items-start justify-between gap-4">
                    <div>
                      <div class="font-medium">${escapeHtml(agent.display_name || agent.machine_name || `Agent #${agent.id}`)}</div>
                      <div class="text-sm text-gray-400">${escapeHtml(agent.machine_name || '-')} | ${escapeHtml(agent.platform || '-')} | last seen ${escapeHtml(agent.last_seen_at || 'never')}</div>
                      <div class="text-xs text-gray-500 mt-1 font-mono">${escapeHtml(agent.agent_key || '-')}</div>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="px-2 py-1 rounded text-xs font-medium ${statusClass}">${escapeHtml(status)}</span>
                      <form method="POST" action="/admin/agents" class="inline">
                        <input type="hidden" name="action" value="set_agent_status">
                        <input type="hidden" name="agent_id" value="${agent.id}">
                        <input type="hidden" name="status" value="${status === 'disabled' ? 'offline' : 'disabled'}">
                        <button type="submit" class="px-3 py-1 text-xs rounded bg-gray-700 hover:bg-gray-600">${status === 'disabled' ? 'Enable' : 'Disable'}</button>
                      </form>
                    </div>
                  </div>
                  <div class="grid grid-cols-3 gap-3 mt-4 text-sm">
                    <div class="bg-gray-900 rounded p-3">
                      <div class="text-gray-500 text-xs">Queued</div>
                      <div class="font-mono text-lg">${Number(counts.queued || 0)}</div>
                    </div>
                    <div class="bg-gray-900 rounded p-3">
                      <div class="text-gray-500 text-xs">Running</div>
                      <div class="font-mono text-lg">${Number(counts.running || 0) + Number(counts.claimed || 0)}</div>
                    </div>
                    <div class="bg-gray-900 rounded p-3">
                      <div class="text-gray-500 text-xs">Completed</div>
                      <div class="font-mono text-lg">${Number(counts.completed || 0)}</div>
                    </div>
                  </div>
                </div>
              `
            }).join('')}
          </div>
        ` : '<div class="text-gray-400">No agents paired yet.</div>'}
      </div>
    </div>
  `
}

function renderPage({ title, user, body, currentPath = '' }) {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)} | Video Workflow Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#6366f1',
            accent: '#22c55e'
          }
        }
      }
    }
  </script>
  <style>
    :root {
      --bg: #0f0f0f;
      --panel: #1a1a1a;
      --ink: #e5e5e5;
      --muted: #9ca3af;
      --line: #2a2a2a;
      --accent: #4f46e5;
      --accent-soft: rgba(99,102,241,0.18);
      --olive: #22c55e;
      --shadow: 0 18px 60px rgba(0,0,0,0.28);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--ink);
      background: var(--bg);
      font-family: "Segoe UI Variable", "Aptos", "Segoe UI", sans-serif;
      min-height: 100vh;
    }
    a { color: inherit; text-decoration: none; }
    .shell { max-width: 1280px; margin: 0 auto; padding: 28px 24px 56px; }
    .topbar, .section-head, .list-card-head, .card-head {
      display:flex; justify-content:space-between; align-items:flex-start; gap:16px;
    }
    .brand { font-size: 1.7rem; font-weight: 700; }
    .brand small { display:block; font-size:0.84rem; color: var(--muted); font-weight: 400; margin-top: 4px; }
    .nav { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 22px;
      box-shadow: var(--shadow);
    }
    .auth-panel { max-width: 540px; margin: 8vh auto 0; }
    h1, h2 { margin: 0 0 10px; line-height: 1.1; }
    h1 { font-size: clamp(2rem, 4vw, 3rem); }
    h2 { font-size: 1.2rem; }
    .lead, .muted { color: var(--muted); }
    .lead { font-size: 1rem; max-width: 54ch; }
    .compact { font-size: 0.88rem; }
    .eyebrow { text-transform: uppercase; letter-spacing: 0.16em; font-size: 0.72rem; color: var(--olive); margin-bottom: 10px; }
    .stack { display: grid; gap: 14px; }
    .grid.two { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .dashboard-grid { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
    .span-two { grid-column: span 2; }
    .field { display:grid; gap:8px; }
    .field span { font-size: 0.86rem; color: var(--muted); }
    .field input, .field select, .field textarea {
      width: 100%; border: 1px solid #374151; border-radius: 12px;
      padding: 12px 14px; background: #1f2937; color: var(--ink); font: inherit;
    }
    .field textarea { resize: vertical; min-height: 120px; font-family: Consolas, "Courier New", monospace; font-size: 0.88rem; }
    .toggle { display:flex; align-items:center; gap:10px; color: var(--muted); }
    .button {
      display:inline-flex; align-items:center; justify-content:center; gap:8px; padding: 11px 15px;
      border-radius: 10px; border: 1px solid #374151; background: #1f2937;
      color: var(--ink); cursor:pointer; font: inherit;
    }
    .button.primary { background: #4f46e5; color: #fff; border-color: #4f46e5; }
    .button.ghost { background: transparent; }
    .button.nav-active { background: #1f2937; border-color: #374151; }
    .toolbar { display:flex; gap:10px; flex-wrap: wrap; align-items:center; }
    .toolbar.wrap { row-gap: 10px; }
    .stats-row { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:12px; margin-top: 16px; }
    .metric { border:1px solid var(--line); border-radius:18px; padding:16px; background: rgba(15,23,42,0.56); }
    .metric span { display:block; font-size: 1.8rem; font-weight: 700; }
    .metric small { color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; }
    .badge { min-width: 70px; text-align:center; padding: 8px 12px; border-radius: 999px; background: var(--accent-soft); color: #c7d2fe; font-weight: 700; font-size: 0.82rem; }
    .flash { border-radius: 18px; padding: 14px 16px; margin-bottom: 16px; border: 1px solid var(--line); }
    .flash.error { background: rgba(127,29,29,0.34); color: #fecaca; }
    .flash.success { background: rgba(20,83,45,0.32); color: #bbf7d0; }
    .flash.info { background: rgba(30,64,175,0.28); color: #bfdbfe; }
    .mono-block {
      margin-top: 10px; padding: 14px; border-radius: 16px; background: #020617; color: #edf2ea;
      overflow:auto; font-family: Consolas, "Courier New", monospace; font-size: 0.84rem; white-space: pre-wrap; word-break: break-word;
    }
    .progress-bar { height: 8px; border-radius: 999px; background: rgba(26,31,25,0.08); overflow:hidden; margin: 14px 0 18px; }
    .progress-bar span { display:block; height:100%; background: linear-gradient(90deg, #0ea5e9, #4f46e5, #7c3aed); border-radius:999px; }
    .automation-card { display:grid; gap: 10px; margin-bottom: 14px; }
    .automation-card-shell { display:grid; gap: 14px; }
    .card-progress { display:grid; gap: 8px; }
    .compact-progress { margin: 0; height: 10px; background: rgba(15,23,42,0.88); }
    .progress-meta { display:flex; justify-content:space-between; gap:12px; color: var(--muted); font-size: 0.84rem; flex-wrap:wrap; }
    .compact-form { margin-top: 6px; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse: collapse; }
    th, td { text-align:left; padding: 12px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); }
    .list-stack { display:grid; gap: 12px; }
    .list-card { border:1px solid var(--line); border-radius: 18px; padding: 16px; background: rgba(15,23,42,0.56); }
    .inline-form { display:flex; gap:10px; flex-wrap: wrap; align-items:center; }
    .inline-form select, .inline-form input { min-width: 160px; }
    .inline-link { color: var(--accent); }
    .hidden { display:none !important; }
    .modal-backdrop {
      position: fixed; inset: 0; z-index: 90; padding: 28px 18px;
      background: rgba(2,6,23,0.82); backdrop-filter: blur(10px);
      display:flex; align-items:flex-start; justify-content:center; overflow:auto;
    }
    .modal-panel {
      width: min(1120px, 100%); margin: 2vh auto; border-radius: 26px; padding: 22px;
      border: 1px solid var(--line); background: rgba(2,6,23,0.96); box-shadow: var(--shadow);
    }
    .modal-wide { width: min(1240px, 100%); }
    .tab-strip, .tabs-wrap { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
    .tab-button, .tab-chip { display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:14px; border:1px solid var(--line); background: rgba(15,23,42,0.72); color: var(--muted); cursor:pointer; }
    .tab-button.active, .tab-chip.active { background: var(--accent-soft); color: var(--ink); border-color: rgba(99,102,241,0.38); }
    .tab-pane { display:none; padding-top:6px; }
    .tab-pane.active { display:block; }
    .note-card, .subpanel { border:1px solid var(--line); border-radius:18px; padding:16px; background: rgba(15,23,42,0.52); }
    .empty-state { min-height: 180px; display:grid; place-content:center; text-align:center; gap: 8px; }
    .runtime-grid { align-items:flex-start; }
    .runtime-progress { margin-bottom: 10px; }
    .runtime-list { display:grid; gap:10px; max-height: 360px; overflow:auto; padding-right: 6px; }
    .runtime-entry { border:1px solid var(--line); border-radius: 16px; padding: 12px; background: rgba(15,23,42,0.58); }
    .card { background: var(--panel); border: 1px solid var(--line); box-shadow: var(--shadow); }
    .rounded-lg { border-radius: 14px; }
    .p-4 { padding: 16px; }
    .p-12 { padding: 48px; }
    .border-b { border-bottom: 1px solid var(--line); }
    .border-gray-800 { border-color: var(--line); }
    .text-xl { font-size: 1.25rem; }
    .text-lg { font-size: 1.125rem; }
    .text-sm { font-size: 0.875rem; }
    .text-xs { font-size: 0.75rem; }
    .font-semibold { font-weight: 600; }
    .font-medium { font-weight: 500; }
    .font-bold { font-weight: 700; }
    .font-mono { font-family: Consolas, "Courier New", monospace; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-gray-400, .text-gray-500 { color: var(--muted); }
    .text-green-500 { color: #22c55e; }
    .text-indigo-500 { color: #6366f1; }
    .text-red-500 { color: #ef4444; }
    .text-yellow-500 { color: #eab308; }
    .text-orange-400 { color: #fb923c; }
    .text-orange-500 { color: #f97316; }
    .text-pink-400 { color: #f472b6; }
    .text-pink-500 { color: #ec4899; }
    .text-sky-400 { color: #38bdf8; }
    .mt-1 { margin-top: 4px; }
    .mb-1 { margin-bottom: 4px; }
    .py-12 { padding-top: 48px; padding-bottom: 48px; }
    .space-y-2 > * + * { margin-top: 8px; }
    .page-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom: 24px; }
    .stats-grid { display:grid; gap:16px; margin-bottom: 24px; }
    .stats-grid-six { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    .stats-grid-three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .stat-card { min-height: 126px; }
    .stat-head { display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px; }
    .stat-value { font-size: 2rem; font-weight: 700; font-family: Consolas, "Courier New", monospace; }
    .clickable-card { cursor: pointer; transition: background-color 0.15s ease; }
    .clickable-card:hover { background: #202020; }
    .tab-inline-nav { display:flex; gap:6px; margin-bottom: 16px; }
    .tab-inline {
      border: 0; background: transparent; color: var(--muted); padding: 10px 18px; border-radius: 10px; cursor:pointer;
      font-size: 0.9rem; font-weight: 500;
    }
    .tab-inline.active { background: #1f2937; color: #fff; }
    .tab-count { background: rgba(249,115,22,0.18); color: #fb923c; padding: 2px 8px; border-radius: 999px; margin-left: 8px; font-size: 0.75rem; }
    .job-row {
      display:flex; justify-content:space-between; align-items:center; gap:16px;
      padding: 12px; border: 1px solid var(--line); border-radius: 12px; background: rgba(255,255,255,0.01);
    }
    .job-main { flex:1; min-width: 0; }
    .job-side { display:flex; align-items:center; gap:12px; flex-wrap: wrap; justify-content:flex-end; }
    .mini-progress { width: 96px; height: 8px; background: #374151; border-radius: 999px; overflow:hidden; }
    .mini-progress-bar { height:100%; background: #6366f1; }
    .status-pill { padding: 4px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; text-transform: lowercase; }
    .user-meta { display:flex; align-items:center; gap:12px; }
    .user-box { text-align:right; }
    .danger-button { border-color: rgba(239,68,68,0.35); color: #fca5a5; }
    .output-summary-card { margin-bottom: 24px; }
    .compact-stack { display:grid; gap: 10px; }
    .player-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
    .player-card { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; overflow: hidden; box-shadow: var(--shadow); }
    .player-card-preview { background: #020617; aspect-ratio: 9 / 16; display:flex; align-items:center; justify-content:center; }
    .player-card-preview video { width: 100%; height: 100%; object-fit: contain; background: #000; }
    .player-card-body { padding: 16px; display:grid; gap: 12px; }
    .player-placeholder { color: var(--muted); font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; }
    .status-success { background: rgba(34,197,94,0.18); color: #86efac; }
    .status-queued { background: rgba(59,130,246,0.18); color: #93c5fd; }
    .status-warning { background: rgba(245,158,11,0.18); color: #fcd34d; }
    .status-error { background: rgba(239,68,68,0.18); color: #fca5a5; }
    .status-neutral { background: rgba(148,163,184,0.16); color: #cbd5e1; }
    code { background: rgba(15,23,42,0.9); padding:2px 6px; border-radius:8px; }
    @media (max-width: 980px) {
      .dashboard-grid { grid-template-columns: 1fr; }
      .span-two { grid-column: span 1; }
      .grid.two, .stats-row, .stats-grid-six, .stats-grid-three { grid-template-columns: 1fr; }
      .topbar, .section-head, .list-card-head, .card-head { flex-direction: column; align-items: stretch; }
      .meta-actions { display:flex; flex-wrap:wrap; gap:10px; }
      .page-head, .job-row, .user-meta { flex-direction: column; align-items: stretch; }
      .modal-backdrop { padding: 12px; }
      .modal-panel { padding: 18px; }
    }
  </style>
</head>
<body class="min-h-screen bg-[#0f0f0f] text-gray-200">
  <div class="border-b border-gray-800 bg-gray-900">
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold leading-tight">
        Video Workflow Control
        <span class="block text-sm font-normal text-gray-400">Cloudflare Worker panel for local automation agents</span>
      </h1>
      ${user ? `
        <div class="flex items-center gap-4">
          <nav class="flex gap-2 flex-wrap">
            ${user.role === 'admin' ? `
              <a href="${legacyPageHref('/dashboard')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/dashboard' ? 'bg-gray-800' : ''}">Dashboard</a>
              <a href="${legacyPageHref('/api-keys')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/api-keys' ? 'bg-gray-800' : ''}">API Keys</a>
              <a href="${legacyPageHref('/jobs')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/jobs' ? 'bg-gray-800' : ''}">Jobs</a>
            ` : ''}
            <a href="${legacyPageHref('/automation')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/automation' ? 'bg-gray-800' : ''}">Automation</a>
            ${user.role === 'admin' ? `
              <a href="${legacyPageHref('/admin/agents')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/admin/agents' ? 'bg-gray-800' : ''}">Agents</a>
              <a href="${legacyPageHref('/admin/users')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/admin/users' ? 'bg-gray-800' : ''}">Users</a>
            ` : ''}
            <a href="${legacyPageHref('/player')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/player' ? 'bg-indigo-600' : ''}">
              <span class="flex items-center gap-1">Player</span>
            </a>
            ${user.role === 'admin' ? `<a href="${legacyPageHref('/settings')}" class="px-4 py-2 rounded hover:bg-gray-800 ${currentPath === '/settings' ? 'bg-gray-800' : ''}">Settings</a>` : ''}
          </nav>
          <div class="flex items-center gap-3 text-sm">
            <div class="text-right">
              <div class="font-medium text-gray-100">${escapeHtml(user.display_name || user.email || 'User')}</div>
              <div class="text-xs text-gray-400">${user.role === 'admin' ? 'Admin' : 'User'}</div>
            </div>
            <form method="POST" action="/logout"><button type="submit" class="px-3 py-2 rounded bg-gray-800 hover:bg-gray-700 text-gray-200">Logout</button></form>
          </div>
        </div>
      ` : `<a class="px-4 py-2 rounded bg-gray-800 hover:bg-gray-700 text-gray-100" href="${legacyPageHref('/login')}">Login</a>`}
    </div>
  </div>
  <main class="max-w-7xl mx-auto px-6 py-8">
    ${body}
  </main>
  <script>
    function confirmDelete(message) {
      return window.confirm(message || 'Are you sure you want to delete?');
    }

    function showToast(message, type) {
      const toast = document.createElement('div');
      toast.className = 'fixed bottom-4 right-4 px-6 py-3 rounded-lg text-white shadow-lg z-50 ' + (type === 'error' ? 'bg-red-600' : 'bg-green-600');
      toast.textContent = message;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 3000);
    }
  </script>
</body>
</html>`
}

async function ensureSchema(env) {
  if (schemaReadyPromise) {
    return schemaReadyPromise
  }

  schemaReadyPromise = (async () => {
    for (const statement of bootstrapSchemaStatements) {
      await env.DB.prepare(statement).run()
    }
    await ensureColumnExists(env, 'output_files', 'local_path', 'TEXT')
  })()

  try {
    await schemaReadyPromise
  } catch (error) {
    schemaReadyPromise = null
    throw error
  }
}

async function ensureColumnExists(env, tableName, columnName, columnDefinition) {
  const rows = await env.DB.prepare(`PRAGMA table_info(${tableName})`).all()
  const exists = (rows.results || []).some((row) => String(row.name || '').toLowerCase() === String(columnName).toLowerCase())
  if (!exists) {
    await env.DB.prepare(`ALTER TABLE ${tableName} ADD COLUMN ${columnName} ${columnDefinition}`).run()
  }
}

async function ensureDefaultAdmin(env) {
  const row = await env.DB.prepare('SELECT COUNT(*) AS total FROM app_users').first()
  if (Number(row?.total || 0) > 0) {
    return
  }
  const now = isoNow()
  const email = normalizeEmail(env.DEFAULT_ADMIN_EMAIL || 'admin@local')
  const password = String(env.DEFAULT_ADMIN_PASSWORD || 'ChangeMe@123')
  const clientSlug = await ensureUniqueClientSlug(env, 'administrator', null)
  await env.DB.prepare(`
    INSERT INTO app_users (
      email, password_hash, display_name, client_slug, role, status,
      can_use_github_runner, assigned_local_agent_id, last_login_at, created_at, updated_at
    ) VALUES (?, ?, ?, ?, 'admin', 'active', 1, NULL, NULL, ?, ?)
  `).bind(
    email,
    await hashPassword(password),
    'Administrator',
    clientSlug,
    now,
    now
  ).run()
}

async function ensurePairingToken(env) {
  const token = await getSetting(env, 'local_agent_pairing_token')
  if (token !== '') {
    return token
  }
  const created = env.DEFAULT_PAIRING_TOKEN && String(env.DEFAULT_PAIRING_TOKEN).trim() !== ''
    ? String(env.DEFAULT_PAIRING_TOKEN).trim()
    : randomHex(16)
  await setSetting(env, 'local_agent_pairing_token', created)
  return created
}

async function canAccessInstallManifest(request, env) {
  const url = new URL(request.url)
  const queryToken = String(url.searchParams.get('pairing_token') || '').trim()
  const validToken = await getPairingToken(env)
  if (queryToken && timingSafeEqual(await sha256Hex(queryToken), await sha256Hex(validToken))) {
    return true
  }
  const session = await getSessionContext(request, env)
  return Boolean(session.user && session.user.role === 'admin')
}

function buildInstallManifest(env, origin) {
  return {
    success: true,
    server_url: origin,
    package_url: resolveAgentPackageUrl(env),
    php_download_url: String(env.PHP_WINDOWS_ZIP_URL || '').trim(),
    install_dir: 'C:\\VideoWorkflowAgent',
    worker_db_name: String(env.DEFAULT_WORKER_DB_NAME || 'video_workflow_agent'),
    worker_base_dir: String(env.DEFAULT_WORKER_BASE_DIR || 'C:\\VideoWorkflowAgentData'),
    poll_interval: Number.parseInt(String(env.DEFAULT_POLL_INTERVAL || '10'), 10) || 10
  }
}

function resolveAgentPackageUrl(env) {
  const direct = String(env.AGENT_PACKAGE_URL || '').trim()
  if (direct !== '') {
    return direct
  }
  const repoSlug = String(env.GITHUB_REPO_SLUG || '').trim()
  if (repoSlug !== '') {
    const ref = String(env.GITHUB_REF || 'main').trim() || 'main'
    return `https://github.com/${repoSlug}/archive/refs/heads/${ref}.zip`
  }
  return ''
}

async function getSessionContext(request, env) {
  const cookies = parseCookies(request.headers.get('cookie') || '')
  const raw = cookies[sessionCookieName]
  if (!raw) {
    return { user: null }
  }
  const payload = await verifySignedSessionCookie(raw, env)
  if (!payload || !payload.user_id || !payload.exp || payload.exp < Math.floor(Date.now() / 1000)) {
    return { user: null }
  }
  const user = await getUserById(env, Number(payload.user_id))
  if (!user || user.status !== 'active') {
    return { user: null }
  }
  return { user }
}

async function signSessionCookie(payload, env) {
  const secret = getSessionSecret(env)
  const encoded = base64UrlEncode(JSON.stringify(payload))
  const signature = await hmacHex(secret, encoded)
  return `${encoded}.${signature}`
}

async function verifySignedSessionCookie(value, env) {
  const parts = String(value || '').split('.')
  if (parts.length !== 2) {
    return null
  }
  const [encoded, signature] = parts
  const expected = await hmacHex(getSessionSecret(env), encoded)
  if (!timingSafeEqual(signature, expected)) {
    return null
  }
  try {
    return JSON.parse(base64UrlDecode(encoded))
  } catch {
    return null
  }
}

function getSessionSecret(env) {
  return String(env.SESSION_SECRET || 'local-dev-session-secret-change-me')
}

function buildSessionCookie(value, secure) {
  return `${sessionCookieName}=${value}; Path=/; HttpOnly; SameSite=Lax${secure ? '; Secure' : ''}`
}

function buildExpiredSessionCookie(secure = true) {
  return `${sessionCookieName}=; Path=/; HttpOnly; SameSite=Lax${secure ? '; Secure' : ''}; Max-Age=0`
}

async function getUserById(env, userId) {
  const row = await env.DB.prepare('SELECT * FROM app_users WHERE id = ? LIMIT 1').bind(userId).first()
  return row ? normalizeUser(row) : null
}

async function getUserByEmail(env, email) {
  if (!email) {
    return null
  }
  const row = await env.DB.prepare('SELECT * FROM app_users WHERE email = ? LIMIT 1').bind(email).first()
  return row ? normalizeUser(row) : null
}

async function listUsers(env) {
  const rows = await env.DB.prepare('SELECT * FROM app_users ORDER BY role DESC, id ASC').all()
  return (rows.results || []).map(normalizeUser)
}

async function listApiKeys(env, includeInactive = true) {
  const rows = includeInactive
    ? await env.DB.prepare('SELECT * FROM api_keys ORDER BY created_at DESC, id DESC').all()
    : await env.DB.prepare("SELECT * FROM api_keys WHERE status = 'active' ORDER BY created_at DESC, id DESC").all()
  return (rows.results || []).map(normalizeApiKey)
}

async function getApiKeyById(env, id) {
  if (!id) {
    return null
  }
  const row = await env.DB.prepare('SELECT * FROM api_keys WHERE id = ? LIMIT 1').bind(id).first()
  return row ? normalizeApiKey(row) : null
}

function normalizeUser(row) {
  return {
    ...row,
    id: Number(row.id),
    can_use_github_runner: Number(row.can_use_github_runner || 0),
    assigned_local_agent_id: row.assigned_local_agent_id === null ? null : Number(row.assigned_local_agent_id)
  }
}

function normalizeApiKey(row) {
  return {
    id: Number(row.id),
    name: String(row.name || ''),
    api_key: String(row.api_key || ''),
    library_id: String(row.library_id || ''),
    storage_zone: row.storage_zone === null ? null : String(row.storage_zone || ''),
    ftp_host: row.ftp_host === null ? null : String(row.ftp_host || ''),
    ftp_username: row.ftp_username === null ? null : String(row.ftp_username || ''),
    ftp_password: row.ftp_password === null ? null : String(row.ftp_password || ''),
    ftp_port: Number(row.ftp_port || 21),
    cdn_hostname: row.cdn_hostname === null ? null : String(row.cdn_hostname || ''),
    pull_zone_id: row.pull_zone_id === null ? null : String(row.pull_zone_id || ''),
    status: String(row.status || 'active'),
    created_at: String(row.created_at || '')
  }
}

async function createUser(env, { email, password, displayName, role, status, canUseGithubRunner, assignedLocalAgentId, clientSlug }) {
  const existing = await getUserByEmail(env, email)
  if (existing) {
    throw new Error('A user with this email already exists.')
  }
  const now = isoNow()
  const slug = await ensureUniqueClientSlug(env, clientSlug || displayName || email.split('@')[0], null)
  const result = await env.DB.prepare(`
    INSERT INTO app_users (
      email, password_hash, display_name, client_slug, role, status,
      can_use_github_runner, assigned_local_agent_id, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).bind(
    email,
    await hashPassword(password),
    displayName || null,
    slug,
    role,
    status,
    canUseGithubRunner ? 1 : 0,
    assignedLocalAgentId || null,
    now,
    now
  ).run()
  return Number(result.meta?.last_row_id || 0)
}

async function createMagicLink(env, { userId, createdByUserId, redirectPath, expiryHours, origin }) {
  const user = await getUserById(env, userId)
  if (!user) {
    throw new Error('User not found.')
  }
  const token = randomToken(24)
  const tokenHash = await sha256Hex(token)
  const expiresAt = new Date(Date.now() + (Math.max(1, expiryHours) * 60 * 60 * 1000)).toISOString()
  await env.DB.prepare(`
    INSERT INTO magic_login_tokens (
      user_id, token_hash, redirect_path, expires_at, one_time, used_at, revoked_at, created_by_user_id, created_at
    ) VALUES (?, ?, ?, ?, 1, NULL, NULL, ?, ?)
  `).bind(
    userId,
    tokenHash,
    sanitizeRedirectPath(redirectPath || '/dashboard'),
    expiresAt,
    createdByUserId || null,
    isoNow()
  ).run()
  const clientPart = user.client_slug ? `&client=${encodeURIComponent(user.client_slug)}` : ''
  return {
    token,
    url: `${origin}/magic-login?token=${encodeURIComponent(token)}${clientPart}`
  }
}

async function consumeMagicLink(env, token, clientSlug) {
  const tokenHash = await sha256Hex(token)
  const record = await env.DB.prepare(`
    SELECT t.*, u.email, u.password_hash, u.display_name, u.client_slug, u.role, u.status, u.can_use_github_runner, u.assigned_local_agent_id
    FROM magic_login_tokens t
    JOIN app_users u ON u.id = t.user_id
    WHERE t.token_hash = ?
    LIMIT 1
  `).bind(tokenHash).first()

  if (!record) {
    return { success: false, error: 'Magic link was not found.' }
  }
  if (record.revoked_at) {
    return { success: false, error: 'Magic link has been revoked.' }
  }
  if (record.used_at && Number(record.one_time || 0) === 1) {
    return { success: false, error: 'Magic link has already been used.' }
  }
  if (new Date(record.expires_at).getTime() <= Date.now()) {
    return { success: false, error: 'Magic link has expired.' }
  }
  if (record.status !== 'active') {
    return { success: false, error: 'User account is disabled.' }
  }
  if (clientSlug && record.client_slug && clientSlug !== record.client_slug) {
    return { success: false, error: 'Magic link does not match this client slug.' }
  }

  const now = isoNow()
  await env.DB.batch([
    env.DB.prepare('UPDATE magic_login_tokens SET used_at = ? WHERE id = ?').bind(now, record.id),
    env.DB.prepare('UPDATE app_users SET last_login_at = ?, updated_at = ? WHERE id = ?').bind(now, now, record.user_id)
  ])

  return {
    success: true,
    user: normalizeUser(record),
    redirectPath: sanitizeRedirectPath(record.redirect_path || '/dashboard')
  }
}

async function listAgents(env) {
  const rows = await env.DB.prepare('SELECT * FROM local_agents ORDER BY display_name ASC, id ASC').all()
  return rows.results || []
}

async function listAgentJobCounts(env) {
  const rows = await env.DB.prepare(`
    SELECT agent_id, status, COUNT(*) AS total
    FROM local_agent_jobs
    GROUP BY agent_id, status
  `).all()
  const map = new Map()
  for (const row of rows.results || []) {
    const agentId = Number(row.agent_id || 0)
    if (!map.has(agentId)) {
      map.set(agentId, {})
    }
    map.get(agentId)[String(row.status || 'unknown')] = Number(row.total || 0)
  }
  return map
}

async function listVisibleAgents(env, user) {
  if (user.role === 'admin') {
    return (await listAgents(env)).filter((agent) => agent.status !== 'disabled')
  }
  if (user.assigned_local_agent_id) {
    const agent = await getAgentById(env, user.assigned_local_agent_id)
    return agent && agent.status !== 'disabled' ? [agent] : []
  }
  return []
}

async function getAgentById(env, agentId) {
  return await env.DB.prepare('SELECT * FROM local_agents WHERE id = ? LIMIT 1').bind(agentId).first()
}

async function authenticateAgent(env, agentKey, agentSecret) {
  if (!agentKey || !agentSecret) {
    return null
  }
  const row = await env.DB.prepare('SELECT * FROM local_agents WHERE agent_key = ? LIMIT 1').bind(agentKey).first()
  if (!row) {
    return null
  }
  if (!await verifyPassword(agentSecret, row.agent_secret_hash)) {
    return null
  }
  return row
}

async function listAutomationsForUser(env, user) {
  const sql = user.role === 'admin'
    ? `
      SELECT a.*, ag.display_name AS agent_name
      FROM automations a
      LEFT JOIN local_agents ag ON ag.id = a.local_agent_id
      ORDER BY a.updated_at DESC, a.id DESC
    `
    : `
      SELECT a.*, ag.display_name AS agent_name
      FROM automations a
      LEFT JOIN local_agents ag ON ag.id = a.local_agent_id
      WHERE a.owner_user_id = ?
      ORDER BY a.updated_at DESC, a.id DESC
    `
  const rows = user.role === 'admin'
    ? await env.DB.prepare(sql).all()
    : await env.DB.prepare(sql).bind(user.id).all()
  return (rows.results || []).map((row) => ({
    ...row,
    id: Number(row.id),
    owner_user_id: Number(row.owner_user_id),
    local_agent_id: row.local_agent_id === null ? null : Number(row.local_agent_id),
    enabled: Number(row.enabled || 0),
    progress_percent: Number(row.progress_percent || 0)
  }))
}

async function getDashboardStats(env, user) {
  const filterSql = user.role === 'admin'
    ? ''
    : ' WHERE a.owner_user_id = ? '
  const bindArgs = user.role === 'admin' ? [] : [user.id]

  const [
    totalJobsRow,
    completedJobsRow,
    processingJobsRow,
    failedJobsRow,
    activeKeysRow,
    automationsRow,
    scheduledPostsRow,
    postedPostsRow
  ] = await Promise.all([
    env.DB.prepare(`
      SELECT COUNT(*) AS count
      FROM local_agent_jobs j
      JOIN automations a ON a.id = j.automation_id
      ${filterSql}
    `).bind(...bindArgs).first(),
    env.DB.prepare(`
      SELECT COUNT(*) AS count
      FROM local_agent_jobs j
      JOIN automations a ON a.id = j.automation_id
      ${filterSql}${filterSql ? " AND " : " WHERE "}j.status = 'completed'
    `).bind(...bindArgs).first(),
    env.DB.prepare(`
      SELECT COUNT(*) AS count
      FROM local_agent_jobs j
      JOIN automations a ON a.id = j.automation_id
      ${filterSql}${filterSql ? " AND " : " WHERE "}j.status IN ('queued', 'claimed', 'running')
    `).bind(...bindArgs).first(),
    env.DB.prepare(`
      SELECT COUNT(*) AS count
      FROM local_agent_jobs j
      JOIN automations a ON a.id = j.automation_id
      ${filterSql}${filterSql ? " AND " : " WHERE "}j.status IN ('error', 'cancelled')
    `).bind(...bindArgs).first(),
    env.DB.prepare(`SELECT COUNT(*) AS count FROM api_keys WHERE status = 'active'`).first(),
    env.DB.prepare(`
      SELECT COUNT(*) AS count
      FROM automations a
      ${user.role === 'admin' ? 'WHERE a.enabled = 1' : 'WHERE a.owner_user_id = ? AND a.enabled = 1'}
    `).bind(...bindArgs).first(),
    env.DB.prepare(`
      SELECT COUNT(*) AS count
      FROM scheduled_posts sp
      JOIN automations a ON a.id = sp.automation_id
      ${filterSql}${filterSql ? " AND " : " WHERE "}sp.status IN ('queued', 'scheduled', 'processing')
    `).bind(...bindArgs).first(),
    env.DB.prepare(`
      SELECT COUNT(*) AS count
      FROM output_files o
      JOIN automations a ON a.id = o.automation_id
      ${filterSql}
    `).bind(...bindArgs).first()
  ])

  return {
    totalJobs: Number(totalJobsRow?.count || 0),
    completedJobs: Number(completedJobsRow?.count || 0),
    processingJobs: Number(processingJobsRow?.count || 0),
    failedJobs: Number(failedJobsRow?.count || 0),
    activeKeys: Number(activeKeysRow?.count || 0),
    automations: Number(automationsRow?.count || 0),
    scheduledPosts: Number(scheduledPostsRow?.count || 0),
    postedPosts: Number(postedPostsRow?.count || 0)
  }
}

async function listRecentJobsForUser(env, user, limit = 10) {
  const sql = user.role === 'admin'
    ? `
      SELECT
        j.id,
        j.trigger_source,
        j.status,
        j.queued_at AS created_at,
        a.name,
        a.progress_percent,
        a.status AS automation_status
      FROM local_agent_jobs j
      JOIN automations a ON a.id = j.automation_id
      ORDER BY j.id DESC
      LIMIT ?
    `
    : `
      SELECT
        j.id,
        j.trigger_source,
        j.status,
        j.queued_at AS created_at,
        a.name,
        a.progress_percent,
        a.status AS automation_status
      FROM local_agent_jobs j
      JOIN automations a ON a.id = j.automation_id
      WHERE a.owner_user_id = ?
      ORDER BY j.id DESC
      LIMIT ?
    `
  const rows = user.role === 'admin'
    ? await env.DB.prepare(sql).bind(limit).all()
    : await env.DB.prepare(sql).bind(user.id, limit).all()
  return (rows.results || []).map((row) => ({
    ...row,
    id: Number(row.id),
    progress_percent: Number(row.progress_percent || 0),
    type: String(row.trigger_source || 'manual')
  }))
}

async function listScheduledPostsForUser(env, user, { automationId = null, limit = 100, activeOnly = true } = {}) {
  const statuses = activeOnly
    ? ['queued', 'scheduled', 'processing']
    : ['queued', 'scheduled', 'processing', 'completed', 'cancelled', 'failed']
  const placeholders = statuses.map(() => '?').join(', ')
  const bindArgs = [...statuses]
  let sql = `
    SELECT sp.*, a.name AS automation_name, a.owner_user_id
    FROM scheduled_posts sp
    JOIN automations a ON a.id = sp.automation_id
    WHERE sp.status IN (${placeholders})
  `

  if (user.role !== 'admin') {
    sql += ' AND a.owner_user_id = ?'
    bindArgs.push(user.id)
  }
  if (automationId) {
    sql += ' AND sp.automation_id = ?'
    bindArgs.push(automationId)
  }

  sql += `
    ORDER BY
      CASE WHEN sp.scheduled_at IS NULL THEN 1 ELSE 0 END,
      sp.scheduled_at ASC,
      sp.id ASC
    LIMIT ?
  `
  bindArgs.push(limit)

  const rows = await env.DB.prepare(sql).bind(...bindArgs).all()
  return (rows.results || []).map(normalizeScheduledPost)
}

async function countScheduledPostsByAutomation(env, user) {
  const sql = user.role === 'admin'
    ? `
      SELECT sp.automation_id, COUNT(*) AS total
      FROM scheduled_posts sp
      JOIN automations a ON a.id = sp.automation_id
      WHERE sp.status IN ('queued', 'scheduled', 'processing')
      GROUP BY sp.automation_id
    `
    : `
      SELECT sp.automation_id, COUNT(*) AS total
      FROM scheduled_posts sp
      JOIN automations a ON a.id = sp.automation_id
      WHERE a.owner_user_id = ?
        AND sp.status IN ('queued', 'scheduled', 'processing')
      GROUP BY sp.automation_id
    `
  const rows = user.role === 'admin'
    ? await env.DB.prepare(sql).all()
    : await env.DB.prepare(sql).bind(user.id).all()
  const map = new Map()
  for (const row of rows.results || []) {
    map.set(Number(row.automation_id), Number(row.total || 0))
  }
  return map
}

async function getOutputSummaryForUser(env, user) {
  const sql = user.role === 'admin'
    ? `
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(o.local_path, '') <> '' THEN 1 ELSE 0 END) AS path_count,
        SUM(CASE WHEN o.stored_in = 'metadata' THEN 1 ELSE 0 END) AS local_count
      FROM output_files o
      JOIN automations a ON a.id = o.automation_id
    `
    : `
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(o.local_path, '') <> '' THEN 1 ELSE 0 END) AS path_count,
        SUM(CASE WHEN o.stored_in = 'metadata' THEN 1 ELSE 0 END) AS local_count
      FROM output_files o
      JOIN automations a ON a.id = o.automation_id
      WHERE a.owner_user_id = ?
    `
  const row = user.role === 'admin'
    ? await env.DB.prepare(sql).first()
    : await env.DB.prepare(sql).bind(user.id).first()
  const outputFolder = await findLatestOutputDirectoryForUser(env, user)
  return {
    total: Number(row?.total || 0),
    localCount: Number(row?.local_count || 0),
    pathCount: Number(row?.path_count || 0),
    outputFolder
  }
}

async function listRecentOutputsForUser(env, user, limit) {
  const sql = user.role === 'admin'
    ? `
      SELECT o.*, a.owner_user_id, a.run_mode
      FROM output_files o
      JOIN automations a ON a.id = o.automation_id
      ORDER BY o.created_at DESC, o.id DESC
      LIMIT ?
    `
    : `
      SELECT o.*, a.owner_user_id, a.run_mode
      FROM output_files o
      JOIN automations a ON a.id = o.automation_id
      WHERE a.owner_user_id = ?
      ORDER BY o.created_at DESC, o.id DESC
      LIMIT ?
    `
  const rows = user.role === 'admin'
    ? await env.DB.prepare(sql).bind(limit).all()
    : await env.DB.prepare(sql).bind(user.id, limit).all()
  return (rows.results || []).map((row) => ({ ...row, id: Number(row.id) }))
}

function normalizeScheduledPost(row) {
  const accountIds = parseJsonMaybe(row.account_ids_json, [])
  return {
    ...row,
    id: Number(row.id),
    automation_id: Number(row.automation_id),
    job_id: row.job_id === null ? null : Number(row.job_id),
    account_ids: Array.isArray(accountIds) ? accountIds.map((item) => String(item)) : [],
    account_count: Array.isArray(accountIds) ? accountIds.length : 0
  }
}

async function getAutomationById(env, automationId) {
  const row = await env.DB.prepare('SELECT * FROM automations WHERE id = ? LIMIT 1').bind(automationId).first()
  return row ? normalizeAutomationRow(row) : null
}

function normalizeAutomationRow(row) {
  return {
    ...row,
    id: Number(row.id),
    owner_user_id: Number(row.owner_user_id),
    local_agent_id: row.local_agent_id === null ? null : Number(row.local_agent_id),
    enabled: Number(row.enabled || 0),
    progress_percent: Number(row.progress_percent || 0)
  }
}

function canAccessAutomation(user, automation) {
  return user.role === 'admin' || Number(automation.owner_user_id) === Number(user.id)
}

async function listAutomationLogs(env, automationId, limit = 50) {
  const rows = await env.DB.prepare(`
    SELECT id, automation_id, action, status, message, created_at
    FROM automation_logs
    WHERE automation_id = ?
    ORDER BY id DESC
    LIMIT ?
  `).bind(automationId, limit).all()
  return (rows.results || [])
    .map((row) => ({
      ...row,
      id: Number(row.id),
      automation_id: Number(row.automation_id)
    }))
    .reverse()
}

async function listOutputsForAutomation(env, automationId, limit = 12) {
  const rows = await env.DB.prepare(`
    SELECT id, automation_id, job_id, filename, stored_in, local_path, content_type, size_bytes, created_at
    FROM output_files
    WHERE automation_id = ?
    ORDER BY id DESC
    LIMIT ?
  `).bind(automationId, limit).all()
  return (rows.results || []).map((row) => ({
    ...row,
    id: Number(row.id),
    automation_id: Number(row.automation_id),
    job_id: Number(row.job_id)
  }))
}

async function getLatestJobForAutomation(env, automationId) {
  const row = await env.DB.prepare(`
    SELECT id, automation_id, agent_id, trigger_source, status, queued_at, claimed_at, started_at, completed_at, last_heartbeat_at, error_message
    FROM local_agent_jobs
    WHERE automation_id = ?
    ORDER BY id DESC
    LIMIT 1
  `).bind(automationId).first()
  return row ? {
    ...row,
    id: Number(row.id),
    automation_id: Number(row.automation_id),
    agent_id: Number(row.agent_id)
  } : null
}

async function findPendingJobForAutomation(env, automationId) {
  const row = await env.DB.prepare(`
    SELECT id, automation_id, agent_id, status
    FROM local_agent_jobs
    WHERE automation_id = ? AND status IN ('queued', 'claimed', 'running')
    ORDER BY id DESC
    LIMIT 1
  `).bind(automationId).first()
  return row ? {
    ...row,
    id: Number(row.id),
    automation_id: Number(row.automation_id),
    agent_id: Number(row.agent_id)
  } : null
}

async function queueAutomation(env, automation, triggerSource) {
  if (automation.run_mode === 'github_runner') {
    return { success: false, error: 'GitHub Runner dispatch is not implemented inside the Worker control plane yet.' }
  }
  if (!automation.local_agent_id) {
    return { success: false, error: 'No local agent is assigned to this automation.' }
  }
  const agent = await getAgentById(env, automation.local_agent_id)
  if (!agent || agent.status === 'disabled') {
    return { success: false, error: 'Assigned local agent is disabled or missing.' }
  }

  const pendingJob = await findPendingJobForAutomation(env, automation.id)
  if (pendingJob) {
    return {
      success: true,
      agentName: agent.display_name || `Agent #${agent.id}`,
      jobId: pendingJob.id,
      alreadyQueued: true
    }
  }

  const now = isoNow()
  const result = await env.DB.prepare(`
    INSERT INTO local_agent_jobs (agent_id, automation_id, trigger_source, status, queued_at)
    VALUES (?, ?, ?, 'queued', ?)
  `).bind(automation.local_agent_id, automation.id, triggerSource, now).run()

  const progressPayload = JSON.stringify({
    step: 'local_agent',
    status: 'info',
    message: `Queued for local agent ${agent.display_name || ('#' + agent.id)}`,
    progress: 5,
    stats: { fetched: 0, downloaded: 0, processed: 0, scheduled: 0, posted: 0 },
    job_id: Number(result.meta?.last_row_id || 0),
    time: now
  })

  await env.DB.batch([
    env.DB.prepare(`
      UPDATE automations
      SET status = 'queued', progress_percent = 5, progress_data = ?, last_progress_at = ?, updated_at = ?,
          next_run_at = CASE WHEN enabled = 1 THEN next_run_at ELSE NULL END
      WHERE id = ?
    `).bind(progressPayload, now, now, automation.id),
    env.DB.prepare(`
      INSERT INTO automation_logs (automation_id, action, status, message, created_at)
      VALUES (?, 'local_agent_queue', 'info', ?, ?)
    `).bind(automation.id, `Queued for local agent ${agent.display_name || ('#' + agent.id)} via ${triggerSource}`, now)
  ])

  return {
    success: true,
    agentName: agent.display_name || `Agent #${agent.id}`,
    jobId: Number(result.meta?.last_row_id || 0),
    alreadyQueued: false
  }
}

async function buildCompressedPayload(env, automation) {
  const automationJson = parseJsonMaybe(automation.automation_json, {})
  let apiKeyJson = automation.api_key_json ? parseJsonMaybe(automation.api_key_json, null) : null
  if (!apiKeyJson && Number(automationJson.api_key_id || 0) > 0) {
    apiKeyJson = await getApiKeyById(env, Number(automationJson.api_key_id || 0))
  }
  const settingsRows = await env.DB.prepare('SELECT setting_key, setting_value FROM settings').all()
  const settings = {}
  for (const row of settingsRows.results || []) {
    settings[String(row.setting_key)] = String(row.setting_value || '')
  }
  if (automation.settings_json) {
    Object.assign(settings, parseJsonMaybe(automation.settings_json, {}))
  }

  const payload = {
    automation: {
      id: automation.id,
      owner_user_id: automation.owner_user_id,
      local_agent_id: automation.local_agent_id,
      name: automation.name,
      run_mode: automation.run_mode,
      enabled: automation.enabled,
      ...automationJson
    },
    api_key: apiKeyJson,
    settings,
    snapshot_at: isoNow()
  }

  const zipped = await gzipString(JSON.stringify(payload))
  return arrayBufferToBase64(zipped)
}

async function authorizeJobClaim(env, jobId, claimToken, statuses) {
  if (!jobId || !claimToken) {
    return null
  }
  const placeholders = statuses.map(() => '?').join(', ')
  const row = await env.DB.prepare(`
    SELECT * FROM local_agent_jobs
    WHERE id = ? AND claim_token = ? AND status IN (${placeholders})
    LIMIT 1
  `).bind(jobId, claimToken, ...statuses).first()
  return row || null
}

async function applyAutomationProgress(env, automationId, payload, action) {
  const now = isoNow()
  const status = normalizeRemoteStatus(String(payload.status || payload.event_status || 'processing'))
  const automation = await getAutomationById(env, automationId)
  const progress = clampInt(payload.progress, 0, 100)
  const safePayload = {
    status,
    event_status: String(payload.event_status || payload.status || 'info'),
    step: String(payload.step || 'local_agent'),
    message: String(payload.message || ''),
    progress,
    stats: isPlainObject(payload.stats) ? payload.stats : {},
    outputs: Array.isArray(payload.outputs) ? payload.outputs.slice(0, 50) : [],
    time: String(payload.time || now)
  }
  const config = automation ? parseJsonMaybe(automation.automation_json, {}) : {}
  const nextRunAt = automation && automation.enabled && ['completed', 'error'].includes(status)
    ? calculateAutomationNextRunAt(
      String(config.schedule_type || 'daily'),
      toInt(config.schedule_hour) || 9,
      toInt(config.schedule_every_minutes) || 10
    )
    : automation?.next_run_at || null

  await env.DB.batch([
    env.DB.prepare(`
      UPDATE automations
      SET status = ?, progress_percent = ?, progress_data = ?, last_progress_at = ?, updated_at = ?,
          last_run_at = CASE WHEN ? IN ('completed', 'error') THEN ? ELSE last_run_at END,
          next_run_at = CASE WHEN ? IN ('completed', 'error') AND enabled = 1 THEN ? ELSE next_run_at END
      WHERE id = ?
    `).bind(status, progress, JSON.stringify(safePayload), now, now, status, now, status, nextRunAt, automationId),
    env.DB.prepare(`
      INSERT INTO automation_logs (automation_id, action, status, message, created_at)
      VALUES (?, ?, ?, ?, ?)
    `).bind(automationId, action, safePayload.event_status, safePayload.message, now)
  ])
}

async function touchAgentByJob(env, job, request) {
  const ipAddress = request.headers.get('CF-Connecting-IP') || request.headers.get('x-forwarded-for') || ''
  const now = isoNow()
  await env.DB.prepare(`
    UPDATE local_agents
    SET status = 'online', last_seen_at = ?, last_ip = ?, updated_at = ?
    WHERE id = ?
  `).bind(now, ipAddress, now, job.agent_id).run()
}

async function cancelPendingJobsForAutomation(env, automationId, reason) {
  const now = isoNow()
  await env.DB.prepare(`
    UPDATE local_agent_jobs
    SET status = 'cancelled', completed_at = ?, error_message = ?
    WHERE automation_id = ? AND status IN ('queued', 'claimed', 'running')
  `).bind(now, String(reason || 'Cancelled.'), automationId).run()
}

async function syncScheduledPostsFromJobResult(env, job, payload, completedAt) {
  const automation = await getAutomationById(env, Number(job.automation_id))
  if (!automation) {
    return
  }

  const config = parseJsonMaybe(automation.automation_json, {})
  const postformeEnabled = truthyValue(config.postforme_enabled, false)
  await env.DB.prepare('DELETE FROM scheduled_posts WHERE job_id = ?').bind(Number(job.id)).run()

  if (!postformeEnabled) {
    return
  }

  const stats = isPlainObject(payload.stats) ? payload.stats : {}
  const scheduledCount = Math.max(0, Number(stats.scheduled || 0))
  const postedCount = Math.max(0, Number(stats.posted || 0))
  const scheduleMode = String(config.postforme_schedule_mode || 'immediate').toLowerCase()
  const outputNames = Array.isArray(payload.outputs)
    ? payload.outputs.map((item) => sanitizeFileName(String(item || '').trim())).filter(Boolean)
    : []

  if (scheduledCount <= 0 && postedCount <= 0 && scheduleMode === 'immediate') {
    return
  }

  const accountIds = normalizeAccountIdList(config.postforme_account_ids || config.postforme_account_ids_csv || [])
  const rows = buildScheduledPostRows({
    automationId: Number(job.automation_id),
    jobId: Number(job.id),
    outputNames,
    scheduledCount,
    postedCount,
    scheduleMode,
    accountIds,
    config,
    completedAt
  })

  for (const row of rows) {
    await env.DB.prepare(`
      INSERT INTO scheduled_posts (
        automation_id, job_id, filename, caption, account_ids_json, remote_post_id,
        status, scheduled_at, published_at, error_message, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).bind(
      row.automation_id,
      row.job_id,
      row.filename,
      row.caption,
      row.account_ids_json,
      row.remote_post_id,
      row.status,
      row.scheduled_at,
      row.published_at,
      row.error_message,
      row.created_at,
      row.updated_at
    ).run()
  }
}

async function syncOutputFilesFromJobResult(env, job, payload, completedAt) {
  const items = extractOutputMetadataFromPayload(payload, completedAt)
  if (!items.length) {
    return
  }

  await env.DB.prepare('DELETE FROM output_files WHERE job_id = ?').bind(Number(job.id)).run()

  for (const item of items) {
    await env.DB.prepare(`
      INSERT INTO output_files (
        automation_id, job_id, filename, object_key, local_path, content_type, size_bytes, stored_in, created_at
      ) VALUES (?, ?, ?, NULL, ?, ?, ?, 'metadata', ?)
    `).bind(
      Number(job.automation_id),
      Number(job.id),
      item.filename,
      item.local_path,
      item.content_type,
      item.size_bytes,
      item.created_at
    ).run()
  }
}

function extractOutputMetadataFromPayload(payload, fallbackCreatedAt) {
  const detailed = Array.isArray(payload.local_output_files) ? payload.local_output_files : []
  const fallbackNames = Array.isArray(payload.outputs) ? payload.outputs : []
  const normalized = []
  const seen = new Set()

  for (const rawItem of detailed) {
    const item = normalizeOutputMetadataItem(rawItem, fallbackCreatedAt)
    if (!item) {
      continue
    }
    const key = item.filename.toLowerCase()
    if (seen.has(key)) {
      continue
    }
    seen.add(key)
    normalized.push(item)
  }

  for (const rawName of fallbackNames) {
    const filename = sanitizeFileName(String(rawName || '').trim())
    if (!filename) {
      continue
    }
    const key = filename.toLowerCase()
    if (seen.has(key)) {
      continue
    }
    seen.add(key)
    normalized.push({
      filename,
      local_path: null,
      content_type: guessOutputContentType(filename),
      size_bytes: 0,
      created_at: fallbackCreatedAt
    })
  }

  return normalized
}

function normalizeOutputMetadataItem(rawItem, fallbackCreatedAt) {
  if (!rawItem) {
    return null
  }

  if (typeof rawItem === 'string') {
    const filename = sanitizeFileName(String(rawItem).trim())
    if (!filename) {
      return null
    }
    return {
      filename,
      local_path: null,
      content_type: guessOutputContentType(filename),
      size_bytes: 0,
      created_at: fallbackCreatedAt
    }
  }

  if (!isPlainObject(rawItem)) {
    return null
  }

  const filename = sanitizeFileName(String(rawItem.filename || rawItem.name || '').trim())
  if (!filename) {
    return null
  }

  const localPath = sanitizeLocalFilePath(String(rawItem.local_path || rawItem.path || '').trim()) || null
  const contentType = String(rawItem.content_type || '').trim() || guessOutputContentType(filename)
  const sizeBytes = Math.max(0, Number(rawItem.size_bytes || rawItem.size || 0) || 0)
  const createdAt = normalizeIsoDateMaybe(rawItem.created_at || rawItem.modified_at || rawItem.updated_at) || fallbackCreatedAt

  return {
    filename,
    local_path: localPath,
    content_type: contentType,
    size_bytes: sizeBytes,
    created_at: createdAt
  }
}

function getOutputStorageLabel(output) {
  if (String(output.local_path || '').trim() !== '') {
    return 'local only'
  }
  if (String(output.stored_in || '').toLowerCase() === 'r2') {
    return 'legacy remote'
  }
  return 'metadata only'
}

function guessOutputContentType(filename) {
  const lower = String(filename || '').toLowerCase()
  if (lower.endsWith('.mp4') || lower.endsWith('.m4v')) {
    return 'video/mp4'
  }
  if (lower.endsWith('.mov')) {
    return 'video/quicktime'
  }
  if (lower.endsWith('.webm')) {
    return 'video/webm'
  }
  if (lower.endsWith('.avi')) {
    return 'video/x-msvideo'
  }
  if (lower.endsWith('.mkv')) {
    return 'video/x-matroska'
  }
  return 'application/octet-stream'
}

function sanitizeLocalFilePath(value) {
  return String(value || '').replace(/[\r\n\t]+/g, ' ').trim()
}

function getDefaultLocalOutputDirectory(env) {
  const base = String(env?.DEFAULT_WORKER_BASE_DIR || 'C:/VideoWorkflowAgentData').replace(/[\\/]+$/, '')
  return base ? `${base}/output` : 'Paired PC local output folder'
}

function getDirectoryNameFromPath(filePath) {
  const value = String(filePath || '').trim()
  if (!value) {
    return ''
  }
  const normalized = value.replace(/[\\/]+/g, '/')
  const index = normalized.lastIndexOf('/')
  return index > 0 ? normalized.slice(0, index) : normalized
}

function deriveOutputDirectoryHint(outputs, env) {
  for (const output of outputs || []) {
    const dir = getDirectoryNameFromPath(output.local_path || '')
    if (dir) {
      return dir
    }
  }
  return getDefaultLocalOutputDirectory(env)
}

function normalizeIsoDateMaybe(value) {
  const text = String(value || '').trim()
  if (!text) {
    return ''
  }
  const parsed = new Date(text)
  if (Number.isNaN(parsed.getTime())) {
    return ''
  }
  return parsed.toISOString()
}

async function findLatestOutputDirectoryForUser(env, user) {
  const sql = user.role === 'admin'
    ? `
      SELECT local_path
      FROM output_files
      WHERE COALESCE(local_path, '') <> ''
      ORDER BY id DESC
      LIMIT 1
    `
    : `
      SELECT o.local_path
      FROM output_files o
      JOIN automations a ON a.id = o.automation_id
      WHERE a.owner_user_id = ? AND COALESCE(o.local_path, '') <> ''
      ORDER BY o.id DESC
      LIMIT 1
    `
  const row = user.role === 'admin'
    ? await env.DB.prepare(sql).first()
    : await env.DB.prepare(sql).bind(user.id).first()
  return deriveOutputDirectoryHint(row ? [row] : [], env)
}

function buildScheduledPostRows({ automationId, jobId, outputNames, scheduledCount, postedCount, scheduleMode, accountIds, config, completedAt }) {
  const rows = []
  const now = completedAt || isoNow()
  const safeOutputs = outputNames.length ? outputNames : [`job_${jobId}.mp4`]

  if (scheduleMode !== 'immediate' || scheduledCount > 0) {
    const total = Math.max(safeOutputs.length, scheduledCount || 0, 1)
    for (let index = 0; index < total; index += 1) {
      const filename = safeOutputs[index] || `scheduled_${jobId}_${index + 1}.mp4`
      rows.push({
        automation_id: automationId,
        job_id: jobId,
        filename,
        caption: buildCaptionFromFilename(filename),
        account_ids_json: JSON.stringify(accountIds),
        remote_post_id: null,
        status: 'scheduled',
        scheduled_at: computeScheduledPostDate(config, index, now),
        published_at: null,
        error_message: null,
        created_at: now,
        updated_at: now
      })
    }
    return rows
  }

  if (postedCount > 0) {
    const total = Math.max(safeOutputs.length, postedCount, 1)
    for (let index = 0; index < total; index += 1) {
      const filename = safeOutputs[index] || `posted_${jobId}_${index + 1}.mp4`
      rows.push({
        automation_id: automationId,
        job_id: jobId,
        filename,
        caption: buildCaptionFromFilename(filename),
        account_ids_json: JSON.stringify(accountIds),
        remote_post_id: null,
        status: 'completed',
        scheduled_at: null,
        published_at: now,
        error_message: null,
        created_at: now,
        updated_at: now
      })
    }
  }

  return rows
}

function computeScheduledPostDate(config, index, completedAt) {
  const mode = String(config.postforme_schedule_mode || 'immediate').toLowerCase()
  const spreadMinutes = Math.max(0, toInt(config.postforme_schedule_spread_minutes) || 0)
  const baseDate = new Date(completedAt || isoNow())

  if (mode === 'scheduled') {
    const raw = String(config.postforme_schedule_datetime || '').trim()
    if (raw !== '') {
      const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T')
      const parsed = new Date(normalized)
      if (!Number.isNaN(parsed.getTime())) {
        parsed.setMinutes(parsed.getMinutes() + (spreadMinutes * index))
        return parsed.toISOString()
      }
      return raw
    }
  }

  if (mode === 'offset') {
    const offsetMinutes = Math.max(0, toInt(config.postforme_schedule_offset_minutes) || 0)
    baseDate.setMinutes(baseDate.getMinutes() + offsetMinutes + (spreadMinutes * index))
    return baseDate.toISOString()
  }

  if (mode !== 'immediate') {
    baseDate.setMinutes(baseDate.getMinutes() + (spreadMinutes * index))
    return baseDate.toISOString()
  }

  return null
}

function normalizeAccountIdList(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item).trim()).filter(Boolean)
  }
  if (typeof value === 'string') {
    return value.split(',').map((item) => item.trim()).filter(Boolean)
  }
  return []
}

function buildCaptionFromFilename(filename) {
  return String(filename || '')
    .replace(/\.[^.]+$/, '')
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function resolveSavedAutomationStatus(existingStatus, enabled) {
  if (!enabled) {
    return 'inactive'
  }
  const normalized = String(existingStatus || '').toLowerCase()
  if (['queued', 'processing', 'running'].includes(normalized)) {
    return normalized
  }
  return 'running'
}

function calculateAutomationNextRunAt(scheduleType, scheduleHour, scheduleEveryMinutes) {
  const nextRun = new Date()
  const type = String(scheduleType || 'daily').toLowerCase()
  const hour = clampInt(scheduleHour, 0, 23)
  const everyMinutes = Math.max(1, toInt(scheduleEveryMinutes) || 10)

  if (type === 'minutes') {
    nextRun.setMinutes(nextRun.getMinutes() + everyMinutes, 0, 0)
    return nextRun.toISOString()
  }

  if (type === 'hourly') {
    nextRun.setHours(nextRun.getHours() + 1, 0, 0, 0)
    return nextRun.toISOString()
  }

  if (type === 'weekly') {
    const day = nextRun.getDay()
    const offset = day === 1 ? 7 : ((8 - day) % 7 || 7)
    nextRun.setDate(nextRun.getDate() + offset)
    nextRun.setHours(hour, 0, 0, 0)
    return nextRun.toISOString()
  }

  nextRun.setHours(hour, 0, 0, 0)
  if (nextRun <= new Date()) {
    nextRun.setDate(nextRun.getDate() + 1)
  }
  return nextRun.toISOString()
}

function formatDisplayDateTime(value) {
  const raw = String(value || '').trim()
  if (raw === '') {
    return 'Unknown'
  }
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) {
    return raw
  }
  return date.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  })
}

function formatBytes(bytes) {
  const value = Number(bytes || 0)
  if (!Number.isFinite(value) || value <= 0) {
    return '-'
  }
  if (value >= 1073741824) return `${Math.round((value / 1073741824) * 100) / 100} GB`
  if (value >= 1048576) return `${Math.round((value / 1048576) * 100) / 100} MB`
  if (value >= 1024) return `${Math.round((value / 1024) * 100) / 100} KB`
  return `${Math.round(value)} bytes`
}

function automationStatusClass(status) {
  switch (String(status || '').toLowerCase()) {
    case 'running':
    case 'completed':
    case 'success':
      return 'status-success'
    case 'queued':
    case 'processing':
    case 'claimed':
      return 'status-queued'
    case 'warning':
    case 'stopped':
      return 'status-warning'
    case 'error':
    case 'failed':
    case 'cancelled':
      return 'status-error'
    default:
      return 'status-neutral'
  }
}

async function getSetting(env, key) {
  const row = await env.DB.prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1').bind(key).first()
  return row ? String(row.setting_value || '') : ''
}

async function setSetting(env, key, value) {
  await env.DB.prepare(`
    INSERT INTO settings (setting_key, setting_value, updated_at)
    VALUES (?, ?, ?)
    ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at
  `).bind(key, String(value || ''), isoNow()).run()
}

async function getSettingsMap(env) {
  const rows = await env.DB.prepare('SELECT setting_key, setting_value FROM settings').all()
  const settings = {}
  for (const row of rows.results || []) {
    settings[String(row.setting_key)] = String(row.setting_value || '')
  }
  return settings
}

async function getPairingToken(env) {
  return await getSetting(env, 'local_agent_pairing_token')
}

async function ensureUniqueClientSlug(env, value, excludeUserId) {
  let slug = slugify(value || 'client')
  let suffix = 1
  while (true) {
    const candidate = suffix === 1 ? slug : `${slug}-${suffix}`
    const row = excludeUserId
      ? await env.DB.prepare('SELECT id FROM app_users WHERE client_slug = ? AND id <> ? LIMIT 1').bind(candidate, excludeUserId).first()
      : await env.DB.prepare('SELECT id FROM app_users WHERE client_slug = ? LIMIT 1').bind(candidate).first()
    if (!row) {
      return candidate
    }
    suffix += 1
  }
}

async function hashPassword(password) {
  const salt = crypto.getRandomValues(new Uint8Array(16))
  const iterations = normalizePasswordIterations(passwordIterations)
  const key = await derivePasswordKey(password, salt, iterations)
  return `pbkdf2$${iterations}$${arrayBufferToBase64(salt)}$${arrayBufferToBase64(key)}`
}

async function verifyPassword(password, storedHash) {
  const parts = String(storedHash || '').split('$')
  if (parts.length !== 4 || parts[0] !== 'pbkdf2') {
    return false
  }
  const requestedIterations = Number(parts[1]) || passwordIterations
  const iterations = normalizePasswordIterations(requestedIterations)
  if (iterations !== requestedIterations) {
    return false
  }
  const salt = base64ToUint8Array(parts[2])
  const expected = parts[3]
  const key = await derivePasswordKey(password, salt, iterations)
  return timingSafeEqual(arrayBufferToBase64(key), expected)
}

async function derivePasswordKey(password, salt, iterations) {
  const keyMaterial = await crypto.subtle.importKey('raw', textEncoder.encode(password), { name: 'PBKDF2' }, false, ['deriveBits'])
  return await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, keyMaterial, 256)
}

function normalizePasswordIterations(value) {
  const iterations = Number(value)
  if (!Number.isFinite(iterations) || iterations < 1) {
    return passwordIterations
  }
  return Math.min(Math.floor(iterations), maxPasswordIterations)
}

async function sha256Hex(value) {
  const digest = await crypto.subtle.digest('SHA-256', textEncoder.encode(String(value || '')))
  const bytes = new Uint8Array(digest)
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

async function hmacHex(secret, value) {
  const key = await crypto.subtle.importKey('raw', textEncoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  const signature = await crypto.subtle.sign('HMAC', key, textEncoder.encode(value))
  const bytes = new Uint8Array(signature)
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

function jsonResponse(payload, status = 200, headers = {}) {
  return new Response(JSON.stringify(payload), { status, headers: { 'Content-Type': 'application/json; charset=utf-8', ...headers } })
}

function htmlResponse(html, status = 200, headers = {}) {
  return new Response(html, { status, headers: { 'Content-Type': 'text/html; charset=utf-8', ...headers } })
}

function textResponse(text, status = 200, headers = {}) {
  return new Response(text, { status, headers })
}

function redirectResponse(location, status = 303, headers = {}) {
  return new Response(null, { status, headers: { Location: location, ...headers } })
}

async function readJsonBody(request) {
  const contentType = request.headers.get('content-type') || ''
  if (contentType.includes('application/json')) {
    try {
      return await request.json()
    } catch {
      return {}
    }
  }
  const form = await request.formData()
  const payload = {}
  for (const [key, value] of form.entries()) {
    payload[key] = value
  }
  return payload
}

function parseCookies(header) {
  const cookies = {}
  for (const part of header.split(';')) {
    const [key, ...rest] = part.split('=')
    if (!key) {
      continue
    }
    cookies[key.trim()] = rest.join('=').trim()
  }
  return cookies
}

function resolveWorkerPath(pathname) {
  return legacyRouteAliases.get(pathname) || pathname
}

function normalizePath(pathname) {
  if (!pathname || pathname === '/') {
    return '/'
  }
  return pathname.replace(/\/+$/, '') || '/'
}

function legacyPageHref(path, query = null) {
  const canonical = resolveWorkerPath(normalizePath(path))
  const alias = {
    '/dashboard': '/index.php',
    '/automation': '/automation.php',
    '/settings': '/settings.php',
    '/api-keys': '/api-keys.php',
    '/admin/users': '/users.php',
    '/admin/agents': '/agents.php',
    '/player': '/player.php',
    '/jobs': '/jobs.php',
    '/login': '/login.php',
    '/magic-login': '/magic-login.php'
  }[canonical] || canonical
  if (!query || (typeof query === 'object' && !Object.keys(query).length)) {
    return alias
  }
  const url = new URL('https://worker.local' + alias)
  if (typeof query === 'string') {
    const queryString = query.startsWith('?') ? query.slice(1) : query
    for (const [key, value] of new URLSearchParams(queryString)) {
      url.searchParams.set(key, value)
    }
  } else {
    for (const [key, value] of Object.entries(query)) {
      if (value !== null && value !== undefined && String(value) !== '') {
        url.searchParams.set(key, String(value))
      }
    }
  }
  return url.pathname + url.search
}

function normalizeEmail(value) {
  return String(value || '').trim().toLowerCase()
}

function sanitizeRedirectPath(value) {
  const path = String(value || '/dashboard').trim()
  if (!path.startsWith('/') || path.startsWith('//')) {
    return '/dashboard'
  }
  return path
}

function sanitizeRunMode(value) {
  return value === 'github_runner' ? 'github_runner' : 'local'
}

function sanitizeAgentStatus(value) {
  return ['online', 'offline', 'disabled'].includes(value) ? value : 'offline'
}

function toUnixTimestamp(value) {
  const raw = String(value || '').trim()
  if (raw === '') {
    return 0
  }
  const date = new Date(raw)
  return Number.isNaN(date.getTime()) ? 0 : Math.floor(date.getTime() / 1000)
}

function formatTimeAgo(value) {
  const unix = typeof value === 'number' ? value : toUnixTimestamp(value)
  if (!unix) {
    return 'unknown'
  }
  const diff = Math.max(0, Math.floor(Date.now() / 1000) - unix)
  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)} min ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)} hours ago`
  return `${Math.floor(diff / 86400)} days ago`
}

function defaultAutomationMessage(status) {
  switch (String(status || '').toLowerCase()) {
    case 'queued':
      return 'Automation is queued.'
    case 'running':
    case 'processing':
    case 'claimed':
      return 'Automation is processing.'
    case 'completed':
      return 'Automation completed.'
    case 'error':
    case 'failed':
      return 'Automation failed.'
    case 'stopped':
      return 'Automation stopped.'
    default:
      return 'Waiting for next run.'
  }
}

function normalizeRemoteStatus(value) {
  if (['completed', 'error', 'processing', 'running', 'queued', 'paused', 'inactive'].includes(value)) {
    return value
  }
  if (value === 'success') {
    return 'completed'
  }
  return 'processing'
}

function sanitizeFileName(value) {
  const cleaned = String(value || '').replace(/[^A-Za-z0-9._-]/g, '_')
  return cleaned || 'output.mp4'
}

function shouldUseSecureCookies(request) {
  const url = new URL(request.url)
  return url.protocol === 'https:'
}

function requireAdmin(user) {
  if (!user || user.role !== 'admin') {
    throw new Error('Admin access is required.')
  }
}

function checkboxValue(value) {
  return value !== null && value !== undefined && String(value) !== ''
}

function toInt(value) {
  const parsed = Number.parseInt(String(value || '0'), 10)
  return Number.isFinite(parsed) ? parsed : 0
}

function toNullableInt(value) {
  const parsed = toInt(value)
  return parsed > 0 ? parsed : null
}

function clampInt(value, min, max) {
  const parsed = toInt(value)
  return Math.min(max, Math.max(min, parsed))
}

function slugify(value) {
  const normalized = String(value || 'client').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
  return normalized || 'client'
}

function prettyJson(value) {
  return JSON.stringify(value, null, 2)
}

function parseJsonMaybe(text, fallback) {
  try {
    return JSON.parse(String(text || ''))
  } catch {
    return fallback
  }
}

function parseJsonObject(text, label) {
  let parsed
  try {
    parsed = JSON.parse(String(text || '{}'))
  } catch {
    throw new Error(`${label} is not valid JSON.`)
  }
  if (!isPlainObject(parsed)) {
    throw new Error(`${label} must be a JSON object.`)
  }
  return parsed
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function isoNow() {
  return new Date().toISOString()
}

function randomToken(byteLength) {
  return base64UrlFromBytes(crypto.getRandomValues(new Uint8Array(byteLength)))
}

function randomHex(byteLength) {
  return [...crypto.getRandomValues(new Uint8Array(byteLength))].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

function base64UrlEncode(text) {
  return base64UrlFromBytes(textEncoder.encode(text))
}

function base64UrlDecode(value) {
  const normalized = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4)
  return atob(padded)
}

function base64UrlFromBytes(bytes) {
  return arrayBufferToBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

function arrayBufferToBase64(value) {
  const bytes = value instanceof Uint8Array ? value : new Uint8Array(value)
  let binary = ''
  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }
  return btoa(binary)
}

function base64ToUint8Array(value) {
  const binary = atob(value)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index)
  }
  return bytes
}

async function gzipString(value) {
  const stream = new CompressionStream('gzip')
  const writer = stream.writable.getWriter()
  writer.write(textEncoder.encode(value))
  writer.close()
  return await new Response(stream.readable).arrayBuffer()
}

function timingSafeEqual(a, b) {
  const left = String(a || '')
  const right = String(b || '')
  if (left.length !== right.length) {
    return false
  }
  let mismatch = 0
  for (let index = 0; index < left.length; index += 1) {
    mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index)
  }
  return mismatch === 0
}
