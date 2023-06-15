<?php

namespace Tsugi\UI\StandardAsync;

use CourseBase;
use Tsugi\Util\U;
use Tsugi\Core\LTIX;
use Tsugi\UI\LessonsOrchestrator;
use Tsugi\UI\LessonsUIHelper;
use Tsugi\Grades\GradeUtil;


class StandardAsyncAdapter extends CourseBase
{
    public AsyncCourse $course;
    /** Index by resource_link */
    public $resource_links;
    /** The individual module */
    public $activeModule;
    /** The individual page */
    public $activePage;
    /** The anchor of the lesson page */
    public $pageAnchor;
    /** The index of the active page */
    public $pageIndex;
    /** The index of the active lesson */
    public $lessonIndex;
    /** The position of the module */
    public $position;
    /** The root path to the context-specific session data */
    public $contextRoot;

    public $contextKey;
    public int $contextId;
    protected string $category;

    function __construct($relativeContext, $moduleAnchor = null, $pageAnchor = null, $index = null)
    {
        try {
            global $CFG;
            $this->category = substr($relativeContext, strrpos($relativeContext, '/') + 1);
            $this->contextRoot = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI' . $relativeContext;
            $courseObject =  LessonsOrchestrator::getLessonsJson($relativeContext);
            $this->resource_links = array();
            LessonsOrchestrator::modifyLessonsAndLinks($courseObject, $this->resource_links);

            $moduleAnchor = isset($_GET['anchor']) ? $_GET['anchor'] : $moduleAnchor;
            $index = isset($_GET['index']) ? $_GET['index'] : $index;

            $this->course = new AsyncCourse($courseObject);

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
        LTIX::session_start();
        LessonsUIHelper::debugLog($this->course);

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

                if ($this->activePage) {
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
        ]);
    }

    private function renderModuleLandingPage()
    {
        $twig = LessonsUIHelper::twig();

        $progress = $this->getPagesProgress($this->activeModule);

        echo $twig->render('async-module-landing-page.twig', [
            'program' => $this->category,
            'breadcrumbs' => $this->getBreadcrumbs(),
            'base_url_warpwire' => $this->base_url_warpwire,
            'module' => $this->activeModule,
            'progress' => $progress,
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

    private function renderAllModulesPage()
    {
        global $CFG;
        $twig = LessonsUIHelper::twig();
        $allowedModules = $this->assembleAllowedModules();

        echo $twig->render('async-all-modules-page.twig', [
            'genericImg' => $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png',
            'breadcrumbs' => $this->getBreadcrumbs(),
            'contextRoot' => $this->contextRoot,
            'course' => $this->course,
            'moduleData' => $allowedModules,
        ]);
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

    /** Get an LTI or Discussion associated with a resource link ID */
    public function getLtiByRlid($resource_link_id)
    {
        foreach ($this->course->modules as $mod) {
            foreach ($mod->lessons as $lesson) {
                foreach ($lesson->pages as $page) {
                    foreach ($page->contents as $content) {
                        if (isset($content->lti)) {
                            if ($content->lti->resource_link_id == $resource_link_id) return $content->lti;
                        }
                    }
                }
            }
        }
        return null;
    }

    /** Get a module associated with a resource link ID */
    public function getModuleByRlid($resource_link_id)
    {
        foreach ($this->course->modules as $mod) {
            foreach ($mod->lessons as $lesson) {
                foreach ($lesson->pages as $page) {
                    foreach ($page->contents as $content) {
                        if (isset($content->lti)) {
                            if ($content->lti->resource_link_id == $resource_link_id) return $mod;
                        }
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

    private function findPreviousPage(int $lessonIndex, int $pageIndex, AsyncModule $module)
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

    private function findNextPage(int $lessonIndex, int $pageIndex, AsyncModule $module)
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
