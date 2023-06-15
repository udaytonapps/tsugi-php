<?php

namespace Tsugi\UI\StandardSync;

use CourseBase;
use Tsugi\Util\U;
use Tsugi\Core\LTIX;
use Tsugi\UI\LessonsOrchestrator;
use Tsugi\UI\LessonsUIHelper;
use Tsugi\Grades\GradeUtil;


class StandardSyncAdapter extends CourseBase
{
    public SyncCourse $course;
    /** Index by resource_link */
    public $resource_links;
    /** The individual module */
    public $activeModule;
    /** The position of the module */
    public $position;
    /** The root path to the context-specific session data */
    public $contextRoot;
    /** All of the current user's registration information */
    public $registrations = array();

    public $contextKey;
    public int $contextId;
    protected string $category;

    function __construct($relativeContext, $moduleAnchor = null, $pageAnchor = null, $index = null)
    {
        try {
            global $CFG, $PDOX;
            $this->category = substr($relativeContext, strrpos($relativeContext, '/') + 1);
            $this->contextRoot = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI' . $relativeContext;
            $courseObject =  LessonsOrchestrator::getLessonsJson($relativeContext);
            $this->resource_links = array();
            LessonsOrchestrator::modifyLessonsAndLinks($courseObject, $this->resource_links);

            $moduleAnchor = isset($_GET['anchor']) ? $_GET['anchor'] : $moduleAnchor;
            $index = isset($_GET['index']) ? $_GET['index'] : $index;

            $this->course = new SyncCourse($courseObject);

            // Search for the selected anchor or index position
            $count = 0;
            if ($moduleAnchor || $index) {
                foreach ($this->course->modules as $mod) {
                    $count++;
                    if ($moduleAnchor !== null && isset($mod->anchor) && $moduleAnchor != $mod->anchor) continue;
                    if ($index !== null && $index != $count) continue;
                    if ($moduleAnchor == null && isset($mod->anchor)) $moduleAnchor = $mod->anchor;
                    $this->activeModule = $mod;
                    $this->contextKey = "{$this->category}_{$this->activeModule->anchor}";
                    $this->position = $count;
                    // Set contextId
                    $this->contextId = LessonsOrchestrator::getOrInitContextId($this->activeModule->title, $this->contextKey);
                }
            }

            // Populate registration data
            if (isset($this->contextId)) {
                // TODO: be sure to consider context - can we get rid of module_launch_id and use linkId?
                // Get user's registration information
                // If logged in, get the user_id from the session data
                $userId = isset($_SESSION['lti']['user_id']) ? $_SESSION['lti']['user_id'] : null;
                $allreg = $PDOX->allRowsDie(
                    "SELECT r.registration_id, i.module_launch_id, i.session_date, i.session_location, i.modality, r.attendance_status
                    FROM {$CFG->dbprefix}learn_module_instance AS i
                    INNER JOIN {$CFG->dbprefix}learn_registration AS r ON i.instance_id = r.instance_id
                    WHERE r.user_id = :UID AND r.context_id = :contextId",
                    array(':UID' => $userId, ':contextId' => $this->contextId)
                );
                if ($allreg) {
                    // Load all info into registrations
                    foreach ($allreg as $reg) {
                        $allfeedback = $PDOX->rowDie(
                            "SELECT count(*) as NUM_FEEDBACK
                        FROM {$CFG->dbprefix}learn_question_response AS r
                        INNER JOIN {$CFG->dbprefix}learn_question AS q ON r.question_id = q.question_id
                        WHERE r.registration_id = :RID AND r.context_id = :contextId AND q.question_type IN ('FEEDBACK', 'FEEDBACK_OUTCOME')",
                            array(':RID' => $reg["registration_id"], ':contextId' => $this->contextId)
                        );
                        $didfeedback = $allfeedback && $allfeedback["NUM_FEEDBACK"] > 0;
                        $reg["feedback"] = $didfeedback;
                        $this->registrations[$reg["module_launch_id"]] = $reg;
                    }
                }
            }
        } catch (\Throwable $e) {
            LessonsUIHelper::errorLog($e);
        }
    }

