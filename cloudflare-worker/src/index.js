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

export default {
  async fetch(request, env) {
    try {
      const url = new URL(request.url)
      const path = normalizePath(url.pathname)

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
          return redirectResponse('/dashboard')
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

      if (path === '/api/automation-status' && request.method === 'GET') {
        if (!session.user) {
          return jsonResponse({ success: false, error: 'Authentication required.' }, 401)
        }
        return handleAutomationStatusApi(request, env, session.user)
      }

      if (path === '/' && !session.user) {
        return redirectResponse('/login')
      }

      if (path === '/') {
        return redirectResponse('/dashboard')
      }

      if (!session.user) {
        return redirectResponse('/login?next=' + encodeURIComponent(path))
      }

      if (path === '/dashboard' && request.method === 'GET') {
        return renderDashboardPage(request, env, session.user, null)
      }

      if (path === '/dashboard' && request.method === 'POST') {
        return handleAutomationAction(request, env, session.user)
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

  return redirectResponse(nextPath, 303, {
    'Set-Cookie': buildSessionCookie(token, shouldUseSecureCookies(request))
  })
}

async function handleUsersAction(request, env, adminUser) {
  const form = await request.formData()
  const action = String(form.get('action') || '')

  if (action === 'create_user') {
    const email = normalizeEmail(form.get('email'))
    const password = String(form.get('password') || '')
    const displayName = String(form.get('display_name') || '').trim()
    const role = String(form.get('role') || 'user') === 'admin' ? 'admin' : 'user'
    const status = String(form.get('status') || 'active') === 'disabled' ? 'disabled' : 'active'
    const canUseGithubRunner = checkboxValue(form.get('can_use_github_runner'))
    const assignedLocalAgentId = toInt(form.get('assigned_local_agent_id'))
    const requestedSlug = String(form.get('client_slug') || '').trim()

    if (email === '' || password === '') {
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

    return renderUsersPage(request, env, adminUser, { success: `User #${userId} created. Share the email and password with the client.` })
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

  return renderAgentsPage(request, env, adminUser, { error: 'Unknown agents action.' })
}

async function handleSettingsAction(request, env, adminUser) {
  const form = await request.formData()
  const action = String(form.get('action') || 'save_settings')
  const tab = sanitizeSettingsTab(String(form.get('tab') || 'bunny'))

  if (action !== 'save_settings') {
    return renderSettingsPage(appendQueryToRequest(request, { tab }), env, adminUser, { error: 'Unknown settings action.' })
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
  let storedIn = 'metadata'
  let objectKey = null

  if (env.OUTPUTS && typeof env.OUTPUTS.put === 'function') {
    objectKey = `${job.automation_id}/${jobId}/${safeName}`
    await env.OUTPUTS.put(objectKey, file.stream(), {
      httpMetadata: {
        contentType: file.type || 'application/octet-stream'
      }
    })
    storedIn = 'r2'
  }

  await env.DB.prepare(`
    INSERT INTO output_files (
      automation_id, job_id, filename, object_key, content_type, size_bytes, stored_in, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).bind(
    job.automation_id,
    jobId,
    safeName,
    objectKey,
    file.type || 'application/octet-stream',
    Number(file.size || 0),
    storedIn,
    createdAt
  ).run()

  return {
    success: true,
    filename: safeName,
    stored_in: storedIn
  }
}

async function renderDashboardPage(request, env, user, feedback) {
  const automations = await listAutomationsForUser(env, user)
  const agents = await listVisibleAgents(env, user)
  const outputs = await listRecentOutputsForUser(env, user, 24)
  const body = renderDashboardBody({
    user,
    automations,
    agents,
    outputs,
    feedback
  })
  return htmlResponse(renderPage({
    title: 'Dashboard',
    user,
    body,
    currentPath: '/dashboard'
  }))
}

async function renderAutomationPage(request, env, user, feedback) {
  const url = new URL(request.url)
  const automations = await listAutomationsForUser(env, user)
  const agents = await listVisibleAgents(env, user)
  const apiKeys = await listApiKeys(env)
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
  return htmlResponse(renderPage({
    title: 'Agents',
    user: adminUser,
    body: renderAgentsBody({
      agents,
      pairingToken,
      feedback,
      installScriptUrl: `${new URL(request.url).origin}/install/windows.ps1?pairing_token=${encodeURIComponent(pairingToken)}`,
      installManifest: manifest
    }),
    currentPath: '/admin/agents'
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

  if (output.stored_in === 'r2' && output.object_key && env.OUTPUTS && typeof env.OUTPUTS.get === 'function') {
    const object = await env.OUTPUTS.get(output.object_key)
    if (!object) {
      return htmlResponse(renderPage({
        title: 'Output',
        user,
        body: `<section class="panel"><h1>Missing</h1><p class="muted">The stored file is no longer available.</p></section>`
      }), 404)
    }
    const headers = new Headers()
    object.writeHttpMetadata(headers)
    headers.set('Content-Disposition', `inline; filename="${sanitizeFileName(output.filename)}"`)
    return new Response(object.body, { headers })
  }

  return htmlResponse(renderPage({
    title: 'Output',
    user,
    body: `<section class="panel"><h1>Metadata Only</h1><p class="muted">This output was reported by the agent but no object storage is configured for downloads.</p></section>`
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
      download_url: output.stored_in === 'r2' ? `/outputs/${output.id}` : null
    })),
    job
  })
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

function renderDashboardBody({ user, automations, agents, outputs, feedback }) {
  const visibleAgentOptions = agents.map((agent) => `
    <option value="${agent.id}">${escapeHtml(agent.display_name || `Agent #${agent.id}`)}${agent.status === 'disabled' ? ' (disabled)' : ''}</option>
  `).join('')

  const cards = automations.map((automation) => {
    const jsonBody = prettyJson(parseJsonMaybe(automation.automation_json, {}))
    const apiKeyBody = automation.api_key_json ? prettyJson(parseJsonMaybe(automation.api_key_json, {})) : ''
    const settingsBody = automation.settings_json ? prettyJson(parseJsonMaybe(automation.settings_json, {})) : ''
    const progress = Number(automation.progress_percent || 0)
    return `
      <article class="panel automation-card">
        <div class="card-head">
          <div>
            <h2>${escapeHtml(automation.name)}</h2>
            <p class="muted compact">Status: ${escapeHtml(automation.status)} | Run mode: ${escapeHtml(automation.run_mode)} | Agent: ${escapeHtml(automation.agent_name || '-')}</p>
          </div>
          <div class="badge">${progress}%</div>
        </div>
        <div class="progress-bar"><span style="width:${progress}%"></span></div>
        <form method="POST" action="/dashboard" class="stack compact-form">
          <input type="hidden" name="action" value="save_automation">
          <input type="hidden" name="automation_id" value="${automation.id}">
          <label class="field"><span>Name</span><input type="text" name="name" value="${escapeHtml(automation.name)}" required></label>
          <div class="grid two">
            <label class="field">
              <span>Run Mode</span>
              <select name="run_mode">
                <option value="local"${automation.run_mode === 'local' ? ' selected' : ''}>Local</option>
                <option value="github_runner"${automation.run_mode === 'github_runner' ? ' selected' : ''}>GitHub Runner</option>
              </select>
            </label>
            <label class="field">
              <span>Local Agent</span>
              <select name="local_agent_id">
                <option value="">Unassigned</option>
                ${agents.map((agent) => `<option value="${agent.id}"${Number(automation.local_agent_id || 0) === Number(agent.id) ? ' selected' : ''}>${escapeHtml(agent.display_name || `Agent #${agent.id}`)}</option>`).join('')}
              </select>
            </label>
          </div>
          <label class="toggle"><input type="checkbox" name="enabled"${automation.enabled ? ' checked' : ''}> <span>Enabled</span></label>
          <label class="field"><span>Automation JSON</span><textarea name="automation_json" rows="12">${escapeHtml(jsonBody)}</textarea></label>
          <label class="field"><span>API Key JSON</span><textarea name="api_key_json" rows="6">${escapeHtml(apiKeyBody)}</textarea></label>
          <label class="field"><span>Settings JSON</span><textarea name="settings_json" rows="6">${escapeHtml(settingsBody)}</textarea></label>
          <div class="toolbar">
            <button type="submit" class="button">Save</button>
          </div>
        </form>
        <div class="toolbar">
          <form method="POST" action="/dashboard">
            <input type="hidden" name="action" value="queue_automation">
            <input type="hidden" name="automation_id" value="${automation.id}">
            <button type="submit" class="button primary">Queue Now</button>
          </form>
          <form method="POST" action="/dashboard">
            <input type="hidden" name="action" value="delete_automation">
            <input type="hidden" name="automation_id" value="${automation.id}">
            <button type="submit" class="button ghost">Delete</button>
          </form>
        </div>
      </article>
    `
  }).join('')

  const outputRows = outputs.map((output) => `
    <tr>
      <td>${escapeHtml(output.filename)}</td>
      <td>${escapeHtml(output.stored_in)}</td>
      <td>${escapeHtml(output.created_at)}</td>
      <td>${output.stored_in === 'r2' ? `<a class="inline-link" href="/outputs/${output.id}">Open</a>` : '<span class="muted">Metadata only</span>'}</td>
    </tr>
  `).join('')

  return `
    ${renderFeedback(feedback)}
    <section class="dashboard-grid">
      <section class="panel span-two">
        <div class="section-head">
          <div>
            <div class="eyebrow">${escapeHtml(user.role)}</div>
            <h1>${escapeHtml(user.display_name || user.email)}</h1>
          </div>
          <div class="meta-actions">
            ${user.role === 'admin' ? `<a class="button" href="/admin/users">Users</a><a class="button" href="/admin/agents">Agents</a>` : ''}
            <form method="POST" action="/logout"><button type="submit" class="button ghost">Logout</button></form>
          </div>
        </div>
        <div class="stats-row">
          <div class="metric"><span>${automations.length}</span><small>Automations</small></div>
          <div class="metric"><span>${agents.length}</span><small>Visible agents</small></div>
          <div class="metric"><span>${outputs.length}</span><small>Recent outputs</small></div>
        </div>
      </section>

      <section class="panel">
        <div class="section-head">
          <div>
            <div class="eyebrow">Create New</div>
            <h2>Automation</h2>
          </div>
        </div>
        <form method="POST" action="/dashboard" class="stack">
          <input type="hidden" name="action" value="save_automation">
          <label class="field"><span>Name</span><input type="text" name="name" placeholder="Client local workflow" required></label>
          <div class="grid two">
            <label class="field">
              <span>Run Mode</span>
              <select name="run_mode">
                <option value="local">Local</option>
                <option value="github_runner"${user.can_use_github_runner || user.role === 'admin' ? '' : ' disabled'}>GitHub Runner</option>
              </select>
            </label>
            <label class="field">
              <span>Local Agent</span>
              <select name="local_agent_id">
                <option value="">Unassigned</option>
                ${visibleAgentOptions}
              </select>
            </label>
          </div>
          <label class="toggle"><input type="checkbox" name="enabled" checked> <span>Enabled</span></label>
          <label class="field"><span>Automation JSON</span><textarea name="automation_json" rows="10">{}</textarea></label>
          <label class="field"><span>API Key JSON</span><textarea name="api_key_json" rows="5"></textarea></label>
          <label class="field"><span>Settings JSON</span><textarea name="settings_json" rows="5"></textarea></label>
          <button type="submit" class="button primary">Save Automation</button>
        </form>
      </section>

      <section class="panel span-two">
        <div class="section-head">
          <div>
            <div class="eyebrow">Queue + Status</div>
            <h2>Automations</h2>
          </div>
        </div>
        ${cards || '<p class="muted">No automations created yet.</p>'}
      </section>

      <section class="panel span-two">
        <div class="section-head">
          <div>
            <div class="eyebrow">Output Feed</div>
            <h2>Recent Output Reports</h2>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Filename</th><th>Storage</th><th>Created</th><th>Access</th></tr></thead>
            <tbody>${outputRows || '<tr><td colspan="4" class="muted">No output has been uploaded yet.</td></tr>'}</tbody>
          </table>
        </div>
      </section>
    </section>
  `
}

function renderAutomationBody({ user, automations, agents, apiKeys, feedback, editor, editorOpen, logAutomation }) {
  const cards = automations.map((automation) => {
    const config = parseJsonMaybe(automation.automation_json, {})
    const progressState = parseJsonMaybe(automation.progress_data, {})
    const source = String(config.video_source || 'ftp')
    const schedule = String(config.schedule_type || 'daily')
    const videosPerRun = config.videos_per_run ?? '-'
    const progressPercent = clampInt(progressState.progress ?? automation.progress_percent ?? 0, 0, 100)
    const progressMessage = String(progressState.message || (automation.enabled ? 'Waiting for next run.' : 'Automation disabled.'))
    const nextRunLabel = automation.next_run_at ? formatDisplayDateTime(automation.next_run_at) : 'Not scheduled'
    const selectionLabel = config.video_selection_method === 'date_range' && (config.video_start_date || config.video_end_date)
      ? `${String(config.video_start_date || '-')} to ${String(config.video_end_date || '-')}`
      : `Last ${String(config.video_days_filter || 30)} days`
    const shortsLabel = String(config.source_shorts_mode || 'single') === 'single'
      ? '1 short/source'
      : `up to ${String(config.source_shorts_max_count || 1)} shorts/source`
    const statusClass = automationStatusClass(automation.status)
    const isRunning = ['queued', 'processing', 'running'].includes(String(automation.status || '').toLowerCase())
    return `
      <article class="list-card automation-card-shell" data-automation-card="${automation.id}">
        <div class="list-card-head">
          <div>
            <strong>${escapeHtml(automation.name)}</strong>
            <div class="muted compact">
              ${escapeHtml(source)} | ${escapeHtml(automation.run_mode)} | ${escapeHtml(schedule)} | ${escapeHtml(String(videosPerRun))} videos/run
            </div>
            <div class="muted compact">
              ${escapeHtml(selectionLabel)} | ${escapeHtml(shortsLabel)} | Next run ${escapeHtml(nextRunLabel)}
            </div>
          </div>
          <div class="badge ${statusClass}" id="automation-status-${automation.id}">${escapeHtml(automation.status)}</div>
        </div>

        <div class="card-progress">
          <div class="progress-bar compact-progress">
            <span id="automation-progress-${automation.id}" style="width:${progressPercent}%"></span>
          </div>
          <div class="progress-meta">
            <span id="automation-progress-text-${automation.id}">${progressPercent}%</span>
            <span id="automation-message-${automation.id}">${escapeHtml(progressMessage)}</span>
          </div>
        </div>

        <div class="toolbar wrap meta-actions">
          <button
            type="button"
            class="button"
            data-open-runtime
            data-automation-id="${automation.id}"
            data-automation-name="${escapeHtml(automation.name)}"
          >Live Logs</button>

          ${isRunning ? `
            <form method="POST" action="/automation" onsubmit="return confirm('Stop this running job?')">
              <input type="hidden" name="action" value="stop_automation">
              <input type="hidden" name="automation_id" value="${automation.id}">
              <button class="button primary" type="submit">Stop</button>
            </form>
          ` : `
            <button
              type="button"
              class="button primary"
              data-run-automation
              data-automation-id="${automation.id}"
              data-automation-name="${escapeHtml(automation.name)}"
            >Run Now</button>
          `}

          <form method="POST" action="/automation">
            <input type="hidden" name="action" value="toggle_automation">
            <input type="hidden" name="automation_id" value="${automation.id}">
            <button class="button" type="submit">${automation.enabled ? 'Disable' : 'Enable'}</button>
          </form>

          ${truthyValue(config.rotation_enabled, false) ? `
            <form method="POST" action="/automation" onsubmit="return confirm('Reset rotation tracking for this automation?')">
              <input type="hidden" name="action" value="reset_rotation">
              <input type="hidden" name="automation_id" value="${automation.id}">
              <button class="button" type="submit">Reset Rotation</button>
            </form>
          ` : ''}

          <a class="button" href="/automation?edit=${automation.id}">Edit</a>

          <form method="POST" action="/automation" onsubmit="return confirm('Delete this automation?')">
            <input type="hidden" name="action" value="delete_automation">
            <input type="hidden" name="automation_id" value="${automation.id}">
            <button class="button ghost" type="submit">Delete</button>
          </form>
        </div>
      </article>
    `
  }).join('')

  return `
    ${renderFeedback(feedback)}
    <section class="dashboard-grid">
      <section class="panel span-two">
        <div class="section-head">
          <div>
            <div class="eyebrow">Legacy Automation Shell</div>
            <h1>Automation Library</h1>
            <p class="lead">This Worker page mirrors the old automation setup: modal editor, tabbed create/update flow, queue actions, runtime logs, and local-agent dispatch.</p>
          </div>
          <div class="toolbar wrap">
            <a class="button primary" href="/automation?create=1">Create Automation</a>
          </div>
        </div>
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
            <div class="eyebrow">Parity Notes</div>
            <h2>Worker View</h2>
          </div>
        </div>
        <div class="stack">
          <div class="note-card">
            <strong>Local runner</strong>
            <div class="muted compact">Choose <code>Local Runner</code> plus an assigned agent to keep processing on the customer PC.</div>
          </div>
          <div class="note-card">
            <strong>Tabs + settings</strong>
            <div class="muted compact">Use <a class="inline-link" href="/settings">Settings</a> and <a class="inline-link" href="/api-keys">API Keys</a> exactly like the old FTP, AI, FFmpeg, storage, and Bunny setup flow.</div>
          </div>
          <div class="note-card">
            <strong>Runner policy</strong>
            <div class="muted compact">${user.role === 'admin' || user.can_use_github_runner ? 'This account can use both local and GitHub Runner modes.' : 'This account is restricted to local mode only.'}</div>
          </div>
        </div>
      </section>
    </section>
    ${renderAutomationEditorModal({ user, agents, apiKeys, editor, editorOpen })}
    ${renderAutomationRuntimeModal(logAutomation)}
    ${renderAutomationEditorScript()}
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
            <h2>${escapeHtml(title)}</h2>
            <p class="muted compact">The same tabbed create/edit flow is available here in popup form so the Worker UI behaves closer to the legacy panel.</p>
          </div>
          <div class="toolbar wrap">
            <a class="button ghost" href="/automation">Close</a>
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

function renderSettingsBody({ tab, settings, feedback }) {
  const tabs = [
    ['bunny', 'Bunny'],
    ['stream', 'Stream APIs'],
    ['ftp', 'FTP'],
    ['openai', 'AI'],
    ['ffmpeg', 'FFmpeg'],
    ['storage', 'Storage'],
    ['github_runner', 'GitHub Runner'],
    ['postforme', 'Post for Me']
  ]

  const tabLinks = tabs.map(([key, label]) => `
    <a class="tab-chip${tab === key ? ' active' : ''}" href="/settings?tab=${key}">${label}</a>
  `).join('')

  return `
    ${renderFeedback(feedback)}
    <section class="dashboard-grid">
      <section class="panel span-two">
        <div class="section-head">
          <div>
            <div class="eyebrow">Legacy Settings</div>
            <h1>${escapeHtml(settingsTabLabel(tab))}</h1>
          </div>
        </div>
        <div class="toolbar wrap tabs-wrap">${tabLinks}</div>
        <form method="POST" action="/settings" class="stack legacy-settings-form">
          <input type="hidden" name="action" value="save_settings">
          <input type="hidden" name="tab" value="${escapeHtml(tab)}">
          ${renderSettingsTabFields(tab, settings)}
          <button type="submit" class="button primary">Save ${escapeHtml(settingsTabLabel(tab))}</button>
        </form>
      </section>
      <section class="panel">
        <div class="section-head">
          <div>
            <div class="eyebrow">Worker Notes</div>
            <h2>How It Applies</h2>
          </div>
        </div>
        <div class="stack">
          <div class="note-card">
            <strong>Saved in D1</strong>
            <div class="muted compact">These values are stored in Worker D1 and included in local-agent payload snapshots.</div>
          </div>
          <div class="note-card">
            <strong>Local processing</strong>
            <div class="muted compact">FFmpeg, FTP, AI, cookies, and runner settings are pulled into the paired machine when jobs are queued.</div>
          </div>
          <div class="note-card">
            <strong>Parity</strong>
            <div class="muted compact">This page is the Worker-side equivalent of the old settings.php tabs.</div>
          </div>
        </div>
      </section>
    </section>
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

function renderAutomationEditorForm({ user, agents, apiKeys, editor }) {
  const apiKeyOptions = ['<option value="">Select Bunny connection</option>', ...apiKeys.map((key) => `<option value="${key.id}"${selectedAttr(editor.api_key_id, key.id)}>${escapeHtml(key.name)}</option>`)].join('')
  const selectedAgentId = editor.local_agent_id || (user.role !== 'admin' ? (user.assigned_local_agent_id || '') : '')
  const agentOptions = [
    `<option value="">${user.role === 'admin' ? 'Select local agent' : 'Assigned device'}</option>`,
    ...agents.map((agent) => `<option value="${agent.id}"${selectedAttr(selectedAgentId, agent.id)}>${escapeHtml(agent.display_name || `Agent #${agent.id}`)}</option>`)
  ].join('')
  const canUseGithubRunner = user.role === 'admin' || !!user.can_use_github_runner

  return `
    <form method="POST" action="/automation" class="stack">
      <input type="hidden" name="action" value="save_automation">
      ${editor.id ? `<input type="hidden" name="automation_id" value="${editor.id}">` : ''}
      <div class="tab-strip">
        <button type="button" class="tab-button active" data-tab-button="basic" onclick="workerShowAutomationTab('basic')">1. Basic</button>
        <button type="button" class="tab-button" data-tab-button="video" onclick="workerShowAutomationTab('video')">2. Video</button>
        <button type="button" class="tab-button" data-tab-button="taglines" onclick="workerShowAutomationTab('taglines')">3. Taglines</button>
        <button type="button" class="tab-button" data-tab-button="publish" onclick="workerShowAutomationTab('publish')">4. Publish</button>
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
                  <select name="postforme_schedule_mode">
                    <option value="immediate"${selectedAttr(editor.postforme_schedule_mode, 'immediate')}>Immediate</option>
                    <option value="scheduled"${selectedAttr(editor.postforme_schedule_mode, 'scheduled')}>Specific date/time</option>
                    <option value="offset"${selectedAttr(editor.postforme_schedule_mode, 'offset')}>Delay after processing</option>
                  </select>
                </label>
                <label class="field"><span>Schedule Date/Time</span><input type="datetime-local" name="postforme_schedule_datetime" value="${escapeHtml(editor.postforme_schedule_datetime)}"></label>
              </div>
              <div class="grid two">
                <label class="field"><span>Timezone</span><input type="text" name="postforme_schedule_timezone" value="${escapeHtml(editor.postforme_schedule_timezone)}" placeholder="Asia/Karachi"></label>
                <label class="field"><span>Delay after processing (minutes)</span><input type="number" name="postforme_schedule_offset_minutes" value="${escapeHtml(String(editor.postforme_schedule_offset_minutes))}" min="0"></label>
              </div>
              <label class="field"><span>Spread between posts (minutes)</span><input type="number" name="postforme_schedule_spread_minutes" value="${escapeHtml(String(editor.postforme_schedule_spread_minutes))}" min="0"></label>
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

function renderAutomationEditorScript() {
  return `
    <script>
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
      function workerToggleSourceShortsMode() {
        const mode = document.getElementById('source_shorts_mode')?.value || 'single';
        document.getElementById('source_shorts_max_wrap')?.classList.toggle('hidden', mode !== 'fixed_count');
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
          window.location.href = '/automation';
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
        const bar = document.getElementById('automation-progress-' + automationId);
        const text = document.getElementById('automation-progress-text-' + automationId);
        const messageNode = document.getElementById('automation-message-' + automationId);
        if (badge) {
          badge.className = 'badge ' + workerStatusClass(status);
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

      document.addEventListener('click', (event) => {
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
        workerToggleSourceShortsMode();
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
  return `
    ${renderFeedback(feedback)}
    <section class="dashboard-grid">
      <section class="panel">
        <div class="section-head"><div><div class="eyebrow">Admin</div><h1>Create User</h1></div></div>
        <form method="POST" action="/admin/users" class="stack">
          <input type="hidden" name="action" value="create_user">
          <label class="field"><span>Email</span><input type="email" name="email" required></label>
          <label class="field"><span>Password</span><input type="text" name="password" required></label>
          <label class="field"><span>Display Name</span><input type="text" name="display_name"></label>
          <label class="field"><span>Client Slug</span><input type="text" name="client_slug" placeholder="client-a"></label>
          <div class="grid two">
            <label class="field">
              <span>Role</span>
              <select name="role"><option value="user">User</option><option value="admin">Admin</option></select>
            </label>
            <label class="field">
              <span>Status</span>
              <select name="status"><option value="active">Active</option><option value="disabled">Disabled</option></select>
            </label>
          </div>
          <label class="field">
            <span>Assigned Agent</span>
            <select name="assigned_local_agent_id">
              <option value="">None</option>
              ${agents.map((agent) => `<option value="${agent.id}">${escapeHtml(agent.display_name || `Agent #${agent.id}`)}</option>`).join('')}
            </select>
          </label>
          <label class="toggle"><input type="checkbox" name="can_use_github_runner"> <span>Allow GitHub Runner</span></label>
          <p class="muted compact">Create the user here, then send the client the email and password manually.</p>
          <button type="submit" class="button primary">Create User</button>
        </form>
      </section>
      <section class="panel span-two">
        <div class="section-head"><div><div class="eyebrow">Access Control</div><h2>Users</h2></div></div>
        <div class="list-stack">
          ${users.map((user) => `
            <article class="list-card">
              <div class="list-card-head">
                <div>
                  <strong>${escapeHtml(user.display_name || user.email)}</strong>
                  <div class="muted compact">${escapeHtml(user.email)} | ${escapeHtml(user.role)} | ${escapeHtml(user.status)} | slug ${escapeHtml(user.client_slug || '-')}</div>
                </div>
                <div class="badge">${user.can_use_github_runner ? 'runner on' : 'local only'}</div>
              </div>
              <div class="toolbar wrap">
                <form method="POST" action="/admin/users">
                  <input type="hidden" name="action" value="toggle_user_status">
                  <input type="hidden" name="user_id" value="${user.id}">
                  <button class="button" type="submit">${user.status === 'active' ? 'Disable' : 'Enable'}</button>
                </form>
                <form method="POST" action="/admin/users">
                  <input type="hidden" name="action" value="toggle_runner">
                  <input type="hidden" name="user_id" value="${user.id}">
                  <button class="button" type="submit">${user.can_use_github_runner ? 'Disable Runner' : 'Enable Runner'}</button>
                </form>
                <form method="POST" action="/admin/users" class="inline-form">
                  <input type="hidden" name="action" value="assign_agent">
                  <input type="hidden" name="user_id" value="${user.id}">
                  <select name="assigned_local_agent_id">
                    <option value="">No agent</option>
                    ${agents.map((agent) => `<option value="${agent.id}"${Number(user.assigned_local_agent_id || 0) === Number(agent.id) ? ' selected' : ''}>${escapeHtml(agent.display_name || `Agent #${agent.id}`)}</option>`).join('')}
                  </select>
                  <button class="button" type="submit">Save Agent</button>
                </form>
              </div>
            </article>
          `).join('')}
        </div>
      </section>
    </section>
  `
}

function renderAgentsBody({ agents, pairingToken, feedback, installScriptUrl, installManifest }) {
  const command = `$p=Join-Path $env:TEMP 'video-workflow-agent-install.ps1'; Invoke-WebRequest '${installScriptUrl}' -OutFile $p; powershell -ExecutionPolicy Bypass -File $p -CreateScheduledTask`
  return `
    ${renderFeedback(feedback)}
    <section class="dashboard-grid">
      <section class="panel">
        <div class="section-head"><div><div class="eyebrow">Pairing</div><h1>Agent Installer</h1></div></div>
        <p class="muted">Share this command with the target Windows PC. The installer will fetch the package, auto-detect or install PHP, pair the machine, and create a logon task if requested.</p>
        <div class="mono-block">${escapeHtml(pairingToken)}</div>
        <div class="mono-block">${escapeHtml(command)}</div>
        <div class="mono-block">${escapeHtml(JSON.stringify(installManifest, null, 2))}</div>
        <form method="POST" action="/admin/agents">
          <input type="hidden" name="action" value="regenerate_pairing_token">
          <button class="button primary" type="submit">Regenerate Pairing Token</button>
        </form>
      </section>
      <section class="panel span-two">
        <div class="section-head"><div><div class="eyebrow">Runtime Nodes</div><h2>Registered Agents</h2></div></div>
        <div class="list-stack">
          ${agents.map((agent) => `
            <article class="list-card">
              <div class="list-card-head">
                <div>
                  <strong>${escapeHtml(agent.display_name || `Agent #${agent.id}`)}</strong>
                  <div class="muted compact">${escapeHtml(agent.machine_name || '-')} | ${escapeHtml(agent.platform || '-')} | last seen ${escapeHtml(agent.last_seen_at || 'never')}</div>
                </div>
                <div class="badge">${escapeHtml(agent.status)}</div>
              </div>
              <div class="toolbar wrap">
                <form method="POST" action="/admin/agents">
                  <input type="hidden" name="action" value="set_agent_status">
                  <input type="hidden" name="agent_id" value="${agent.id}">
                  <input type="hidden" name="status" value="${agent.status === 'disabled' ? 'offline' : 'disabled'}">
                  <button class="button" type="submit">${agent.status === 'disabled' ? 'Enable' : 'Disable'}</button>
                </form>
              </div>
            </article>
          `).join('') || '<p class="muted">No agents paired yet.</p>'}
        </div>
      </section>
    </section>
  `
}

function renderPage({ title, user, body, currentPath = '' }) {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)}</title>
  <style>
    :root {
      --bg: #090c14;
      --panel: rgba(17,24,39,0.88);
      --ink: #f8fafc;
      --muted: #94a3b8;
      --line: rgba(148,163,184,0.18);
      --accent: #4f46e5;
      --accent-soft: rgba(79,70,229,0.18);
      --olive: #38bdf8;
      --shadow: 0 30px 120px rgba(2,6,23,0.45);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--ink);
      background:
        radial-gradient(circle at top left, rgba(79,70,229,0.25), transparent 34%),
        radial-gradient(circle at top right, rgba(14,165,233,0.18), transparent 32%),
        linear-gradient(180deg, #020617 0%, #0f172a 100%);
      font-family: "Segoe UI Variable", "Aptos", "Segoe UI", sans-serif;
      min-height: 100vh;
    }
    a { color: inherit; text-decoration: none; }
    .shell { max-width: 1320px; margin: 0 auto; padding: 28px 20px 56px; }
    .topbar, .section-head, .list-card-head, .card-head {
      display:flex; justify-content:space-between; align-items:flex-start; gap:16px;
    }
    .topbar { margin-bottom: 22px; }
    .brand { font-family: Georgia, "Times New Roman", serif; font-size: 1.35rem; letter-spacing: 0.02em; }
    .brand small { display:block; font-size:0.82rem; color: var(--muted); font-family: "Aptos", "Segoe UI", sans-serif; }
    .nav { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .panel {
      background: var(--panel);
      backdrop-filter: blur(16px);
      border: 1px solid var(--line);
      border-radius: 24px;
      padding: 22px;
      box-shadow: var(--shadow);
    }
    .auth-panel { max-width: 540px; margin: 8vh auto 0; }
    h1, h2 { margin: 0 0 10px; line-height: 1.1; }
    h1 { font-family: Georgia, "Times New Roman", serif; font-size: clamp(2rem, 4vw, 3.2rem); }
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
      width: 100%; border: 1px solid rgba(148,163,184,0.18); border-radius: 16px;
      padding: 12px 14px; background: rgba(15,23,42,0.9); color: var(--ink); font: inherit;
    }
    .field textarea { resize: vertical; min-height: 120px; font-family: Consolas, "Courier New", monospace; font-size: 0.88rem; }
    .toggle { display:flex; align-items:center; gap:10px; color: var(--muted); }
    .button {
      display:inline-flex; align-items:center; justify-content:center; gap:8px; padding: 11px 15px;
      border-radius: 999px; border: 1px solid rgba(148,163,184,0.16); background: rgba(15,23,42,0.82);
      color: var(--ink); cursor:pointer; font: inherit;
    }
    .button.primary { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: #fff; border-color: transparent; }
    .button.ghost { background: transparent; }
    .button.nav-active { background: var(--accent-soft); border-color: rgba(99,102,241,0.32); }
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
    .status-success { background: rgba(34,197,94,0.18); color: #86efac; }
    .status-queued { background: rgba(59,130,246,0.18); color: #93c5fd; }
    .status-warning { background: rgba(245,158,11,0.18); color: #fcd34d; }
    .status-error { background: rgba(239,68,68,0.18); color: #fca5a5; }
    .status-neutral { background: rgba(148,163,184,0.16); color: #cbd5e1; }
    code { background: rgba(15,23,42,0.9); padding:2px 6px; border-radius:8px; }
    @media (max-width: 980px) {
      .dashboard-grid { grid-template-columns: 1fr; }
      .span-two { grid-column: span 1; }
      .grid.two, .stats-row { grid-template-columns: 1fr; }
      .topbar, .section-head, .list-card-head, .card-head { flex-direction: column; align-items: stretch; }
      .meta-actions { display:flex; flex-wrap:wrap; gap:10px; }
      .modal-backdrop { padding: 12px; }
      .modal-panel { padding: 18px; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <div class="brand">
        Video Workflow Control
        <small>Cloudflare Worker panel with the legacy automation shell</small>
      </div>
      <nav class="nav">
        ${user ? `
          <a class="button ghost${currentPath === '/dashboard' ? ' nav-active' : ''}" href="/dashboard">Dashboard</a>
          <a class="button ghost${currentPath === '/automation' ? ' nav-active' : ''}" href="/automation">Automation</a>
          ${user.role === 'admin' ? `<a class="button ghost${currentPath === '/api-keys' ? ' nav-active' : ''}" href="/api-keys">API Keys</a><a class="button ghost${currentPath === '/settings' ? ' nav-active' : ''}" href="/settings">Settings</a><a class="button ghost${currentPath === '/admin/users' ? ' nav-active' : ''}" href="/admin/users">Users</a><a class="button ghost${currentPath === '/admin/agents' ? ' nav-active' : ''}" href="/admin/agents">Agents</a>` : ''}
          <form method="POST" action="/logout"><button type="submit" class="button ghost">Logout</button></form>
        ` : '<a class="button ghost" href="/login">Login</a>'}
      </nav>
    </header>
    ${body}
  </main>
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
  })()

  try {
    await schemaReadyPromise
  } catch (error) {
    schemaReadyPromise = null
    throw error
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

async function listRecentOutputsForUser(env, user, limit) {
  const sql = user.role === 'admin'
    ? `
      SELECT o.*, a.owner_user_id
      FROM output_files o
      JOIN automations a ON a.id = o.automation_id
      ORDER BY o.created_at DESC, o.id DESC
      LIMIT ?
    `
    : `
      SELECT o.*, a.owner_user_id
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

async function getAutomationById(env, automationId) {
  const row = await env.DB.prepare('SELECT * FROM automations WHERE id = ? LIMIT 1').bind(automationId).first()
  return row ? {
    ...row,
    id: Number(row.id),
    owner_user_id: Number(row.owner_user_id),
    local_agent_id: row.local_agent_id === null ? null : Number(row.local_agent_id),
    enabled: Number(row.enabled || 0),
    progress_percent: Number(row.progress_percent || 0)
  } : null
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
    SELECT id, automation_id, job_id, filename, stored_in, content_type, size_bytes, created_at
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

function normalizePath(pathname) {
  if (!pathname || pathname === '/') {
    return '/'
  }
  return pathname.replace(/\/+$/, '') || '/'
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
