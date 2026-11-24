<?php
/**
 * @file plugins/generic/kisiTypesetter/KisiTypesetterPlugin.php
 *
 * Copyright (c) 2026 PT Karya Inovasi Berkelanjutan
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class KisiTypesetterPlugin
 * @brief Automated DOCX Re-Layout (2026 Template), AmeliCA/OJS JATS 1.2 XML Generator,
 *        and Direct Full-Text XML Digest Renderer on the OJS 3.5 Article Landing Page.
 */

namespace APP\plugins\generic\kisiTypesetter;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RedirectAction;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class KisiTypesetterPlugin extends GenericPlugin
{
    /** @var bool Track whether Templates::Article::Main already rendered full text in this request */
    protected bool $fullTextRenderedInHook = false;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled($mainContextId)) {
            // 1. Page handler for Studio Re-Layout & JATS XML
            Hook::add('LoadHandler', [$this, 'setupHandler']);

            // 2. Direct Full-Text JATS XML Digest on public article landing page (/article/view/{id})
            Hook::add('Templates::Article::Main', [$this, 'renderFullTextOnArticlePage']);

            // 3. Backend quick-launch button & custom-theme fallback for Full-Text Digest
            Hook::add('TemplateManager::display', [$this, 'onTemplateDisplay']);
        }

        return $success;
    }

    /**
     * Route /index.php/{journal}/kisiTypesetter/{op} to KisiTypesetterHandler
     */
    public function setupHandler(string $hookName, array $args): bool
    {
        $page = $args[0] ?? '';
        if ($page !== 'kisiTypesetter') {
            return false;
        }

        $this->loadPluginClasses();

        if (array_key_exists(3, $args)) {
            $args[3] = new \APP\plugins\generic\kisiTypesetter\classes\KisiTypesetterHandler($this);
            return true;
        }

        $sourceFile =& $args[2];
        $sourceFile = $this->getPluginPath() . '/classes/KisiTypesetterHandler.php';
        if (!defined('HANDLER_CLASS')) {
            define('HANDLER_CLASS', 'APP\plugins\generic\kisiTypesetter\classes\KisiTypesetterHandler');
        }
        return true;
    }

    /**
     * Load helper classes on demand
     */
    protected function loadPluginClasses(): void
    {
        require_once($this->getPluginPath() . '/classes/HealthTableFormatter.php');
        require_once($this->getPluginPath() . '/classes/LanguageAuditor.php');
        require_once($this->getPluginPath() . '/classes/DocxParser.php');
        require_once($this->getPluginPath() . '/classes/DocxGenerator.php');
        require_once($this->getPluginPath() . '/classes/JatsXmlGenerator.php');
        require_once($this->getPluginPath() . '/classes/JatsHtmlRenderer.php');
        require_once($this->getPluginPath() . '/classes/GeminiRestructurer.php');
        require_once($this->getPluginPath() . '/classes/KisiTypesetterHandler.php');
    }

    /**
     * Hook: Templates::Article::Main
     * Automatically digests the article's JATS XML file and renders Full-Text HTML
     * directly on the public OJS 3.5 article landing page!
     */
    public function renderFullTextOnArticlePage(string $hookName, array $args): bool
    {
        $smarty =& $args[1];
        $output =& $args[2];

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }

        // Check if built-in full-text digest is enabled (default: enabled)
        $digestEnabled = $this->getSetting($context->getId(), 'enableFullTextDigest');
        if ($digestEnabled === '0' || $digestEnabled === false) {
            return false;
        }

        $article = $smarty->getTemplateVars('article') ?: $smarty->getTemplateVars('submission');
        $publication = $smarty->getTemplateVars('publication');
        if (!$publication && $article && method_exists($article, 'getCurrentPublication')) {
            $publication = $article->getCurrentPublication();
        }
        if (!$article) {
            return false;
        }

        $xmlContent = $this->findArticleJatsXmlContent((int) $article->getId(), $publication);
        if ($xmlContent === '') {
            return false;
        }

        require_once($this->getPluginPath() . '/classes/JatsHtmlRenderer.php');
        $hideThemeAbstract = ($this->getSetting($context->getId(), 'replaceDefaultAbstract') !== '0');

        $html = \APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer::renderFullTextHtml($xmlContent, [
            'include_abstract' => $hideThemeAbstract,
        ]);

        if ($html !== '') {
            if ($hideThemeAbstract) {
                $html .= <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('kisi-sec-abstract')) return;
    var defaultAbs = document.querySelectorAll('.obj_article_details .item.abstract, .article-details .article-abstract, section.item.abstract');
    defaultAbs.forEach(function(el) {
        if (!el.contains(document.getElementById('kisi-jats-fulltext-container'))) {
            el.style.display = 'none';
        }
    });
});
</script>
HTML;
            }
            $output .= $html;
            $this->fullTextRenderedInHook = true;
        }

        return false;
    }

    /**
     * Hook: TemplateManager::display
     * 1. Injects floating quick-launch button in OJS editorial backend
     * 2. Provides automatic fallback injection on frontend/pages/article.tpl for custom OJS themes
     */
    public function onTemplateDisplay(string $hookName, array $args): bool
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $template = (string) ($args[1] ?? '');

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }

        // A. Public Article Landing Page fallback (if theme doesn't fire Templates::Article::Main)
        if (strpos($template, 'frontend/pages/article.tpl') !== false && !$this->fullTextRenderedInHook) {
            $digestEnabled = $this->getSetting($context->getId(), 'enableFullTextDigest');
            if ($digestEnabled !== '0' && $digestEnabled !== false) {
                $article = $templateMgr->getTemplateVars('article') ?: $templateMgr->getTemplateVars('submission');
                $publication = $templateMgr->getTemplateVars('publication');
                if (!$publication && $article && method_exists($article, 'getCurrentPublication')) {
                    $publication = $article->getCurrentPublication();
                }
                if ($article) {
                    $xmlContent = $this->findArticleJatsXmlContent((int) $article->getId(), $publication);
                    if ($xmlContent !== '') {
                        require_once($this->getPluginPath() . '/classes/JatsHtmlRenderer.php');
                        $hideThemeAbstract = ($this->getSetting($context->getId(), 'replaceDefaultAbstract') !== '0');
                        $html = \APP\plugins\generic\kisiTypesetter\classes\JatsHtmlRenderer::renderFullTextHtml($xmlContent, [
                            'include_abstract' => $hideThemeAbstract,
                        ]);
                        if ($html !== '') {
                            $jsonHtml = json_encode($html, JSON_UNESCAPED_UNICODE);
                            $fallbackScript = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('kisi-jats-fulltext-container')) return;
    var target = document.querySelector('.obj_article_details .main_entry')
        || document.querySelector('.article-details .article-main')
        || document.querySelector('.item.abstract')
        || document.querySelector('.obj_article_details');
    if (target) {
        var wrapper = document.createElement('div');
        wrapper.innerHTML = {$jsonHtml};
        if (target.classList.contains('abstract')) {
            target.parentNode.insertBefore(wrapper, target.nextSibling);
            target.style.display = 'none';
        } else {
            target.appendChild(wrapper);
        }
    }
});
</script>
HTML;
                            $existingHead = (string) $templateMgr->getTemplateVars('additionalHeadData');
                            $templateMgr->assign('additionalHeadData', $existingHead . "\n" . $fallbackScript);
                        }
                    }
                }
            }
        }

        // B. Editorial Backend Quick-Launch Button
        $router = $request->getRouter();
        if (!method_exists($router, 'getRequestedPage')) {
            return false;
        }

        $page = (string) $router->getRequestedPage($request);
        if (!in_array($page, ['workflow', 'authorDashboard', 'submissions', 'manageIssues', 'management'], true)) {
            return false;
        }

        $dispatcher = $request->getDispatcher();
        $studioUrl = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'kisiTypesetter',
            'studio'
        );

        $escapedUrl = htmlspecialchars($studioUrl, ENT_QUOTES, 'UTF-8');

        $script = <<<HTML