    public function header($buffer = false)
    {
        LessonsUIHelper::renderGeneralHeader($this, $buffer);
    }

    public function render($buffer = null)
    {
        LessonsUIHelper::debugLog($this->course);
        LTIX::session_start();

        // If logged in, get the user_id from the session data
        $userId = isset($_SESSION['lti']['user_id']) ? $_SESSION['lti']['user_id'] : null;

        if ($this->isSingle()) {
            LessonsUIHelper::debugLog('Rendering Module Details Page');
            return $this->renderModuleLandingPage();
        } else {
            LessonsUIHelper::debugLog('Rendering All Modules Page');
            return $this->renderAllModulesPage();
        }
    }

    public function footer()
    {
        LessonsUIHelper::renderGeneralFooter();
    }

    public function getAllProgramsPageData()
    {
        global $CFG;
        $modules = $this->assembleAllowedModules();
        return (object)[
            'genericImg' => $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png',
            'course' => $this->course,
            'moduleData' => $modules,
        ];
    }

    public function getModuleCardData()
    {
        return $this->assembleAllowedModules();
    }

    private function isSingle()
    {
        return ($this->activeModule !== null || $this->position !== null);
    }

    private function getBreadcrumbs()
    {
        global $CFG;

        $breadcrumbs = [];
        $crumb = (object)['path' => $CFG->apphome, 'label' => 'Home'];
        $breadcrumbs[] = $crumb;
        $crumb = (object)['path' => $CFG->apphome . '/programs', 'label' => 'All Programs'];
        $breadcrumbs[] = $crumb;
        if (isset($this->category)) {
            $crumb = (object)['path' => $CFG->apphome . '/programs' . '/' . $this->category, 'label' => $this->course->title];
            $breadcrumbs[] = $crumb;

            if (isset($this->activeModule)) {
                $crumb = (object)['path' => $CFG->apphome . '/programs' . '/' . $this->category . '/' . $this->activeModule->anchor, 'label' => $this->activeModule->title];
                $breadcrumbs[] = $crumb;
            }
        }
        return $breadcrumbs;
    }

    private function renderModuleLandingPage()
    {

        // Find prev/next pages to populate anchors (using 0-index)
        $prevModule = $this->findPreviousModule($this->position - 1);
        $nextModule = $this->findNextModule($this->position - 1);

        $restPath = U::rest_path();
        $returnUrl = "{$restPath->parent}/{$restPath->controller}/{$this->category}/{$this->activeModule->anchor}";

        $moduleMetadata = $this->getModuleMetadata($this->activeModule);

        // Mock admin tool
        // $_SESSION["admin"] = true;
        // $buttonLtiUrl = $launchPath . "/admin-{$this->activeModule->anchor}";

        $twig = LessonsUIHelper::twig();
        echo $twig->render('sync-module-landing-page.twig', [
            'program' => $this->category,
            'prevModule' => $prevModule,
            'nextModule' => $nextModule,
            'contextRoot' => $this->contextRoot,
            'returnUrl' => $returnUrl,
            'breadcrumbs' => $this->getBreadcrumbs(),

            'module' => (array)$this->activeModule,
            'moduleMetadata' => $moduleMetadata,
        ]);
    }

    private function renderAllModulesPage()
    {
        global $CFG;
        $allowedModuleData = $this->assembleAllowedModules();

        $twig = LessonsUIHelper::twig();

        echo $twig->render('sync-all-modules-page.twig', [
            'genericImg' => $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png',
            'breadcrumbs' => $this->getBreadcrumbs(),
            'contextRoot' => $this->contextRoot,
            'course' => $this->course,
            'moduleData' => $allowedModuleData,
        ]);
    }

