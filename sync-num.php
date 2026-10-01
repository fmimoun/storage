<?php
declare(strict_types=1);

namespace NumstopMirror;

// Define a private key of at least 32 characters before enabling HTTP updates.
const SYNC_TOKEN = '';
const PUBLIC_BASE_URL = 'https://raw.githubusercontent.com/fmimoun/storage/main/lists';
const UPSTREAM = 'https://app.saracroche.org';
const MAX_CATALOG_BYTES = 512_000;
const MAX_LIST_BYTES = 12_000_000;

function upstreamUrl(string $address): string
{
    $url = str_starts_with($address, '/') ? UPSTREAM . $address : $address;
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https'
        || ($parts['host'] ?? '') !== 'app.saracroche.org'
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('~^/storage/fr-[a-z0-9-]+\.jsonl$~D', $parts['path'] ?? '')) {
        throw new \RuntimeException('Adresse de liste non autorisee.');
    }
    return $url;
}

function publicBase(string $url): string
{
    $url = rtrim($url, '/');
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
        || isset($parts['fragment'])
        || !preg_match('~^/[a-zA-Z0-9/_-]+$~D', $parts['path'] ?? '')
        || str_contains($parts['path'], '//')) {
        throw new \RuntimeException('PUBLIC_BASE_URL doit etre une adresse HTTPS de dossier.');
    }
    return $url;
}

function download(string $url, int $limit): string
{
    if (!extension_loaded('curl')) {
        throw new \RuntimeException('Extension PHP cURL manquante.');
    }
    $body = '';
    $overflow = false;
    $curl = curl_init($url);
    if ($curl === false) {
        throw new \RuntimeException('Initialisation cURL impossible.');
    }
    curl_setopt_array($curl, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'NumStop-List-Mirror/1.0',
        CURLOPT_HTTPHEADER => ['X-Country-Code: FR', 'Accept: application/json, application/x-ndjson'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                $overflow = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    try {
        $ok = curl_exec($curl);
        if ($overflow) {
            throw new \RuntimeException('Telechargement trop volumineux.');
        }
        if ($ok === false) {
            throw new \RuntimeException('Echec HTTPS : ' . curl_error($curl));
        }
        if ((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new \RuntimeException('Le serveur de listes ne renvoie pas HTTP 200.');
        }
        return $body;
    } finally {
        // PHP 8.5 deprecates curl_close; releasing the handle closes the connection.
        unset($curl);
    }
}

function catalog(string $json): array
{
    if (strlen($json) > MAX_CATALOG_BYTES) {
        throw new \RuntimeException('Catalogue trop volumineux.');
    }
    $items = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($items) || !array_is_list($items) || count($items) > 32) {
        throw new \RuntimeException('Catalogue invalide.');
    }
    $lists = [];
    $ids = [];
    $files = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new \RuntimeException('Entree de catalogue invalide.');
        }
        if (($item['channel'] ?? 'phone') !== 'phone') {
            continue;
        }
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $priority = $item['priority'] ?? null;
        $version = $item['version'] ?? null;
        $hash = $item['hash'] ?? null;
        if (!is_int($id) || $id <= 0 || isset($ids[$id])
            || !is_string($name) || trim($name) === '' || strlen($name) > 1000
            || !in_array($item['type'] ?? '', ['allow', 'block'], true)
            || !is_int($priority) || $priority < 1 || $priority > 10000
            || !is_bool($item['is_enabled_by_default'] ?? null)
            || !is_string($version) || $version === '' || strlen($version) > 100
            || ($hash !== null && (!is_string($hash) || $hash === '' || strlen($hash) > 128))
            || ($item['license'] ?? '') !== 'CC BY-NC-SA 4.0'
            || !is_string($item['download_url'] ?? null)) {
            throw new \RuntimeException('Metadonnees ou licence de liste invalides.');
        }
        $item['download_url'] = upstreamUrl($item['download_url']);
        $file = basename(parse_url($item['download_url'], PHP_URL_PATH));
        if (isset($files[$file])) {
            throw new \RuntimeException('Nom de fichier duplique.');
        }
        $ids[$id] = true;
        $files[$file] = true;
        $lists[] = $item;
    }
    if ($lists === []) {
        throw new \RuntimeException('Catalogue francais vide.');
    }
    return $lists;
}

