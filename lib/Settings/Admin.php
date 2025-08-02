<?php
namespace OCA\PdfTagger\Settings;

use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Template;
use OCP\Util;

class Admin implements ISettings {
    private IConfig $config;
    private string $appName;

    public function __construct(IConfig $config, string $appName) {
        $this->config = $config;
        $this->appName = $appName;
    }

    /**
     * @return Template
     */
    public function getPanel(): Template {
        $template = new Template($this->appName, 'settings-admin');

        // Pre-fill the form with saved values, providing defaults if they don't exist
        $template->assign('ollama_url', $this->config->getAppValue($this->appName, 'ollama_url', 'http://localhost:11434'));
        $template->assign('model_name', $this->config->getAppValue($this->appName, 'model_name', 'llama3'));
        $template->assign('scan_folders', $this->config->getAppValue($this->appName, 'scan_folders', '/Scans'));
        $template->assign('available_tags', $this->config->getAppValue($this->appName, 'available_tags', 'Invoice, Receipt, Contract, Report'));
        
        // Add the new setting for choosing the processing mode. Default to true (using the parser).
        $template->assign('use_pdf_parser', $this->config->getAppValue($this->appName, 'use_pdf_parser', '1') === '1');

        Util::addScript($this->appName, 'admin');

        return $template;
    }

    public function getSectionID(): string {
        return 'pdf_tagger';
    }

    public function getPriority(): int {
        return 50;
    }
}