    private function assembleAllowedModules()
    {
        global $CFG;

        $moduleCardData = [];
        foreach ($this->course->modules as $module) {

            // Don't render hidden or auth-only modules // TODO
            // if (isset($module->hidden) && $module->hidden) continue;
            // if (isset($module->login) && $module->login && !isset($_SESSION['id'])) continue;

            // foreach ($module->lti as &$lti) { // TODO
            //     $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id . '?redirect_url=' . $_SERVER['REQUEST_URI'];
            //     $lti->calulated_launch_path = $launch_path;
            // }

            $moduleMetadata = $this->getModuleMetadata($module);

            $encodedAnchor = urlencode($module->anchor);

            array_push($moduleCardData, (object)[
                'module' => $module,
                'contextRoot' => $this->contextRoot,
                'moduleUrl' => "{$CFG->apphome}/programs/{$this->category}/{$encodedAnchor}",
                // Status as well as sync-specific, registration related data
                'moduleMetadata' => $moduleMetadata,
            ]);
        }

        LessonsUIHelper::debugLog($moduleCardData);

        return $moduleCardData;
    }

    private function getModuleMetadata($module)
    {
        global $CFG, $PDOX;
        $userId = isset($_SESSION['lti']['user_id']) ? $_SESSION['lti']['user_id'] : null;

        // Concerning session timing
        $instances = $PDOX->allRowsDie(
            "SELECT session_date, session_location, duration_minutes, module_launch_id, capacity
            FROM {$CFG->dbprefix}learn_module_instance
            WHERE module_launch_id = :moduleId",
            array(':moduleId' => $module->anchor)
        );
        $upcoming = array();
        foreach ($instances as $instance) {
            $theDate = date('m/d', strtotime($instance['session_date']));
            if (strtotime($instance['session_date']) >= strtotime('now')) {
                array_push($upcoming, $theDate);
            }
        }
        $isOver = count($upcoming) <= 0;

        // Concerning user registration
        $allreg = $PDOX->allRowsDie(
            "SELECT r.registration_id, i.module_launch_id, i.session_date, i.session_location, i.modality, r.attendance_status
                FROM {$CFG->dbprefix}learn_module_instance AS i
                INNER JOIN {$CFG->dbprefix}learn_registration AS r ON i.instance_id = r.instance_id
                WHERE r.user_id = :UID",
            array(':UID' => $userId)
        );
        if ($allreg) {
            // Load all info into registrations
            foreach ($allreg as $reg) {
                $allfeedback = $PDOX->rowDie(
                    "SELECT count(*) as NUM_FEEDBACK
                    FROM {$CFG->dbprefix}learn_question_response AS r
                    INNER JOIN {$CFG->dbprefix}learn_question AS q ON r.question_id = q.question_id
                    WHERE r.registration_id = :RID AND q.question_type IN ('FEEDBACK', 'FEEDBACK_OUTCOME')",
                    array(':RID' => $reg["registration_id"])
                );
                $didfeedback = $allfeedback && $allfeedback["NUM_FEEDBACK"] > 0;
                $reg["feedback"] = $didfeedback;
                $this->registrations[$reg["module_launch_id"]] = $reg;
            }
        }

        // Assemble template variables
        $registered = array_key_exists($module->anchor, $this->registrations) && $this->registrations[$module->anchor]["attendance_status"] == "REGISTERED";
        $attended = array_key_exists($module->anchor, $this->registrations) && ($this->registrations[$module->anchor]["attendance_status"] == "ATTENDED" || $this->registrations[$module->anchor]["attendance_status"] == "LATE");
        $absent = isset($this->registrations[$module->anchor]["attendance_status"]) &&  $this->registrations[$module->anchor]["attendance_status"] === "ABSENT";

        $greeting = $attended ? 'You attended the following session' : ($absent ? "We missed you at the session on" : "You are registered for");
        $regDate = isset($this->registrations[$module->anchor]["session_date"]) ? new \DateTime($this->registrations[$module->anchor]["session_date"]) : null;
        $regDate = isset($regData) ? $regDate->format("D. M j, Y - g:i a") : null;
        $location = isset($this->registrations[$module->anchor]["session_location"]) ? $this->registrations[$module->anchor]["session_location"] : null;

        $gaveFeedback = isset($this->registrations[$module->anchor]["feedback"]) ? $this->registrations[$module->anchor]["feedback"] : false;

        $restPath = U::rest_path();
        $loginPath = "{$restPath->parent}/tsugi/login.php";
        $launchPath = "{$restPath->parent}/{$restPath->controller}/{$this->category}/{$module->anchor}/lti-launch";

        $status = null;
        // Choose button action
        $buttonAction = null;
        $buttonLtiUrl = $launchPath;
        if (!isset($userId)) {
            $buttonAction = 'LOGIN';
        } else if ($registered) {
            $buttonAction = 'CHANGE';
            $buttonLtiUrl .= "/reg-{$module->anchor}";
            $status = 'IN_PROGRESS';
        } else if ($attended) {
            if ($gaveFeedback) {
                $buttonAction = 'COMPLETE';
                $buttonLtiUrl .= "/reg-{$module->anchor}";
                $status = 'COMPLETE';
            } else {
                $buttonAction = 'FEEDBACK';
                $buttonLtiUrl .= "/feedback-{$module->anchor}";
                $status = 'IN_PROGRESS';
            }
        } else if ($absent) {
            $buttonAction = 'CHANGE';
            $buttonLtiUrl .= "/reg-{$module->anchor}";
        } else {
            $buttonAction = 'REGISTER';
            $buttonLtiUrl .= "/reg-{$module->anchor}";
        }

        return (object)[
            'greeting' => $greeting,
            'regDate' => $regDate,
            'location' => $location,
            'buttonAction' => $buttonAction,
            'ltiUrl' => $buttonLtiUrl,
            'loginUrl' => $loginPath,
            'attended' => $attended,
            'absent' => $absent,
            'registered' => $registered,
            // Status to determine whether to render
            'status' => $status,
        ];
    }

