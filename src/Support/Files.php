<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

/**
 * Private file store outside the web root (CANPAS_FLEET_PRIVATE_DIR).
 * Files are saved under random names; the original name is kept only for display.
 * Nothing here checks permissions: callers do that before streaming.
 */
final class Files
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** MIME types accepted for IDs, permits and proofs of payment. */
    public const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Move an uploaded file into the private store.
     *
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $upload one entry of $_FILES
     * @return array{path:string,name:string} path relative to the private dir, original file name
     */
    public static function storeUpload(array $upload, string $folder): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
            throw new DomainError('file_missing', 'Please attach the file.');
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_BYTES) {
            throw new DomainError('file_too_large', 'The file is larger than 10 MB.');
        }
        $tmp = (string) $upload['tmp_name'];
        if (!is_uploaded_file($tmp) && !(defined('WP_CLI') && WP_CLI)) {
            throw new DomainError('file_missing', 'Please attach the file.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw new DomainError('file_type', 'Upload a PDF, JPG, PNG or WebP file.');
        }
        $folder = trim(preg_replace('/[^a-z0-9\/_-]/', '', strtolower($folder)) ?? '', '/');
        $dir = self::base() . $folder;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new DomainError('storage_unavailable', 'Files cannot be saved right now. Please contact Canpas.');
        }
        @chmod($dir, 0700);
        $rel = $folder . '/' . bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];
        $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, self::base() . $rel) : copy($tmp, self::base() . $rel);
        if (!$ok) {
            throw new DomainError('storage_unavailable', 'Files cannot be saved right now. Please contact Canpas.');
        }
        @chmod(self::base() . $rel, 0600);
        $name = sanitize_file_name(basename((string) ($upload['name'] ?? 'file.' . self::ALLOWED[$mime])));
        return ['path' => $rel, 'name' => substr($name, 0, 190)];
    }

    /**
     * Store several uploads from one multi-file field (e.g. photos[]).
     *
     * @param array<string,mixed>|null $field the $_FILES entry, single or multiple
     * @return list<string> relative paths
     */
    public static function storeMany(?array $field, string $folder, int $max = 8): array
    {
        $list = [];
        if ($field !== null && isset($field['tmp_name'])) {
            if (is_array($field['tmp_name'])) {
                foreach (array_keys($field['tmp_name']) as $i) {
                    if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $list[] = ['name' => $field['name'][$i] ?? '', 'tmp_name' => $field['tmp_name'][$i], 'size' => $field['size'][$i] ?? 0, 'error' => $field['error'][$i] ?? UPLOAD_ERR_OK];
                }
            } elseif (($field['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $list[] = $field;
            }
        }
        if (count($list) > $max) {
            throw new DomainError('too_many_files', "Attach at most {$max} photos.", ['field' => 'photos']);
        }
        $paths = [];
        try {
            foreach ($list as $f) {
                $paths[] = self::storeUpload($f, $folder)['path'];
            }
        } catch (DomainError $e) {
            foreach ($paths as $p) {
                self::delete($p);
            }
            throw new DomainError($e->reason, $e->getMessage(), ['field' => 'photos']);
        }
        return $paths;
    }

    /** Send a stored file to the browser and stop. */
    public static function stream(string $relative, string $downloadName): void
    {
        $full = realpath(self::base() . ltrim($relative, '/'));
        $base = realpath(self::base());
        if ($full === false || $base === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
            status_header(404);
            exit('File not found.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($full);
        nocache_headers();
        header('Content-Type: ' . (isset(self::ALLOWED[$mime]) ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($full);
        exit;
    }

    public static function delete(?string $relative): void
    {
        if ($relative === null || $relative === '') {
            return;
        }
        $full = realpath(self::base() . ltrim($relative, '/'));
        $base = realpath(self::base());
        if ($full !== false && $base !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            @unlink($full);
        }
    }

    private static function base(): string
    {
        return trailingslashit(CANPAS_FLEET_PRIVATE_DIR);
    }
}