function validateRules(string $body): int
{
    if (strlen($body) > MAX_LIST_BYTES) {
        throw new \RuntimeException('Liste trop volumineuse.');
    }
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new \RuntimeException('Lecture de liste impossible.');
    }
    try {
        if (fwrite($stream, $body) !== strlen($body)) {
            throw new \RuntimeException('Lecture de liste incomplete.');
        }
        rewind($stream);
        $count = 0;
        while (($line = fgets($stream, 4098)) !== false) {
            $line = rtrim($line, "\r\n");
            if (strlen($line) > 4096) {
                throw new \RuntimeException('Ligne de liste trop longue.');
            }
            if (trim($line) === '') {
                continue;
            }
            $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            $pattern = is_array($entry) ? ($entry['pattern'] ?? null) : null;
            if (!is_string($pattern) || !preg_match('/^[0-9]+#*$/D', $pattern)
                || strlen($pattern) > 15
                || (isset($entry['name']) && !is_string($entry['name']))) {
                throw new \RuntimeException('Regle de numero invalide.');
            }
            if (++$count > 250_000) {
                throw new \RuntimeException('Trop de regles.');
            }
        }
        if (!feof($stream) || $count === 0) {
            throw new \RuntimeException('Liste vide ou incomplete.');
        }
        return $count;
    } finally {
        fclose($stream);
    }
}

