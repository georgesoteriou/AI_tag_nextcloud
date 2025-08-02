<?php
namespace OCA\PdfTagger\Settings;

use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Template;
use OCP\Util;

class Admin implements ISettings {
    private IConfig $config;
    private string $appName;

    public function __construct(IConfig $config, string $appName = 'pdf_tagger') {
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
        
        // Add the setting for choosing the processing mode. Default to true (using the parser).
        $template->assign('use_pdf_parser', $this->config->getAppValue($this->appName, 'use_pdf_parser', '1') === '1');

        Util::addScript($this->appName, 'admin');

        return $template;
    }

    /**
     * @return string the section ID, e.g. 'sharing'
     */
    public function getSection(): string {
        return 'pdf_tagger'; // The unique ID for the settings section
    }

    /**
	 * @return string the human-readable name of the section
	 */
	public function getName(): string {
		return 'PDF Auto Tagger'; // The name displayed in the settings navigation
	}

    /**
     * @return int whether the form should be rather on the top or bottom of all list elements
     */
    public function getPriority(): int {
        return 50; // Controls the order in the settings menu
    }
}
