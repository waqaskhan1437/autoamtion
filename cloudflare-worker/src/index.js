import { renderWindowsInstallScript } from './install-script.js'

const textEncoder = new TextEncoder()
const sessionCookieName = 'vw_session'
const defaultSessionTtlSeconds = 60 * 60 * 24 * 7
const passwordIterations = 210000

export default {
  async fetch(request, env) {
    try {
      const url = new URL(request.url)
      const path = normalizePath(url.pathname)

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
        return handleDashboardAction(request, env, session.user)
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

    let message = `User #${userId} created.`
    let magicLink = null
    if (checkboxValue(form.get('generate_magic_link'))) {
      const magic = await createMagicLink(env, {
        userId,
        createdByUserId: adminUser.id,
        redirectPath: '/dashboard',
        expiryHours: toInt(form.get('magic_expiry_hours')) || 24,
        origin: new URL(request.url).origin
      })
      magicLink = magic.url
      message = `User #${userId} created and magic link generated.`
    }

    return renderUsersPage(request, env, adminUser, { success: message, magicLink })
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
    const userId = toInt(form.get('user_id'))
    const expiryHours = toInt(form.get('magic_expiry_hours')) || 24
    const user = await getUserById(env, userId)
    if (!user) {
      return renderUsersPage(request, env, adminUser, { error: 'User not found.' })
    }
    const magic = await createMagicLink(env, {
      userId,
      createdByUserId: adminUser.id,
      redirectPath: '/dashboard',
      expiryHours,
      origin: new URL(request.url).origin
    })
    return renderUsersPage(request, env, adminUser, {
      success: `Magic link generated for ${user.email}.`,
      magicLink: magic.url
    })
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

async function handleDashboardAction(request, env, user) {
  const form = await request.formData()
  const action = String(form.get('action') || '')

  if (action === 'save_automation') {
    const automationId = toNullableInt(form.get('automation_id'))
    const name = String(form.get('name') || '').trim()
    const runMode = sanitizeRunMode(String(form.get('run_mode') || 'local'))
    const localAgentId = toNullableInt(form.get('local_agent_id'))
    const enabled = checkboxValue(form.get('enabled')) ? 1 : 0
    const automationJsonText = String(form.get('automation_json') || '{}').trim() || '{}'
    const apiKeyJsonText = String(form.get('api_key_json') || '').trim()
    const settingsJsonText = String(form.get('settings_json') || '').trim()

    if (name === '') {
      return renderDashboardPage(request, env, user, { error: 'Automation name is required.' })
    }

    let automationJson
    let apiKeyJson = null
    let settingsJson = null
    try {
      automationJson = parseJsonObject(automationJsonText, 'Automation JSON')
      if (apiKeyJsonText !== '') {
        apiKeyJson = parseJsonObject(apiKeyJsonText, 'API Key JSON')
      }
      if (settingsJsonText !== '') {
        settingsJson = parseJsonObject(settingsJsonText, 'Settings JSON')
      }
    } catch (error) {
      return renderDashboardPage(request, env, user, {
        error: error instanceof Error ? error.message : 'JSON payload is invalid.'
      })
    }

    if (runMode === 'github_runner' && !user.can_use_github_runner && user.role !== 'admin') {
      return renderDashboardPage(request, env, user, { error: 'This user is not allowed to use GitHub Runner.' })
    }

    if (runMode === 'local' && user.role !== 'admin' && user.assigned_local_agent_id && localAgentId && user.assigned_local_agent_id !== localAgentId) {
      return renderDashboardPage(request, env, user, { error: 'This user can only use the assigned local agent.' })
    }

    if (automationId) {
      const existing = await getAutomationById(env, automationId)
      if (!existing || !canAccessAutomation(user, existing)) {
        return renderDashboardPage(request, env, user, { error: 'Automation not found.' })
      }
      await env.DB.prepare(`
        UPDATE automations
        SET name = ?, run_mode = ?, local_agent_id = ?, enabled = ?, automation_json = ?, api_key_json = ?, settings_json = ?, updated_at = ?
        WHERE id = ?
      `).bind(
        name,
        runMode,
        localAgentId,
        enabled,
        JSON.stringify(automationJson),
        apiKeyJson ? JSON.stringify(apiKeyJson) : null,
        settingsJson ? JSON.stringify(settingsJson) : null,
        isoNow(),
        automationId
      ).run()
      return renderDashboardPage(request, env, user, { success: `Automation ${name} updated.` })
    }

    await env.DB.prepare(`
      INSERT INTO automations (
        owner_user_id, name, run_mode, local_agent_id, enabled, status,
        progress_percent, automation_json, api_key_json, settings_json, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, 'inactive', 0, ?, ?, ?, ?, ?)
    `).bind(
      user.id,
      name,
      runMode,
      localAgentId,
      enabled,
      JSON.stringify(automationJson),
      apiKeyJson ? JSON.stringify(apiKeyJson) : null,
      settingsJson ? JSON.stringify(settingsJson) : null,
      isoNow(),
      isoNow()
    ).run()

    return renderDashboardPage(request, env, user, { success: `Automation ${name} created.` })
  }

  if (action === 'queue_automation') {
    const automationId = toInt(form.get('automation_id'))
    const automation = await getAutomationById(env, automationId)
    if (!automation || !canAccessAutomation(user, automation)) {
      return renderDashboardPage(request, env, user, { error: 'Automation not found.' })
    }

    const result = await queueAutomation(env, automation, 'manual')
    if (!result.success) {
      return renderDashboardPage(request, env, user, { error: result.error })
    }

    return renderDashboardPage(request, env, user, { success: `Automation queued on ${result.agentName}.` })
  }

  if (action === 'delete_automation') {
    const automationId = toInt(form.get('automation_id'))
    const automation = await getAutomationById(env, automationId)
    if (!automation || !canAccessAutomation(user, automation)) {
      return renderDashboardPage(request, env, user, { error: 'Automation not found.' })
    }
    await env.DB.prepare('DELETE FROM automations WHERE id = ?').bind(automationId).run()
    await env.DB.prepare('DELETE FROM automation_logs WHERE automation_id = ?').bind(automationId).run()
    return renderDashboardPage(request, env, user, { success: `Automation ${automation.name} deleted.` })
  }

  return renderDashboardPage(request, env, user, { error: 'Unknown dashboard action.' })
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
    body
  }))
}

