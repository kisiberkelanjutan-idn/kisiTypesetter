<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/KisiTypesetterHandler.php
 *
 * Page Handler for Kisi Berkelanjutan Typesetter & AmeliCA JATS XML Generator (OJS 3.5).
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\kisiTypesetter\KisiTypesetterPlugin;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\submissionFile\SubmissionFile;

require_once __DIR__ . '/VancouverFormatter.php';

class KisiTypesetterHandler extends Handler
{
    /** @var KisiTypesetterPlugin|null */
    protected ?KisiTypesetterPlugin $plugin = null;

    public function __construct(?KisiTypesetterPlugin $plugin = null)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    /**
     * Ensure current user is logged in and has Editor / Manager / Admin privileges
     */
    protected function ensureAuthorized(Request $request): bool
    {
        $user = $request->getUser();
        if (!$user) {
            Validation::redirectLogin();
            return false;
        }

        $context = $request->getContext();
        $contextId = $context ? $context->getId() : PKPApplication::CONTEXT_ID_NONE;

        if (method_exists($user, 'hasRole')) {
            $allowed = $user->hasRole(
                [Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
                $contextId
            ) || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::CONTEXT_ID_NONE);

            if (!$allowed) {
                http_response_code(403);
                echo '403 Forbidden: Anda harus login sebagai Journal Manager atau Editor.';
                return false;
            }
        }

        return true;
    }

    protected function getPluginInstance(): ?KisiTypesetterPlugin
    {
        if ($this->plugin) {
            return $this->plugin;
        }
        $plugins = \PKP\plugins\PluginRegistry::getPlugins('generic');
        return $plugins['kisitypesetterplugin'] ?? $plugins['kisiTypesetter'] ?? null;
    }

    /**
     * Default entry point -> redirect or render studio
     */
    public function index($args, $request)
    {
        return $this->studio($args, $request);
    }

    /**
     * Main interactive UI for Kisi Berkelanjutan Typesetter (all current and archival issues)
     */
    public function studio($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }

        $context = $request->getContext();
        $contextId = $context ? $context->getId() : 0;
        $plugin = $this->getPluginInstance();

        $settings = $this->getSettingsArray($contextId, $plugin);
        $issuesList = $this->getJournalIssues($contextId);
        $selectedIssueId = (int) $request->getUserVar('issueId');
        $selectedSubmissionId = (int) $request->getUserVar('submissionId');

        $submissionsList = $this->getJournalSubmissions($contextId, $selectedIssueId);

        $activeArticleData = null;
        $attachedDocxFiles = [];
        if ($selectedSubmissionId > 0) {
            $submission = Repo::submission()->get($selectedSubmissionId);
            if ($submission && (int) $submission->getData('contextId') === (int) $contextId) {
                $activeArticleData = $this->extractOjsSubmissionData($submission, $context, $request, $settings);
                $attachedDocxFiles = $this->getSubmissionDocxFiles($submission->getId());

                // Automatically parse the latest attached .docx file if one exists in OJS!
                if (!empty($attachedDocxFiles)) {
                    $firstDocx = $attachedDocxFiles[0];
                    $loadedPath = '';
                    $tmpAutoUnlink = '';
                    if (!empty($firstDocx['abs_path']) && file_exists($firstDocx['abs_path'])) {
                        $loadedPath = $firstDocx['abs_path'];
                    } elseif (!empty($firstDocx['rel_path'])) {
                        $content = $this->readFromFileService($firstDocx['rel_path']);
                        if ($content !== '') {
                            $tmpAutoUnlink = tempnam(sys_get_temp_dir(), 'kisi_auto_') . '.docx';
                            file_put_contents($tmpAutoUnlink, $content);
                            $loadedPath = $tmpAutoUnlink;
                        }
                    }
                    if ($loadedPath !== '') {
                        $parsedDocx = DocxParser::parse($loadedPath);
                        $activeArticleData = $this->mergeOjsAndDocxData($activeArticleData, $parsedDocx);
                        $activeArticleData['_loaded_docx_name'] = $firstDocx['name'];
                    }
                    if ($tmpAutoUnlink !== '' && file_exists($tmpAutoUnlink)) {
                        @unlink($tmpAutoUnlink);
                    }
                }
            }
        }

