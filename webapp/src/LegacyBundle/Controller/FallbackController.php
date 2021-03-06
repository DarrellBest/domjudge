<?php declare(strict_types=1);

namespace LegacyBundle\Controller;

use DOMJudgeBundle\Service\BalloonService;
use DOMJudgeBundle\Service\DOMJudgeService;
use DOMJudgeBundle\Service\EventLogService;
use DOMJudgeBundle\Service\ScoreboardService;
use DOMJudgeBundle\Service\SubmissionService;
use DOMJudgeBundle\Utils\Utils;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class FallbackController extends Controller
{
    private $webDir;

    /**
     * @var DOMJudgeService
     */
    private $DOMJudgeService;

    /**
     * @var EventLogService
     */
    protected $eventLogService;

    /**
     * @var ScoreboardService
     */
    protected $scoreboardService;

    /**
     * @var BalloonService
     */
    protected $balloonService;

    /**
     * @var SubmissionService
     */
    protected $submissionService;

    /**
     * @var \Twig_Environment
     */
    protected $twig;

    public function __construct(
        $webDir,
        Container $container,
        DOMJudgeService $DOMJudgeService,
        EventLogService $eventLogService,
        ScoreboardService $scoreboardService,
        BalloonService $balloonService,
        SubmissionService $submissionService,
        \Twig_Environment $twig
    ) {
        $this->webDir            = $webDir;
        $this->DOMJudgeService   = $DOMJudgeService;
        $this->eventLogService   = $eventLogService;
        $this->scoreboardService = $scoreboardService;
        $this->balloonService    = $balloonService;
        $this->submissionService = $submissionService;
        $this->twig              = $twig;
        $this->setContainer($container);
    }

    public function fallback(Request $request, $path)
    {
        if ($this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_FULLY')) {
            $user = $this->get('security.token_storage')->getToken()->getUser();
            $user->setLastLogin(Utils::now());
            $user->setLastIpAddress($this->DOMJudgeService->getClientIp());
            $this->getDoctrine()->getManager()->flush();

            $_SESSION['username'] = $user->getUsername();
        }


        $thefile = realpath($this->webDir . $request->getPathInfo());

        if ($request->server->has('REQUEST_URI')) {
            $_SERVER['REQUEST_URI'] = $request->server->get('REQUEST_URI');
        }

        $_SERVER['PHP_SELF'] = basename($path);
        $_SERVER['SCRIPT_NAME'] = basename($path);// This is used in a few scripts to set refererrer
        if ($thefile!==false && is_dir($thefile)) {
            $thefile = realpath($thefile . "/index.php");
            $_SERVER['PHP_SELF'] = "index.php";

            // Make sure it ends with a trailing slash, otherwise redirect
            $pathInfo = $request->getPathInfo();
            $requestUri = $request->getRequestUri();
            if (rtrim($pathInfo, ' /') == $pathInfo) {
                $url = str_replace($pathInfo, $pathInfo . '/', $requestUri);
                return $this->redirect($url, 301);
            }
        }
        if ($thefile===false || !file_exists($thefile)) {
            return Response::create('Not found.', 404);
        }
        chdir(dirname($thefile));
        ob_start();
        global $G_SYMFONY, $G_EVENT_LOG, $G_SCOREBOARD_SERVICE, $G_BALLOON_SERVICE, $G_SYMFONY_TWIG,
               $G_SUBMISSION_SERVICE;
        $G_SYMFONY = $this->DOMJudgeService;
        $G_EVENT_LOG = $this->eventLogService;
        $G_SCOREBOARD_SERVICE = $this->scoreboardService;
        $G_BALLOON_SERVICE = $this->balloonService;
        $G_SUBMISSION_SERVICE = $this->submissionService;
        $G_SYMFONY_TWIG = $this->twig;
        require($thefile);

        $http_response_code = http_response_code();
        if ($http_response_code === false) {
            // When called from phpunit, the response is not set,
            // which would break the following Response::create call.
            $http_response_code = 200;
        }
        $response = Response::create(ob_get_clean(), $http_response_code);

        // Headers may already have been sent on pages with streaming output.
        if (!headers_sent()) {
            $headers = headers_list();
            header_remove();
            foreach ($headers as $header) {
                $pieces = explode(':', $header);
                $headerName = array_shift($pieces);
                $response->headers->set($headerName, trim(implode(':', $pieces)), false);
            }
        }

        if (!$response->headers->has('Content-Type')) {
            $contentType = mime_content_type($thefile);
            if ($contentType !== 'text/x-php') {
                $response->headers->set('Content-Type', $contentType);
            }
        }

        return $response;
    }
}