async function renderUsersPage(request, env, adminUser, feedback) {
  const users = await listUsers(env)
  const agents = await listAgents(env)
  return htmlResponse(renderPage({
    title: 'Users',
    user: adminUser,
    body: renderUsersBody({ users, agents, feedback })
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
    })
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
      <p class="muted compact">Admins can also generate one-time magic links from the users page.</p>
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
  if (feedback.magicLink) {
    notices.push(`<div class="flash info"><strong>Magic Link</strong><div class="mono-block">${escapeHtml(feedback.magicLink)}</div></div>`)
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
          <label class="toggle"><input type="checkbox" name="generate_magic_link" checked> <span>Generate Magic Link</span></label>
          <label class="field"><span>Magic Link Expiry Hours</span><input type="number" name="magic_expiry_hours" min="1" max="168" value="24"></label>
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
                <form method="POST" action="/admin/users" class="inline-form">
                  <input type="hidden" name="action" value="generate_magic_link">
                  <input type="hidden" name="user_id" value="${user.id}">
                  <input type="number" name="magic_expiry_hours" value="24" min="1" max="168">
                  <button class="button primary" type="submit">Generate Magic Link</button>
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

function renderPage({ title, user, body }) {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)}</title>
  <style>
    :root {
      --bg: #f1eee6;
      --panel: rgba(255,255,255,0.78);
      --ink: #1a1f19;
      --muted: #5e6458;
      --line: rgba(26,31,25,0.12);
      --accent: #b24a2c;
      --accent-soft: rgba(178,74,44,0.12);
      --olive: #5e6d47;
      --shadow: 0 24px 80px rgba(29, 31, 28, 0.12);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--ink);
      background:
        radial-gradient(circle at top left, rgba(178,74,44,0.18), transparent 34%),
        radial-gradient(circle at top right, rgba(94,109,71,0.16), transparent 32%),
        linear-gradient(180deg, #f6f2e9 0%, #ece7dc 100%);
      font-family: "Aptos", "Segoe UI Variable", "Segoe UI", sans-serif;
      min-height: 100vh;
    }
    a { color: inherit; text-decoration: none; }
    .shell { max-width: 1320px; margin: 0 auto; padding: 28px 20px 56px; }
    .topbar, .section-head, .list-card-head, .card-head {
      display:flex; justify-content:space-between; align-items:flex-start; gap:16px;
    }
    .topbar { margin-bottom: 22px; }
    .brand { font-family: Georgia, "Times New Roman", serif; font-size: 1.25rem; letter-spacing: 0.02em; }
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
      width: 100%; border: 1px solid rgba(26,31,25,0.14); border-radius: 16px;
      padding: 12px 14px; background: rgba(255,255,255,0.86); color: var(--ink); font: inherit;
    }
    .field textarea { resize: vertical; min-height: 120px; font-family: Consolas, "Courier New", monospace; font-size: 0.88rem; }
    .toggle { display:flex; align-items:center; gap:10px; color: var(--muted); }
    .button {
      display:inline-flex; align-items:center; justify-content:center; gap:8px; padding: 11px 15px;
      border-radius: 999px; border: 1px solid rgba(26,31,25,0.12); background: rgba(255,255,255,0.82);
      color: var(--ink); cursor:pointer; font: inherit;
    }
    .button.primary { background: var(--accent); color: #fff6f2; border-color: transparent; }
    .button.ghost { background: transparent; }
    .toolbar { display:flex; gap:10px; flex-wrap: wrap; align-items:center; }
    .toolbar.wrap { row-gap: 10px; }
    .stats-row { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:12px; margin-top: 16px; }
    .metric { border:1px solid var(--line); border-radius:18px; padding:16px; background: rgba(255,255,255,0.56); }
    .metric span { display:block; font-size: 1.8rem; font-weight: 700; }
    .metric small { color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; }
    .badge { min-width: 70px; text-align:center; padding: 8px 12px; border-radius: 999px; background: var(--accent-soft); color: var(--accent); font-weight: 700; font-size: 0.82rem; }
    .flash { border-radius: 18px; padding: 14px 16px; margin-bottom: 16px; border: 1px solid var(--line); }
    .flash.error { background: rgba(189, 63, 54, 0.12); color: #8e2d25; }
    .flash.success { background: rgba(80, 122, 59, 0.12); color: #365826; }
    .flash.info { background: rgba(55, 92, 116, 0.12); color: #294a5e; }
    .mono-block {
      margin-top: 10px; padding: 14px; border-radius: 16px; background: #1a1f19; color: #edf2ea;
      overflow:auto; font-family: Consolas, "Courier New", monospace; font-size: 0.84rem; white-space: pre-wrap; word-break: break-word;
    }
    .progress-bar { height: 8px; border-radius: 999px; background: rgba(26,31,25,0.08); overflow:hidden; margin: 14px 0 18px; }
    .progress-bar span { display:block; height:100%; background: linear-gradient(90deg, var(--olive), var(--accent)); border-radius:999px; }
    .automation-card { display:grid; gap: 10px; margin-bottom: 14px; }
    .compact-form { margin-top: 6px; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse: collapse; }
    th, td { text-align:left; padding: 12px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); }
    .list-stack { display:grid; gap: 12px; }
    .list-card { border:1px solid var(--line); border-radius: 18px; padding: 16px; background: rgba(255,255,255,0.56); }
    .inline-form { display:flex; gap:10px; flex-wrap: wrap; align-items:center; }
    .inline-form select, .inline-form input { min-width: 160px; }
    .inline-link { color: var(--accent); }
    @media (max-width: 980px) {
      .dashboard-grid { grid-template-columns: 1fr; }
      .span-two { grid-column: span 1; }
      .grid.two, .stats-row { grid-template-columns: 1fr; }
      .topbar, .section-head, .list-card-head, .card-head { flex-direction: column; align-items: stretch; }
      .meta-actions { display:flex; flex-wrap:wrap; gap:10px; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <div class="brand">
        Video Workflow Control
        <small>Cloudflare Worker panel for local automation agents</small>
      </div>
      <nav class="nav">
        ${user ? `
          <a class="button ghost" href="/dashboard">Dashboard</a>
          ${user.role === 'admin' ? '<a class="button ghost" href="/admin/users">Users</a><a class="button ghost" href="/admin/agents">Agents</a>' : ''}
        ` : '<a class="button ghost" href="/login">Login</a>'}
      </nav>
    </header>
    ${body}
  </main>
</body>
</html>`
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

function normalizeUser(row) {
  return {
    ...row,
    id: Number(row.id),
    can_use_github_runner: Number(row.can_use_github_runner || 0),
    assigned_local_agent_id: row.assigned_local_agent_id === null ? null : Number(row.assigned_local_agent_id)
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
      SET status = 'queued', progress_percent = 5, progress_data = ?, last_progress_at = ?, updated_at = ?
      WHERE id = ?
    `).bind(progressPayload, now, now, automation.id),
    env.DB.prepare(`
      INSERT INTO automation_logs (automation_id, action, status, message, created_at)
      VALUES (?, 'local_agent_queue', 'info', ?, ?)
    `).bind(automation.id, `Queued for local agent ${agent.display_name || ('#' + agent.id)} via ${triggerSource}`, now)
  ])

  return { success: true, agentName: agent.display_name || `Agent #${agent.id}` }
}

async function buildCompressedPayload(env, automation) {
  const automationJson = parseJsonMaybe(automation.automation_json, {})
  const apiKeyJson = automation.api_key_json ? parseJsonMaybe(automation.api_key_json, null) : null
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

  await env.DB.batch([
    env.DB.prepare(`
      UPDATE automations
      SET status = ?, progress_percent = ?, progress_data = ?, last_progress_at = ?, updated_at = ?,
          last_run_at = CASE WHEN ? IN ('completed', 'error') THEN ? ELSE last_run_at END
      WHERE id = ?
    `).bind(status, progress, JSON.stringify(safePayload), now, now, status, now, automationId),
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
  const key = await derivePasswordKey(password, salt, passwordIterations)
  return `pbkdf2$${passwordIterations}$${arrayBufferToBase64(salt)}$${arrayBufferToBase64(key)}`
}

async function verifyPassword(password, storedHash) {
  const parts = String(storedHash || '').split('$')
  if (parts.length !== 4 || parts[0] !== 'pbkdf2') {
    return false
  }
  const iterations = Number(parts[1]) || passwordIterations
  const salt = base64ToUint8Array(parts[2])
  const expected = parts[3]
  const key = await derivePasswordKey(password, salt, iterations)
  return timingSafeEqual(arrayBufferToBase64(key), expected)
}

async function derivePasswordKey(password, salt, iterations) {
  const keyMaterial = await crypto.subtle.importKey('raw', textEncoder.encode(password), { name: 'PBKDF2' }, false, ['deriveBits'])
  return await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, keyMaterial, 256)
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
