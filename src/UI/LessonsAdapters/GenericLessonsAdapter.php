<?php

use Tsugi\Util\U;
use Tsugi\Core\LTIX;
use Tsugi\UI\LessonsOrchestrator;
use Tsugi\UI\LessonsUIHelper;
use Tsugi\Grades\GradeUtil;

class GenericAdapter extends CourseBase
{

    // General Properties
    /** Index by resource_link */
    public $resource_links;
    /** The individual module */
    public $activeModule;

    // Sync Properties
    public Course $course;
    /** The position of the module */
    public $position;
    /** The root path to the context-specific session data */
    public $contextRoot;
    /** All of the current user's registration information */
    public $registrations = array();

    // Async Properties
    /** The individual page */
    public $activePage;
    /** The anchor of the lesson page */
    public $pageAnchor;
    /** The index of the active page */
    public $pageIndex;
    /** The index of the active lesson */
    public $lessonIndex;

    public $contextKey;
    public int $contextId;
    public string $category;

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

            $this->course = new Course($courseObject);

            // Search for the selected anchor or index position
            $count = 0;
            if ($moduleAnchor || $index) {
                foreach ($this->course->modules as $mod) {
                    $count++;
                    if ($moduleAnchor !== null && isset($mod->anchor) && $moduleAnchor != $mod->anchor) continue;
                    if ($index !== null && $index != $count) continue;
                    if ($moduleAnchor == null && isset($mod->anchor)) $moduleAnchor = $mod->anchor;

                    if (isset($mod->facilitators) && count($mod->facilitators)) {
                        // Populate facilitator list
                        $populatedList = [];
                        foreach ($mod->facilitators as &$facilitator) {
                            if (is_string($facilitator)) {
                                $populatedList[] = LessonsOrchestrator::getFacilitatorByEmail($facilitator);
                            } else {
                                throw new ErrorException('Facilitators should be an array of email strings!');
                            }
                        }
                        $mod->facilitators = $populatedList;
                    }
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
                    "SELECT r.registration_id, i.module_launch_id, i.session_date, i.session_location, i.modality, i.meeting_link, r.attendance_status
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
            if (isset($pageAnchor)) {
                foreach ($this->activeModule->lessons as $lessonIndex => $lesson) {
                    foreach ($lesson->pages as $pageIndex => $page) {
                        if (isset($page->anchor) && $pageAnchor == $page->anchor) {
                            $this->activePage = $page;
                            $this->pageIndex = $pageIndex;
                            $this->lessonIndex = $lessonIndex;
                        }
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

        if (isset($this->activePage)) {
            LessonsUIHelper::debugLog('Rendering A Lessons Page');
            return $this->renderLessonPage();
        } else if ($this->isSingle()) {
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
            'genericImgAlt' => 'University of Dayton Chapel',
            'course' => $this->course,
            'moduleData' => $modules,
            'courseUrl' => $CFG->apphome . '/programs/' . $this->category
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

                if (isset($this->activePage)) {
                    $crumb = (object)['path' => $CFG->apphome . '/programs' . '/' . $this->category . '/' . $this->activeModule->anchor . '/' . $this->activePage->anchor, 'label' => $this->activePage->title];
                    $breadcrumbs[] = $crumb;
                }
            }
        }
        return $breadcrumbs;
    }

    private function renderLessonPage()
    {
        global $CFG;
        $twig = LessonsUIHelper::twig();
        // Find prev/next pages to populate anchors
        $prevPage = $this->findPreviousPage($this->lessonIndex, $this->pageIndex, $this->activeModule);
        $nextPage = $this->findNextPage($this->lessonIndex, $this->pageIndex, $this->activeModule);
        $rest_path = U::rest_path();
        $launch_path = "{$rest_path->parent}/{$rest_path->controller}/{$this->category}/{$this->activeModule->anchor}/{$this->activePage->anchor}/lti-launch";
        $this->updatePagesProgress($this->category, $this->activeModule->anchor, $this->activePage->anchor);
        $progress = $this->getPagesProgress($this->activeModule);
        echo $twig->render('async-module-lesson-page.twig', [
            'program' => $this->category,
            'breadcrumbs' => $this->getBreadcrumbs(),
            'prevPage' => $prevPage,
            'nextPage' => $nextPage,
            'module' => $this->activeModule,
            'page' => $this->activePage,
            'ltiRoot' => $launch_path,
            'authorized' => true, // TODO
            'progress' => $progress,
            'urlRoot' => $CFG->apphome
        ]);
    }

    private function renderModuleLandingPage()
    {
        global $CFG, $PDOX;
        if (isset($this->activeModule->async) && $this->activeModule->async) {
            $twig = LessonsUIHelper::twig();
            $progress = $this->getPagesProgress($this->activeModule);
            echo $twig->render('async-module-landing-page.twig', [
                'program' => $this->category,
                'breadcrumbs' => $this->getBreadcrumbs(),
                'base_url_warpwire' => $this->base_url_warpwire,
                'base_url_youtube' => $this->base_url_youtube,
                'module' => $this->activeModule,
                'progress' => $progress,
            ]);
        } else {
            // Find prev/next pages to populate anchors (using 0-index)
            $prevModule = $this->findPreviousModule($this->position - 1);
            $nextModule = $this->findNextModule($this->position - 1);

            $restPath = U::rest_path();
            $returnUrl = "{$restPath->parent}/{$restPath->controller}/{$this->category}/{$this->activeModule->anchor}";

            $moduleMetadata = $this->getModuleMetadata($this->activeModule);

            // Mock admin tool
            // $_SESSION["admin"] = true;
            // $buttonLtiUrl = $launchPath . "/admin-{$this->activeModule->anchor}";

            // Find upcoming sessions
            $upcomingInstances = $PDOX->allRowsDie(
                "SELECT session_date, session_location, duration_minutes, module_launch_id, module_program, capacity FROM {$CFG->dbprefix}learn_module_instance WHERE module_launch_id = '".$this->activeModule->anchor."' AND session_date >=CURDATE() ORDER BY session_date ASC;"
            );
            $upcomingSessions = [];
            foreach($upcomingInstances as $instance){
                $regDate = isset($instance["session_date"]) ? new DateTime($instance["session_date"]) : null;
                $instance['session_date'] = isset($regDate) ? $regDate->format("D., M. j, Y") : null;
                $regDate = isset($regDate) ? $regDate->format("g:i A") : null;
                $instance['session_time'] = $regDate;
                $instance["duration"] = isset($instance["duration_minutes"]) && $instance["duration_minutes"] !== "" ? $instance["duration_minutes"]." min." : "";
                array_push($upcomingSessions,$instance);
            }


            $twig = LessonsUIHelper::twig();
            echo $twig->render('sync-module-landing-page.twig', [
                'program' => $this->category,
                'prevModule' => $prevModule,
                'nextModule' => $nextModule,
                'contextRoot' => $this->contextRoot,
                'returnUrl' => $returnUrl,
                'breadcrumbs' => $this->getBreadcrumbs(),
                'upcomingSessions' => $upcomingSessions,
                'module' => (array)$this->activeModule,
                'moduleMetadata' => $moduleMetadata,
            ]);
        }
    }

    private function renderAllModulesPage()
    {
        global $CFG;
        $twig = LessonsUIHelper::twig();
        $allowedModules = $this->assembleAllowedModules();

        echo $twig->render('all-modules-page.twig', [
            'genericImg' => $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png',
            'genericImgAlt' => 'University of Dayton Chapel',
            'breadcrumbs' => $this->getBreadcrumbs(),
            'contextRoot' => $this->contextRoot,
            'course' => $this->course,
            'moduleData' => $allowedModules,
        ]);
    }

    private function assembleAllowedModules()
    {
        global $CFG, $PDOX;
        $instances = $PDOX->allRowsDie(
            "SELECT session_date, session_location, duration_minutes, module_launch_id, module_program, capacity FROM {$CFG->dbprefix}learn_module_instance ORDER BY session_date ASC"
        );
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
            $instancesNow = array_keys(array_column($instances, 'module_launch_id'),$module->anchor);
            $monthString = '';
            $monthNowPretty = '';
            foreach ($instancesNow as $instanceKey) {
                $lastMonth = $monthNowPretty;
                $monthNow = isset($instances[$instanceKey]['session_date']) ? new \DateTime($instances[$instanceKey]['session_date']) : null;
                $monthNowPretty = isset($monthNow) ? $monthNow->format("M").'.' : null;
                if(empty($monthString)){
                    $monthString = $monthNowPretty;
                } else if (!str_contains($monthString, $monthNowPretty)) {
                    $monthString = $monthString.' / '.$monthNowPretty;
                }
            }
            if ($monthString == '' && isset($module->calendar)) {
                $monthString = $module->calendar;
            }
            if (isset($module->async) && $module->async) {
                $type = 'async';
            } else {
                $type = 'sync';
            }

            array_push($moduleCardData, (object)[
                'module' => $module,
                'moduleMonthString' => $monthString,
                'contextRoot' => $this->contextRoot,
                'moduleUrl' => "{$CFG->apphome}/programs/{$this->category}/{$encodedAnchor}",
                'moduletype' => $type,
                // Status as well as sync-specific, registration related data
                'moduleMetadata' => $moduleMetadata,
            ]);
        }

        LessonsUIHelper::debugLog($moduleCardData);

        return $moduleCardData;
    }

    private function getModuleMetadata($module)
    {
        if (isset($module->async) && $module->async) {
            return $this->getAsyncModuleMetadata($module);
        } else {
            return $this->getSyncModuleMetadata($module);
        }
    }

    private function getSyncModuleMetadata($module)
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
            "SELECT r.registration_id, i.module_launch_id, i.session_date, i.session_location, i.modality, i.meeting_link, r.attendance_status
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
        $location = isset($this->registrations[$module->anchor]["session_location"]) ? $this->registrations[$module->anchor]["session_location"] : null;
        $meetingLink = isset($this->registrations[$module->anchor]["meeting_link"]) ? $this->registrations[$module->anchor]["meeting_link"] : null;

        $gaveFeedback = isset($this->registrations[$module->anchor]["feedback"]) ? $this->registrations[$module->anchor]["feedback"] : false;

        $restPath = U::rest_path();
        $loginPath = "{$restPath->parent}/tsugi/login.php";
        $launchPath = "{$restPath->parent}/{$restPath->controller}/{$this->category}/{$module->anchor}/lti-launch";

        $status = null;
        // Choose button action
        $buttonAction = null;
        $buttonLtiUrl = null;
        if (is_array($module->lti) && count($module->lti) > 0) {
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
            'facilitatorBasePath' => $CFG->apphome . '/facilitators',
            // Status to determine whether to render
            'status' => $status,
            'meetingLink' => $meetingLink
        ];
    }

    private function getAsyncModuleMetadata($module)
    {
        global $CFG, $PDOX;
        $userId = isset($_SESSION['lti']['user_id']) ? $_SESSION['lti']['user_id'] : null;
        if (!isset($this->contextId)) {
            $contextKey = "{$this->category}_{$module->anchor}";
            $contextId = LessonsOrchestrator::getOrInitContextId($module->title, $contextKey);
        }

        $pageStatus = null;
        $ltiStatus = null;
        $status = null;


        // Check page progress
        $pageProgressObj = $this->getPagesProgress($module);
        if (isset($pageProgressObj->{$contextKey})) {
            $pageProgressCount = count((array)$pageProgressObj->{$contextKey});
            $pageCount = 0;
            foreach ($module->lessons as $lesson) {
                foreach ($lesson->pages as $page) {
                    $pageCount++;
                }
            }
            if ($pageProgressCount == $pageCount) {
                $pageStatus =  'COMPLETE';
            } else if ($pageProgressCount > 0) {
                $pageStatus =  'IN_PROGRESS';
            }
        }

        // Check lti assignment completion
        if (isset($pageProgressObj->{$contextKey})) {
            foreach ($pageProgressObj->{$contextKey} as $pageProgress) {
                if ($pageProgress) {
                    $ltiStatus = 'IN_PROGRESS';
                    // echo (json_encode((array)$pageProgress));
                    foreach ((array)$pageProgress as $progressIndicator) {
                        if ($progressIndicator == 'LTI_UNATTEMPTED' || $progressIndicator == 'LTI_FAILED') {
                            $ltiStatus = 'IN_PROGRESS';
                        }
                    }
                }
            }
        }

        // Determine overall status based on combination of page progress and lti completion
        if ($pageStatus == 'COMPLETE' && $ltiStatus == 'COMPLETE') {
            $status =  'COMPLETE';
        } else if (
            $pageStatus == 'COMPLETE' ||
            $ltiStatus == 'COMPLETE' ||
            $pageStatus == 'IN_PROGRESS' ||
            $ltiStatus == 'IN_PROGRESS'
        ) {
            $status =  'IN_PROGRESS';
        }

        return (object)[
            // Status to determine whether to render
            'status' => $status,
        ];
    }

    public function getLtiContent($module = null)
    {
        $ltiContent = [];
        if (isset($module)) {
            $modules = [$module];
        } else {
            $modules = $this->course->modules;
        }
        foreach ($modules as $mod) {
            foreach ($mod->lessons as $lesson) {
                foreach ($lesson->pages as $page) {
                    foreach ($page->contents as $content) {
                        if (isset($content->lti)) {
                            $ltiContent[] = $content->lti;
                        }
                    }
                }
            }
        }
        return $ltiContent;
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
            if (isset($mod->async) && $mod->async) {
                foreach ($mod->lessons as $lesson) {
                    foreach ($lesson->pages as $page) {
                        foreach ($page->contents as $content) {
                            if (isset($content->lti)) {
                                if ($content->lti->resource_link_id == $resource_link_id) return $content->lti;
                            }
                        }
                    }
                }
            } else {
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
        }
    }

    /**
     * Get a module associated with a resource link ID
     */
    public function getModuleByRlid($resource_link_id)
    {
        foreach ($this->course->modules as $mod) {
            if (isset($mod->async) && $mod->async) {
                foreach ($mod->lessons as $lesson) {
                    foreach ($lesson->pages as $page) {
                        foreach ($page->contents as $content) {
                            if (isset($content->lti)) {
                                if ($content->lti->resource_link_id == $resource_link_id) return $mod;
                            }
                        }
                    }
                }
            } else {
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
        }
        return null;
    }

    public function getPagesProgress($module)
    {
        global $CFG, $PDOX;
        $contextId = isset($this->contextId) ? $this->contextId : null;
        if (!isset($this->contextId)) {
            $contextKey = "{$this->category}_{$module->anchor}";
            $contextId = LessonsOrchestrator::getOrInitContextId($module->title, $contextKey);
        }
        $progress = null;
        // "asyncProgress": { "moduleAnchor": { "pageAnchor": "02-02-2023-01:02:12Z" }
        if (isset($_SESSION) && isset($_SESSION['profile_id'])) {
            // Standard retrieval of profile info
            $stmt = $PDOX->queryDie(
                "SELECT json FROM {$CFG->dbprefix}profile WHERE profile_id = :PID",
                array('PID' => $_SESSION['profile_id'])
            );
            $profile_row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!empty($profile_row) && !is_null($profile_row['json'])) {
                $profile = json_decode($profile_row['json']);
                // If there is any progress, we need to check against LTI tools as well
                if (isset($profile->asyncProgress)) {
                    $grades = GradeUtil::loadGradesForCourse($_SESSION['id'], $contextId);
                    $progress = $profile->asyncProgress;
                    // Initialize the module anchor if it doesn't exist (it may be null in the json)
                    if (!isset($progress->{$this->contextKey})) $progress->{$this->contextKey} = (object)[];
                    foreach ($module->lessons as $lesson) {
                        foreach ($lesson->pages as $page) {
                            // Initialize the page anchor if it doesn't exist (it may be null in the json)
                            if (!isset($progress->{$this->contextKey}->{$page->anchor})) $progress->{$this->contextKey}->{$page->anchor} = null;
                            foreach ($page->contents as $content) {
                                $gradeFound = false;
                                if (isset($content->lti)) {
                                    // Assume any LTI tool is ungraded - the grade rows will update the progress
                                    $originalTimestamp = $progress->{$this->contextKey}->{$page->anchor};
                                    $progress->{$this->contextKey}->{$page->anchor} = null;
                                    foreach ($grades as $row) {
                                        if ($row['resource_link_id'] == $content->lti->resource_link_id) {
                                            $gradeFound = true;
                                            if (isset($row['grade'])) {
                                                if (isset($content->lti->threshold)) {
                                                    if ($row['grade'] < $content->lti->threshold) {
                                                        $progress->{$this->contextKey}->{$page->anchor} = 'LTI_FAILED';
                                                    } else {
                                                        $progress->{$this->contextKey}->{$page->anchor} = $originalTimestamp;
                                                    }
                                                } else {
                                                    $progress->{$this->contextKey}->{$page->anchor} = $originalTimestamp;
                                                }
                                            }
                                        }
                                    }
                                    if (!$gradeFound && $originalTimestamp) {
                                        $progress->{$this->contextKey}->{$page->anchor} = 'LTI_UNATTEMPTED';
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $progress;
    }

    public function updatePagesProgress($program, $moduleAnchor, $pageAnchor)
    {
        global $CFG, $PDOX;
        if (isset($_SESSION['profile_id'])) {
            $stmt = $PDOX->queryDie(
                "SELECT json FROM {$CFG->dbprefix}profile WHERE profile_id = :PID",
                array('PID' => $_SESSION['profile_id'])
            );
            $profile_row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $profile = json_decode($profile_row['json']);
            if (!is_object($profile)) $profile = (object)[];
            if (!isset($profile->asyncProgress)) {
                $profile->asyncProgress = (object)[];
            }
            $asyncProgress = $profile->asyncProgress;
            $moduleKey = "{$program}_{$moduleAnchor}";
            if (!isset($asyncProgress->{$moduleKey})) {
                $asyncProgress->{$moduleKey} = (object)[];
            }
            $moduleProgress = $asyncProgress->{$moduleKey};
            // Only update if it doesn't already exist at the page level
            if (!isset($moduleProgress->{$pageAnchor})) {
                $moduleProgress->{$pageAnchor} =  "02-02-2023-01:02:12";
                $new_json = json_encode($profile);
                $stmt = $PDOX->queryDie(
                    "UPDATE {$CFG->dbprefix}profile SET json= :JSON
                    WHERE profile_id = :PID",
                    array('JSON' => $new_json, 'PID' => $_SESSION['profile_id'])
                );
            }
        }
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

    private function findPreviousPage(int $lessonIndex, int $pageIndex, $module)
    {
        if ($pageIndex > 0) {
            // Paginate backward if prev page is there in same lesson
            return $module->lessons[$lessonIndex]->pages[$pageIndex - 1];
        } else if ($lessonIndex > 0) {
            // Otherwise, look to the last page of the previous lesson
            $prevLessonPages = $module->lessons[$lessonIndex - 1]->pages;
            if (isset($prevLessonPages) && count($prevLessonPages) > 0) {
                // If pages exist in prev lesson, get last one
                return $prevLessonPages[count($prevLessonPages) - 1];
            } else {
                // Otherwise, go to the previous lesson
                return $this->findPreviousPage($lessonIndex - 1, count($prevLessonPages) - 1, $module);
            }
        } else {
            // No previous page found
            return null;
        }
    }

    private function findNextPage(int $lessonIndex, int $pageIndex, $module)
    {
        if ($pageIndex < count($module->lessons[$lessonIndex]->pages) - 1) {
            // Paginate forward if next page is there in same lesson
            return $module->lessons[$lessonIndex]->pages[$pageIndex + 1];
        } else if ($lessonIndex < count($module->lessons) - 1) {
            // Otherwise, look to the first page of the next lesson
            $nextLessonPages = $module->lessons[$lessonIndex + 1]->pages;
            if (isset($nextLessonPages) && count($nextLessonPages) > 0) {
                // If pages exist in the next lesson, get the first one
                return $nextLessonPages[0];
            } else {
                // Otherwise, go to the next lesson
                return $this->findNextPage($lessonIndex + 1, 0, $module);
            }
        } else {
            // No next page found
            return null;
        }
    }
}