        $dispatcher = $request->getDispatcher();
        $baseUrl = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'kisiTypesetter');
        $journalFaviconUrl = $this->resolveJournalFaviconUrl($context, $request, $plugin);
        $pluginFaviconUrl = rtrim((string) $request->getBaseUrl(), '/') . '/' . ltrim((string) $plugin->getPluginPath(), '/') . '/resources/kisi-favicon.png';

        require($plugin->getPluginPath() . '/templates/studioView.php');
    }

    /**
     * Resolve the active journal favicon URL from OJS 3.5 context (fallback to bundled Kisi Berkelanjutan favicon).
     */
    protected function resolveJournalFaviconUrl($context, Request $request, ?KisiTypesetterPlugin $plugin): string
    {
        $baseUrl = rtrim((string) $request->getBaseUrl(), '/');
        $fallbackUrl = $plugin
            ? ($baseUrl . '/' . ltrim((string) $plugin->getPluginPath(), '/') . '/resources/kisi-favicon.png')
            : '';

        if (!$context) {
            return $fallbackUrl;
        }

        try {
            $fav = method_exists($context, 'getLocalizedFavicon') ? $context->getLocalizedFavicon() : null;
            if (empty($fav) || empty($fav['uploadName'])) {
                $favMap = (array) $context->getData('favicon');
                foreach (['id', 'id_ID', 'en', 'en_US'] as $loc) {
                    if (!empty($favMap[$loc]['uploadName'])) {
                        $fav = $favMap[$loc];
                        break;
                    }
                }
                if ((empty($fav) || empty($fav['uploadName'])) && !empty($favMap)) {
                    foreach ($favMap as $item) {
                        if (is_array($item) && !empty($item['uploadName'])) {
                            $fav = $item;
                            break;
                        }
                    }
                }
            }
            if (!empty($fav['uploadName'])) {
                $contextFilesDir = 'public/journals/' . (int) $context->getId();
                if (class_exists('\APP\file\PublicFileManager')) {
                    $pfm = new \APP\file\PublicFileManager();
                    if (method_exists($pfm, 'getContextFilesPath')) {
                        $contextFilesDir = trim((string) $pfm->getContextFilesPath((int) $context->getId()), '/');
                    }
                }
                return $baseUrl . '/' . $contextFilesDir . '/' . rawurlencode((string) $fav['uploadName']);
            }
        } catch (\Throwable $e) {
            // Fall through cleanly to bundled journal favicon
        }

        return $fallbackUrl;
    }

    /**
     * AJAX POST: Save Plugin Settings (Gemini API Key, Journal Defaults)
     */
    public function saveSettings($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $context = $request->getContext();
        $contextId = $context ? $context->getId() : 0;
        $plugin = $this->getPluginInstance();

        if (!$plugin) {
            echo json_encode(['ok' => false, 'error' => 'Plugin instance not found.']);
            return;
        }

        $keys = [
            'geminiApiKey',
            'geminiModel',
            'journalTitle',
            'journalShortFooter',
            'publisherName',
            'eissn',
            'referenceStyle',
            'formLanguageMode',
            'defaultFunding',
            'defaultCompetingInterests',
            'retypesetNote',
            'enableFullTextDigest',
            'replaceDefaultAbstract',
        ];

        foreach ($keys as $k) {
            $val = $request->getUserVar($k);
            if ($val !== null) {
                $plugin->updateSetting($contextId, $k, trim((string) $val), 'string');
            }
        }

        echo json_encode(['ok' => true, 'message' => 'Pengaturan plugin berhasil disimpan.']);
    }

    /**
     * AJAX POST: Parse an uploaded .docx file or an existing OJS submissionFileId
     */
    public function parseDocx($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $docxPath = '';
        $fileName = '';
        $tempToUnlink = '';

        if (!empty($_FILES['docx_file']['tmp_name']) && is_uploaded_file($_FILES['docx_file']['tmp_name'])) {
            $docxPath = $_FILES['docx_file']['tmp_name'];
            $fileName = $_FILES['docx_file']['name'] ?? 'uploaded.docx';
        }

        if ($docxPath === '' && ($submissionFileId = (int) $request->getUserVar('submissionFileId')) > 0) {
            $sf = Repo::submissionFile()->get($submissionFileId);
            if ($sf) {
                $resolved = $this->resolveSubmissionFilePath($sf);
                $fileName = (string) ($sf->getLocalizedData('name') ?: basename($resolved['rel_path'] ?: 'manuscript.docx'));
                if ($resolved['abs_path'] !== '' && file_exists($resolved['abs_path'])) {
                    $docxPath = $resolved['abs_path'];
                } elseif ($resolved['rel_path'] !== '') {
                    $content = $this->readFromFileService($resolved['rel_path']);
                    if ($content !== '') {
                        $tmpDocx = tempnam(sys_get_temp_dir(), 'kisi_read_') . '.docx';
                        file_put_contents($tmpDocx, $content);
                        $docxPath = $tmpDocx;
                        $tempToUnlink = $tmpDocx;
                    }
                }
            }
        }

        if ($docxPath === '' || !file_exists($docxPath)) {
            if ($tempToUnlink !== '' && file_exists($tempToUnlink)) {
                @unlink($tempToUnlink);
            }
            echo json_encode(['ok' => false, 'error' => 'File .docx tidak ditemukan atau gagal diunggah.']);
            return;
        }

        $parsed = DocxParser::parse($docxPath);
        if ($tempToUnlink !== '' && file_exists($tempToUnlink)) {
            @unlink($tempToUnlink);
        }
        echo json_encode([
            'ok' => true,
            'fileName' => $fileName,
            'parsed' => $parsed,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * AJAX POST: Run Gemini AI (or local heuristic fallback) to split abstracts & structure sections
     */
    public function aiRestructure($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $context = $request->getContext();
        $contextId = $context ? $context->getId() : 0;
        $plugin = $this->getPluginInstance();
        $settings = $this->getSettingsArray($contextId, $plugin);

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $restructured = GeminiRestructurer::restructure(
            $articleData,
            $settings['geminiApiKey'] ?? '',
            $settings['geminiModel'] ?? 'gemini-2.5-flash'
        );

        echo json_encode([
            'ok' => true,
            'article' => $restructured,
            'message' => $restructured['_ai_status'] ?? 'Berhasil dirapikan.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * AJAX POST: Audit Indonesian & English typos, non-standard words (kata tidak baku), and EYD V/AMA compliance
     */
    public function auditLanguage($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $context = $request->getContext();
        $contextId = $context ? $context->getId() : 0;
        $plugin = $this->getPluginInstance();
        $settings = $this->getSettingsArray($contextId, $plugin);

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $bodyLang = (string) ($request->getUserVar('bodyLang') ?: 'auto');
        $useLanguageTool = (string) $request->getUserVar('useLanguageTool') === '1';
        $useGemini = (string) $request->getUserVar('useGemini') === '1';

        $auditResult = LanguageAuditor::audit($articleData, [
            'body_lang' => $bodyLang,
            'use_languagetool' => $useLanguageTool,
            'use_gemini' => $useGemini,
            'gemini_api_key' => $settings['geminiApiKey'] ?? '',
            'gemini_model' => $settings['geminiModel'] ?? 'gemini-2.5-flash',
        ]);

        echo json_encode([
            'ok' => true,
            'audit' => $auditResult,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * AJAX POST: Format all tables in the article according to Health-Science Journal Standards (ICMJE/AMA 3-line tables)
     */
    public function formatHealthTables($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $bodyLang = (string) ($request->getUserVar('bodyLang') ?: 'auto');
        if ($bodyLang !== 'id' && $bodyLang !== 'en') {
            $sample = '';
            foreach ((array) ($articleData['sections']['hasil'] ?? []) as $b) {
                if (is_array($b)) {
                    $sample .= ' ' . ($b['text'] ?? '') . ' ' . ($b['caption'] ?? '');
                }
            }
            $bodyLang = LanguageAuditor::detectLanguage($sample);
        }

        $formatted = HealthTableFormatter::formatArticleTables($articleData, [
            'lang' => $bodyLang,
            'force_reclassify' => (string) $request->getUserVar('forceReclassify') === '1',
            'suggest_footnote' => (string) $request->getUserVar('suggestFootnote') !== '0',
        ]);
        $formatted = LanguageAuditor::normalizeArticleParagraphSpaces($formatted);

        echo json_encode([
            'ok' => true,
            'article' => $formatted,
            'message' => 'Seluruh tabel berhasil diformat sesuai standar tabel ilmiah rumpun kesehatan (3 garis terbuka, perataan stub/numerik, hierarki variabel, & catatan kaki).',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * AJAX POST: Enforce single-space paragraph standard across all article fields
     */
    public function normalizeSpacing($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $changes = 0;
        $normalized = LanguageAuditor::normalizeArticleParagraphSpaces($articleData, $changes);
        echo json_encode([
            'ok' => true,
            'article' => $normalized,
            'changes' => $changes,
            'message' => $changes > 0
                ? "Aturan baku spasi tunggal diterapkan pada {$changes} bidang/paragraf."
                : 'Semua paragraf sudah mematuhi aturan maksimal 1 spasi tunggal.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * AJAX POST: Parse & push bibliography entries into OJS 3.5 Publication -> References menu
     * strictly formatted in the journal's configured citation style (default: Vancouver).
     */
    public function syncReferencesToOjs($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;
        $plugin = $this->getPluginInstance();
        $settings = $this->getSettingsArray($contextId, $plugin);
        $journalStyle = $this->resolveJournalReferenceStyle($context, $settings);

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $submissionId = (int) ($articleData['submission_id'] ?? 0);
        if ($submissionId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Pilih artikel OJS terlebih dahulu sebelum menyinkronkan ke menu References OJS.']);
            return;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            echo json_encode(['ok' => false, 'error' => 'Artikel OJS #' . $submissionId . ' tidak ditemukan.']);
            return;
        }

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            echo json_encode(['ok' => false, 'error' => 'Publication aktif tidak ditemukan untuk artikel ini.']);
            return;
        }

        try {
            $syncRes = $this->syncPublicationReferencesToOjs($publication, $articleData, $journalStyle);
            $updatedArticle = ($journalStyle === 'apa')
                ? $this->normalizeArticleReferencesForStyle($articleData, 'apa')
                : VancouverFormatter::convertAllReferences($articleData);
            $updatedArticle['reference_style'] = $journalStyle;

            $styleLabel = strtoupper($journalStyle);
            echo json_encode([
                'ok' => true,
                'count' => $syncRes['count'],
                'style' => $journalStyle,
                'styleLabel' => $styleLabel,
                'citationsRaw' => $syncRes['citationsRaw'],
                'article' => $updatedArticle,
                'message' => $syncRes['count'] > 0
                    ? "Berhasil memparser {$syncRes['count']} referensi (gaya {$styleLabel}) ke menu References OJS artikel #{$submissionId}."
                    : "Daftar References artikel #{$submissionId} dikosongkan (tidak ada entri referensi).",
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode([
                'ok' => false,
                'error' => 'Gagal memparser ke menu References OJS: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * AJAX POST: Convert and normalize bibliography entries into Vancouver (or active journal) style and check citation sync
     */
    public function formatVancouverReferences($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;
        $plugin = $this->getPluginInstance();
        $settings = $this->getSettingsArray($contextId, $plugin);
        $journalStyle = $this->resolveJournalReferenceStyle($context, $settings);

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $converted = 0;
        if ($journalStyle === 'apa') {
            $updated = $this->normalizeArticleReferencesForStyle($articleData, 'apa', $converted);
        } else {
            $updated = VancouverFormatter::convertAllReferences($articleData, $converted);
        }
        $updated['reference_style'] = $journalStyle;
        $updated = LanguageAuditor::normalizeArticleParagraphSpaces($updated);
        $syncReport = VancouverFormatter::auditSync($updated);

        echo json_encode([
            'ok' => true,
            'article' => $updated,
            'converted' => $converted,
            'style' => $journalStyle,
            'sync' => $syncReport,
            'message' => $converted > 0
                ? 'Berhasil mengonversi ' . $converted . ' entri sumber pustaka ke gaya ' . strtoupper($journalStyle) . '.'
                : 'Seluruh sumber pustaka sudah sesuai gaya ' . strtoupper($journalStyle) . ' jurnal.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST: Generate and download the re-layouted .docx file (2026 Template)
     */
    public function generateDocx($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }

        $plugin = $this->getPluginInstance();
        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            http_response_code(400);
            echo 'Invalid article data JSON';
            return;
        }

        $masterPath = $plugin->getMasterTemplatePath();
        $tmpOut = tempnam(sys_get_temp_dir(), 'kisi_docx_') . '.docx';

        $ok = DocxGenerator::generate($articleData, $masterPath, $tmpOut);
        if (!$ok || !file_exists($tmpOut)) {
            http_response_code(500);
            echo 'Gagal membuat file DOCX dari master template.';
            return;
        }

        $eloc = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($articleData['elocation_id'] ?? 'e76'));
        $downloadName = 'Kisi_Berkelanjutan_' . $eloc . '_Layout2026.docx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($tmpOut));
        readfile($tmpOut);
        @unlink($tmpOut);
        exit;
    }

    /**
     * AJAX POST: Preview the digested Full-Text HTML directly from the generated JATS XML
     */
    public function previewFullText($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $xmlString = JatsXmlGenerator::generate($articleData);
        $htmlDigest = JatsHtmlRenderer::renderFullTextHtml($xmlString, [
            'include_abstract' => true,
            'form_language_mode' => (string) ($articleData['form_mode'] ?? 'en'),
        ]);

        echo json_encode([
            'ok' => true,
            'html' => $htmlDigest,
            'xml' => $xmlString,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST: Generate and download the AmeliCA / OJS JATS 1.2 XML file (.xml)
     */
    public function generateJats($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }

        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);
        if (!is_array($articleData)) {
            http_response_code(400);
            echo 'Invalid article data JSON';
            return;
        }

        $xmlString = JatsXmlGenerator::generate($articleData);
        $eloc = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($articleData['elocation_id'] ?? 'e76'));
        $downloadName = 'Kisi_Berkelanjutan_' . $eloc . '_JATS.xml';

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . strlen($xmlString));
        echo $xmlString;
        exit;
    }

    /**
     * AJAX POST: Generate and directly attach JATS XML (and DOCX) to the OJS 3.5 Article Galleys!
     */
    public function saveToGalley($args, $request)
    {
        if (!$this->ensureAuthorized($request)) {
            return;
        }
        header('Content-Type: application/json; charset=UTF-8');

        $context = $request->getContext();
        $plugin = $this->getPluginInstance();
        $rawPayload = (string) $request->getUserVar('articleJson');
        $articleData = json_decode($rawPayload, true);

        if (!is_array($articleData)) {
            echo json_encode(['ok' => false, 'error' => 'Data artikel JSON tidak valid.']);
            return;
        }

        $submissionId = (int) ($articleData['submission_id'] ?? 0);
        if ($submissionId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Submission ID tidak ditemukan. Pilih artikel OJS terlebih dahulu.']);
            return;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission) {
            echo json_encode(['ok' => false, 'error' => 'Artikel OJS #' . $submissionId . ' tidak ditemukan.']);
            return;
        }

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            echo json_encode(['ok' => false, 'error' => 'Publication aktif tidak ditemukan untuk artikel ini.']);
            return;
        }

        try {
            $eloc = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($articleData['elocation_id'] ?? ('e' . $submissionId)));
            $createdItems = [];

            // 1. Generate JATS XML & attach BOTH to Production Ready Files (for JATS Parser) AND Publication Galley (for Full-Text Digest & Lens Viewer)
            $xmlContent = JatsXmlGenerator::generate($articleData);
            $tmpXml = tempnam(sys_get_temp_dir(), 'kisi_jats_') . '.xml';
            file_put_contents($tmpXml, $xmlContent);

            $xmlFileName = 'Kisi_Berkelanjutan_' . $eloc . '_JATS.xml';
            $prodXmlId = $this->attachProductionReadyFile(
                $submission,
                $context,
                $request,
                $tmpXml,
                $xmlFileName,
                'application/xml'
            );
            if ($prodXmlId) {
                $createdItems[] = 'Production JATS XML (#' . $prodXmlId . ')';
            }

            $xmlGalleyId = $this->attachFileToOjsGalley(
                $submission,
                $publication,
                $context,
                $request,
                $tmpXml,
                $xmlFileName,
                'application/xml',
                'XML (JATS)'
            );
            @unlink($tmpXml);
            if ($xmlGalleyId) {
                $createdItems[] = 'Galley JATS XML & Full-Text Digest (#' . $xmlGalleyId . ')';
            }

            // 2. Generate Re-typeset DOCX & attach to Production Ready Files / DOCX Galley
            $includeDocxGalley = (bool) $request->getUserVar('includeDocxGalley');
            $tmpDocx = tempnam(sys_get_temp_dir(), 'kisi_docx_') . '.docx';
            $docxOk = DocxGenerator::generate($articleData, $plugin->getMasterTemplatePath(), $tmpDocx);
            if ($docxOk && file_exists($tmpDocx)) {
                $docxFileName = 'Kisi_Berkelanjutan_' . $eloc . '_Layout2026.docx';
                if ($includeDocxGalley) {
                    $docxGalleyId = $this->attachFileToOjsGalley(
                        $submission,
                        $publication,
                        $context,
                        $request,
                        $tmpDocx,
                        $docxFileName,
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'DOCX'
                    );
                    if ($docxGalleyId) {
                        $createdItems[] = 'Galley DOCX (#' . $docxGalleyId . ')';
                    }
                } else {
                    $sfId = $this->attachProductionReadyFile(
                        $submission,
                        $context,
                        $request,
                        $tmpDocx,
                        $docxFileName,
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    );
                    if ($sfId) {
                        $createdItems[] = 'File Production DOCX (#' . $sfId . ')';
                    }
                }
            }
            @unlink($tmpDocx);

            // 3. Parse & sync bibliography references + fill missing OJS Publication abstract metadata
            $syncRefsFlag = (string) $request->getUserVar('syncReferences');
            if ($syncRefsFlag !== '0' && !empty($articleData['referensi'])) {
                $contextId = $context ? (int) $context->getId() : 0;
                $settings = $this->getSettingsArray($contextId, $plugin);
                $journalStyle = $this->resolveJournalReferenceStyle($context, $settings);
                $refSync = $this->syncPublicationReferencesToOjs($publication, $articleData, $journalStyle);
                if ($refSync['count'] > 0) {
                    $createdItems[] = 'Menu References OJS (' . $refSync['count'] . ' ' . strtoupper($journalStyle) . ')';
                }
            }
            $this->syncMissingPublicationAbstracts($publication, $articleData);

            echo json_encode([
                'ok' => true,
                'message' => 'Berhasil disimpan langsung ke artikel #' . $submissionId . ': ' . implode(' & ', $createdItems),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            echo json_encode([
                'ok' => false,
                'error' => 'Gagal menyimpan ke Galley OJS: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Helper to attach a file as a Publication Galley in OJS 3.4 / 3.5
     */
    protected function attachFileToOjsGalley(
        $submission,
        $publication,
        $context,
        Request $request,
        string $localFilePath,
        string $displayFileName,
        string $mimeType,
        string $galleyLabel
    ): ?int {
        $contextId = (int) $context->getId();
        $submissionId = (int) $submission->getId();
        $publicationId = (int) $publication->getId();
        $locale = $publication->getData('locale') ?: $context->getPrimaryLocale();

        // Store physical file via OJS File Service (OJS 3.5 app()->get('file'))
        $fileService = $this->getFileService();
        if (!$fileService) {
            throw new \RuntimeException('File service tidak tersedia di OJS.');
        }

        $submissionDir = Repo::submissionFile()->getSubmissionDir($contextId, $submissionId);
        $ext = pathinfo($displayFileName, PATHINFO_EXTENSION);
        $destPath = $submissionDir . '/' . uniqid('kisi_', true) . '.' . $ext;
        $fileId = (int) $fileService->add($localFilePath, $destPath);

        $genreId = $this->resolvePrimaryGenreId($contextId);

        // Reuse existing Galley with matching label if present so re-saving does not create duplicates
        $galleyId = null;
        try {
            $existingGalleys = Repo::galley()->getCollector()
                ->filterByPublicationIds([$publicationId])
                ->getMany();
            foreach ($existingGalleys as $eg) {
                if (strcasecmp(trim((string) $eg->getData('label')), trim($galleyLabel)) === 0) {
                    $galleyId = (int) $eg->getId();
                    break;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        if (!$galleyId) {
            $galley = Repo::galley()->newDataObject();
            $galley->setData('publicationId', $publicationId);
            $galley->setData('label', $galleyLabel);
            $galley->setData('locale', $locale);
            $galleyId = (int) Repo::galley()->add($galley);
        }

        // Create SubmissionFile linked to this Galley
        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('submissionId', $submissionId);
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
        $submissionFile->setData('genreId', $genreId);
        $submissionFile->setData('name', [$locale => $displayFileName]);
        $submissionFile->setData('assocType', PKPApplication::ASSOC_TYPE_REPRESENTATION);
        $submissionFile->setData('assocId', $galleyId);
        $submissionFile->setData('uploaderUserId', (int) $request->getUser()->getId());

        $submissionFileId = (int) Repo::submissionFile()->add($submissionFile);

        // Link submissionFileId back to Galley
        $galleyObj = Repo::galley()->get($galleyId);
        if ($galleyObj) {
            Repo::galley()->edit($galleyObj, ['submissionFileId' => $submissionFileId]);
        }

        return $galleyId;
    }

    /**
     * Helper to attach a file to Production Ready Files in OJS 3.4 / 3.5
     */
    protected function attachProductionReadyFile(
        $submission,
        $context,
        Request $request,
        string $localFilePath,
        string $displayFileName,
        string $mimeType
    ): ?int {
        $contextId = (int) $context->getId();
        $submissionId = (int) $submission->getId();
        $locale = $context->getPrimaryLocale();

        $fileService = $this->getFileService();
        if (!$fileService) {
            throw new \RuntimeException('File service tidak tersedia di OJS.');
        }

        $submissionDir = Repo::submissionFile()->getSubmissionDir($contextId, $submissionId);
        $ext = pathinfo($displayFileName, PATHINFO_EXTENSION);
        $destPath = $submissionDir . '/' . uniqid('kisi_prod_', true) . '.' . $ext;
        $fileId = (int) $fileService->add($localFilePath, $destPath);

        $genreId = $this->resolvePrimaryGenreId($contextId);

        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('submissionId', $submissionId);
        $submissionFile->setData('fileId', $fileId);
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY);
        $submissionFile->setData('genreId', $genreId);
        $submissionFile->setData('name', [$locale => $displayFileName]);
        $submissionFile->setData('uploaderUserId', (int) $request->getUser()->getId());

        return (int) Repo::submissionFile()->add($submissionFile);
    }

    /**
     * Extract comprehensive article metadata directly from OJS 3.5 Submission & Publication objects
     */
    protected function extractOjsSubmissionData($submission, $context, Request $request, array $settings): array
    {
        $publication = $submission->getCurrentPublication();
        $subId = (int) $submission->getId();

        // Titles (ID & EN - supporting both 2-letter OJS 3.5 and 5-letter locale codes)
        $titles = (array) ($publication ? $publication->getData('title') : []);
        $subtitles = (array) ($publication ? $publication->getData('subtitle') : []);

        $titleId = self::cleanHtmlText(self::pickLocaleValue($titles, ['id', 'id_ID'], false));
        $subIdText = self::cleanHtmlText(self::pickLocaleValue($subtitles, ['id', 'id_ID'], false));
        if ($subIdText !== '') {
            $titleId = $titleId !== '' ? (rtrim($titleId, ': ') . ': ' . $subIdText) : $subIdText;
        }

        $titleEn = self::cleanHtmlText(self::pickLocaleValue($titles, ['en', 'en_US'], false));
        $subEnText = self::cleanHtmlText(self::pickLocaleValue($subtitles, ['en', 'en_US'], false));
        if ($subEnText !== '') {
            $titleEn = $titleEn !== '' ? (rtrim($titleEn, ': ') . ': ' . $subEnText) : $subEnText;
        }

        // Section / Article Type
        $articleType = 'ORIGINAL ARTICLE';
        $sectionId = $publication ? (int) $publication->getData('sectionId') : 0;
        if ($sectionId > 0) {
            $section = Repo::section()->get($sectionId);
            if ($section) {
                $secTitle = trim((string) $section->getLocalizedTitle());
                if ($secTitle !== '') {
                    $articleType = mb_strtoupper($secTitle, 'UTF-8');
                }
            }
        }

        // Authors & Affiliations (OJS 3.5 ROR/multi-affiliation objects + legacy fallback)
        $authorsData = [];
        $corrName = '';
        $corrEmail = '';
        $primaryContactId = $publication ? $publication->getData('primaryContactId') : null;

        if ($publication) {
            $authors = $publication->getData('authors');
            if ($authors) {
                $idx = 0;
                foreach ($authors as $author) {
                    $fullName = trim((string) $author->getFullName());
                    $affStr = self::extractAuthorAffiliation($author);
                    $email = trim((string) $author->getData('email'));
                    $isPrimary = ($primaryContactId && (int) $author->getId() === (int) $primaryContactId) || ($idx === 0 && !$primaryContactId);

                    if ($isPrimary) {
                        $corrName = $fullName;
                        $corrEmail = $email;
                    }

                    $authorsData[] = [
                        'name' => $fullName,
                        'affiliation' => $affStr,
                        'email' => $email,
                        'is_corresponding' => $isPrimary,
                    ];
                    $idx++;
                }
            }
        }

        // Review Editor -> Pulled from Metadata > Subjects (supports OJS 3.5 ControlledVocab objects/arrays & legacy strings)
        $reviewEditor = '';
        if ($publication) {
            $subjects = $publication->getData('subjects');
            if (empty($subjects)) {
                $subjects = $this->fetchControlledVocabFallback((int) $publication->getId(), 'submissionSubject');
            }
            $reviewEditor = self::extractControlledVocabString($subjects, ['id', 'id_ID', 'en', 'en_US'], true);
        }

        // Issue & Publication Dates
        $volume = '1';
        $issueNumber = '1';
        $issueYear = '2024';
        $issuePeriod = '';
        $issueId = $publication ? (int) $publication->getData('issueId') : 0;
        if ($issueId > 0) {
            $issue = Repo::issue()->get($issueId);
            if ($issue) {
                if ($issue->getVolume()) {
                    $volume = (string) $issue->getVolume();
                }
                if ($issue->getNumber()) {
                    $issueNumber = (string) $issue->getNumber();
                }
                if ($issue->getYear()) {
                    $issueYear = (string) $issue->getYear();
                }
                $rawIssueTitles = method_exists($issue, 'getData') ? (array) $issue->getData('title') : [];
                $issueTitleId = self::pickLocaleValue($rawIssueTitles, ['id', 'id_ID', 'en', 'en_US'], true);
                if ($issueTitleId === '') {
                    $issueTitleId = trim((string) $issue->getLocalizedTitle());
                }
                $issuePeriod = $issueTitleId;
            }
        }

        $dateSubRaw = $submission->getData('dateSubmitted');
        $recTs = $dateSubRaw ? strtotime((string) $dateSubRaw) : false;
        $receivedDateFormatted = $recTs ? date('F j, Y', $recTs) : '';

        $acceptedDateFormatted = '';
        try {
            if (method_exists(Repo::class, 'decision')) {
                $decisions = Repo::decision()->getCollector()
                    ->filterBySubmissionIds([$subId])
                    ->getMany();
                foreach ($decisions as $dec) {
                    $decType = (int) $dec->getData('decision');
                    // PKP Decision::ACCEPT == 1, INITIAL_DECLINE == 8, etc. Also accept date if decision matches accept
                    if ($decType === 1 || stripos(get_class($dec), 'Accept') !== false) {
                        $decDate = $dec->getData('dateDecided');
                        $accTs = $decDate ? strtotime((string) $decDate) : false;
                        if ($accTs) {
                            $acceptedDateFormatted = date('F j, Y', $accTs);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore if decision collector unavailable
        }

        $datePubRaw = $publication ? $publication->getData('datePublished') : '';
        $ts = $datePubRaw ? strtotime((string) $datePubRaw) : false;
        $publishedDateFormatted = $ts ? date('F j, Y', $ts) : ('March 30, ' . $issueYear);
        $copyrightYear = $ts ? date('Y', $ts) : $issueYear;
        $isVol3Plus = LanguageAuditor::isVolume3Plus($volume);
        $contributorRoles = $isVol3Plus ? LanguageAuditor::buildDefaultContributorRoles($authorsData) : '';

        // Elocation ID / Pages (e.g. e76)
        $pages = $publication ? trim((string) $publication->getData('pages')) : '';
        $elocationId = $pages !== '' ? $pages : ('e' . $subId);
        if (ctype_digit($elocationId)) {
            $elocationId = 'e' . $elocationId;
        }

        // Article View URL
        $dispatcher = $request->getDispatcher();
        $articleUrl = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'article',
            'view',
            [$submission->getBestId()]
        );

        // DOI
        $doi = '';
        if ($publication) {
            if (method_exists($publication, 'getDoi') && $publication->getDoi()) {
                $doi = (string) $publication->getDoi();
            } elseif (($doiObj = $publication->getData('doiObject')) && is_object($doiObj) && method_exists($doiObj, 'getData')) {
                $doi = (string) $doiObj->getData('doi');
            } elseif ($publication->getData('pub-id::doi')) {
                $doi = (string) $publication->getData('pub-id::doi');
            }
        }

        // Abstracts (ID & EN) from OJS Metadata
        $abstracts = (array) ($publication ? $publication->getData('abstract') : []);
        $absIdRaw = self::cleanHtmlText(self::pickLocaleValue($abstracts, ['id', 'id_ID'], false));
        $absEnRaw = self::cleanHtmlText(self::pickLocaleValue($abstracts, ['en', 'en_US'], false));

        // Keywords (ID & EN) from OJS Metadata (supports OJS 3.5 ControlledVocab objects/arrays & legacy strings)
        $keywords = $publication ? $publication->getData('keywords') : [];
        if (empty($keywords)) {
            $keywords = $this->fetchControlledVocabFallback((int) $publication->getId(), 'submissionKeyword');
        }
        $kwId = self::extractControlledVocabString($keywords, ['id', 'id_ID'], false);
        $kwEn = self::extractControlledVocabString($keywords, ['en', 'en_US'], false);

        $effectiveFormMode = $this->resolveFormLanguageMode($context, $settings, [
            'title_id' => $titleId,
            'title_en' => $titleEn,
            'abs_id' => $absIdRaw,
            'abs_en' => $absEnRaw,
            'kw_id' => $kwId,
            'kw_en' => $kwEn,
        ]);

        if ($effectiveFormMode === 'en') {
            if ($titleEn === '') {
                $fallbackTitle = self::cleanHtmlText(self::pickLocaleValue($titles, ['en', 'en_US', 'id', 'id_ID'], true));
                $titleEn = $titleId !== '' ? $titleId : $fallbackTitle;
            }
            if ($absEnRaw === '' && $absIdRaw !== '' && LanguageAuditor::detectLanguage($absIdRaw) === 'en') {
                $absEnRaw = $absIdRaw;
                $absIdRaw = '';
            } elseif ($absEnRaw === '' && $absIdRaw === '') {
                $anyAbs = self::cleanHtmlText(self::pickLocaleValue($abstracts, ['en', 'en_US', 'id', 'id_ID'], true));
                if ($anyAbs !== '' && LanguageAuditor::detectLanguage($anyAbs) === 'en') {
                    $absEnRaw = $anyAbs;
                }
            }
            if ($kwEn === '' && $kwId !== '' && LanguageAuditor::detectLanguage($kwId) === 'en') {
                $kwEn = $kwId;
                $kwId = '';
            } elseif ($kwEn === '' && $kwId === '') {
                $anyKw = self::extractControlledVocabString($keywords, ['en', 'en_US', 'id', 'id_ID'], true);
                if ($anyKw !== '' && LanguageAuditor::detectLanguage($anyKw) === 'en') {
                    $kwEn = $anyKw;
                }
            }
            // JATS XML English-only mode: keep single English title while preserving any Indonesian abstract/keywords for Studio & PDF
            $titleId = '';
        } elseif ($titleId === '') {
            $fallbackTitle = self::cleanHtmlText(self::pickLocaleValue($titles, ['id', 'id_ID', 'en', 'en_US'], true));
            $titleId = $titleEn !== '' ? $titleEn : $fallbackTitle;
        }

        // Auto-build APA Citation for Left Sidebar
        $firstAuthorLast = DocxGenerator::extractFirstAuthorLastName($authorsData);
        $citeTitle = $titleEn !== '' ? $titleEn : $titleId;
        $journalTitle = $settings['journalTitle'] ?? 'Kisi Berkelanjutan: Sains Medis Dan Kesehatan';
        $citation = sprintf(
            '%s et al., (%s). %s. %s, %s(%s), %s. %s',
            $firstAuthorLast,
            $copyrightYear,
            rtrim($citeTitle, '.'),
            $journalTitle,
            $volume,
            $issueNumber,
            $elocationId,
            $articleUrl
        );

        $footerIssueLine = LanguageAuditor::formatIndonesianFooterIssueLine($volume, $issueNumber, $issuePeriod, $issueYear);
        $journalRefStyle = $this->resolveJournalReferenceStyle($context, $settings);
        $ojsReferensi = $this->extractOjsPublicationReferences($publication);

        $data = [
            'submission_id' => $subId,
            'elocation_id' => $elocationId,
            'article_type' => $articleType,
            'form_mode' => $effectiveFormMode,
            'title_id' => $titleId,
            'title_en' => $titleEn,
            'authors' => $authorsData,
            'corresponding_name' => $corrName,
            'corresponding_email' => $corrEmail,
            'citation' => $citation,
            'doi' => $doi,
            'review_editor' => $reviewEditor,
            'received_date' => $receivedDateFormatted,
            'accepted_date' => $acceptedDateFormatted,
            'published_date' => $publishedDateFormatted,
            'copyright_year' => $copyrightYear,
            'volume' => $volume,
            'issue_number' => $issueNumber,
            'footer_issue_line' => $footerIssueLine,
            'article_url' => $articleUrl,
            'journal_path' => $context->getPath(),
            'journal_title' => $journalTitle,
            'journal_short_footer' => $settings['journalShortFooter'] ?? 'Kisi Berkelanjutan: SMK',
            'publisher_name' => $settings['publisherName'] ?? 'PT Karya Inovasi Berkelanjutan',
            'eissn' => $settings['eissn'] ?? '3062-9063',
            'reference_style' => $journalRefStyle,
            'funding' => $settings['defaultFunding'] ?? ($isVol3Plus ? 'None' : 'none'),
            'competing_interests' => $settings['defaultCompetingInterests'] ?? 'The authors have declared that no competing interests exist.',
            'contributor_roles' => $contributorRoles,
            'retypeset_note' => $isVol3Plus ? '' : ($settings['retypesetNote'] ?? "This article was re-typeset in 2026 to standardize the journal's visual layout. No changes were made to the scientific substance, data, or metadata of the original Version of Record."),
            'abstract_id' => [
                'pendahuluan_metode' => $absIdRaw,
                'hasil' => '',
                'kesimpulan_saran' => '',
                'raw' => $absIdRaw,
            ],
            'keywords_id' => $kwId,
            'sdgs_id' => '',
            'abstract_en' => [
                'intro_methods' => $absEnRaw,
                'results' => '',
                'conclusions_recommendations' => '',
                'raw' => $absEnRaw,
            ],
            'keywords_en' => $kwEn,
            'sdgs_en' => '',
            'sections' => [
                'badan_artikel' => [],
                'pendahuluan' => [],
                'metode' => [],
                'hasil' => [],
                'pembahasan' => [],
                'kesimpulan_saran' => [],
            ],
            'referensi' => $ojsReferensi,
        ];

        // Perform automatic local heuristic split on OJS abstract if single paragraph
        $data = GeminiRestructurer::restructureLocally($data);
        return $data;
    }

    /**
     * Merge OJS Database Metadata with Parsed DOCX Body & Abstracts
     */
    protected function mergeOjsAndDocxData(array $ojsData, array $docxData): array
    {
        $formMode = strtolower(trim((string) ($ojsData['form_mode'] ?? 'en')));
        $enOnly = ($formMode === 'en');

        if ($enOnly) {
            if (($ojsData['title_en'] ?? '') === '') {
                $ojsData['title_en'] = ($docxData['title_en'] ?? '') !== '' ? $docxData['title_en'] : ($docxData['title_id'] ?? '');
            }
            $ojsData['title_id'] = '';
        } else {
            if ($ojsData['title_id'] === '' && $docxData['title_id'] !== '') {
                $ojsData['title_id'] = $docxData['title_id'];
            }
            if ($ojsData['title_en'] === '' && $docxData['title_en'] !== '') {
                $ojsData['title_en'] = $docxData['title_en'];
            }
        }
        if ($ojsData['review_editor'] === '' && $docxData['review_editor'] !== '') {
            $ojsData['review_editor'] = $docxData['review_editor'];
        }
        if ($ojsData['doi'] === '' && $docxData['doi'] !== '') {
            $ojsData['doi'] = $docxData['doi'];
        }
        if (($ojsData['received_date'] ?? '') === '' && !empty($docxData['received_date'])) {
            $ojsData['received_date'] = $docxData['received_date'];
        }
        if (($ojsData['accepted_date'] ?? '') === '' && !empty($docxData['accepted_date'])) {
            $ojsData['accepted_date'] = $docxData['accepted_date'];
        }
        if (!empty($docxData['contributor_roles'])) {
            $ojsData['contributor_roles'] = $docxData['contributor_roles'];
        }
        if (!empty($docxData['footer_issue_line']) && ($ojsData['footer_issue_line'] ?? '') === '') {
            $ojsData['footer_issue_line'] = $docxData['footer_issue_line'];
        }
        // Keep Indonesian & English keywords/SDGs strictly separated (ID for DOCX/PDF, EN for DOCX/PDF & JATS XML)
        if ($ojsData['keywords_id'] === '' && $docxData['keywords_id'] !== '') {
            $ojsData['keywords_id'] = $docxData['keywords_id'];
        }
        if (($ojsData['keywords_en'] ?? '') === '' && ($docxData['keywords_en'] ?? '') !== '') {
            $ojsData['keywords_en'] = $docxData['keywords_en'];
        }
        if (!empty($docxData['sdgs_id'])) {
            $ojsData['sdgs_id'] = $docxData['sdgs_id'];
        }
        if (!empty($docxData['sdgs_en'])) {
            $ojsData['sdgs_en'] = $docxData['sdgs_en'];
        }

        if (!LanguageAuditor::isNoAbstractArticleType((string) ($ojsData['article_type'] ?? ''))) {
            // If DOCX has structured abstract, prefer DOCX's structured abstract for both PDF (ID & EN) and JATS XML (EN)
            if (!empty($docxData['abstract_id']['hasil']) || ($ojsData['abstract_id']['pendahuluan_metode'] ?? '') === '') {
                $ojsData['abstract_id'] = $docxData['abstract_id'];
            }
            if (!empty($docxData['abstract_en']['results']) || ($ojsData['abstract_en']['intro_methods'] ?? '') === '') {
                $ojsData['abstract_en'] = $docxData['abstract_en'];
            }
        }

        if (!isset($docxData['sections']['badan_artikel'])) {
            $docxData['sections']['badan_artikel'] = [];
        }
        $ojsData['sections'] = $docxData['sections'];
        if (!empty($docxData['referensi'])) {
            $ojsData['referensi'] = $docxData['referensi'];
        }

        return GeminiRestructurer::restructureLocally($ojsData);
    }

    /**
     * Resolve active form language mode ('en' = English-Only Form, 'bilingual' = ID & EN Forms).
     * Defaults to 'en' while supporting explicit 'bilingual' or 'auto' archive detection.
     */
    protected function resolveFormLanguageMode($context, array $settings = [], array $signals = []): string
    {
        $configured = strtolower(trim((string) ($settings['formLanguageMode'] ?? 'en')));
        if ($configured === 'bilingual' || $configured === 'id_en') {
            return 'bilingual';
        }
        if ($configured === 'auto') {
            $hasDualTitles = trim((string) ($signals['title_id'] ?? '')) !== ''
                && trim((string) ($signals['title_en'] ?? '')) !== '';
            if ($hasDualTitles) {
                return 'bilingual';
            }
        }
        return 'en';
    }

    /**
     * Resolve the journal's authoritative citation/reference style ('vancouver', 'apa', or 'ieee').
     * Respects KisiTypesetter setting override or falls back to OJS CSL plugin settings (default: vancouver).
     */
    protected function resolveJournalReferenceStyle($context, array $settings = []): string
    {
        $configured = strtolower(trim((string) ($settings['referenceStyle'] ?? 'auto')));
        if ($configured !== '' && $configured !== 'auto') {
            return VancouverFormatter::resolveReferenceStyle($configured);
        }

        if ($context) {
            try {
                $contextId = (int) $context->getId();
                // 1. Check loaded generic plugin instance
                if (class_exists('\PKP\plugins\PluginRegistry')) {
                    $csl = \PKP\plugins\PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
                    if ($csl && method_exists($csl, 'getEnabled') && $csl->getEnabled($contextId) && method_exists($csl, 'getSetting')) {
                        $primary = (string) $csl->getSetting($contextId, 'primaryCitationStyle');
                        if (trim($primary) !== '') {
                            return VancouverFormatter::resolveReferenceStyle($primary);
                        }
                    }
                }
                // 2. Fallback via PluginSettingsDAO
                if (class_exists('\PKP\db\DAORegistry')) {
                    $psDao = \PKP\db\DAORegistry::getDAO('PluginSettingsDAO');
                    if ($psDao && method_exists($psDao, 'getPluginSettings')) {
                        foreach (['CitationStyleLanguagePlugin', 'citationstylelanguageplugin'] as $plugName) {
                            $cslSettings = $psDao->getPluginSettings($contextId, $plugName);
                            if (is_array($cslSettings) && !empty($cslSettings['enabled']) && !empty($cslSettings['primaryCitationStyle'])) {
                                return VancouverFormatter::resolveReferenceStyle((string) $cslSettings['primaryCitationStyle']);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Fall back safely to vancouver
            }
        }

        return 'vancouver';
    }

    /**
     * Extract existing references stored in OJS 3.4/3.5 Publication -> References (`citationsRaw` or parsed `citations`).
     */
    protected function extractOjsPublicationReferences($publication): array
    {
        if (!$publication) {
            return [];
        }

        $lines = [];
        try {
            $raw = trim((string) $publication->getData('citationsRaw'));
            if ($raw !== '') {
                $lines = preg_split('/\r?\n/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }

            if (empty($lines)) {
                $citations = $publication->getData('citations');
                if (empty($citations) && method_exists(Repo::class, 'citation')) {
                    $citRepo = Repo::citation();
                    if (method_exists($citRepo, 'getCollector')) {
                        $citations = $citRepo->getCollector()
                            ->filterByPublicationIds([(int) $publication->getId()])
                            ->getMany();
                    } elseif (method_exists($citRepo, 'getByPublicationId')) {
                        $citations = $citRepo->getByPublicationId((int) $publication->getId());
                    }
                }
                if ($citations) {
                    foreach ($citations as $cit) {
                        $txt = '';
                        if (is_object($cit)) {
                            if (method_exists($cit, 'getData')) {
                                $txt = (string) ($cit->getData('rawCitation') ?: '');
                            }
                            if ($txt === '' && method_exists($cit, 'getRawCitation')) {
                                $txt = (string) $cit->getRawCitation();
                            }
                        } elseif (is_string($cit)) {
                            $txt = $cit;
                        }
                        if (trim($txt) !== '') {
                            $lines[] = trim($txt);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore if citations API unavailable
        }

        $out = [];
        foreach ($lines as $line) {
            $clean = VancouverFormatter::stripLeadingNumber((string) $line);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }
        return $out;
    }

    /**
     * Parse and save bibliography entries directly into OJS Publication -> References menu (`citationsRaw` + `citations`).
     */
    protected function syncPublicationReferencesToOjs($publication, array $articleData, string $referenceStyle = 'vancouver'): array
    {
        $refs = (array) ($articleData['referensi'] ?? []);
        $citationsRaw = VancouverFormatter::formatCitationsRawBlock($refs, $referenceStyle);
        $count = ($citationsRaw !== '') ? count(explode("\n", $citationsRaw)) : 0;

        // OJS 3.5 Publication::edit validates citationsRaw as string (never pass null)
        Repo::publication()->edit($publication, ['citationsRaw' => (string) $citationsRaw]);

        // Verify structured citation rows exist when references were provided; trigger Repo::citation()->importCitations() if empty
        try {
            $pubId = (int) $publication->getId();
            if ($pubId > 0 && method_exists(Repo::class, 'citation')) {
                $citRepo = Repo::citation();
                $existingCount = 0;
                if (method_exists($citRepo, 'getCollector')) {
                    $rows = $citRepo->getCollector()->filterByPublicationIds([$pubId])->getMany();
                    foreach ($rows as $_row) {
                        $existingCount++;
                    }
                }
                if ($count > 0 && $existingCount === 0 && method_exists($citRepo, 'importCitations')) {
                    $rm = new \ReflectionMethod($citRepo, 'importCitations');
                    $params = $rm->getParameters();
                    $firstParam = $params[0] ?? null;
                    $expectsInt = $firstParam && $firstParam->getType() && $firstParam->getType()->getName() === 'int';
                    $citRepo->importCitations($expectsInt ? $pubId : $publication, (string) $citationsRaw);
                }
            }
        } catch (\Throwable $e) {
            // Do not abort if Repo::publication()->edit() already persisted citationsRaw cleanly
        }

        return [
            'ok' => true,
            'count' => $count,
            'style' => $referenceStyle,
            'citationsRaw' => $citationsRaw,
        ];
    }

    private function normalizeArticleReferencesForStyle(array $articleData, string $style, int &$convertedCount = 0): array
    {
        if ($style !== 'apa') {
            return VancouverFormatter::convertAllReferences($articleData, $convertedCount);
        }
        $convertedCount = 0;
        $cleanList = [];
        foreach ((array) ($articleData['referensi'] ?? []) as $rawRef) {
            $orig = trim((string) $rawRef);
            if ($orig === '') {
                continue;
            }
            $formatted = VancouverFormatter::formatToApaBody($orig);
            if ($formatted === '') {
                continue;
            }
            if ($formatted !== VancouverFormatter::stripLeadingNumber($orig)) {
                $convertedCount++;
            }
            $cleanList[] = $formatted;
        }
        $articleData['referensi'] = $cleanList;
        return $articleData;
    }

    /**
     * Populate OJS native Publication abstract locales when missing in OJS metadata so OJS themes
     * and external JATS parsers always display an abstract alongside the XML galley.
     */
    protected function syncMissingPublicationAbstracts($publication, array $articleData): void
    {
        if (!$publication || LanguageAuditor::isNoAbstractArticleType((string) ($articleData['article_type'] ?? ''))) {
            return;
        }
        try {
            $isEnglishOnly = LanguageAuditor::isEnglishOnlyArticle($articleData);
            $existing = (array) $publication->getData('abstract');
            $pubLocale = trim((string) ($publication->getData('locale') ?: ($isEnglishOnly ? 'en' : 'id')));

            $buildText = static function (array $parts): string {
                $chunks = [];
                foreach ($parts as $val) {
                    $v = trim((string) $val);
                    if ($v !== '') {
                        $chunks[] = '<p>' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                }
                return implode('', $chunks);
            };

            $idHtml = $isEnglishOnly ? '' : $buildText([
                $articleData['abstract_id']['pendahuluan_metode'] ?? '',
                $articleData['abstract_id']['hasil'] ?? '',
                $articleData['abstract_id']['kesimpulan_saran'] ?? '',
            ]);
            $enHtml = $buildText([
                $articleData['abstract_en']['intro_methods'] ?? '',
                $articleData['abstract_en']['results'] ?? '',
                $articleData['abstract_en']['conclusions_recommendations'] ?? '',
            ]);

            $changed = false;
            $updated = $existing;
            if (!$isEnglishOnly && $idHtml !== '') {
                $idKeys = array_unique([$pubLocale, 'id', 'id_ID']);
                foreach ($idKeys as $k) {
                    if (stripos($k, 'en') === 0) {
                        continue;
                    }
                    if (trim(strip_tags((string) ($updated[$k] ?? ''))) === '') {
                        $updated[$k] = $idHtml;
                        $changed = true;
                        break;
                    }
                }
            }

            if ($enHtml !== '') {
                $titleKeys = array_keys(array_filter((array) $publication->getData('title'), static fn ($v) => trim((string) $v) !== ''));
                $enTitleKeys = array_values(array_filter($titleKeys, static fn ($tk) => stripos((string) $tk, 'en') === 0));
                $enCandidates = array_values(array_unique(array_filter(array_merge(
                    $enTitleKeys,
                    stripos($pubLocale, 'en') === 0 ? [$pubLocale] : [],
                    ['en', 'en_US']
                ))));
                $targetEnKey = $enCandidates[0] ?? 'en';
                $enAlreadyPresent = false;
                foreach ($enCandidates as $cand) {
                    if (array_key_exists($cand, $existing)) {
                        $targetEnKey = $cand;
                        break;
                    }
                }
                foreach ($enCandidates as $cand) {
                    if (trim(strip_tags((string) ($updated[$cand] ?? ''))) !== '') {
                        $enAlreadyPresent = true;
                        break;
                    }
                }
                if (!$enAlreadyPresent) {
                    $updated[$targetEnKey] = $enHtml;
                    $changed = true;
                }
            }

            if ($changed) {
                Repo::publication()->edit($publication, ['abstract' => $updated]);
            }
        } catch (\Throwable $e) {
            // Non-fatal fallback
        }
    }

    /**
     * List all Issues in the journal (current running issues and archives)
     */
    protected function getJournalIssues(int $contextId): array
    {
        $list = [];
        try {
            $issues = Repo::issue()->getCollector()
                ->filterByContextIds([$contextId])
                ->getMany();
            foreach ($issues as $issue) {
                $list[] = [
                    'id' => (int) $issue->getId(),
                    'label' => sprintf(
                        'Vol. %s No. %s (%s)%s',
                        $issue->getVolume() ?: '1',
                        $issue->getNumber() ?: '1',
                        $issue->getYear() ?: '2024',
                        $issue->getLocalizedTitle() ? (' - ' . $issue->getLocalizedTitle()) : ''
                    ),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $list;
    }

    /**
     * List submissions in the journal (optionally filtered by Issue ID)
     */
    protected function getJournalSubmissions(int $contextId, int $issueId = 0): array
    {
        $list = [];
        try {
            $collector = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId]);
            if ($issueId > 0 && method_exists($collector, 'filterByIssueIds')) {
                $collector->filterByIssueIds([$issueId]);
            }
            $submissions = $collector->getMany();
            foreach ($submissions as $sub) {
                $pub = $sub->getCurrentPublication();
                if (!$pub) {
                    continue;
                }
                if ($issueId > 0 && (int) $pub->getData('issueId') !== $issueId) {
                    continue;
                }
                $titles = (array) $pub->getData('title');
                $t = self::pickLocaleValue($titles, ['id', 'id_ID', 'en', 'en_US']);
                $authorsStr = $pub->getShortAuthorString();
                $list[] = [
                    'id' => (int) $sub->getId(),
                    'title' => $t ?: ('Artikel #' . $sub->getId()),
                    'authors' => $authorsStr,
                    'issueId' => (int) $pub->getData('issueId'),
                    'pages' => (string) ($pub->getData('pages') ?: ('e' . $sub->getId())),
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $list;
    }

    /**
     * Find all .docx files attached to a submission in OJS
     */
    protected function getSubmissionDocxFiles(int $submissionId): array
    {
        $files = [];
        try {
            $subFiles = Repo::submissionFile()->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->getMany();

            foreach ($subFiles as $sf) {
                $resolved = $this->resolveSubmissionFilePath($sf);
                $relPath = $resolved['rel_path'];
                $mime = $resolved['mimetype'];
                $name = (string) ($sf->getLocalizedData('name') ?: basename($relPath));
                $isDocx = preg_match('/\.docx$/i', $relPath)
                    || preg_match('/\.docx$/i', $name)
                    || stripos($mime, 'wordprocessingml') !== false;

                if ($isDocx) {
                    $files[] = [
                        'id' => (int) $sf->getId(),
                        'name' => $name !== '' ? $name : ('file_' . $sf->getId() . '.docx'),
                        'stage' => (int) $sf->getData('fileStage'),
                        'rel_path' => $relPath,
                        'abs_path' => $resolved['abs_path'],
                    ];
                }
            }

            // Prefer Production Ready / Proof / Copyedit / newest files first
            usort($files, function (array $a, array $b) {
                $priority = [
                    SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY => 5,
                    SubmissionFile::SUBMISSION_FILE_COPYEDIT => 4,
                    SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION => 3,
                    SubmissionFile::SUBMISSION_FILE_SUBMISSION => 2,
                    SubmissionFile::SUBMISSION_FILE_PROOF => 1,
                ];
                $pa = $priority[$a['stage']] ?? 0;
                $pb = $priority[$b['stage']] ?? 0;
                if ($pa !== $pb) {
                    return $pb <=> $pa;
                }
                return $b['id'] <=> $a['id'];
            });
        } catch (\Throwable $e) {
            // ignore
        }
        return $files;
    }

    /**
     * Resolve OJS File Service (supports OJS 3.5 app()->get('file') and OJS 3.4 fallback)
     */
    protected function getFileService()
    {
        if (function_exists('app')) {
            try {
                $svc = app()->get('file');
                if ($svc) {
                    return $svc;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if (class_exists('\APP\core\Services')) {
            return \APP\core\Services::get('file');
        }
        return null;
    }

    /**
     * Resolve primary "Article Text" Genre ID safely in OJS 3.4/3.5
     */
    protected function resolvePrimaryGenreId(int $contextId): int
    {
        try {
            $genreDao = \PKP\db\DAORegistry::getDAO('GenreDAO');
            if ($genreDao) {
                if (method_exists($genreDao, 'getByKey')) {
                    $primary = $genreDao->getByKey('SUBMISSION', $contextId);
                    if ($primary) {
                        return (int) $primary->getId();
                    }
                }
                $genres = $genreDao->getByContextId($contextId)->toArray();
                if (!empty($genres)) {
                    return (int) $genres[0]->getId();
                }
            }
        } catch (\Throwable $e) {
            // fallback below
        }
        return 1;
    }

    /**
     * Resolve relative & absolute disk path and mimetype from a SubmissionFile
     */
    protected function resolveSubmissionFilePath($sf): array
    {
        $relPath = (string) ($sf->getData('path') ?? '');
        $mime = (string) ($sf->getData('mimetype') ?? '');
        $fileId = (int) $sf->getData('fileId');

        if (($relPath === '' || $mime === '') && $fileId > 0) {
            try {
                $fileSvc = $this->getFileService();
                if ($fileSvc && method_exists($fileSvc, 'get')) {
                    $fObj = $fileSvc->get($fileId);
                    if ($fObj) {
                        if ($relPath === '' && !empty($fObj->path)) {
                            $relPath = (string) $fObj->path;
                        }
                        if ($mime === '' && !empty($fObj->mimetype)) {
                            $mime = (string) $fObj->mimetype;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/\\');
        $absPath = ($relPath !== '' && $filesDir !== '')
            ? ($filesDir . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\'))
            : '';

        return [
            'rel_path' => $relPath,
            'abs_path' => $absPath,
            'mimetype' => $mime,
        ];
    }

    protected function readFromFileService(string $relPath): string
    {
        try {
            $fileSvc = $this->getFileService();
            if ($fileSvc && isset($fileSvc->fs) && method_exists($fileSvc->fs, 'read')) {
                return (string) $fileSvc->fs->read($relPath);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    /**
     * Optional fallback via Repo::controlledVocab() when Publication::getData() returns empty
     */
    protected function fetchControlledVocabFallback(int $publicationId, string $symbolic)
    {
        if ($publicationId <= 0) {
            return [];
        }
        try {
            if (method_exists(Repo::class, 'controlledVocab')) {
                $cvRepo = Repo::controlledVocab();
                if (method_exists($cvRepo, 'getBySymbolic')) {
                    $vocab = $cvRepo->getBySymbolic($symbolic, PKPApplication::ASSOC_TYPE_PUBLICATION, $publicationId);
                    if ($vocab && method_exists($vocab, 'getData')) {
                        return $vocab->getData('entries') ?: [];
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return [];
    }

    protected function getSettingsArray(int $contextId, ?KisiTypesetterPlugin $plugin): array
    {
        $defaults = [
            'geminiApiKey' => '',
            'geminiModel' => 'gemini-2.5-flash',
            'journalTitle' => 'Kisi Berkelanjutan: Sains Medis Dan Kesehatan',
            'journalShortFooter' => 'Kisi Berkelanjutan: SMK',
            'publisherName' => 'PT Karya Inovasi Berkelanjutan',
            'eissn' => '3062-9063',
            'referenceStyle' => 'auto',
            'formLanguageMode' => 'en',
            'defaultFunding' => 'none',
            'defaultCompetingInterests' => 'The authors have declared that no competing interests exist.',
            'retypesetNote' => "This article was re-typeset in 2026 to standardize the journal's visual layout. No changes were made to the scientific substance, data, or metadata of the original Version of Record.",
            'enableFullTextDigest' => '1',
            'replaceDefaultAbstract' => '1',
        ];

        if (!$plugin) {
            return $defaults;
        }

        foreach ($defaults as $k => $v) {
            $saved = $plugin->getSetting($contextId, $k);
            if ($saved !== null && $saved !== '') {
                $defaults[$k] = (string) $saved;
            }
        }
        return $defaults;
    }

    /**
     * Extract author affiliation across OJS 3.5 (getAffiliations() ROR objects) and OJS 3.4 (getData('affiliation'))
     */
    private static function extractAuthorAffiliation($author): string
    {
        $affNames = [];

        if (method_exists($author, 'getAffiliations')) {
            try {
                $affObjs = $author->getAffiliations();
                if ($affObjs) {
                    foreach ($affObjs as $affObj) {
                        $name = '';
                        if (is_string($affObj)) {
                            $name = trim($affObj);
                        } elseif (is_object($affObj)) {
                            if (method_exists($affObj, 'getName')) {
                                $rawName = $affObj->getName(null);
                                if (is_array($rawName)) {
                                    $name = self::pickLocaleValue($rawName, ['id', 'id_ID', 'en', 'en_US'], true);
                                } elseif (is_string($rawName)) {
                                    $name = trim($rawName);
                                }
                            }
                            if ($name === '' && method_exists($affObj, 'getLocalizedName')) {
                                $name = trim((string) $affObj->getLocalizedName());
                            }
                            if ($name === '' && method_exists($affObj, 'getData')) {
                                $rawName = $affObj->getData('name');
                                if (is_array($rawName)) {
                                    $name = self::pickLocaleValue($rawName, ['id', 'id_ID', 'en', 'en_US'], true);
                                } elseif (is_string($rawName)) {
                                    $name = trim($rawName);
                                }
                            }
                        } elseif (is_array($affObj)) {
                            $rawName = $affObj['name'] ?? '';
                            if (is_array($rawName)) {
                                $name = self::pickLocaleValue($rawName, ['id', 'id_ID', 'en', 'en_US'], true);
                            } elseif (is_string($rawName)) {
                                $name = trim($rawName);
                            }
                        }
                        if ($name !== '' && !in_array($name, $affNames, true)) {
                            $affNames[] = $name;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // fallback below
            }
        }

        if (empty($affNames) && method_exists($author, 'getData')) {
            $rawAff = $author->getData('affiliation');
            if (is_string($rawAff) && trim($rawAff) !== '') {
                $affNames[] = trim($rawAff);
            } elseif (is_array($rawAff)) {
                $picked = self::pickLocaleValue($rawAff, ['id', 'id_ID', 'en', 'en_US'], true);
                if ($picked !== '') {
                    $affNames[] = $picked;
                }
            }
        }

        if (empty($affNames) && method_exists($author, 'getLocalizedData')) {
            $locAff = $author->getLocalizedData('affiliation');
            if (is_string($locAff) && trim($locAff) !== '') {
                $affNames[] = trim($locAff);
            }
        }

        return implode('; ', $affNames);
    }

    /**
     * Safely extract OJS 3.5 ControlledVocab terms (handles ['name'=>..., 'source'=>..., 'identifier'=>...] arrays, objects, or plain strings)
     */
    private static function extractControlledVocabString($rawVocabData, array $preferredLocales, bool $fallbackAnyLocale = true): string
    {
        if (is_string($rawVocabData) || is_numeric($rawVocabData)) {
            return trim((string) $rawVocabData);
        }
        if (!is_array($rawVocabData) && !($rawVocabData instanceof \Traversable)) {
            return '';
        }

        $dataArr = is_array($rawVocabData) ? $rawVocabData : iterator_to_array($rawVocabData);
        if (empty($dataArr)) {
            return '';
        }

        // Check preferred locales (exact match first, then prefix match e.g. id -> id_ID)
        foreach ($preferredLocales as $loc) {
            if (!empty($dataArr[$loc])) {
                $joined = self::joinVocabEntries($dataArr[$loc]);
                if ($joined !== '') {
                    return $joined;
                }
            }
        }

        foreach ($preferredLocales as $loc) {
            $prefix = strtolower(explode('_', str_replace('-', '_', $loc))[0]);
            foreach ($dataArr as $k => $v) {
                if (is_string($k) && strtolower(explode('_', str_replace('-', '_', $k))[0]) === $prefix) {
                    $joined = self::joinVocabEntries($v);
                    if ($joined !== '') {
                        return $joined;
                    }
                }
            }
        }

        if ($fallbackAnyLocale) {
            // Handle flat list of entries (numeric keys) or other locale keys
            $isList = array_keys($dataArr) === range(0, count($dataArr) - 1);
            if ($isList) {
                return self::joinVocabEntries($dataArr);
            }
            foreach ($dataArr as $val) {
                $joined = self::joinVocabEntries($val);
                if ($joined !== '') {
                    return $joined;
                }
            }
        }

        return '';
    }

    private static function joinVocabEntries($entries): string
    {
        if (is_string($entries) || is_numeric($entries)) {
            return trim((string) $entries);
        }
        if (!is_array($entries) && !($entries instanceof \Traversable)) {
            return '';
        }

        $terms = [];
        foreach ($entries as $entry) {
            $term = self::extractSingleVocabTerm($entry);
            if ($term !== '' && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }
        return implode('; ', $terms);
    }

    private static function extractSingleVocabTerm($entry): string
    {
        if (is_string($entry) || is_numeric($entry)) {
            return trim((string) $entry);
        }
        if (is_array($entry)) {
            if (array_key_exists('name', $entry)) {
                return self::extractSingleVocabTerm($entry['name']);
            }
            if (array_key_exists('value', $entry)) {
                return self::extractSingleVocabTerm($entry['value']);
            }
            if (array_key_exists('text', $entry)) {
                return self::extractSingleVocabTerm($entry['text']);
            }
            // Localized sub-array (e.g. ['id' => '...', 'en' => '...'])
            return self::pickLocaleValue($entry, ['id', 'id_ID', 'en', 'en_US'], true);
        }
        if (is_object($entry)) {
            if (method_exists($entry, 'getLocalizedName')) {
                $v = $entry->getLocalizedName();
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
            if (method_exists($entry, 'getName')) {
                $v = $entry->getName(null);
                $res = self::extractSingleVocabTerm($v);
                if ($res !== '') {
                    return $res;
                }
            }
            if (isset($entry->name)) {
                return self::extractSingleVocabTerm($entry->name);
            }
            if (method_exists($entry, 'getData')) {
                return self::extractSingleVocabTerm($entry->getData('name'));
            }
        }
        return '';
    }

    private static function cleanHtmlText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = preg_replace('/<\s*(br\s*\/?|\/p|\/div|\/li)\s*>/i', ' ', $html);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return LanguageAuditor::normalizeParagraphSpacing($text);
    }

    private static function pickLocaleValue(array $localizedArray, array $preferredLocales, bool $fallbackAny = true): string
    {
        foreach ($preferredLocales as $loc) {
            if (!empty($localizedArray[$loc]) && is_string($localizedArray[$loc])) {
                return trim($localizedArray[$loc]);
            }
        }
        // Prefix fallback (e.g. id -> id_ID)
        foreach ($preferredLocales as $loc) {
            $prefix = strtolower(explode('_', str_replace('-', '_', $loc))[0]);
            foreach ($localizedArray as $k => $val) {
                if (is_string($k) && strtolower(explode('_', str_replace('-', '_', $k))[0]) === $prefix && is_string($val) && trim($val) !== '') {
                    return trim($val);
                }
            }
        }
        if ($fallbackAny) {
            foreach ($localizedArray as $val) {
                if (!empty($val) && is_string($val)) {
                    return trim($val);
                }
            }
        }
        return '';
    }
}
