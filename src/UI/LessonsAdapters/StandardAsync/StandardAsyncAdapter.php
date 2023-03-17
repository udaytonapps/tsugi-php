<?php

namespace Tsugi\UI\StandardAsync;

use \Tsugi\Util\U;
use Tsugi\Core\LTIX;
use Tsugi\UI\LessonsOrchestrator;
use Tsugi\UI\LessonsUIHelper;


class StandardAsyncAdapter extends AsyncBase
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
                    $this->position = $count;
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

    public function getModuleData()
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
        array_push($breadcrumbs, $crumb);
        if (isset($this->category)) {
            $crumb = (object)['path' => $CFG->apphome . '/programs' . '/' . $this->category, 'label' => $this->course->title];
            array_push($breadcrumbs, $crumb);

            if (isset($this->activeModule)) {
                $crumb = (object)['path' => $CFG->apphome . '/programs' . '/' . $this->category . '/' . $this->activeModule->anchor, 'label' => $this->activeModule->title];
                array_push($breadcrumbs, $crumb);

                if ($this->activePage) {
                    $crumb = (object)['path' => $CFG->apphome . '/programs' . '/' . $this->category . '/' . $this->activeModule->anchor . '/' . $this->activePage->anchor, 'label' => $this->activePage->title];
                    array_push($breadcrumbs, $crumb);
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
        echo $twig->render('async-module-lesson-page.twig', [
            'breadcrumbs' => $this->getBreadcrumbs(),
            'prevPage' => $prevPage,
            'nextPage' => $nextPage,
            'module' => (array)$this->activeModule,
            'page' => (array)$this->activePage,
            'lti_launch_path' => $launch_path,
            'ltiRoot' => $launch_path,
            'authorized' => true, // TODO
        ]);
    }

    private function renderModuleLandingPage()
    {
        $twig = LessonsUIHelper::twig();

        echo $twig->render('async-module-landing-page.twig', [
            'breadcrumbs' => $this->getBreadcrumbs(),
            'base_url_warpwire' => $this->base_url_warpwire,
            'module' => (array)$this->activeModule
        ]);
    }

    private function assembleAllowedModules()
    {
        global $CFG;

        $moduleCardData = (object)['moduleData' => []];
        foreach ($this->course->modules as $module) {

            // Don't render hidden or auth-only modules // TODO
            // if (isset($module->hidden) && $module->hidden) continue;
            // if (isset($module->login) && $module->login && !isset($_SESSION['id'])) continue;

            // foreach ($module->lti as &$lti) { // TODO
            //     $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id . '?redirect_url=' . $_SERVER['REQUEST_URI'];
            //     $lti->calulated_launch_path = $launch_path;
            // }

            $encodedAnchor = urlencode($module->anchor);

            array_push($moduleCardData->moduleData, (object)[
                'module' => $module,
                'contextRoot' => $this->contextRoot,
                // 'moduleUrl' => U::get_rest_path() . '/' . urlencode($module->anchor),
                'moduleUrl' => "{$CFG->apphome}/programs/{$this->category}/{$encodedAnchor}",
            ]);
        }

        // Assign default BG image, breadcrumbs and course info (for header)
        $moduleCardData->genericImg = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png';
        $moduleCardData->breadcrumbs = $this->getBreadcrumbs();
        $moduleCardData->course = $this->course;

        LessonsUIHelper::debugLog($moduleCardData);

        return $moduleCardData;
    }

    private function renderAllModulesPage()
    {
        $twig = LessonsUIHelper::twig();
        $allowedModules = $this->assembleAllowedModules();
        echo $twig->render('async-all-modules-page.twig', (array)$allowedModules);
    }

    /** Get an LTI or Discussion associated with a resource link ID */
    public function getLtiByRlid($resource_link_id)
    {
        // if (isset($this->lessons->discussions)) {
        //     foreach ($this->lessons->discussions as $discussion) {
        //         if ($discussion->resource_link_id == $resource_link_id) return $discussion;
        //     }
        // }
        foreach ($this->course->modules as $mod) {
            foreach ($mod->lessons as $lesson) {
                foreach ($lesson->pages as $page) {
                    foreach ($page->contents as $content) {
                        if (isset($content->lti)) {
                            if ($content->lti->resource_link_id == $resource_link_id) return $content->lti;
                        }
                        // if (isset($mod->discussions)) {
                        //     foreach ($mod->discussions as $discussion) {
                        //         if ($discussion->resource_link_id == $resource_link_id) return $discussion;
                        //     }
                        // }

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
                        // if (isset($mod->discussions)) {
                        //     foreach ($mod->discussions as $discussion) {
                        //         if ($discussion->resource_link_id == $resource_link_id) return $discussion;
                        //     }
                        // }

                    }
                }
            }
        }
        return null;
    }
}
