<?php

class PublicTunnelManager
{
    private string $projectRoot;
    private string $statePath;
    private string $stdoutPath;
    private string $stderrPath;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__);
        $logsDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'logs';
        $this->statePath = $logsDir . DIRECTORY_SEPARATOR . 'public-url-tunnel-state.json';
        $this->stdoutPath = $logsDir . DIRECTORY_SEPARATOR . 'public-url-tunnel.out.log';
        $this->stderrPath = $logsDir . DIRECTORY_SEPARATOR . 'public-url-tunnel.err.log';
    }

    public function startTunnel(int $localPort = 80): array
    {
        return $this->runScript('start', $localPort);
    }

    public function stopTunnel(): array
    {
        return $this->runScript('stop', 80);
    }

    public function getStatus(): array
    {
        return $this->runScript('status', 80);
    }

    public function buildPublicBaseUrl(string $publicOrigin, string $basePath = ''): string
    {
        $publicOrigin = rtrim(trim($publicOrigin), '/');
        $basePath = trim(str_replace('\\', '/', $basePath));
        if ($basePath === '' || $basePath === '/') {
            return $publicOrigin;
        }

        return $publicOrigin . '/' . ltrim($basePath, '/');
    }

    private function runScript(string $action, int $localPort): array
    {
        $scriptPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'manage-public-url-tunnel.ps1';
        if (!is_file($scriptPath)) {
            return ['success' => false, 'error' => 'Public tunnel helper script is missing.'];
        }

        $powershell = $this->resolvePowerShellBinary();
        if ($powershell === null) {
            return ['success' => false, 'error' => 'PowerShell is required to manage public tunnel.'];
        }

        $command = [
            $powershell,
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $scriptPath,
            '-Action',
            $action,
            '-StatePath',
            $this->statePath,
            '-StdOutPath',
            $this->stdoutPath,
            '-StdErrPath',
            $this->stderrPath,
            '-LocalPort',
            (string)$localPort,
        ];

        $result = $this->runLocalProcess($command, $this->projectRoot);
        if (!$result['success']) {
            return $result;
        }

        $stdout = trim((string)($result['stdout'] ?? ''));
        $stderr = trim((string)($result['stderr'] ?? ''));
        if (($result['exit_code'] ?? 1) !== 0 && $stdout === '') {
            return [
                'success' => false,
                'error' => $stderr !== '' ? $stderr : 'Public tunnel helper failed.'
            ];
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'error' => $stderr !== '' ? $stderr : 'Public tunnel helper returned invalid output.'
            ];
        }

        if (empty($decoded['ok'])) {
            return [
                'success' => false,
                'error' => (string)($decoded['error'] ?? ($stderr !== '' ? $stderr : 'Public tunnel action failed.')),
                'details' => $decoded
            ];
        }

        return [
            'success' => true,
            'running' => !empty($decoded['running']),
            'reused' => !empty($decoded['reused']),
            'stopped' => !empty($decoded['stopped']),
            'public_origin' => trim((string)($decoded['public_origin'] ?? '')),
            'pid' => (int)($decoded['pid'] ?? 0),
            'stdout_path' => (string)($decoded['stdout_path'] ?? $this->stdoutPath),
            'stderr_path' => (string)($decoded['stderr_path'] ?? $this->stderrPath),
        ];
    }

    private function resolvePowerShellBinary(): ?string
    {
        foreach ([
            trim((string)(getenv('ComSpec') ?: '')),
            'powershell.exe',
            'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        ] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            if (stripos($candidate, 'cmd.exe') !== false) {
                continue;
            }
            if (is_file($candidate)) {
                return $candidate;
            }
            if (strcasecmp($candidate, 'powershell.exe') === 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function runLocalProcess(array $command, ?string $cwd = null, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            return ['success' => false, 'error' => 'proc_open is required to run local helper commands.'];
        }

        $parts = [];
        foreach ($command as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        if (empty($parts)) {
            return ['success' => false, 'error' => 'Local helper command is empty.'];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $commandString = implode(' ', array_map('escapeshellarg', $parts));
        $pipes = [];
        $process = @proc_open(
            $commandString,
            $descriptorSpec,
            $pipes,
            $cwd ?: $this->projectRoot,
            $this->buildProcessEnvironment($env)
        );

        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start local helper command.'];
        }

        fwrite($pipes[0], '');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'success' => true,
            'exit_code' => (int)$exitCode,
            'stdout' => (string)$stdout,
            'stderr' => (string)$stderr,
        ];
    }

    private function buildProcessEnvironment(array $overrides = []): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }

        foreach (['PATH', 'SystemRoot', 'ComSpec', 'PATHEXT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = (string)$value;
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && $key !== '' && (is_scalar($value) || $value === null)) {
                $env[$key] = $value === null ? '' : (string)$value;
            }
        }

        foreach ($overrides as $key => $value) {
            if (is_string($key) && $key !== '') {
                $env[$key] = (string)$value;
            }
        }

        return $env;
    }
}
