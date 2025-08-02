<?php
/** @var array $_ */
use OCP\Util;

Util::addScript('pdf_tagger', 'admin');
?>

<div id="pdf_tagger_settings" class="section">
    <h2>PDF Auto Tagger</h2>
    <p class="settings-hint">Configure the connection to your local Ollama instance and define which folders and tags to use for automatic PDF categorization.</p>

    <table class="grid">
        <tbody>
            <tr>
                <td><label for="pdf_tagger_processing_mode">Processing Mode</label></td>
                <td>
                    <input type="checkbox" id="pdf_tagger_use_pdf_parser" class="setting" data-key="use_pdf_parser"
                           <?php if ($_['use_pdf_parser']) p('checked'); ?>>
                    <label for="pdf_tagger_use_pdf_parser">Use internal PDF text parser</label>
                    <p id="parser-hint" class="settings-hint">
                        <!-- This hint will be updated by javascript -->
                    </p>
                </td>
            </tr>
            <tr>
                <td><label for="pdf_tagger_ollama_url">Ollama URL</label></td>
                <td><input type="text" id="pdf_tagger_ollama_url" class="setting" data-key="ollama_url"
                           value="<?php p($_['ollama_url']); ?>"
                           placeholder="e.g., http://localhost:11434"></td>
            </tr>
            <tr>
                <td><label for="pdf_tagger_model_name">Model Name</label></td>
                <td><input type="text" id="pdf_tagger_model_name" class="setting" data-key="model_name"
                           value="<?php p($_['model_name']); ?>"
                           placeholder="e.g., llama3 or llava">
                </td>
            </tr>
            <tr>
                <td><label for="pdf_tagger_scan_folders">Folder(s) to Scan</label></td>
                <td><input type="text" id="pdf_tagger_scan_folders" class="setting" data-key="scan_folders"
                           value="<?php p($_['scan_folders']); ?>"
                           placeholder="e.g., /Scans, /Documents/Incoming">
                    <p class="settings-hint">Comma-separated list of full paths to folders.</p>
                </td>
            </tr>
            <tr>
                <td><label for="pdf_tagger_available_tags">Tags to Use</label></td>
                <td><input type="text" id="pdf_tagger_available_tags" class="setting" data-key="available_tags"
                           value="<?php p($_['available_tags']); ?>"
                           placeholder="e.g., Invoice, Receipt, Contract">
                     <p class="settings-hint">Comma-separated list of tags the AI can choose from.</p>
                </td>
            </tr>
            <tr>
                <td></td>
                <td>
                    <button id="start-categorization-btn" type="button" class="button">Start Categorization</button>
                    <span id="categorization-status" class="settings-hint" style="margin-left: 10px;"></span>
                </td>
            </tr>
        </tbody>
    </table>
</div>
