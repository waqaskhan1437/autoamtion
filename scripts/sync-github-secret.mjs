import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);

let sodium;

try {
  sodium = require('libsodium-wrappers');
} catch (error) {
  console.error('Missing dependency libsodium-wrappers. Run npm install.');
  process.exit(2);
}

await sodium.ready;

function requireEnv(name) {
  const value = (process.env[name] || '').trim();
  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

async function githubRequest(url, token, { method = 'GET', body = null } = {}) {
  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/vnd.github+json',
      Authorization: `Bearer ${token}`,
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'VideoWorkflow-GitHubRunner/1.0',
      ...(body === null ? {} : { 'Content-Type': 'application/json' }),
    },
    body: body === null ? undefined : JSON.stringify(body),
  });

  const text = await response.text();
  let json = null;

  if (text) {
    try {
      json = JSON.parse(text);
    } catch (error) {
      json = null;
    }
  }

  return {
    ok: response.ok,
    status: response.status,
    text,
    json,
  };
}

async function upsertEncryptedSecret(baseUrl, token, secretName, plainValue, publicKey) {
  const encryptedBytes = sodium.crypto_box_seal(
    sodium.from_string(plainValue),
    sodium.from_base64(publicKey.key, sodium.base64_variants.ORIGINAL),
  );
  const encryptedValue = sodium.to_base64(encryptedBytes, sodium.base64_variants.ORIGINAL);

  const response = await githubRequest(`${baseUrl}/${encodeURIComponent(secretName)}`, token, {
    method: 'PUT',
    body: {
      encrypted_value: encryptedValue,
      key_id: publicKey.key_id,
    },
  });

  if (!(response.status === 201 || response.status === 204)) {
    const detail = response.json?.message || response.text || `HTTP ${response.status}`;
    throw new Error(`Failed to update GitHub secret ${secretName}: ${detail}`);
  }
}

async function main() {
  const token = requireEnv('GH_TOKEN');
  const owner = requireEnv('GH_OWNER');
  const repo = requireEnv('GH_REPO');
  const secretName = (process.env.GH_SECRET_NAME || 'YTDLP_COOKIES_B64').trim() || 'YTDLP_COOKIES_B64';
  const sourceValue = (process.env.GH_SECRET_VALUE || '');
  const sourceFileRaw = (process.env.GH_SECRET_FILE || '').trim();
  const sourceFile = sourceFileRaw ? path.resolve(sourceFileRaw) : '';
  const chunkPrefix = (process.env.GH_SECRET_CHUNK_PREFIX || `${secretName}_`).trim() || `${secretName}_`;
  const deleteChunked = (process.env.GH_SECRET_DELETE_CHUNKED || '1').trim() !== '0';
  const singleLimit = Number.parseInt(process.env.GH_SECRET_SINGLE_LIMIT || '45000', 10) || 45000;
  const chunkSize = Number.parseInt(process.env.GH_SECRET_CHUNK_SIZE || '40000', 10) || 40000;
  const chunkMax = Number.parseInt(process.env.GH_SECRET_CHUNK_MAX || '10', 10) || 10;

  const sourceText = sourceValue !== '' ? sourceValue : await readFile(sourceFile, 'utf8');
  if (!sourceText.trim()) {
    throw new Error('secret source is empty.');
  }
  const encodedSecretValue = Buffer.from(sourceText, 'utf8').toString('base64');

  const baseUrl = `https://api.github.com/repos/${encodeURIComponent(owner)}/${encodeURIComponent(repo)}/actions/secrets`;
  const publicKeyRes = await githubRequest(`${baseUrl}/public-key`, token);
  if (!publicKeyRes.ok || !publicKeyRes.json?.key || !publicKeyRes.json?.key_id) {
    const detail = publicKeyRes.json?.message || publicKeyRes.text || `HTTP ${publicKeyRes.status}`;
    throw new Error(`Failed to fetch GitHub Actions public key: ${detail}`);
  }
  const publicKey = publicKeyRes.json;

  const updatedSecrets = [];
  const deletedSecrets = [];
  let mode = 'single';

  if (encodedSecretValue.length <= singleLimit) {
    await upsertEncryptedSecret(baseUrl, token, secretName, encodedSecretValue, publicKey);
    updatedSecrets.push(secretName);
  } else {
    mode = 'chunked';
    const totalChunks = Math.ceil(encodedSecretValue.length / chunkSize);
    if (totalChunks > chunkMax) {
      throw new Error(`cookies.txt is too large even for chunked secret sync (${totalChunks} chunks needed, max ${chunkMax}).`);
    }

    for (let idx = 0; idx < totalChunks; idx += 1) {
      const chunkSecret = `${chunkPrefix}${idx + 1}`;
      const chunkValue = encodedSecretValue.slice(idx * chunkSize, (idx + 1) * chunkSize);
      await upsertEncryptedSecret(baseUrl, token, chunkSecret, chunkValue, publicKey);
      updatedSecrets.push(chunkSecret);
    }

    const deleteSingleRes = await githubRequest(`${baseUrl}/${encodeURIComponent(secretName)}`, token, {
      method: 'DELETE',
    });
    if (deleteSingleRes.status === 204) {
      deletedSecrets.push(secretName);
    } else if (deleteSingleRes.status !== 404) {
      const detail = deleteSingleRes.json?.message || deleteSingleRes.text || `HTTP ${deleteSingleRes.status}`;
      throw new Error(`Failed to delete old GitHub secret ${secretName}: ${detail}`);
    }
  }

  if (deleteChunked) {
    const firstChunkToDelete = mode === 'chunked' ? updatedSecrets.length + 1 : 1;
    for (let idx = firstChunkToDelete; idx <= chunkMax; idx += 1) {
      const chunkSecret = `${chunkPrefix}${idx}`;
      const deleteRes = await githubRequest(`${baseUrl}/${encodeURIComponent(chunkSecret)}`, token, {
        method: 'DELETE',
      });

      if (deleteRes.status === 204) {
        deletedSecrets.push(chunkSecret);
        continue;
      }

      if (deleteRes.status !== 404) {
        const detail = deleteRes.json?.message || deleteRes.text || `HTTP ${deleteRes.status}`;
        throw new Error(`Failed to delete old GitHub secret ${chunkSecret}: ${detail}`);
      }
    }
  }

  console.log(
    JSON.stringify({
      ok: true,
      mode,
      secret_name: secretName,
      source_file: sourceFile || '',
      source_value: sourceValue !== '' ? '[inline]' : '',
      updated_secrets: updatedSecrets,
      deleted_secrets: deletedSecrets,
    }),
  );
}

try {
  await main();
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
}
