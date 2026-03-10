import { unstable_dev } from 'wrangler'

function expect(condition, message) {
  if (!condition) {
    throw new Error(message)
  }
}

function escapeRegex(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

async function main() {
  const worker = await unstable_dev('cloudflare-worker/src/index.js', {
    config: 'wrangler.jsonc',
    local: true,
    logLevel: 'error',
    experimental: { disableExperimentalWarning: true }
  })

  const base = 'http://127.0.0.1:' + worker.port
  const cookieJar = new Map()

  function storeCookies(response) {
    const getSetCookie = typeof response.headers.getSetCookie === 'function'
      ? response.headers.getSetCookie()
      : []
    const headerValues = getSetCookie.length
      ? getSetCookie
      : (response.headers.get('set-cookie') ? [response.headers.get('set-cookie')] : [])
    for (const cookie of headerValues) {
      const first = String(cookie || '').split(';')[0]
      const eq = first.indexOf('=')
      if (eq === -1) {
        continue
      }
      const name = first.slice(0, eq).trim()
      const value = first.slice(eq + 1).trim()
      if (!name) {
        continue
      }
      if (value === '') {
        cookieJar.delete(name)
      } else {
        cookieJar.set(name, value)
      }
    }
  }

  function cookieHeader() {
    return [...cookieJar.entries()].map(([key, value]) => key + '=' + value).join('; ')
  }

  async function request(path, init = {}) {
    const headers = new Headers(init.headers || {})
    const cookie = cookieHeader()
    if (cookie) {
      headers.set('cookie', cookie)
    }
    const response = await worker.fetch(base + path, { ...init, headers })
    storeCookies(response)
    return response
  }

  async function requestText(path, init = {}) {
    const response = await request(path, init)
    const text = await response.text()
    return { response, text }
  }

  async function requestJson(path, init = {}) {
    const response = await request(path, init)
    const text = await response.text()
    let data
    try {
      data = JSON.parse(text)
    } catch {
      throw new Error('Expected JSON from ' + path + ' but got: ' + text.slice(0, 240))
    }
    return { response, data, text }
  }

  try {
    const health = await requestJson('/api/health')
    expect(health.response.ok && health.data.success, 'Health check failed.')

    const loginPage = await requestText('/login')
    expect(loginPage.response.ok && loginPage.text.includes('Video Workflow Control'), 'Login page did not render.')

    const loginBody = new URLSearchParams({
      email: 'admin@local',
      password: 'ChangeMe@123',
      next: '/dashboard'
    })
    const loginResponse = await request('/login', {
      method: 'POST',
      body: loginBody,
      redirect: 'manual'
    })
    expect(loginResponse.status === 303, 'Login did not redirect.')
    expect(cookieJar.has('vw_session'), 'Login cookie was not set.')
    const dashboard = await requestText(loginResponse.headers.get('location') || '/dashboard')
    expect(dashboard.text.includes('Administrator'), 'Dashboard did not load after login.')
    expect(dashboard.text.includes('Scheduled Posts'), 'Dashboard scheduled posts section missing.')

    const settingsPage = await requestText('/settings')
    expect(settingsPage.text.includes('GitHub Runner') && settingsPage.text.includes('FFmpeg'), 'Settings tabs missing.')

    const apiKeysPage = await requestText('/api-keys')
    expect(apiKeysPage.text.includes('Create Connection'), 'API Keys page missing.')

    const usersPage = await requestText('/admin/users')
    expect(usersPage.text.includes('Create User'), 'Users page missing.')

    const createModalPage = await requestText('/automation?create=1')
    expect(
      createModalPage.text.includes('automation-editor-modal') &&
      createModalPage.text.includes('Create Automation') &&
      createModalPage.text.includes('Scheduled Queue') &&
      createModalPage.text.includes('View Processed Videos'),
      'Automation shell parity controls missing.'
    )

    const agentsPage = await requestText('/admin/agents')
    const tokenMatches = [...agentsPage.text.matchAll(/<div class=\"mono-block\">([^<]+)<\/div>/g)]
    expect(tokenMatches.length >= 1, 'Pairing token not found.')
    const pairingToken = tokenMatches[0][1]

    const registerAgent = await requestJson('/api/agent/register', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        pairing_token: pairingToken,
        display_name: 'Smoke Agent',
        machine_name: 'SMOKEBOX',
        host_name: 'smoke-host',
        platform: 'windows',
        agent_version: 'smoke-test',
        capabilities: { ffmpeg: true, yt_dlp: true }
      })
    })
    expect(registerAgent.data.success, 'Agent registration failed.')
    const agentId = Number(registerAgent.data.agent.id)
    const agentKey = String(registerAgent.data.agent_key)
    const agentSecret = String(registerAgent.data.agent_secret)

    const automationName = 'Smoke Automation ' + Date.now()
    const createForm = new URLSearchParams({
      action: 'save_automation',
      name: automationName,
      video_source: 'manual_links',
      manual_video_links: 'https://example.com/video.mp4',
      run_mode: 'local',
      local_agent_id: String(agentId),
      schedule_type: 'minutes',
      schedule_hour: '9',
      schedule_every_minutes: '15',
      enabled: 'on',
      video_selection_method_hidden: 'days',
      video_days_filter: '7',
      rotation_enabled: 'on',
      rotation_shuffle: 'on',
      rotation_auto_reset: 'on',
      videos_per_run: '1',
      short_duration: '60',
      playback_speed: '1.0',
      short_aspect_ratio: '9:16',
      source_shorts_mode: 'single',
      source_shorts_max_count: '1',
      ai_taglines_enabled: 'on',
      ai_tagline_prompt: 'Generate universal greeting taglines',
      branding_text_top: '',
      branding_text_bottom: '',
      random_words: '',
      whisper_language: 'en',
      postforme_enabled: 'on',
      postforme_account_ids_csv: 'acct-1,acct-2',
      postforme_schedule_mode: 'offset',
      postforme_schedule_timezone: 'UTC',
      postforme_schedule_offset_minutes: '60',
      postforme_schedule_spread_minutes: '15'
    })
    const createAutomation = await requestText('/automation', {
      method: 'POST',
      body: createForm
    })
    expect(createAutomation.text.includes(automationName), 'Automation create response missing new automation.')

    const automationPage = await requestText('/automation')
    const automationPattern = new RegExp('data-automation-card=\"(?<id>\\d+)\"[^]*?<strong>' + escapeRegex(automationName) + '<\\/strong>')
    const automationMatch = automationPage.text.match(automationPattern)
    expect(automationMatch && automationMatch.groups && automationMatch.groups.id, 'Created automation not found in library.')
    const automationId = Number(automationMatch.groups.id)

    const editModalPage = await requestText('/automation?edit=' + automationId)
    expect(editModalPage.text.includes('Edit Automation'), 'Edit modal not rendered.')

    const logsModalPage = await requestText('/automation?logs=' + automationId)
    expect(logsModalPage.text.includes('data-initial-open=\"1\"'), 'Runtime modal query flag missing.')

    const statusBeforeRun = await requestJson('/api/automation-status?automation_id=' + automationId)
    expect(statusBeforeRun.data.success, 'Status endpoint failed before run.')

    const queueResponse = await requestJson('/api/automation-run', {
      method: 'POST',
      body: new URLSearchParams({ automation_id: String(automationId) })
    })
    expect(queueResponse.data.success && Number(queueResponse.data.job_id) > 0, 'Queue endpoint failed.')
    const jobId = Number(queueResponse.data.job_id)

    const claimResponse = await requestJson('/api/agent/poll', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ agent_key: agentKey, agent_secret: agentSecret })
    })
    expect(claimResponse.data.success && claimResponse.data.job, 'Agent failed to claim queued job.')
    expect(Number(claimResponse.data.job.id) === jobId, 'Claimed job did not match queued job.')
    const claimToken = String(claimResponse.data.job.claim_token)

    const progressResponse = await requestJson('/api/agent/report', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        job_id: jobId,
        claim_token: claimToken,
        payload: {
          status: 'processing',
          event_status: 'info',
          step: 'download',
          message: 'Downloaded source clip',
          progress: 45,
          stats: { fetched: 1, downloaded: 1, processed: 0, scheduled: 0, posted: 0 }
        }
      })
    })
    expect(progressResponse.data.success, 'Agent progress update failed.')

    const statusMid = await requestJson('/api/automation-status?automation_id=' + automationId)
    expect(['processing', 'running'].includes(String(statusMid.data.automation.status)), 'Unexpected mid-run status: ' + String(statusMid.data.automation.status))
    expect(statusMid.data.logs.some((log) => log.message === 'Downloaded source clip'), 'Progress log missing from status feed.')

    const boundary = '----codexboundary' + Date.now()
    const encoder = new TextEncoder()
    const fileBytes = new Uint8Array(32)
    const prefix = encoder.encode(
      '--' + boundary + '\r\n' +
      'Content-Disposition: form-data; name=\"job_id\"\r\n\r\n' + String(jobId) + '\r\n' +
      '--' + boundary + '\r\n' +
      'Content-Disposition: form-data; name=\"claim_token\"\r\n\r\n' + String(claimToken) + '\r\n' +
      '--' + boundary + '\r\n' +
      'Content-Disposition: form-data; name=\"output_file\"; filename=\"sample.mp4\"\r\n' +
      'Content-Type: video/mp4\r\n\r\n'
    )
    const suffix = encoder.encode('\r\n--' + boundary + '--\r\n')
    const uploadBody = new Uint8Array(prefix.length + fileBytes.length + suffix.length)
    uploadBody.set(prefix, 0)
    uploadBody.set(fileBytes, prefix.length)
    uploadBody.set(suffix, prefix.length + fileBytes.length)
    const uploadResponse = await requestJson('/api/agent-upload-output.php', {
      method: 'POST',
      headers: { 'content-type': 'multipart/form-data; boundary=' + boundary },
      body: uploadBody
    })
    expect(uploadResponse.data.success, 'Agent output upload failed.')

    const completeResponse = await requestJson('/api/agent/complete', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        job_id: jobId,
        claim_token: claimToken,
        payload: {
          status: 'completed',
          event_status: 'success',
          step: 'complete',
          message: 'Automation finished cleanly',
          progress: 100,
          stats: { fetched: 1, downloaded: 1, processed: 1, scheduled: 0, posted: 0 }
        }
      })
    })
    expect(completeResponse.data.success, 'Agent completion update failed.')

    const statusDone = await requestJson('/api/automation-status?automation_id=' + automationId)
    expect(String(statusDone.data.automation.status) === 'completed', 'Unexpected final status: ' + String(statusDone.data.automation.status))
    expect(statusDone.data.outputs.some((output) => output.filename === 'sample.mp4'), 'Uploaded output missing from status feed.')
    expect(statusDone.data.logs.some((log) => log.message === 'Automation finished cleanly'), 'Completion log missing.')

    const scheduledPosts = await requestJson('/api/scheduled-posts?automation_id=' + automationId)
    expect(scheduledPosts.data.success, 'Scheduled posts endpoint failed.')
    expect(Array.isArray(scheduledPosts.data.posts) && scheduledPosts.data.posts.length >= 1, 'Scheduled posts were not generated.')
    expect(String(scheduledPosts.data.posts[0].status) === 'scheduled', 'Scheduled post did not retain scheduled status.')

    const playerPage = await requestText('/player')
    expect(playerPage.text.includes('Processed Shorts') && playerPage.text.includes('Back to Automations'), 'Player parity shell missing.')

    await request('/automation', {
      method: 'POST',
      body: new URLSearchParams({ action: 'toggle_automation', automation_id: String(automationId) })
    })
    const statusOff = await requestJson('/api/automation-status?automation_id=' + automationId)
    expect(Number(statusOff.data.automation.enabled) === 0, 'Disable toggle failed.')

    await request('/automation', {
      method: 'POST',
      body: new URLSearchParams({ action: 'toggle_automation', automation_id: String(automationId) })
    })
    const statusOn = await requestJson('/api/automation-status?automation_id=' + automationId)
    expect(Number(statusOn.data.automation.enabled) === 1, 'Enable toggle failed.')

    await request('/automation', {
      method: 'POST',
      body: new URLSearchParams({ action: 'reset_rotation', automation_id: String(automationId) })
    })
    const statusReset = await requestJson('/api/automation-status?automation_id=' + automationId)
    expect(statusReset.data.logs.some((log) => log.action === 'reset_rotation'), 'Reset rotation log missing.')

    const queueAgain = await requestJson('/api/automation-run', {
      method: 'POST',
      body: new URLSearchParams({ automation_id: String(automationId) })
    })
    expect(queueAgain.data.success, 'Second queue attempt failed.')

    await request('/automation', {
      method: 'POST',
      body: new URLSearchParams({ action: 'stop_automation', automation_id: String(automationId) })
    })
    const statusStopped = await requestJson('/api/automation-status?automation_id=' + automationId)
    expect(String(statusStopped.data.automation.status) === 'stopped', 'Stop action failed.')

    const pollAfterStop = await requestJson('/api/agent/poll', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ agent_key: agentKey, agent_secret: agentSecret })
    })
    expect(!pollAfterStop.data.job, 'Cancelled queued job was still claimable after stop.')

    console.log('SMOKE_OK automation_id=' + automationId + ' agent_id=' + agentId + ' job_id=' + jobId + ' output=' + uploadResponse.data.filename)
  } finally {
    await worker.stop()
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.stack || error.message : String(error))
  process.exit(1)
})
