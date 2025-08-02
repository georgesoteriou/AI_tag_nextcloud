<?php
namespace OCA\PdfTagger\BackgroundJob;

use OCP\BackgroundJob\Job;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IContainer;
use OCP\ITagManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Http\Client\IClientService;
use OCP\ITimeFactory;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser;

class CategorizeFiles extends Job {

    protected IContainer $container;

    // The constructor is now much simpler. We only ask for the services
    // that the Job class itself requires, plus the main service container.
    public function __construct(ITimeFactory $timeFactory, IContainer $container) {
        parent::__construct($timeFactory);
        $this->container = $container;
    }

    /**
     * This is the main execution method for the job.
     */
    protected function run($argument): void {
        // At runtime, we get all the services we need from the container.
        $config = $this->container->get(IConfig::class);
        $userManager = $this->container->get(IUserManager::class);
        $logger = $this->container->get(LoggerInterface::class);

        if (!is_array($argument) || !isset($argument['userId'])) {
            $logger->error("CategorizeFiles job ran without a valid userId argument. Halting.");
            return;
        }
        $userId = $argument['userId'];

        $user = $userManager->get($userId);
        if (!$user) {
            $logger->error("User '$userId' not found for categorization job.");
            return;
        }
        $logger->info("Starting PDF categorization job for user: " . $user->getUID());

        // We still use runAsUser to ensure permissions are correct.
        $userManager->runAsUser($user, function () use ($user, $config, $logger) {
            $this->processUserFiles($user, $config, $logger);
        });
    }

    /**
     * Contains the logic to find and process files for a specific user.
     */
    private function processUserFiles(IUser $user, IConfig $config, LoggerInterface $logger): void {
        // We get the remaining services we need here.
        $rootFolder = $this->container->get(IRootFolder::class);
        $tagManager = $this->container->get(ITagManager::class);
        $clientService = $this->container->get(IClientService::class);

        $appName = 'pdf_tagger';
        $usePdfParser = $config->getAppValue($appName, 'use_pdf_parser', '1') === '1';
        $ollamaUrl = $config->getAppValue($appName, 'ollama_url');
        $model = $config->getAppValue($appName, 'model_name');
        $foldersStr = $config->getAppValue($appName, 'scan_folders', '');
        $tagsStr = $config->getAppValue($appName, 'available_tags', '');

        if (empty($ollamaUrl) || empty($model) || empty($foldersStr) || empty($tagsStr)) {
            $logger->warning("PDF Tagger is not fully configured for user '{$user->getUID()}'. Halting job.");
            return;
        }

        $folders = array_map('trim', explode(',', $foldersStr));
        $availableTags = array_map('trim', explode(',', $tagsStr));
        $userFolder = $rootFolder->getUserFolder($user->getUID());

        foreach ($folders as $folderPath) {
            try {
                $node = $userFolder->get($folderPath);
                if ($node instanceof Folder) {
                    $this->processFolder($node, $availableTags, $usePdfParser, $ollamaUrl, $model, $tagManager, $clientService, $logger);
                }
            } catch (NotFoundException $e) {
                $logger->warning("Folder not found: '$folderPath' for user {$user->getUID()}");
            }
        }
    }

    private function processFolder(Folder $folder, array $availableTags, bool $usePdfParser, string $ollamaUrl, string $model, ITagManager $tagManager, IClientService $clientService, LoggerInterface $logger): void {
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof File && strtolower($node->getExtension()) === 'pdf') {
                $this->processFile($node, $availableTags, $usePdfParser, $ollamaUrl, $model, $tagManager, $clientService, $logger);
            }
        }
    }

    private function processFile(File $file, array $availableTags, bool $usePdfParser, string $ollamaUrl, string $model, ITagManager $tagManager, IClientService $clientService, LoggerInterface $logger): void {
        $fileTagsObject = $tagManager->getTagsForObjects([$file->getId()]);
        $existingTags = $fileTagsObject[$file->getId()] ?? [];
        if (!empty(array_intersect($existingTags, $availableTags))) {
            $logger->info("Skipping file with existing tag: " . $file->getPath());
            return;
        }

        $chosenTag = null;
        if ($usePdfParser) {
            try {
                $parser = new Parser();
                $pdf = $parser->parseContent($file->getContent());
                $text = mb_substr($pdf->getText(), 0, 4000);
                if (empty(trim($text))) {
                    $logger->warning("PDF '{$file->getPath()}' contains no extractable text.");
                    return;
                }
                $chosenTag = $this->getTagFromOllamaText($text, $availableTags, $ollamaUrl, $model, $clientService, $logger);
            } catch (\Exception $e) {
                $logger->error("Failed to parse PDF '{$file->getPath()}': " . $e->getMessage());
                return;
            }
        } else {
            $pdfContent = $file->getContent();
            if (empty($pdfContent)) {
                $logger->warning("PDF '{$file->getPath()}' is empty.");
                return;
            }
            $base64Pdf = base64_encode($pdfContent);
            $chosenTag = $this->getTagFromOllamaFile($base64Pdf, $availableTags, $ollamaUrl, $model, $clientService, $logger);
        }

        if ($chosenTag && in_array($chosenTag, $availableTags)) {
            $tagManager->tagAs($file->getId(), $chosenTag);
            $logger->info("Tagged '{$file->getName()}' as '{$chosenTag}'");
        } else {
            $logger->warning("Ollama returned an invalid or empty tag ('$chosenTag') for file: " . $file->getPath());
        }
    }

    private function getTagFromOllamaText(string $text, array $tags, string $url, string $model, IClientService $clientService, LoggerInterface $logger): ?string {
        $prompt = "Analyze the following document text and classify it using exactly one tag from this list: [" . implode(', ', $tags) . "]. Respond with only the single most appropriate tag and nothing else.\n\nText: \"$text\"";
        $payload = ['model' => $model, 'prompt' => $prompt, 'stream' => false];
        return $this->sendToOllama($payload, $url, $clientService, $logger);
    }

    private function getTagFromOllamaFile(string $base64Content, array $tags, string $url, string $model, IClientService $clientService, LoggerInterface $logger): ?string {
        $prompt = "You are a document classification expert. Analyze the provided document and classify it using exactly one of the following tags: [" . implode(', ', $tags) . "]. Your response must be only the single, most appropriate tag from the list. Do not add any explanation or punctuation.";
        $payload = ['model' => $model, 'prompt' => $prompt, 'stream' => false, 'images' => [$base64Content]];
        return $this->sendToOllama($payload, $url, $clientService, $logger);
    }

    private function sendToOllama(array $payload, string $url, IClientService $clientService, LoggerInterface $logger): ?string {
        try {
            $client = $clientService->newClient();
            $response = $client->post("$url/api/generate", ['json' => $payload, 'timeout' => 120]);
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true);
            return trim($data['response'] ?? '', " \t\n\r\0\x0B\"");
        } catch (\Exception $e) {
            $logger->error("Ollama API request failed: " . $e->getMessage());
            return null;
        }
    }
}
