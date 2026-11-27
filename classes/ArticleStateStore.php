<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/ArticleStateStore.php
 *
 * Per-submission JSON state store for Kisi Berkelanjutan Typesetter Studio.
 *
 * Persists the typesetter's latest edited Studio payload (metadata, citation,
 * bilingual titles/abstracts/keywords/SDGs, structured body sections, tables,
 * figure media_keys, and references) keyed by (contextId, submissionId) so that
 * reopening Studio after typeset completion or article publication restores the
 * last edit history instead of re-parsing the source DOCX or resetting fields.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

use PKP\config\Config;

class ArticleStateStore
{
    private const EPHEMERAL_KEYS = [
        '_ai_status',
        '_loaded_docx_name',
        '_has_saved_state',
        '_saved_at',
        '_saved_reason',
        '_saved_by',
        '_is_published',
        'raw_text_for_ai',
    ];

    /**
     * Strip transport-only flags and redundant base64 URIs when media_key references exist.
     */
    public static function prepareSnapshot(array $articleData): array
    {
        foreach (self::EPHEMERAL_KEYS as $key) {
            unset($articleData[$key]);
        }

        if (!empty($articleData['sections']) && is_array($articleData['sections'])) {
            foreach ($articleData['sections'] as $secKey => $blocks) {
                if (!is_array($blocks)) {
                    continue;
                }
                foreach ($blocks as $idx => $block) {
                    if (!is_array($block) || ($block['type'] ?? '') !== 'image') {
                        continue;
                    }
                    if (!empty($block['media_key'])) {
                        $articleData['sections'][$secKey][$idx]['data'] = '';
                        $articleData['sections'][$secKey][$idx]['src'] = '';
                    }
                    if (!empty($block['media_key2'])) {
                        $articleData['sections'][$secKey][$idx]['data2'] = '';
                        $articleData['sections'][$secKey][$idx]['src2'] = '';
                    }
                }
            }
        }

        return $articleData;
    }

    /**
     * Save the latest Studio edit state for a submission.
     *
     * @return array|null Summary metadata on success, or null on write failure.
     */
    public static function save(
        int $contextId,
        int $submissionId,
        array $articleData,
        array $meta = [],
        ?string $overrideDir = null
    ): ?array {
        if ($submissionId <= 0) {
            return null;
        }

        $path = self::filePath($contextId, $submissionId, $overrideDir);
        if ($path === '') {
            return null;
        }

        $savedAt = trim((string) ($meta['saved_at'] ?? '')) ?: date('c');
        $savedBy = trim((string) ($meta['saved_by'] ?? ''));
        $savedReason = trim((string) ($meta['saved_reason'] ?? '')) ?: 'manual';

        $record = [
            'version' => 1,
            'context_id' => $contextId,
            'submission_id' => $submissionId,
            'saved_at' => $savedAt,
            'saved_by' => $savedBy,
            'saved_reason' => $savedReason,
            'article' => self::prepareSnapshot($articleData),
        ];

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $tmpPath = $path . '.tmp.' . str_replace('.', '', uniqid('', true));
        if (@file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            @unlink($tmpPath);
            if (@file_put_contents($path, $json, LOCK_EX) === false) {
                return null;
            }
        } elseif (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            if (@file_put_contents($path, $json, LOCK_EX) === false) {
                return null;
            }
        }

        return [
            'saved_at' => $savedAt,
            'saved_by' => $savedBy,
            'saved_reason' => $savedReason,
        ];
    }

    /**
     * Load the saved Studio edit state for a submission, or null when none exists.
     */
    public static function load(int $contextId, int $submissionId, ?string $overrideDir = null): ?array
    {
        if ($submissionId <= 0) {
            return null;
        }

        $path = self::filePath($contextId, $submissionId, $overrideDir);
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['article']) || !is_array($decoded['article'])) {
            return null;
        }

        return [
            'saved_at' => (string) ($decoded['saved_at'] ?? ''),
            'saved_by' => (string) ($decoded['saved_by'] ?? ''),
            'saved_reason' => (string) ($decoded['saved_reason'] ?? ''),
            'article' => $decoded['article'],
        ];
    }

    /**
     * Check whether a saved edit state exists for this submission.
     */
    public static function exists(int $contextId, int $submissionId, ?string $overrideDir = null): bool
    {
        if ($submissionId <= 0) {
            return false;
        }
        $path = self::filePath($contextId, $submissionId, $overrideDir);
        return $path !== '' && is_file($path);
    }

    /**
     * Delete any saved Studio edit state for a submission (e.g. explicit reset to OJS/DOCX).
     */
    public static function delete(int $contextId, int $submissionId, ?string $overrideDir = null): bool
    {
        if ($submissionId <= 0) {
            return false;
        }
        $path = self::filePath($contextId, $submissionId, $overrideDir);
        if ($path === '' || !is_file($path)) {
            return true;
        }
        return @unlink($path);
    }

    /**
     * Resolve the absolute path to the submission state JSON file.
     */
    public static function filePath(int $contextId, int $submissionId, ?string $overrideDir = null): string
    {
        $dir = self::directory($overrideDir);
        if ($dir === '') {
            return '';
        }
        return $dir . DIRECTORY_SEPARATOR . max(0, $contextId) . '_' . $submissionId . '.json';
    }

    /**
     * Resolve and create the writable storage directory for Studio edit states.
     */
    public static function directory(?string $overrideDir = null): string
    {
        if ($overrideDir !== null) {
            $target = rtrim($overrideDir, '/\\');
            if ($target === '') {
                return '';
            }
            if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                return '';
            }
            return is_writable($target) ? $target : '';
        }

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
            $target = $base . DIRECTORY_SEPARATOR . 'kisiTypesetterState';
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

    private static function candidateBaseDirs(): array
    {
        $bases = [];

        $filesDir = '';
        if (class_exists('\PKP\config\Config')) {
            try {
                $filesDir = trim((string) Config::getVar('files', 'files_dir'));
            } catch (\Throwable $e) {
                $filesDir = '';
            }
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
