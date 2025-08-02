<?php
namespace OCA\PdfTagger\BackgroundJob;

use OCP\BackgroundJob\Job;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\ITagManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser;

class CategorizeFiles extends Job {

    private IConfig $config;
    private IRootFolder $rootFolder;
    private IUserManager $userManager;
    private ITagManager $tagManager;
    private IClientService $clientService;
    private LoggerInterface $logger;

    public function __construct(
        IConfig $config, IRootFolder $rootFolder, IUserManager $userManager,
        ITagManager $tagManager, IClientService $clientService, LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->rootFolder = $rootFolder;
        $this->userManager = $userManager;
        $this->tagManager = $tagManager;
        $this->clientService = $clientService;
        $this->logger = $logger;
    }

    protected function run($argument): void {
        $userId = $argument['userId'] ?? null;
        if (!$userId) {
            $this->logger->error("CategorizeFiles job ran without a userId.");
            return;
        }
        $user = $this->userManager->get($userId);
        if (!$user) {
            $this->logger->error("User '$userId' not found for categorization job.");
            return;
        }
        $this->logger->info("Starting PDF categorization job for user: " . $user->getUID());
        $this->userManager->runAsUser($user, function () use ($user) {
            $this->processUserFiles($user);
        });
    }

    private function processUserFiles(IUser $user): void {
        $appName = 'pdf_tagger';
        $usePdfParser = $this->config->getAppValue($appName, 'use_pdf_parser', '1') === '1';
        $ollamaUrl = $this->config->getAppValue($appName, 'ollama_url');
        $model = $this->config->getAppValue($appName, 'model_name');
        $foldersStr = $this->config->getAppValue($appName, 'scan_folders', '');
        $tagsStr = $this->config->getAppValue($appName, 'available_tags', '');

        if (empty($ollamaUrl) || empty($model) || empty($foldersStr) || empty($tagsStr)) {
            $this->logger->warning("PDF Tagger is not fully configured for user '{$user->getUID()}'. Halting job.");
            return;
        }

        $folders = array_map('trim', explode(',', $foldersStr));
        $availableTags = array_map('trim', explode(',', $tagsStr));
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        foreach ($folders as $folderPath) {
            try {
                $node = $userFolder->get($folderPath);
                if ($node instanceof Folder) {
                    $this->processFolder($node, $availableTags, $usePdfParser, $ollamaUrl, $model);
                }
            } catch (NotFoundException $e) {
                $this->logger->warning("Folder not found: '$folderPath' for user {$user->getUID()}");
            }
        }
    }

    private function processFolder(Folder $folder, array $availableTags, bool $usePdfParser, string $ollamaUrl, string $model): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && strtolower($node->getExtension()) === 'pdf') {
                $this->processFile($node, $availableTags, $usePdfParser, $ollamaUrl, $model);
            }
        }
    }

    private function processFile(File $file, array $availableTags, bool $usePdfParser, string $ollamaUrl, string $model): void {
        $fileTagsObject = $this->tagManager->getTagsForObjects([$file->getId()]);
        $existingTags = $fileTagsObject[$file->getId()] ?? [];
        if (!empty(array_intersect($existingTags, $availableTags))) {
            $this->logger->info("Skipping file with existing tag: " . $file->getPath());
            return;
        }

        $chosenTag = null;
        if ($usePdfParser) {
            // MODE 1: Extract text and send to a text model
            try {
                $parser = new Parser();
                $pdf = $parser->parseContent($file->getContent());
                $text = mb_substr($pdf->getText(), 0, 4000);
                if (empty(trim($text))) {
                    $this->logger->warning("PDF '{$file->getPath()}' contains no extractable text.");
                    return;
                }
                $chosenTag = $this->getTagFromOllamaText($text, $availableTags, $ollamaUrl, $model);
            } catch (\Exception $e) {
                $this->logger->error("Failed to parse PDF '{$file->getPath()}': " . $e->getMessage());
                return;
            }
        } else {
            // MODE 2: Base64 encode the whole file and send to a multi-modal model
            $pdfContent = $file->getContent();
            if (empty($pdfContent)) {
                $this->logger->warning("PDF '{$file->getPath()}' is empty.");
                return;
            }
            $base64Pdf = base64_encode($pdfContent);
            $chosenTag = $this->getTagFromOllamaFile($base64Pdf, $availableTags, $ollamaUrl, $model);
        }

        if ($chosenTag && in_array($chosenTag, $availableTags)) {
            $this->tagManager->tagAs($file->getId(), $chosenTag);
            $this->logger->info("Tagged '{$file->getName()}' as '{$chosenTag}'");
        } else {
            $this->logger->warning("Ollama returned an invalid or empty tag ('$chosenTag') for file: " . $file->getPath());
        }
    }

    private function getTagFromOllamaText(string $text, array $tags, string $url, string $model): ?string {
        $prompt = "Analyze the following document text and classify it using exactly one tag from this list: [" . implode(', ', $tags) . "]. Respond with only the single most appropriate tag and nothing else.\n\nText: \"$text\"";
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false
        ];
        return $this->sendToOllama($payload, $url);
    }

    private function getTagFromOllamaFile(string $base64Content, array $tags, string $url, string $model): ?string {
        $prompt = "You are a document classification expert. Analyze the provided document and classify it using exactly one of the following tags: [" . implode(', ', $tags) . "]. Your response must be only the single, most appropriate tag from the list. Do not add any explanation or punctuation.";
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'images' => [$base64Content]
        ];
        return $this->sendToOllama($payload, $url);
    }

    private function sendToOllama(array $payload, string $url): ?string {
        try {
            $client = $this->clientService->newClient();
            $response = $client->post("$url/api/generate", [
                'json' => $payload,
                'timeout' => 120,
            ]);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
            return trim($data['response'] ?? '', " \t\n\r\0\x0B\"");
        } catch (\Exception $e) {
            $this->logger->error("Ollama API request failed: " . $e->getMessage());
            return null;
        }
    }
}

