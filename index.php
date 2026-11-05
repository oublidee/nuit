<?php
declare(strict_types=1);
/* NUIT v1.0 — version fonctionnelle validée par l’utilisateur. Fichier autonome. Lancement : php -S 127.0.0.1:8787 index.php
 * Nécessite PHP 8.1+, yt-dlp et ffmpeg. Aucun export de cookies, aucune base de données.
 * Les préférences et l'historique sont conservés dans le navigateur (localStorage).
 */
ini_set('display_errors', '0');
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true) || !preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/D', $host)) {
    http_response_code(403); exit('Cette application fonctionne uniquement en local.');
}
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!in_array($requestPath, ['/', '/index.php'], true)) {
    http_response_code(404); exit('Page introuvable.');
}
$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)
    && !(($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate' && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document')) {
    http_response_code(403); exit('Requête externe refusée.');
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('NUITSESSID');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
$_SESSION['token'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token'];
session_write_close();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
function respond(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function binary(string $name): ?string {
    foreach (['/opt/homebrew/bin/', '/usr/local/bin/', '/usr/bin/'] as $prefix) {
        if (is_executable($prefix . $name)) return $prefix . $name;
    }
    return null;
}
function folder(string $path): string {
    if ($path === '' || strlen($path) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $path)) throw new RuntimeException('Chemin de dossier invalide.');
    if ($path === '~' || str_starts_with($path, '~/')) $path = (getenv('HOME') ?: '') . substr($path, 1);
    $real = realpath($path);
    if ($real === false || !is_dir($real) || !is_writable($real)) throw new RuntimeException('Choisis un dossier existant dans lequel tu peux écrire.');
    return $real;
}
function videoUrl(string $url): string {
    if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F]/', trim($url))) throw new RuntimeException('Lien YouTube invalide.');
    $parts = parse_url(trim($url));
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) throw new RuntimeException('Colle un lien YouTube HTTPS valide.');
    $domain = strtolower($parts['host'] ?? ''); $path = $parts['path'] ?? '';
    $id = null;
    if ($domain === 'youtu.be') $id = trim($path, '/');
    elseif (in_array($domain, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
        if ($path === '/watch') { parse_str($parts['query'] ?? '', $query); $id = $query['v'] ?? null; }
        elseif (preg_match('~^/(?:shorts|live|embed)/([A-Za-z0-9_-]{11})/?$~D', $path, $match)) $id = $match[1];
    }
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) throw new RuntimeException('Ce lien ne désigne pas une vidéo YouTube. Les playlists seules ne sont pas prises en charge.');
    return 'https://www.youtube.com/watch?v=' . $id;
}
function processLines(array $command, callable $onLine, int $seconds = 120, ?callable $onTick = null): int {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Impossible de démarrer le processus local.');
    $close = static function() use (&$process): void {
        if (is_resource($process)) {
            proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
            if ($status['running']) proc_terminate($process, 9);
            proc_close($process); $process = null;
        }
    };
    register_shutdown_function($close);
    stream_set_blocking($pipes[1], false);
    $deadline = microtime(true) + $seconds; $lastOutput = microtime(true); $buffer = ''; $processExit = null;
    try {
        while (true) {
            if (microtime(true) > $deadline || microtime(true) - $lastOutput > 300) throw new RuntimeException('Le processus local a dépassé le délai autorisé.');
            if (feof($pipes[1])) {
                $status = proc_get_status($process);
                if (!$status['running']) { $processExit = $status['exitcode']; break; }
                usleep(100000);
                if ($onTick) $onTick();
                continue;
            }
            $read = [$pipes[1]]; $write = null; $except = null;
            if (stream_select($read, $write, $except, 1) === false) throw new RuntimeException('Lecture du processus interrompue.');
            if ($read) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false) throw new RuntimeException('Erreur de lecture du processus.');
                if ($chunk !== '') $lastOutput = microtime(true);
                $buffer .= $chunk;
                if (strlen($buffer) > 65536) throw new RuntimeException('Le processus a renvoyé une ligne trop volumineuse.');
                while (($end = strpos($buffer, "\n")) !== false) {
                    $onLine(rtrim(substr($buffer, 0, $end), "\r")); $buffer = substr($buffer, $end + 1);
                }
            }
            if ($onTick) $onTick();
        }
        if ($buffer !== '') $onLine($buffer);
        fclose($pipes[1]);
        $exit = proc_close($process); $process = null;
        return $processExit !== null && $processExit >= 0 ? $processExit : $exit;
    } finally {
        if (is_resource($pipes[1])) fclose($pipes[1]);
        $close();
    }
}
function run(array $command): array {
    $output = '';
    $code = processLines($command, static function(string $line) use (&$output): void {
        $output .= $line . "\n";
        if (strlen($output) > 65536) throw new RuntimeException('Réponse locale trop volumineuse.');
    });
    return [$code, trim($output)];
}
function textInput(array $input, string $key, string $default = ''): string {
    $value = $input[$key] ?? $default;
    if (!is_string($value)) throw new RuntimeException('Valeur incorrecte pour : ' . $key);
    return $value;
}
function historyFile(array $item): array {
    $name = textInput($item, 'file');
    if (strlen($name) > 255 || basename($name) !== $name || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/', $name)
        || !preg_match('/ \[[A-Za-z0-9_-]{11}\]\.(mp4|mp3)$/D', $name)) throw new RuntimeException('Fichier refusé : seuls les MP4 et MP3 nommés par NUIT peuvent être mis à la corbeille.');
    $dir = folder(textInput($item, 'folder'));
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    clearstatcache(true, $path);
    if (is_link($path)) throw new RuntimeException('Les liens symboliques ne peuvent pas être supprimés depuis NUIT.');
    $stat = @lstat($path);
    if ($stat !== false && (!is_file($path) || realpath($path) !== $path)) throw new RuntimeException('Ce chemin ne désigne pas un fichier ordinaire du dossier choisi.');
    return ['file' => $name, 'folder' => $dir, 'stat' => $stat === false ? null : array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))];
}
function trashHistoryFiles(array $items, callable $move): array {
    $results = [];
    foreach ($items as $item) {
        try {
            $current = historyFile($item);
            if ($current['stat'] === null) $status = 'missing';
            else {
                if ($current['folder'] !== $item['folder'] || $item['stat'] === null || $current['stat'] !== $item['stat']) throw new RuntimeException('Le fichier a changé depuis la confirmation. Sélectionne-le à nouveau.');
                $move($item['folder'] . DIRECTORY_SEPARATOR . $item['file']);
                clearstatcache(true, $item['folder'] . DIRECTORY_SEPARATOR . $item['file']);
                if (file_exists($item['folder'] . DIRECTORY_SEPARATOR . $item['file'])) throw new RuntimeException('Le fichier est encore présent. Vérifie les autorisations du Finder.');
                $status = 'trashed';
            }
            $results[] = ['file' => $item['file'], 'folder' => $item['folder'], 'status' => $status];
        } catch (Throwable $error) {
            $results[] = ['file' => $item['file'], 'folder' => $item['folder'], 'status' => 'error', 'error' => $error->getMessage()];
        }
    }
    return $results;
}
$action = $_GET['action'] ?? '';
if ($action !== '') {
    if (!is_string($action)) respond(['error' => 'Action invalide.'], 400);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Méthode non autorisée.'], 405);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if (($fetchSite !== '' && $fetchSite !== 'same-origin') || ($origin !== '' && $origin !== 'http://' . $host) || !hash_equals($token, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['error' => 'Recharge la page pour renouveler la session.'], 403);
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') respond(['error' => 'Contenu JSON requis.'], 415);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) respond(['error' => 'Requête trop volumineuse.'], 413);
    $body = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($body) > 16384) respond(['error' => 'Requête trop volumineuse.'], 413);
    $input = json_decode($body, true, 16);
    if (!is_array($input) || !str_starts_with(ltrim($body), '{')) respond(['error' => 'Objet JSON requis.'], 400);
    try {
        if ($action === 'status') respond(['tools' => ['PHP' => PHP_VERSION_ID >= 80100, 'yt-dlp' => binary('yt-dlp') !== null, 'ffmpeg' => binary('ffmpeg') !== null], 'defaultFolder' => (getenv('HOME') ?: '') . '/Downloads']);
        if (in_array($action, ['choose', 'reveal', 'download', 'prepare-trash', 'trash'], true)) {
            // Verrou consultatif du fichier lui-même : aucun fichier auxiliaire à conserver.
            $operationLock = fopen(__FILE__, 'r');
            if (!$operationLock || !flock($operationLock, LOCK_EX | LOCK_NB)) respond(['error' => 'Une opération est déjà en cours. Réessaie après sa fin.'], 409);
        }
        if ($action === 'choose') {
            $script = 'POSIX path of (choose folder with prompt "Choisir le dossier de téléchargement NUIT")';
            $preferred = '';
            try { $preferred = folder(textInput($input, 'folder')); } catch (RuntimeException $ignored) {}
            $command = ['/usr/bin/osascript', '-e', $script];
            if ($preferred !== '') $command = ['/usr/bin/osascript', '-e', 'on run argv' . "\n" . 'return POSIX path of (choose folder with prompt "Choisir le dossier de téléchargement NUIT" default location (POSIX file (item 1 of argv)))' . "\nend run", $preferred];
            [$code, $path] = run($command);
            if ($code !== 0) respond(['cancelled' => true]);
            respond(['folder' => folder($path)]);
        }
        if ($action === 'validate') respond(['folder' => folder(textInput($input, 'folder'))]);
        if ($action === 'reveal') {
            [$code] = run(['/usr/bin/open', folder(textInput($input, 'folder'))]);
            if ($code !== 0) throw new RuntimeException('Impossible d’ouvrir ce dossier dans le Finder.');
            respond(['ok' => true]);
        }
        if ($action === 'prepare-trash') {
            $items = $input['items'] ?? null;
            if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 15) throw new RuntimeException('Sélectionne entre 1 et 15 téléchargements.');
            $files = [];
            foreach ($items as $item) {
                if (!is_array($item)) throw new RuntimeException('Sélection incorrecte.');
                $file = historyFile($item);
                $files[$file['folder'] . '/' . $file['file']] = $file;
            }
            $plan = ['id' => bin2hex(random_bytes(24)), 'expires' => time() + 600, 'items' => array_values($files)];
            session_start(); $_SESSION['trashPlan'] = $plan; session_write_close();
            respond(['plan' => $plan['id'], 'files' => array_map(static fn(array $file): array => ['file' => $file['file'], 'folder' => $file['folder'], 'missing' => $file['stat'] === null], $plan['items'])]);
        }
        if ($action === 'trash') {
            $id = textInput($input, 'plan');
            session_start();
            $plan = $_SESSION['trashPlan'] ?? null;
            if (!is_array($plan) || !hash_equals($plan['id'], $id) || $plan['expires'] < time()) {
                session_write_close(); respond(['error' => 'Confirmation expirée ou invalide. Rouvre la fenêtre de suppression.'], 409);
            }
            unset($_SESSION['trashPlan']); session_write_close();
            // Arguments séparés : le nom d’un fichier ne devient jamais du code AppleScript.
            $script = 'on run argv' . "\n" . 'tell application "Finder" to delete (POSIX file (item 1 of argv) as alias)' . "\nend run";
            $results = trashHistoryFiles($plan['items'], static function(string $path) use ($script): void {
                [$code] = run(['/usr/bin/osascript', '-e', $script, $path]);
                if ($code !== 0) throw new RuntimeException('Mise à la corbeille refusée. Autorise le contrôle du Finder demandé par macOS, puis réessaie.');
            });
            respond(['results' => $results]);
        }
        if ($action !== 'download') respond(['error' => 'Action inconnue.'], 404);
        $url = videoUrl(textInput($input, 'url'));
        $dir = folder(textInput($input, 'folder'));
        $freeSpace = disk_free_space($dir);
        if ($freeSpace !== false && $freeSpace < 536870912) throw new RuntimeException('Il reste moins de 512 Mo sur ce disque. Libère de l’espace avant de télécharger.');
        if (isset($input['cookies']) && !is_bool($input['cookies'])) throw new RuntimeException('Option de session Brave invalide.');
        $mode = $input['quality'] ?? '1080';
        if (!in_array($mode, ['1080', '2160', 'best', 'audio'], true)) throw new RuntimeException('Qualité inconnue.');
        $yt = binary('yt-dlp'); $ffmpeg = binary('ffmpeg');
        if (!$yt || !$ffmpeg) throw new RuntimeException('Installe les outils manquants avec : brew install yt-dlp ffmpeg');
        $command = [$yt, '--ignore-config', '--no-plugin-dirs', '--no-remote-components', '--downloader', 'native', '--match-filters', '!is_live', '--no-playlist', '--no-simulate', '--no-overwrites', '--newline', '--no-color', '--progress', '--socket-timeout', '25', '--retries', '3', '--fragment-retries', '3', '--ffmpeg-location', dirname($ffmpeg), '--paths', $dir, '-o', '%(title).180B [%(id)s].%(ext)s', '--print', 'before_dl:NUIT_TITLE:%(title)j', '--print', 'after_move:NUIT_FILE:%(filepath)j', '--progress-template', 'download:NUIT_PROGRESS:%(progress)j'];
        if (binary('node')) array_push($command, '--js-runtimes', 'node:' . binary('node'));
        if (($input['cookies'] ?? true) === true) {
            $profile = trim(textInput($input, 'profile'));
            if ($profile !== '' && !preg_match('/^(Default|Profile [0-9]{1,3})$/D', $profile)) throw new RuntimeException('Profil Brave invalide : utilise Default ou Profile 1, Profile 2…');
            array_push($command, '--cookies-from-browser', 'brave' . ($profile !== '' ? ':' . $profile : ''));
        }
        if ($mode === 'audio') array_push($command, '-f', 'bestaudio/best', '-x', '--audio-format', 'mp3', '--audio-quality', '0');
        else {
            $cap = $mode === 'best' ? '' : '[height<=' . $mode . ']';
            array_push($command, '-f', 'bv*' . $cap . '+ba/b' . $cap, '--merge-output-format', 'mp4', '--remux-video', 'mp4');
        }
        array_push($command, '--', $url);
        set_time_limit(0);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) ob_end_flush();
        $emit = static function(array $event): void {
            echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n"; flush();
        };
        $emit(['type' => 'start', 'message' => 'Connexion à YouTube…']);
        $errors = []; $finalFile = null;
        $heartbeat = microtime(true);
        $exit = processLines($command, static function(string $line) use ($emit, &$errors, &$finalFile): void {
            $line = trim($line);
            if (str_starts_with($line, 'NUIT_PROGRESS:')) {
                $progress = json_decode(substr($line, 14), true);
                if (is_array($progress)) $emit(['type' => 'progress', 'data' => array_intersect_key($progress, array_flip(['status', 'downloaded_bytes', 'total_bytes', 'total_bytes_estimate', 'speed', 'eta']))]);
            } elseif (str_starts_with($line, 'NUIT_TITLE:')) $emit(['type' => 'title', 'title' => json_decode(substr($line, 11), true)]);
            elseif (str_starts_with($line, 'NUIT_FILE:')) $finalFile = json_decode(substr($line, 10), true);
            elseif ($line !== '') {
                $errors[] = $line; $errors = array_slice($errors, -14);
                $emit(['type' => 'log', 'message' => $line]);
            }
        }, 21600, static function() use ($emit, &$heartbeat, $dir): void {
            if (microtime(true) - $heartbeat >= 15) {
                $freeSpace = disk_free_space($dir);
                if ($freeSpace !== false && $freeSpace < 268435456) throw new RuntimeException('Transfert arrêté : le disque dispose de moins de 256 Mo libres. Les fichiers partiels sont conservés.');
                $emit(['type' => 'heartbeat']); $heartbeat = microtime(true);
            }
        });
        $resolvedFile = is_string($finalFile) ? realpath($finalFile) : false;
        if ($exit !== 0 || !$resolvedFile || dirname($resolvedFile) !== $dir || !is_file($resolvedFile)) $emit(['type' => 'error', 'message' => implode("\n", $errors) ?: 'Le téléchargement n’a pas produit de fichier dans le dossier choisi.']);
        else $emit(['type' => 'done', 'file' => basename($finalFile), 'folder' => $dir]);
        exit;
    } catch (Throwable $error) {
        if (headers_sent()) { echo json_encode(['type' => 'error', 'message' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE) . "\n"; exit; }
        respond(['error' => $error->getMessage()], 400);
    }
}
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark"><title>NUIT 1.0 — YouTube, à emporter.</title>
<style nonce="<?= $nonce ?>">
:root{color-scheme:dark;--bg:#0b0e13;--panel:#12171f;--line:#252c37;--muted:#8c98ab;--text:#eff3fa;--lime:#d3fb78;--red:#ff929c}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 75% 0%,#1c26324d,transparent 45%),var(--bg);color:var(--text);font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}button,input,select{font:inherit}button,a,input,select{ -webkit-tap-highlight-color:transparent}button{cursor:pointer}button:disabled{cursor:wait;opacity:.5}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{outline:2px solid var(--lime);outline-offset:4px}a{color:inherit;text-decoration:none}header{max-width:1240px;margin:auto;padding:28px 40px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line)}.brand{display:flex;align-items:center;gap:12px;font-size:21px;font-weight:800;letter-spacing:5px}.mark{width:32px;height:32px;border:1px solid var(--lime);border-radius:10px;display:grid;place-items:center;color:var(--lime);font-size:22px;letter-spacing:0}.top-meta{display:flex;gap:24px;align-items:center;color:var(--muted);font-size:12px}.dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--lime);margin-right:8px;box-shadow:0 0 12px #d3fb7855}.pill{border:1px solid var(--line);border-radius:30px;padding:7px 12px;font-size:11px;letter-spacing:1px}main{max-width:1160px;margin:auto;padding:64px 20px 36px}.eyebrow{color:var(--lime);font:11px/1.5 ui-monospace,monospace;letter-spacing:2px;text-transform:uppercase}.hero{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:38px;gap:24px}h1{font-size:clamp(34px,4.4vw,55px);font-weight:550;letter-spacing:-2.5px;line-height:1.12;margin:14px 0 16px}h1 span{color:var(--muted)}p{margin:0;color:var(--muted)}.button{display:inline-flex;align-items:center;justify-content:center;gap:10px;border:1px solid var(--line);background:#19202a;color:var(--text);border-radius:11px;padding:12px 18px;font-weight:550;transition:background .2s,border-color .2s}.button:hover{background:#252e3a;border-color:#596371}.button.primary{background:var(--lime);color:#17220a;border-color:var(--lime);font-weight:700}.button.primary:hover{background:#e0ffa3}.layout{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:22px;align-items:start}.card{background:linear-gradient(135deg,#171e27aa,#12171f);border:1px solid var(--line);border-radius:18px;overflow:hidden}.card-head{padding:21px 25px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}.card-head h2{font-size:15px;font-weight:600;margin:0}.card-head span{font:11px ui-monospace,monospace;color:var(--muted)}.form-body{padding:26px}.field{margin-bottom:24px}label,.label{display:block;font-size:12px;font-weight:600;margin-bottom:9px;color:#c8d1df}input[type=url],input[type=text],select{width:100%;border:1px solid #303948;background:#0c1118;border-radius:10px;padding:13px 14px;color:var(--text);min-width:0}input::placeholder{color:#68758a}input:focus,select:focus{border-color:var(--lime);outline:none}.input-wrap{display:flex;gap:9px}.input-wrap input{flex:1}.hint{font-size:11px;line-height:1.7;color:var(--muted);margin-top:8px}.row{display:grid;grid-template-columns:1fr 1fr;gap:18px}.dest{border:1px solid var(--line);padding:15px;border-radius:12px;background:#0c111880}.dest-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}.dest-top strong{font-size:12px;font-weight:500}.small{padding:7px 11px;font-size:12px;border-radius:8px}.remember{color:var(--lime);font-size:10px}.submit{width:100%;margin-top:24px;padding:15px}.aside{display:grid;gap:18px}.aside-body{padding:23px}.step{display:flex;gap:13px;margin-bottom:22px}.step:last-child{margin:0}.num{flex:none;width:25px;height:25px;border:1px solid #384143;border-radius:8px;display:grid;place-items:center;color:var(--lime);font:11px ui-monospace,monospace}.step strong{font-size:12px;font-weight:600;display:block;margin-bottom:3px}.step p{font-size:12px;line-height:1.7}.tools{display:flex;flex-wrap:wrap;gap:7px;margin:15px 0}.tool{font:10px ui-monospace,monospace;border:1px solid var(--line);border-radius:6px;padding:5px 8px;color:var(--muted)}.tool.ok{color:var(--lime)}.tool.bad{color:var(--red)}details{margin-top:20px;border-top:1px solid var(--line);padding-top:15px}summary{cursor:pointer;font-size:12px;color:var(--muted)}.advanced{padding-top:16px}.check{display:flex;align-items:center;gap:9px;margin-bottom:12px;font-weight:400}input[type=checkbox]{accent-color:var(--lime);width:16px;height:16px}.notice{border:1px solid #53622f;background:#d3fb7809;border-radius:10px;padding:12px;font-size:12px;color:#cddab3;margin-top:16px}.error{color:var(--red);border-color:#70404b;background:#ff929c08;white-space:pre-wrap;overflow-wrap:anywhere}.progress-card{padding:23px;margin-top:20px}.progress-head{display:flex;justify-content:space-between;gap:15px}.progress-title{font-size:13px;overflow-wrap:anywhere}.percent{font:20px ui-monospace,monospace;color:var(--lime)}progress{appearance:none;width:100%;height:6px;display:block;border:0;border-radius:8px;margin:16px 0;background:#28313f;overflow:hidden}progress::-webkit-progress-bar{background:#28313f}progress::-webkit-progress-value{background:var(--lime);border-radius:8px}progress::-moz-progress-bar{background:var(--lime)}.progress-meta{display:flex;justify-content:space-between;gap:12px;font-size:11px;color:var(--muted)}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:11px/1.7 ui-monospace,monospace;max-height:200px;overflow:auto;color:var(--muted)}.history{margin-top:28px}.history-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.history-head h2{font-size:13px;font-weight:500}.text-btn{background:none;border:0;color:var(--muted);font-size:11px;padding:6px}.history-empty{border:1px dashed var(--line);border-radius:12px;text-align:center;padding:25px;color:var(--muted);font-size:12px}.history-item{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 0;border-bottom:1px solid var(--line)}.file-name{font-size:12px;overflow-wrap:anywhere}.file-sub{font-size:10px;color:var(--muted);margin-top:3px;overflow-wrap:anywhere}.done-icon{color:var(--lime);margin-right:8px}footer{display:flex;justify-content:space-between;border-top:1px solid var(--line);padding-top:20px;margin-top:46px;color:#6f7b8f;font-size:10px;letter-spacing:.4px}.footer-mark{color:#a7b492}#toast{position:fixed;bottom:25px;left:50%;transform:translateX(-50%);padding:12px 20px;background:#202a36;border:1px solid #485668;border-radius:12px;font-size:12px;z-index:10;max-width:90vw}[hidden]{display:none!important}@media(max-width:900px){.layout{grid-template-columns:1fr}.aside{grid-template-columns:1fr 1fr}.hero{align-items:start}.top-meta .pill{display:none}}@media(max-width:600px){header{padding:20px}.top-meta{font-size:10px}main{padding-top:36px}.hero{display:block}.hero .button{margin-top:22px}.aside{grid-template-columns:1fr}.row{grid-template-columns:1fr}.form-body{padding:20px}.card-head{padding:18px 20px}footer{gap:18px;align-items:start}.brand{font-size:18px}.input-wrap .button{padding:10px}.progress-meta{flex-wrap:wrap}}@media(prefers-reduced-motion:reduce){*{transition:none!important}}
.folder-controls{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.folder-controls .hint{flex-basis:100%}.manage-button{margin-right:5px}.system-details{margin:0;border:0;padding:0}.setup-actions{display:flex;gap:8px;flex-wrap:wrap}.history-head{gap:12px}.history-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;border:1px solid var(--line);border-radius:10px;padding:11px 13px;margin-bottom:6px}.select-all{margin:0;font-size:11px}.history-actions>span{font-size:11px;color:var(--muted);margin-left:auto}.history-check{flex:none;width:18px;height:18px}.history-item .history-text{flex:1;min-width:0}.history-item .button{flex:none}.history-item.selected{background:#d3fb7808}.history-item:has(input:focus-visible){outline:1px solid var(--lime)}dialog{width:min(540px,calc(100% - 32px));max-height:85dvh;overflow:auto;background:var(--panel);color:var(--text);border:1px solid #3b4656;border-radius:18px;padding:25px;box-shadow:0 24px 100px #0009}dialog::backdrop{background:#05080dcc;backdrop-filter:blur(5px)}.delete-heading{display:flex;gap:15px;align-items:start;justify-content:space-between}.delete-heading h2{font-size:18px;margin:0 0 12px}.delete-heading button{font-size:16px;line-height:1}.delete-heading+p{font-size:12px}#delete-list{max-height:160px;overflow:auto;padding-left:18px;color:#c8d1df;font-size:12px;overflow-wrap:anywhere}#delete-list li{margin-bottom:8px}#delete-list small{display:block;color:var(--muted)}#delete-options{padding:0;margin:20px 0 0;border:0}#delete-options legend{font-size:12px;color:var(--muted);margin-bottom:10px}.delete-choice{display:flex;gap:12px;align-items:flex-start;padding:13px;border:1px solid var(--line);border-radius:10px;cursor:pointer}.delete-choice:has(input:checked){border-color:var(--lime);background:#d3fb7808}.delete-choice input{accent-color:var(--lime);margin-top:4px}.delete-choice strong{display:block;font-size:12px}.delete-choice small{display:block;font-size:11px;color:var(--muted);margin-top:3px}.delete-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:20px}.button.danger{background:#ff929c;color:#291015;border-color:#ff929c}#history-error{color:var(--red);white-space:pre-wrap}.profile-label{margin-top:14px}.aside-copy{font-size:12px}.aside-note{margin-top:16px}
</style>
</head>
<body>
<header><a class="brand" href="./" aria-label="NUIT accueil"><span class="mark" aria-hidden="true">↘</span>NUIT</a><div class="top-meta"><span><i class="dot"></i>Espace local</span><span class="pill">YOUTUBE → MAC · v1.0</span></div></header>
<main>
<section class="hero"><div><div class="eyebrow">Ta bibliothèque. Tes règles.</div><h1>YouTube,<br><span>à emporter.</span></h1><p>Un lien, un dossier. Le reste se passe ici.</p></div><a class="button" href="https://www.youtube.com/" target="_blank" rel="noopener noreferrer">Explorer YouTube <span aria-hidden="true">↗</span></a></section>
<div class="layout"><div>
<section class="card"><div class="card-head"><h2>Nouveau téléchargement</h2><span>01 / TRANSFERT</span></div>
<form id="download-form" class="form-body">
<div class="field"><label for="url">Lien de la vidéo</label><input type="url" id="url" name="url" required placeholder="https://www.youtube.com/watch?v=…" autocomplete="off"><p class="hint">Vidéos, Shorts et rediffusions · Hors directs en cours</p></div>
<div class="row"><div class="field"><label for="format">Format</label><select id="format"><option value="video">Vidéo · MP4</option><option value="audio">Audio · MP3</option></select></div><div class="field"><label for="quality">Qualité maximale</label><select id="quality"><option value="1080">Full HD · 1080p</option><option value="2160">Ultra HD · 4K</option><option value="best">Meilleure disponible</option></select></div></div>
<div class="dest"><div class="dest-top"><strong>↳ Dossier de destination</strong><div><button class="button small manage-button" type="button" id="manage-folder" aria-expanded="false" aria-controls="folder-controls">Gérer</button><button class="button small" type="button" id="choose">Choisir…</button></div></div><label for="folder" class="label">Chemin sur ton Mac</label><input type="text" id="folder" required placeholder="/Users/ton-nom/Downloads" spellcheck="false"><p class="hint" id="folder-hint">Le dossier sera mémorisé dans ce navigateur.</p><div class="folder-controls" id="folder-controls" hidden><button class="button small" type="button" id="save-folder">Vérifier et mémoriser</button><button class="button small" type="button" id="open-folder">Ouvrir dans le Finder ↗</button><button class="button small" type="button" id="reset-folder">Utiliser Téléchargements</button><p class="hint">Modifie le chemin ci-dessus ou choisis un autre dossier. Aucun fichier ne sera déplacé ni effacé.</p></div></div>
<details><summary>Compte Brave et options</summary><div class="advanced"><label class="check"><input type="checkbox" id="cookies" checked>Utiliser ma session YouTube dans Brave</label><p class="hint">macOS peut demander l’accès au trousseau. Aucun mot de passe à saisir ici ; les cookies ne sont pas exportés.</p><label for="profile" class="profile-label">Profil Brave (facultatif)</label><input type="text" id="profile" placeholder="Automatique · ou Default, Profile 1…" pattern="Default|Profile [0-9]{1,3}"><p class="hint">Si tu as plusieurs profils, le dernier dossier du « Chemin du profil » dans brave://version indique lequel utiliser.</p></div></details>
<button class="button primary submit" id="download" type="submit"><span aria-hidden="true">↓</span> Télécharger sur mon Mac</button>
<div id="error" role="alert" class="notice error" hidden></div>
</form></section>
<section id="transfer" class="card progress-card" aria-label="Progression du téléchargement" hidden><div class="progress-head"><div class="progress-title" id="transfer-title">Connexion à YouTube…</div><span class="percent" id="percent">…</span></div><progress id="progress" max="100" aria-label="Progression"></progress><div class="progress-meta"><span id="stage" role="status" aria-live="polite">Préparation</span><span id="metrics"></span></div><details><summary>Journal du transfert</summary><pre id="logs"></pre></details></section>
<section class="history"><div class="history-head"><h2>Derniers téléchargements <span id="count"></span></h2><button class="text-btn" id="clear" type="button">Tout effacer…</button></div><div id="history-actions" class="history-actions" hidden><label class="check select-all"><input type="checkbox" id="select-all">Tout sélectionner</label><span id="selection-count" role="status"></span><button class="button small" id="delete-selected" type="button" disabled>Effacer la sélection…</button></div><div id="history"></div><p class="hint" id="history-error" role="alert" hidden></p></section>
</div><aside class="aside">
<section class="card"><div class="card-head"><h2>Ton flux, en trois étapes</h2><span>↗</span></div><div class="aside-body"><div class="step"><span class="num">01</span><div><strong>Explore avec ton compte</strong><p>Ouvre YouTube dans Brave et navigue comme d’habitude.</p></div></div><div class="step"><span class="num">02</span><div><strong>Copie le lien</strong><p>Colle l’adresse de ta vidéo ici. Choisis le format et la qualité.</p></div></div><div class="step"><span class="num">03</span><div><strong>Garde-la sur ton Mac</strong><p>Choisis ton dossier une fois. Retrouve-le à ta prochaine visite.</p></div></div></div></section>
<section class="card"><div class="aside-body"><div class="eyebrow">Directement sur ton Mac</div><p class="aside-copy">Le transfert s’effectue directement sur ton Mac. Garde cette page ouverte jusqu’à la fin.</p><details class="system-details"><summary>État des outils et installation</summary><div id="tools" class="tools" aria-live="polite"></div><button type="button" class="button small" id="check-tools">Vérifier à nouveau</button><p class="hint">Vérification automatique et silencieuse à chaque ouverture. PHP est nécessaire au démarrage de la page ; s’il manque, installe-le avec <code>brew install php</code> dans Terminal.</p></details><div id="setup" class="notice" role="status" hidden><p id="setup-message"></p><pre id="install-command"></pre><div class="setup-actions"><button type="button" class="button small" id="copy-install">Copier la commande</button></div><p class="hint">À exécuter dans Terminal avec Homebrew, puis clique sur « Vérifier à nouveau ». Aucune installation ne se lance sans ton intervention.</p></div><p class="hint aside-note">La qualité dépend de la vidéo source. Les pistes vidéo et audio sont téléchargées puis assemblées.</p></div></section>
</aside></div>
<footer><span>NUIT <span class="footer-mark">/ LOCAL FIRST</span></span><span>Télécharge les contenus pour lesquels tu disposes des droits nécessaires.</span></footer>
</main>
<dialog id="delete-dialog" aria-labelledby="delete-title" aria-describedby="delete-description"><div class="delete-heading"><h2 id="delete-title">Effacer les téléchargements sélectionnés</h2><button type="button" id="delete-close" class="text-btn" aria-label="Fermer la fenêtre">✕</button></div><p id="delete-description"></p><ul id="delete-list"></ul><fieldset id="delete-options"><legend>Que souhaites-tu effacer ?</legend><label class="delete-choice"><input type="radio" name="delete-mode" id="delete-history" value="history" checked><span><strong>L’historique uniquement</strong><small>Les fichiers restent dans leur dossier.</small></span></label><label class="delete-choice"><input type="radio" name="delete-mode" id="delete-files" value="files"><span><strong>Les fichiers et l’historique</strong><small>Les fichiers seront placés dans la Corbeille du Mac.</small></span></label></fieldset><p id="delete-note" class="hint" role="status"></p><p id="delete-error" class="notice error" role="alert" hidden></p><div class="delete-actions"><button class="button small" type="button" id="delete-cancel">Annuler</button><button class="button primary small" type="button" id="delete-confirm">Effacer de l’historique</button></div></dialog>
<div id="toast" role="status" hidden></div>
<script nonce="<?= $nonce ?>">
'use strict';
const TOKEN=<?= json_encode($token) ?>;
const $=id=>document.getElementById(id);
const storage={get(key,fallback){try{return JSON.parse(localStorage.getItem('nuit.'+key))??fallback}catch{return fallback}},set(key,value){try{localStorage.setItem('nuit.'+key,JSON.stringify(value));return true}catch{return false}}};
function readHistory(fallback=[]){const items=storage.get('history',fallback);return(Array.isArray(items)?items:fallback).filter(x=>x&&typeof x.file==='string'&&typeof x.folder==='string'&&typeof x.date==='string').slice(0,15)}
let history=readHistory();
let busy=false,ready=false,finished=false,currentTitle='',toastTimer,defaultFolder='';
const selectedHistory=new Set();
let pendingDelete=[],deletePlan=null,deletePreparing=false,deleteWorking=false,deleteRevision=0;
function toast(message){$('toast').textContent=message;$('toast').hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>$('toast').hidden=true,4500)}
function showError(message){$('error').textContent=message;$('error').hidden=false}
async function api(action,data={}){const response=await fetch('?action='+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':TOKEN},body:JSON.stringify(data)});const result=await response.json();if(!response.ok||result.error)throw new Error(result.error||'Erreur du service local.');return result}
function rememberFolder(){const ok=storage.set('folder',$('folder').value.trim());$('folder-hint').textContent=ok?'✓ Dossier mémorisé pour la prochaine visite.':'Le navigateur bloque la mémorisation. Autorise le stockage pour conserver le dossier.';$('folder-hint').classList.toggle('remember',ok)}
function saveOptions(){storage.set('options',{cookies:$('cookies').checked,profile:$('profile').value,format:$('format').value,quality:$('quality').value})}
function updateFormat(){$('quality').disabled=busy||$('format').value==='audio'}
function lock(value){busy=value;for(const id of ['url','folder','choose','format','quality','cookies','profile','manage-folder','save-folder','open-folder','reset-folder','check-tools'])$(id).disabled=value;updateFormat();$('download').disabled=value||!ready;$('download').textContent=value?'Opération en cours…':'↓ Télécharger sur mon Mac';updateHistoryControls()}
const historyId=item=>JSON.stringify([item.folder,item.file,item.date]);
const fileId=item=>JSON.stringify([item.folder,item.file]);
function updateHistoryControls(){
 const ids=new Set(history.map(historyId));for(const id of selectedHistory)if(!ids.has(id))selectedHistory.delete(id);
 const count=history.filter(item=>selectedHistory.has(historyId(item))).length;
 $('selection-count').textContent=count?count+' sélectionné'+(count>1?'s':''):'';
 $('select-all').checked=history.length>0&&count===history.length;$('select-all').indeterminate=count>0&&count<history.length;
 $('select-all').disabled=busy||deleteWorking;$('clear').disabled=busy||deleteWorking;$('delete-selected').disabled=busy||deleteWorking||count===0;
 for(const checkbox of $('history').querySelectorAll('input[type=checkbox]')){checkbox.checked=selectedHistory.has(checkbox.value);checkbox.disabled=busy||deleteWorking;checkbox.closest('.history-item').classList.toggle('selected',checkbox.checked)}
}
function renderHistory(){
 const root=$('history');root.replaceChildren();$('count').textContent=history.length?'· '+history.length:'';$('clear').hidden=!history.length;$('history-actions').hidden=!history.length;
 if(!history.length){const empty=document.createElement('div');empty.className='history-empty';empty.textContent='Tes prochains téléchargements apparaîtront ici.';root.append(empty);updateHistoryControls();return}
 for(const item of history){
  const row=document.createElement('div');row.className='history-item';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.className='history-check';checkbox.value=historyId(item);checkbox.setAttribute('aria-label','Sélectionner '+item.file);checkbox.addEventListener('change',()=>{if(checkbox.checked)selectedHistory.add(checkbox.value);else selectedHistory.delete(checkbox.value);updateHistoryControls()});
  const text=document.createElement('div');text.className='history-text';const title=document.createElement('div');title.className='file-name';title.textContent='✓ '+item.file;const sub=document.createElement('div');sub.className='file-sub';const date=new Date(item.date);sub.textContent=(isNaN(date)?'':date.toLocaleString('fr-FR',{dateStyle:'short',timeStyle:'short'})+' · ')+item.folder;text.append(title,sub);
  const open=document.createElement('button');open.type='button';open.className='button small';open.textContent='Dossier ↗';open.addEventListener('click',()=>api('reveal',{folder:item.folder}).catch(e=>toast(e.message)));row.append(checkbox,text,open);root.append(row);
 }
 updateHistoryControls();
}
function updateDeleteConfirm(){const files=$('delete-files').checked;$('delete-confirm').textContent=deleteWorking?'Opération en cours…':files?'Mettre à la corbeille et effacer':'Effacer de l’historique';$('delete-confirm').disabled=deleteWorking||deletePreparing||(files&&!deletePlan);$('delete-confirm').classList.toggle('danger',files);$('delete-options').disabled=deleteWorking;$('delete-cancel').disabled=deleteWorking;$('delete-close').disabled=deleteWorking}
function openDelete(items){
 if(busy||deleteWorking||!items.length)return;pendingDelete=[...items];deletePlan=null;deletePreparing=false;deleteRevision++;$('delete-history').checked=true;$('delete-error').hidden=true;$('history-error').hidden=true;$('delete-note').textContent='Cette action concerne uniquement les éléments affichés ci-dessus.';
 $('delete-description').textContent=items.length+' entrée'+(items.length>1?'s sélectionnées.':' sélectionnée.');$('delete-list').replaceChildren();
 for(const item of items){const li=document.createElement('li');li.textContent=item.file;const path=document.createElement('small');path.textContent=item.folder;li.append(path);$('delete-list').append(li)}
 updateDeleteConfirm();$('delete-dialog').showModal();
}
async function prepareDelete(){
 const revision=++deleteRevision;deletePlan=null;$('delete-error').hidden=true;
 if(!$('delete-files').checked){deletePreparing=false;$('delete-note').textContent='Les fichiers restent sur ton Mac.';updateDeleteConfirm();return}
 deletePreparing=true;$('delete-note').textContent='Vérification des fichiers sélectionnés…';updateDeleteConfirm();
 try{const result=await api('prepare-trash',{items:pendingDelete.map(({file,folder})=>({file,folder}))});if(revision!==deleteRevision)return;deletePlan=result.plan;const missing=result.files.filter(item=>item.missing).length;$('delete-note').textContent='Les fichiers seront restaurables depuis la Corbeille du Mac.'+(missing?' '+missing+' fichier(s) déjà absent(s) : seules leurs entrées seront effacées.':'')}
 catch(e){if(revision===deleteRevision){$('delete-error').textContent=e.message;$('delete-error').hidden=false;$('delete-note').textContent='Tu peux choisir « historique uniquement » pour conserver les fichiers.'}}
 finally{if(revision===deleteRevision){deletePreparing=false;updateDeleteConfirm()}}
}
function closeDelete(){if(!deleteWorking)$('delete-dialog').close()}
async function confirmDelete(){
 if(deleteWorking||deletePreparing||!pendingDelete.length)return;const files=$('delete-files').checked;if(files&&!deletePlan)return;
 deleteWorking=true;updateDeleteConfirm();lock(true);
 try{
  if(!files){const ids=new Set(pendingDelete.map(historyId));const next=readHistory(history).filter(item=>!ids.has(historyId(item)));if(!storage.set('history',next))throw new Error('Le navigateur refuse l’enregistrement. L’historique est conservé.');history=next;toast('Entrées effacées. Les fichiers sont conservés.');$('delete-dialog').close()}
  else{
   const result=await api('trash',{plan:deletePlan});deletePlan=null;
   const removed=new Set(result.results.filter(item=>item.status!=='error').map(fileId));const failures=result.results.filter(item=>item.status==='error');history=readHistory(history).filter(item=>!removed.has(fileId(item)));const saved=storage.set('history',history);const count=result.results.filter(item=>item.status==='trashed').length;
   if(!saved||failures.length){$('history-error').textContent=[saved?'':'Le navigateur refuse de mémoriser le nouvel historique. Les fichiers mis à la corbeille restent restaurables.',...failures.map(item=>item.file+' : '+item.error)].filter(Boolean).join('\n');$('history-error').hidden=false}
   toast(count?count+' fichier(s) placé(s) dans la Corbeille.':removed.size?'Entrées de fichiers déjà absents effacées.':'Aucun fichier déplacé. Consulte le message sous l’historique.');$('delete-dialog').close();
  }
  selectedHistory.clear();renderHistory();
 }catch(e){$('delete-error').textContent=e.message;$('delete-error').hidden=false;if(files){deletePlan=null;$('delete-note').textContent='Pour réessayer, sélectionne « historique uniquement », puis à nouveau « fichiers et historique ».'}}
 finally{deleteWorking=false;lock(false);updateHistoryControls();updateDeleteConfirm()}
}
const bytes=value=>{if(!Number.isFinite(value)||value<=0)return '';const units=['o','Ko','Mo','Go'];const power=Math.min(3,Math.floor(Math.log(value)/Math.log(1024)));return(value/1024**power).toLocaleString('fr-FR',{maximumFractionDigits:1})+' '+units[power]};
function event(data){
 if(data.type==='title'&&typeof data.title==='string'){currentTitle=data.title;$('transfer-title').textContent=data.title}
 if(data.type==='log'){$('logs').textContent=($('logs').textContent+data.message+'\n').split('\n').slice(-70).join('\n');$('logs').scrollTop=$('logs').scrollHeight;if(/Merger|ExtractAudio|VideoRemuxer/.test(data.message)){$('stage').textContent='Finalisation du fichier…';$('progress').removeAttribute('value');$('percent').textContent='…'}}
 if(data.type==='progress'){const p=data.data,total=p.total_bytes||p.total_bytes_estimate,done=p.downloaded_bytes||0;if(total>0){const percent=Math.max(0,Math.min(100,done/total*100));$('progress').value=percent;$('percent').textContent=Math.floor(percent)+' %'}else{$('progress').removeAttribute('value');$('percent').textContent='…'}$('stage').textContent=p.status==='finished'?'Piste reçue · assemblage ou piste suivante…':'Téléchargement d’une piste…';$('metrics').textContent=[bytes(done),p.speed?bytes(p.speed)+'/s':'',Number.isFinite(p.eta)?'~ '+Math.ceil(p.eta)+' s':''].filter(Boolean).join(' · ')}
 if(data.type==='error'){finished=true;$('stage').textContent='Transfert interrompu';$('percent').textContent='!';$('progress').value=0;showError(data.message+'\n\nSi Brave est verrouillé ou si le trousseau refuse l’accès, ouvre Brave et autorise l’accès demandé par macOS. Pour une vidéo publique, tu peux aussi désactiver la session Brave dans les options.')}
 if(data.type==='done'){finished=true;$('stage').textContent='Enregistré sur ton Mac';$('percent').textContent='100 %';$('progress').value=100;$('metrics').textContent='Terminé';$('transfer-title').textContent=data.file;history=readHistory(history);history.unshift({file:data.file,folder:data.folder,date:new Date().toISOString()});history=history.slice(0,15);storage.set('history',history);renderHistory();toast('Fichier enregistré dans ton dossier.')}
}
$('folder').value=storage.get('folder','');
const options=storage.get('options',{});if(options&&typeof options==='object'){if(typeof options.cookies==='boolean')$('cookies').checked=options.cookies;if(typeof options.profile==='string')$('profile').value=options.profile;if(['video','audio'].includes(options.format))$('format').value=options.format;if(['1080','2160','best'].includes(options.quality))$('quality').value=options.quality}
updateFormat();renderHistory();
$('folder').addEventListener('change',rememberFolder);
for(const id of ['cookies','profile','format','quality'])$(id).addEventListener('change',()=>{saveOptions();updateFormat()});
$('choose').addEventListener('click',async()=>{const button=$('choose');button.disabled=true;button.textContent='Sélection…';try{const result=await api('choose',{folder:$('folder').value.trim()});if(!result.cancelled){$('folder').value=result.folder;rememberFolder()}}catch(e){showError(e.message)}finally{button.disabled=false;button.textContent='Choisir…'}});
$('clear').addEventListener('click',()=>openDelete(history));
$('select-all').addEventListener('change',()=>{selectedHistory.clear();if($('select-all').checked)for(const item of history)selectedHistory.add(historyId(item));updateHistoryControls()});
$('delete-selected').addEventListener('click',()=>openDelete(history.filter(item=>selectedHistory.has(historyId(item)))));
for(const id of ['delete-history','delete-files'])$(id).addEventListener('change',prepareDelete);
$('delete-confirm').addEventListener('click',confirmDelete);
$('delete-close').addEventListener('click',closeDelete);$('delete-cancel').addEventListener('click',closeDelete);
$('delete-dialog').addEventListener('cancel',e=>{if(deleteWorking)e.preventDefault()});
$('delete-dialog').addEventListener('close',()=>{deleteRevision++;pendingDelete=[];deletePlan=null;deletePreparing=false});
$('download-form').addEventListener('submit',async e=>{e.preventDefault();if(busy||!ready)return;$('error').hidden=true;try{const validated=await api('validate',{folder:$('folder').value.trim()});$('folder').value=validated.folder;rememberFolder()}catch(error){showError(error.message);return}saveOptions();lock(true);finished=false;currentTitle='';$('transfer').hidden=false;$('transfer-title').textContent='Connexion à YouTube…';$('stage').textContent=$('cookies').checked?'Lecture de la session Brave…':'Préparation…';$('metrics').textContent='';$('logs').textContent='';$('percent').textContent='…';$('progress').removeAttribute('value');try{const response=await fetch('?action=download',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':TOKEN},body:JSON.stringify({url:$('url').value.trim(),folder:$('folder').value,quality:$('format').value==='audio'?'audio':$('quality').value,cookies:$('cookies').checked,profile:$('profile').value.trim()})});if(!response.ok||!response.headers.get('content-type')?.includes('ndjson')){const result=await response.json();throw new Error(result.error||'Impossible de lancer le transfert.')}const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='';for(;;){const{done,value}=await reader.read();buffer+=decoder.decode(value||new Uint8Array(),{stream:!done});let end;while((end=buffer.indexOf('\n'))>=0){const line=buffer.slice(0,end);buffer=buffer.slice(end+1);if(line.trim())event(JSON.parse(line))}if(done)break}if(buffer.trim())event(JSON.parse(buffer));if(!finished)throw new Error('La connexion au service local a été interrompue. Vérifie le Terminal avant de relancer.')}catch(error){$('stage').textContent='Transfert interrompu';showError(error.message)}finally{lock(false)}});
window.addEventListener('storage',e=>{if(e.key==='nuit.history'&&!busy){history=readHistory();renderHistory()}});
window.addEventListener('beforeunload',e=>{if(busy){e.preventDefault();e.returnValue=''}});
lock(false);
async function checkTools(){
 const button=$('check-tools');button.disabled=true;
 try{const result=await api('status');defaultFolder=result.defaultFolder;$('tools').replaceChildren();const packages={PHP:'php','yt-dlp':'yt-dlp',ffmpeg:'ffmpeg'},missing=[];
 for(const[name,ok]of Object.entries(result.tools)){const badge=document.createElement('span');badge.className='tool '+(ok?'ok':'bad');badge.textContent=(ok?'✓ ':'× ')+name;$('tools').append(badge);if(!ok)missing.push(packages[name])}
 ready=missing.length===0;$('setup').hidden=ready;$('install-command').textContent=missing.length?'brew install '+missing.join(' '):'';$('setup-message').textContent='Outils manquants : '+missing.join(', ')+'. Pour les installer :';
 if(!$('folder').value)$('folder').value=defaultFolder;else $('folder-hint').textContent='✓ Dossier retrouvé depuis ta dernière visite.';
 }catch(e){ready=false;showError(e.message)}finally{button.disabled=false;lock(busy)}
}
$('manage-folder').addEventListener('click',()=>{const panel=$('folder-controls');panel.hidden=!panel.hidden;$('manage-folder').setAttribute('aria-expanded',String(!panel.hidden))});
$('save-folder').addEventListener('click',async()=>{try{const result=await api('validate',{folder:$('folder').value.trim()});$('folder').value=result.folder;rememberFolder();toast('Dossier vérifié et mémorisé.')}catch(e){showError(e.message)}});
$('open-folder').addEventListener('click',()=>api('reveal',{folder:$('folder').value.trim()}).catch(e=>showError(e.message)));
$('reset-folder').addEventListener('click',()=>{if(defaultFolder){$('folder').value=defaultFolder;rememberFolder();toast('Le dossier Téléchargements est sélectionné.')}});
$('check-tools').addEventListener('click',()=>checkTools().then(()=>{if(ready)toast('Tous les outils sont disponibles.')}));
$('copy-install').addEventListener('click',async()=>{try{await navigator.clipboard.writeText($('install-command').textContent);toast('Commande copiée. Colle-la dans Terminal.')}catch{toast('Sélectionne et copie la commande affichée.')}});
checkTools();
</script>
</body></html>
