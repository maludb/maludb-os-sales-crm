<?php
declare(strict_types=1);

/**
 * The canonical attachment store (GL's `attachments` reused; the estate's rules — slice 1): the bytes live under the storage directory
 * (CRM_STORAGE_DIR, else storage/ beside the install; ROOT_STEPS 2b), never under html/: `attachments/<kind>/<record id>/<sha256 12>-<safe name>`.
 * The type is sniffed (finfo), never trusted from the browser; the ceiling is the lesser of the setting (max_attachment_bytes, 25 MB) and the
 * environment's; the gate (/attachments/{id}) streams every byte with nosniff, as a download unless the type is one a browser shows safely.
 */
const ATTACHMENT_INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];
const ATTACHMENT_REFUSED_EXTENSIONS = ['php', 'phtml', 'phar', 'cgi', 'pl', 'sh', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'js', 'jar', 'vbs', 'ps1'];

function attachment_storage_dir(): string
{
    return rtrim((string) (env('CRM_STORAGE_DIR', '') ?: dirname(__DIR__, 3) . '/storage'), '/');
}

/** The ceiling in bytes: the setting (25 MB by default), never above the environment's CRM_MAX_ATTACHMENT_BYTES when set. */
function attachment_max_bytes(PDO $pdo): int
{
    $setting = (int) (one_value($pdo, "SELECT crm_setting_int('max_attachment_bytes')") ?: 26214400);
    $envMax = (int) env('CRM_MAX_ATTACHMENT_BYTES', '0');
    return $envMax > 0 ? min($setting, $envMax) : $setting;
}

/** A file name a person can read and a disk can hold: letters, digits, dot, dash, underscore; up to 120; never a hidden or empty name. */
function attachment_safe_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name);
    $name = ltrim(trim($name, '-'), '.');
    return mb_substr($name !== '' ? $name : 'file', -120);
}

/**
 * Judge and store one upload: ['tmp' => path, 'name' => the person's name, 'size' => bytes]. Answers [filename, mime_type, byte_size, sha256,
 * storage_path (relative to the storage directory)]; refuses (DomainException, a sentence) a file over the ceiling, an empty one, or one whose
 * extension runs as a program.
 */
function attachment_store(PDO $pdo, string $kind, int $recordId, array $file): array
{
    $tmp = (string) ($file['tmp'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('Choose a file to attach.');
    }
    $size = (int) filesize($tmp);
    $max = attachment_max_bytes($pdo);
    if ($size > $max) {
        throw new DomainException('A file is up to ' . (int) round($max / 1048576) . ' MB; this one is ' . number_format($size / 1048576, 1) . ' MB.');
    }
    if ($size === 0) {
        throw new DomainException('That file is empty.');
    }
    $name = attachment_safe_name((string) ($file['name'] ?? 'file'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ATTACHMENT_REFUSED_EXTENSIONS, true)) {
        throw new DomainException('A program (.' . $ext . ') cannot be attached; zip it first.');
    }
    $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream');
    $sha = hash_file('sha256', $tmp);
    $rel = 'attachments/' . $kind . '/' . $recordId . '/' . substr($sha, 0, 12) . '-' . $name;
    $abs = attachment_storage_dir() . '/' . $rel;
    if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) {
        throw new RuntimeException('attachment store: cannot make ' . dirname($rel));
    }
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $abs) : copy($tmp, $abs);
    if (!$moved) {
        throw new RuntimeException('attachment store: cannot write ' . $rel);
    }
    @chmod($abs, 0640);
    return ['filename' => $name, 'mime_type' => $mime, 'byte_size' => $size, 'sha256' => $sha, 'storage_path' => $rel];
}

/** Send the bytes: nosniff, a download unless the type is shown safely and the caller asked for it inline; never HTML inline. */
function attachment_stream(array $a, bool $inline): never
{
    $abs = attachment_storage_dir() . '/' . $a['storage_path'];
    if (!is_file($abs)) {
        refuse(404, 'That file is no longer here.');
    }
    $mime = (string) $a['mime_type'];
    $showInline = $inline && in_array($mime, ATTACHMENT_INLINE_TYPES, true);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . ($showInline ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($abs));
    header('Content-Disposition: ' . ($showInline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $a['filename']) . '"');
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, max-age=0');
    readfile($abs);
    exit;
}

/** Remove the bytes (after the row is gone); a missing file is not an error. */
function attachment_unlink(string $rel): void
{
    $abs = attachment_storage_dir() . '/' . $rel;
    if (is_file($abs)) {
        @unlink($abs);
    }
}