function atomicWrite(string $directory, string $filename, string $contents): void
{
    if ($filename === '' || basename($filename) !== $filename
        || str_contains($filename, '\\') || str_contains($filename, "\0")) {
        throw new \RuntimeException('Nom de fichier local invalide.');
    }
    $temporary = tempnam($directory, '.mirror-');
    if ($temporary === false) {
        throw new \RuntimeException('Creation du fichier temporaire impossible.');
    }
    try {
        if (realpath(dirname($temporary)) !== realpath($directory)
            || file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
            || !chmod($temporary, 0644)
            || !rename($temporary, $directory . DIRECTORY_SEPARATOR . $filename)) {
            throw new \RuntimeException('Publication du fichier impossible.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
}

function cachedFile(array $old, array $incoming, string $directory): ?string
{
    $file = $old['mirror_file'] ?? null;
    $stem = basename(parse_url($incoming['download_url'], PHP_URL_PATH), '.jsonl');
    if (($incoming['hash'] ?? null) === null || ($old['hash'] ?? null) !== $incoming['hash']
        || !is_int($old['rule_count'] ?? null) || $old['rule_count'] <= 0
        || !is_string($file)
        || !str_starts_with($file, $stem . '.')
        || !preg_match('/^fr-[a-z0-9-]+\.([a-f0-9]{64})\.jsonl$/D', $file, $match)
        || !is_file($directory . DIRECTORY_SEPARATOR . $file)
        || hash_file('sha256', $directory . DIRECTORY_SEPARATOR . $file) !== $match[1]) {
        return null;
    }
    return $file;
}

function publicationMetadata(array $item): array
{
    if ($item['id'] === 3) {
        $item['name'] = "Num\u{00e9}ros valid\u{00e9}s par Num";
        $item['description'] = "Cette liste contient des num\u{00e9}ros valid\u{00e9}s";
    } elseif ($item['id'] === 6) {
        $item['description'] = "Liste des pr\u{00e9}fixes t\u{00e9}l\u{00e9}phoniques attribu\u{00e9}s "
            . "\u{00e0} des op\u{00e9}rateurs les plus signal\u{00e9}s par la communaut\u{00e9} "
            . "pour leurs appels de d\u{00e9}marchage. Tous les num\u{00e9}ros sont automatiquement bloqu\u{00e9}s.";
    }
    unset($item['source_download_url']);
    return $item;
}

function synchronize(string $directory, string $baseUrl, ?callable $fetch = null): array
{
    $baseUrl = publicBase($baseUrl);
    $fetch ??= __NAMESPACE__ . '\\download';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new \RuntimeException('Creation du dossier storage impossible.');
    }
    if (!is_writable($directory)) {
        throw new \RuntimeException('Le dossier storage doit etre accessible en ecriture.');
    }
    $lock = fopen($directory . DIRECTORY_SEPARATOR . '.mirror.lock', 'c');
    if ($lock === false) {
        throw new \RuntimeException('Creation du verrou impossible.');
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Une synchronisation est deja en cours.', 409);
        }
        $previous = [];
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'catalogue-fr.json';
        if (is_file($manifestPath)) {
            if (filesize($manifestPath) > MAX_CATALOG_BYTES) {
                throw new \RuntimeException('Catalogue local trop volumineux.');
            }
            $old = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($old) || !array_is_list($old)) {
                throw new \RuntimeException('Catalogue local invalide, aucune publication.');
            }
            foreach ($old as $item) {
                if (!is_array($item) || !is_int($item['id'] ?? null)) {
                    throw new \RuntimeException('Catalogue local invalide, aucune publication.');
                }
                $previous[$item['id']] = $item;
            }
        }
        $remote = catalog($fetch(UPSTREAM . '/api/v2/lists', MAX_CATALOG_BYTES));
        $published = [];
        $updated = 0;
        $unchanged = 0;
        foreach ($remote as $item) {
            $old = $previous[$item['id']] ?? [];
            $file = cachedFile($old, $item, $directory);
            $ruleCount = $old['rule_count'] ?? 0;
            if ($file === null) {
                $body = $fetch($item['download_url'], MAX_LIST_BYTES);
                $ruleCount = validateRules($body);
                $digest = hash('sha256', $body);
                $stem = basename(parse_url($item['download_url'], PHP_URL_PATH), '.jsonl');
                $file = $stem . '.' . $digest . '.jsonl';
                atomicWrite($directory, $file, $body);
                ++$updated;
            } else {
                ++$unchanged;
            }
            $item['download_url'] = $baseUrl . '/' . $file;
            $item['mirror_file'] = $file;
            $item['rule_count'] = $ruleCount;
            $published[] = publicationMetadata($item);
        }
        $attribution = [
            'source' => 'Saracroche', 'catalog_url' => UPSTREAM . '/api/v2/lists',
            'license' => 'CC BY-NC-SA 4.0', 'license_url' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
            'modifications' => 'Regles copiees sans modification. Adresses et libelles du catalogue adaptes au miroir Num.',
        ];
        atomicWrite($directory, 'attribution.json', json($attribution));
        // Publish the catalog last; immutable URLs keep its previous generation consistent.
        atomicWrite($directory, 'catalogue-fr.json', json($published));
        $report = ['ok' => true, 'updated_at' => gmdate('c'), 'updated' => $updated,
            'unchanged' => $unchanged, 'lists' => count($published),
            'rules' => array_sum(array_column($published, 'rule_count')),
            'catalog_url' => $baseUrl . '/catalogue-fr.json'];
        try {
            atomicWrite($directory, 'sync-status.json', json($report));
            $current = array_column($published, 'mirror_file');
            foreach (glob($directory . DIRECTORY_SEPARATOR . 'fr-*.jsonl') ?: [] as $path) {
                $name = basename($path);
                if (preg_match('/^fr-[a-z0-9-]+\.[a-f0-9]{64}\.jsonl$/D', $name)
                    && !in_array($name, $current, true) && !is_link($path)
                    && filemtime($path) < time() - 172800) {
                    unlink($path);
                }
            }
        } catch (\Throwable $error) {
            $report['maintenance_warning'] = $error->getMessage();
        }
        return $report;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function authorized(string $expected, string $provided): bool
{
    return strlen($expected) >= 32 && hash_equals($expected, $provided);
}

function main(): int
{
    ini_set('display_errors', '0');
    $cli = PHP_SAPI === 'cli';
    if (!$cli) {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $localTest = PHP_SAPI === 'cli-server'
            && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (!$secure && !$localTest) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json(['ok' => false, 'error' => 'HTTPS obligatoire.']);
            return 1;
        }
        $token = getenv('NUMSTOP_SYNC_TOKEN') ?: SYNC_TOKEN;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width">';
            echo '<title>Miroir Num</title><h1>Miroir Num</h1>';
            if (strlen($token) < 32) {
                http_response_code(503);
                echo '<p>Configurer SYNC_TOKEN ou NUMSTOP_SYNC_TOKEN avant la premiere synchronisation.</p>';
            } else {
                echo '<form method="post"><label>Cle d\'administration <input type="password" name="token" required autocomplete="off"></label> ';
                echo '<button type="submit">Synchroniser les listes francaises</button></form>';
            }
            echo '</html>';
            return 0;
        }
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            echo json(['ok' => false, 'error' => 'Utiliser POST pour synchroniser.']);
            return 1;
        }
        $provided = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? $_POST['token'] ?? '';
        if (!is_string($provided) || !authorized($token, $provided)) {
            http_response_code(403);
            echo json(['ok' => false, 'error' => 'Cle absente ou incorrecte.']);
            return 1;
        }
    }
    try {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $directory = getenv('NUMSTOP_STORAGE_DIR') ?: __DIR__ . DIRECTORY_SEPARATOR . 'storage';
        $base = getenv('NUMSTOP_PUBLIC_BASE_URL') ?: PUBLIC_BASE_URL;
        echo json(synchronize($directory, $base));
        return 0;
    } catch (\Throwable $error) {
        if (!$cli) {
            http_response_code($error->getCode() === 409 ? 409 : 503);
        }
        echo json(['ok' => false, 'error' => $error->getMessage(), 'previous_catalog_preserved' => true]);
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main());
}