    public function getLtiContent($module = null)
    {
        // $ltiContent = [];
        // if (isset($module)) {
        //     $modules = [$module];
        // } else {
        //     $modules = $this->course->modules;
        // }
        // foreach ($modules as $mod) {
        //     foreach ($mod->lessons as $lesson) {
        //         foreach ($lesson->pages as $page) {
        //             foreach ($page->contents as $content) {
        //                 if (isset($content->lti)) {
        //                     $ltiContent[] = $content->lti;
        //                 }
        //             }
        //         }
        //     }
        // }
        // return $ltiContent;
    }

    /**
     * Get an LTI or Discussion associated with a resource link ID
     */
    public function getLtiByRlid($resource_link_id)
    {
        if (isset($this->course->discussions)) {
            foreach ($this->course->discussions as $discussion) {
                if ($discussion->resource_link_id == $resource_link_id) return $discussion;
            }
        }

        foreach ($this->course->modules as $mod) {
            if (isset($mod->lti)) {
                foreach ($mod->lti as $lti) {
                    if ($lti->resource_link_id == $resource_link_id) return $lti;
                }
            }
            if (isset($mod->discussions)) {
                foreach ($mod->discussions as $discussion) {
                    if ($discussion->resource_link_id == $resource_link_id) return $discussion;
                }
            }
        }
        return null;
    }

    /**
     * Get a module associated with a resource link ID
     */
    public function getModuleByRlid($resource_link_id)
    {
        foreach ($this->course->modules as $mod) {
            if (isset($mod->lti)) {
                foreach ($mod->lti as $lti) {
                    if ($lti->resource_link_id == $resource_link_id) return $mod;
                }
            }
            if (isset($mod->discussions)) {
                foreach ($mod->discussions as $discussion) {
                    if ($discussion->resource_link_id == $resource_link_id) return $mod;
                }
            }
        }
        return null;
    }

    private function findPreviousModule(int $moduleIndex)
    {
        if ($moduleIndex > 0) {
            // Paginate backward if prev page is there in same lesson
            return $this->course->modules[$moduleIndex - 1];
        } else {
            // No previous page found
            return null;
        }
    }

    private function findNextModule(int $moduleIndex)
    {
        if ($moduleIndex < count($this->course->modules) - 1) {
            // Paginate forward if next module
            return $this->course->modules[$moduleIndex + 1];
        } else {
            // No next page found
            return null;
        }
    }
}
