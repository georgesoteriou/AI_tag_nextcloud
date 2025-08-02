<?php
namespace OCA\PdfTagger\Controller;

use OCA\PdfTagger\BackgroundJob\CategorizeFiles;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IQueue;
use OCP\IRequest;
use OCP\IUserSession;

class AjaxController extends Controller {
    private IQueue $queue;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        IQueue $queue,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->queue = $queue;
        $this->userSession = $userSession;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * This endpoint enqueues the background job.
     */
    public function startCategorization(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['status' => 'error', 'message' => 'User not logged in'], 401);
        }

        // Add the job to the queue, passing the user who initiated it
        $this->queue->add(CategorizeFiles::class, ['userId' => $user->getUID()]);

        return new JSONResponse(['status' => 'success']);
    }
}

