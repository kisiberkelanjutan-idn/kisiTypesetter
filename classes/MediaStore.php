<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/MediaStore.php
 *
 * Content-addressed on-disk store for figure binaries.
 *
 * Studio holds the article state in the browser, so every AJAX/download request used to
 * carry the whole payload - including base64 image data URIs - back to the server. On an
 * illustrated article that body grows past the web server request limit and Apache answers
 * 413 Request Entity Too Large. Figures are therefore stored server side (keyed by the
 * SHA-1 of their bytes) and the browser only sends the small media_key reference.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

use PKP\config\Config;

class MediaStore
{
    /** Supported image extensions mapped to their MIME type. */
    private const MIME_BY_EXT = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'webp' => 'image/webp',
        'emf' => 'image/x-emf',
        'wmf' => 'image/x-wmf',
        'svg' => 'image/svg+xml',
    ];

    private const MAX_BYTES = 20971520; // 20 MB per figure

    /**
     * Resolve a raw extension to a supported one (jpg for jpeg, tiff for tif, ...).
     */
    public static function normalizeExt(string $ext): string
    {
        $ext = strtolower(trim($ext));
        $ext = (string) preg_replace('/[^a-z0-9]/', '', $ext);
        $aliases = [
            'jpeg' => 'jpg',
            'tif' => 'tiff',
            'svgz' => 'svg',
        ];
        $ext = $aliases[$ext] ?? $ext;
        return isset(self::MIME_BY_EXT[$ext]) ? $ext : '';
    }

    public static function mimeFromExt(string $ext): string
    {
        $ext = self::normalizeExt($ext);
        return $ext !== '' ? self::MIME_BY_EXT[$ext] : 'application/octet-stream';
    }

    /**
     * Resolve a MIME type back to its normalized extension ('' when unsupported).
     */
    public static function extFromMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        foreach (self::MIME_BY_EXT as $ext => $candidateMime) {
            if ($candidateMime === $mime) {
                return self::normalizeExt($ext);
            }
        }
        return '';
    }

    /**
     * Store a base64 data URI and return its media key, or null when it is unusable.
     */
    public static function putDataUri(string $dataUri): ?string
    {
        if (!preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', trim($dataUri), $m)) {
            return null;
        }

        $binary = base64_decode((string) preg_replace('/\s+/', '', $m[2]), true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $mime = strtolower(trim($m[1]));
        $ext = self::extFromMime($mime);
        if ($ext === '') {
            $ext = 'png';
        }

        return self::putBinary($binary, $ext);
    }

    /**
     * Store raw bytes and return the content-addressed key ("<sha1 prefix>.<ext>").
     */
    public static function putBinary(string $binary, string $ext): ?string
    {
        $ext = self::normalizeExt($ext);
        if ($ext === '' || $binary === '' || strlen($binary) > self::MAX_BYTES) {
            return null;
        }

        $dir = self::directory();
        if ($dir === '') {
            return null;
        }

        $key = substr(sha1($binary), 0, 24) . '.' . $ext;
        $path = $dir . DIRECTORY_SEPARATOR . $key;
        if (is_file($path) && (int) @filesize($path) === strlen($binary)) {
            return $key;
        }
        if (@file_put_contents($path, $binary, LOCK_EX) === false) {
            return null;
        }

        return $key;
    }

    /**
     * Read a stored figure back. Returns binary, extension, MIME and a ready data URI.
     */
    public static function read(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{16,40}\.([a-z0-9]{2,5})$/', $key, $m)) {
            return null;
        }
        $ext = self::normalizeExt($m[1]);
        if ($ext === '') {
            return null;
        }

        $dir = self::directory();
        if ($dir === '') {
            return null;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $key;
        if (!is_file($path)) {
            return null;
        }

        $binary = @file_get_contents($path);
        if ($binary === false || $binary === '') {
            return null;
        }

        $mime = self::MIME_BY_EXT[$ext];
        return [
            'binary' => $binary,
            'ext' => $ext,
            'mime' => $mime,
            'data_uri' => 'data:' . $mime . ';base64,' . base64_encode($binary),
        ];
    }

    /**
     * Absolute path of the storage directory, created on first use ('' when unavailable).
     */
    public static function directory(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = '';
        foreach (self::candidateBaseDirs() as $base) {
            $base = rtrim($base, '/\\');
            if ($base === '') {
                continue;
            }
            $target = $base . DIRECTORY_SEPARATOR . 'kisiTypesetterMedia';
            if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                continue;
            }
            if (!is_writable($target)) {
                continue;
            }
            $resolved = $target;
            break;
        }

        return $resolved;
    }

    /**
     * Candidate base directories: the OJS files directory first, then the system temp dir.
     */
    private static function candidateBaseDirs(): array
    {
        $bases = [];

        try {
            $filesDir = trim((string) Config::getVar('files', 'files_dir'));
        } catch (\Throwable $e) {
            $filesDir = '';
        }

        if ($filesDir !== '') {
            $bases[] = $filesDir;
            $isAbsolute = (bool) preg_match('#^([A-Za-z]:[\\\\/]|/)#', $filesDir);
            if (!$isAbsolute && defined('BASE_SYS_DIR')) {
                $bases[] = rtrim((string) BASE_SYS_DIR, '/\\') . DIRECTORY_SEPARATOR . $filesDir;
            }
        }

        $tmp = sys_get_temp_dir();
        if ($tmp !== '') {
            $bases[] = $tmp;
        }

        return $bases;
    }
}