<script>
(function() {
    if (document.getElementById('kisi-typesetter-fab')) return;
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('kisi-typesetter-fab')) return;
        var baseUrl = "{$escapedUrl}";
        var m = window.location.pathname.match(/\/workflow\/(?:index|access)\/(\d+)/) || window.location.search.match(/submissionId=(\d+)/);
        var targetUrl = m ? (baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + 'submissionId=' + m[1]) : baseUrl;
        var btn = document.createElement('a');
        btn.id = 'kisi-typesetter-fab';
        btn.href = targetUrl;
        btn.innerHTML = '⚡ <strong>Kisi Re-Layout &amp; JATS XML</strong>';
        btn.style.cssText = 'position:fixed;bottom:22px;right:24px;z-index:99999;background:linear-gradient(135deg,#0a2e46,#1565c0);color:#fff;padding:10px 18px;border-radius:999px;font-family:system-ui,-apple-system,sans-serif;font-size:13px;text-decoration:none;box-shadow:0 6px 20px rgba(10,46,70,0.35);border:1px solid rgba(255,255,255,0.2);display:inline-flex;align-items:center;gap:8px;transition:transform 0.15s ease;';
        btn.onmouseenter = function() { btn.style.transform = 'translateY(-2px)'; };
        btn.onmouseleave = function() { btn.style.transform = 'none'; };
        document.body.appendChild(btn);
    });
})();
</script>
HTML;

        $existingHeader = (string) $templateMgr->getTemplateVars('additionalHeadData');
        $templateMgr->assign('additionalHeadData', $existingHeader . "\n" . $script);

        return false;
    }

    /**
     * Find the JATS XML file content attached to an article's Galleys or Production Ready Files
     */
    public function findArticleJatsXmlContent(int $submissionId, $publication = null): string
    {
        // 1. Check Publication Galleys first
        if ($publication) {
            $galleys = $publication->getData('galleys');
            if (!$galleys) {
                try {
                    $galleys = Repo::galley()->getCollector()
                        ->filterByPublicationIds([(int) $publication->getId()])
                        ->getMany();
                } catch (\Throwable $e) {
                    $galleys = [];
                }
            }
            if ($galleys) {
                foreach ($galleys as $galley) {
                    $sfId = (int) $galley->getData('submissionFileId');
                    if ($sfId > 0) {
                        $sf = Repo::submissionFile()->get($sfId);
                        if ($sf) {
                            $xml = $this->readXmlFromSubmissionFile($sf);
                            if ($xml !== '') {
                                return $xml;
                            }
                        }
                    }
                }
            }
        }

        // 2. Fallback: Check Submission Files (Production Ready / Proof / JATS stages)
        try {
            $subFiles = Repo::submissionFile()->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->getMany();
            foreach ($subFiles as $sf) {
                $xml = $this->readXmlFromSubmissionFile($sf);
                if ($xml !== '') {
                    return $xml;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '';
    }

    /**
     * Read XML content from an OJS 3.4/3.5 SubmissionFile (via disk path or Flysystem)
     */
    protected function readXmlFromSubmissionFile($sf): string
    {
        $filesDir = rtrim((string) Config::getVar('files', 'files_dir'), '/\\');
        $relPath = (string) ($sf->getData('path') ?? '');
        $mime = (string) ($sf->getData('mimetype') ?? '');
        $name = (string) ($sf->getLocalizedData('name') ?: '');
        $fileSvc = null;

        if (($relPath === '' || $mime === '') && ($fileId = (int) $sf->getData('fileId')) > 0) {
            try {
                if (function_exists('app')) {
                    $fileSvc = app()->get('file');
                } elseif (class_exists('\APP\core\Services')) {
                    $fileSvc = \APP\core\Services::get('file');
                }
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

        $isXml = preg_match('/\.xml$/i', $relPath)
            || preg_match('/\.xml$/i', $name)
            || stripos($mime, 'xml') !== false;

        if (!$isXml || $relPath === '') {
            return '';
        }

        $absPath = $filesDir . DIRECTORY_SEPARATOR . ltrim($relPath, '/\\');
        if ($filesDir !== '' && file_exists($absPath)) {
            return (string) file_get_contents($absPath);
        }

        try {
            if (!$fileSvc && function_exists('app')) {
                $fileSvc = app()->get('file');
            }
            if ($fileSvc && isset($fileSvc->fs) && method_exists($fileSvc->fs, 'read')) {
                return (string) $fileSvc->fs->read($relPath);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '';
    }

    /**
     * Add direct launch action in the OJS Plugins table
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $context = $request->getContext();
        if (!$context) {
            return $actions;
        }

        $dispatcher = $request->getDispatcher();
        $studioUrl = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'kisiTypesetter',
            'studio'
        );

        array_unshift(
            $actions,
            new LinkAction(
                'openStudio',
                new RedirectAction($studioUrl),
                __('plugins.generic.kisiTypesetter.openStudio'),
                null
            )
        );

        return $actions;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.kisiTypesetter.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.kisiTypesetter.description');
    }

    /**
     * Helper to get path to embedded master DOCX template
     */
    public function getMasterTemplatePath(): string
    {
        return $this->getPluginPath() . '/resources/master_template.docx';
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\kisiTypesetter\KisiTypesetterPlugin', '\KisiTypesetterPlugin');
}
