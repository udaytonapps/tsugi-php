<?php

namespace Tsugi\UI;

use CourseBase;
use \Tsugi\Util\U;
use \Tsugi\Util\LTI;
use \Tsugi\Core\LTIX;
use \Tsugi\Crypt\AesOpenSSL;

use \DateTime;
use Tsugi\UI\LessonsOrchestrator;
use Tsugi\UI\StandardSync\SyncBase;
use Tsugi\UI\StandardSync\SyncCourse;

class AtlsLessons extends CourseBase
{

    /** All the lessons */
    public $course; // TODO SyncCourse types

    /** The individual module */
    public $module;

    /** The anchor of the module */
    public $anchor;

    /** The position of the module */
    public $position;

    /** Index by resource_link */
    public $resource_links;

    /** All of the current user's registration information */
    public $registrations = array();

    /** The root path to the context-specific session data */
    public $contextRoot;

    protected string $category;
    public int $contextId;

    /*
     ** Load up the JSON from the file
     **/
    public function __construct($relativeContext, $anchor = null, $index = null)
    {
        global $CFG;
        $this->category = substr($relativeContext, strrpos($relativeContext, '/') + 1);
        $this->contextRoot = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI' . $relativeContext;
        $this->course =  LessonsOrchestrator::getLessonsJson($relativeContext);
        $this->resource_links = array();
        LessonsOrchestrator::modifyLessonsAndLinks($this->course, $this->resource_links);

        $anchor = isset($_GET['anchor']) ? $_GET['anchor'] : $anchor;
        $index = isset($_GET['index']) ? $_GET['index'] : $index;

        // Search for the selected anchor or index position
        $count = 0;
        if ($anchor || $index) {
            foreach ($this->course->modules as $mod) {
                $count++;
                if ($anchor !== null && isset($mod->anchor) && $anchor != $mod->anchor) continue;
                if ($index !== null && $index != $count) continue;
                if ($anchor == null && isset($mod->anchor)) $anchor = $mod->anchor;
                $this->module = $mod;
                $this->position = $count;
                $this->contextId = LessonsOrchestrator::getOrInitContextId($this->module->title, "{$this->category}_{$this->module->anchor}");
                if ($mod->anchor) $this->anchor = $mod->anchor;
            }
        }
        return true;
    }

    /** HEADER */
    public function header($buffer = false)
    {
        LessonsUIHelper::renderGeneralHeader($this, $buffer);
    }

    /**
     * Indicate we are in a single lesson
     */
    public function isSingle()
    {
        return ($this->anchor !== null || $this->position !== null);
    }

    /**
     * Get a module associated with an anchor
     */
    public function getModuleByAnchor($anchor)
    {
        foreach ($this->course->modules as $mod) {
            if ($mod->anchor == $anchor) return $mod;
        }
        return null;
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

    public function getModuleData()
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
        // $moduleCardData->breadcrumbs = $this->getBreadcrumbs();
        $moduleCardData->course = $this->course;

        LessonsUIHelper::debugLog($moduleCardData);

        return $moduleCardData;
    }

    /*
     ** render
     */
    public function render($buffer = false)
    {
        LTIX::session_start();
        global $CFG, $PDOX;

        // Get user's registration information
        // If logged in, get the user_id from the session data
        $userId = isset($_SESSION['lti']['user_id']) ? $_SESSION['lti']['user_id'] : null;
        $allreg = $PDOX->allRowsDie(
            "SELECT r.registration_id, i.module_launch_id, i.session_date, i.session_location, i.modality, r.attendance_status
                FROM {$CFG->dbprefix}atls_module_instance AS i
                INNER JOIN {$CFG->dbprefix}atls_registration AS r ON i.instance_id = r.instance_id
                WHERE r.user_id = :UID",
            array(':UID' => $userId)
        );
        if ($allreg) {
            // Load all info into registrations
            foreach ($allreg as $reg) {
                $allfeedback = $PDOX->rowDie(
                    "SELECT count(*) as NUM_FEEDBACK
                    FROM {$CFG->dbprefix}atls_question_response AS r
                    INNER JOIN {$CFG->dbprefix}atls_question AS q ON r.question_id = q.question_id
                    WHERE r.registration_id = :RID AND q.question_type IN ('FEEDBACK', 'FEEDBACK_OUTCOME')",
                    array(':RID' => $reg["registration_id"])
                );
                $didfeedback = $allfeedback && $allfeedback["NUM_FEEDBACK"] > 0;
                $reg["feedback"] = $didfeedback;
                $this->registrations[$reg["module_launch_id"]] = $reg;
            }
        }
        echo ('<div class="container">');
        if ($this->isSingle()) {
            return $this->renderSingle($buffer);
        } else {
            return $this->renderAll($buffer);
        }
        echo ('</div>');
    }

    public static function absolute_url_ref(&$url)
    {
        $url = self::expandLink($url);
        $url = U::absolute_url($url);
    }

    /*
     * Do macro substitution on a link
     */
    public static function expandLink($url)
    {
        global $CFG;
        $search = array(
            "{apphome}",
            "{wwwroot}",
        );
        $replace = array(
            $CFG->apphome,
            $CFG->wwwroot,
        );
        $url = str_replace($search, $replace, $url);
        return $url;
    }

    /*
     * A Nostyle URL Link with title
     */
    public static function nostyleUrl($title, $url)
    {
        $url = self::expandLink($url);
        echo ('<a href="' . $url . '" target="_blank" typeof="oer:SupportingMaterial">' . htmlentities($url) . "</a>\n");
        if (isset($_SESSION['gc_count'])) {
            echo ('<div class="g-sharetoclassroom" data-size="16" data-url="' . $url . '" ');
            echo (' data-title="' . htmlentities($title) . '" ');
            echo ('></div>');
        }
    }

    /*
     * A Nostyle URL Link with title as the href text
     */
    public static function nostyleLink($title, $url)
    {
        $url = self::expandLink($url);
        echo ('<a href="' . $url . '" target="_blank" class="tsugi-lessons-link" typeof="oer:SupportingMaterial">' . htmlentities($title) . "</a>\n");
        if (isset($_SESSION['gc_count'])) {
            echo ('<div class="g-sharetoclassroom" data-size="16" data-url="' . $url . '" ');
            echo (' data-title="' . htmlentities($title) . '" ');
            echo ('></div>');
        }
    }


    /*
     * render a lesson
     */
    public function renderSingle($buffer = false)
    {
        global $CFG, $OUTPUT;
        ob_start();
        if (isset($_GET['nostyle'])) {
            if ($_GET['nostyle'] == 'yes') {
                $_SESSION['nostyle'] = 'yes';
            } else {
                unset($_SESSION['nostyle']);
            }
        }
        $nostyle = isset($_SESSION['nostyle']);

        $module = $this->module;

        if ($nostyle && isset($_SESSION['gc_count'])) {
?>
            <script src="https://apis.google.com/js/platform.js" async defer></script>
            <div id="iframe-dialog" title="Read Only Dialog" style="display: none;">
                <iframe name="iframe-frame" style="height:200px" id="iframe-frame" src="<?= $OUTPUT->getSpinnerUrl() ?>"></iframe>
            </div>
        <?php
        }
        $all = U::get_rest_parent();
        ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $all ?>">All Sessions</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= $module->session ?></li>
            </ol>
        </nav>
        <?php
        echo ('<div typeof="oer:Lesson"><ul class="nav nav-pills nav-justified mb-3">' . "\n");
        $disabled = ($this->position == 1) ? ' disabled' : '';

        if ($this->position == 1) {
            echo ('<li class="nav-item previous disabled"><a class="nav-link disabled text-muted" href="#" onclick="return false;"><i class="fa fa-ban" aria-hidden="true"></i> ' . __('Previous') . '</a></li>' . "\n");
        } else {
            $prev = $all . '/' . urlencode($this->course->modules[$this->position - 2]->anchor);
            echo ('<li class="nav-item previous"><a class="nav-link" href="' . $prev . '"><i class="fa fa-arrow-left" aria-hidden="true"></i> ' . __('Previous') . '</a></li>' . "\n");
        }
        echo ('<li class="nav-item"><a class="nav-link" href="' . $all . '">' . __('All') . ' (' . $this->position . ' / ' . count($this->course->modules) . ')</a></li>');
        if ($this->position >= count($this->course->modules)) {
            echo ('<li class="nav-item next disabled"><a class="nav-link disabled text-muted" href="#" onclick="return false;">' . __('Next') . ' <i class="fa fa-ban" aria-hidden="true"></i></a></li>' . "\n");
        } else {
            $next = $all . '/' . urlencode($this->course->modules[$this->position]->anchor);
            echo ('<li class="nav-item next"><a class="nav-link" href="' . $next . '">' . __('Next') . ' <i class="fa fa-arrow-right" aria-hidden="true"></i></a></li>' . "\n");
        }
        echo ("</ul></div>\n");
        ?>
        <div class="p-5 text-center bg-image" style="background-image: url('<?= self::expandLink($this->contextRoot . $module->image) ?>');height: 400px;">
            <div class="mask" style="background-color: rgba(0, 0, 0, 0.6);">
                <div class="d-flex justify-content-center align-items-center h-100">
                    <div class="text-white">
                        <h4 property="oer:name" class="tsugi-lessons-module-title mb-3"><?= $module->session ?></h4>
                        <h1 class="mb-3"><?= $module->title ?></h1>
                        <?php
                        $absent = false;
                        // Add registration date information
                        if (array_key_exists($module->anchor, $this->registrations)) {
                            $regDate = new DateTime($this->registrations[$module->anchor]["session_date"]);
                            $absent = isset($this->registrations[$module->anchor]["attendance_status"]) &&  $this->registrations[$module->anchor]["attendance_status"] === "ABSENT";
                            $attended = isset($this->registrations[$module->anchor]["attendance_status"]) &&  $this->registrations[$module->anchor]["attendance_status"] === "ATTENDED";
                            $greeting = $attended ? 'You attended the following session' : ($absent ? "We missed you at the session on" : "You are registered for");
                        ?>
                            <h5 class="fw-normal"><?= $greeting; ?></h5>
                            <p class="mb-4">
                                <?= $regDate->format("D. M j, Y - g:i a") ?> - <?= $this->registrations[$module->anchor]["session_location"] ?>
                            </p>
                        <?php
                        }
                        // Register not logged in
                        if (isset($module->lti) && !isset($_SESSION['secret'])) {
                            echo '<a class="btn btn-outline-light btn-lg" href="' . $CFG->wwwroot . '/login.php" role="button"><i class="fa fa-lock" aria-hidden="true"></i> Login to Register</a>';
                        }
                        // Register logged in
                        $btnreg = true;
                        $btnclass = 'btn-white';
                        global $_SESSION;
                        $_SESSION['context_key'] = 'test';
                        if (
                            isset($module->lti) && U::get($_SESSION, 'secret') && U::get($_SESSION, 'context_key')
                            && U::get($_SESSION, 'user_key') && U::get($_SESSION, 'displayname') && U::get($_SESSION, 'email')
                        ) {
                            $btncontent = 'Register <i class="fa fa-arrow-right" aria-hidden="true"></i>';
                            if (
                                array_key_exists($module->anchor, $this->registrations) &&
                                $this->registrations[$module->anchor]["attendance_status"] == "REGISTERED"
                            ) {
                                $btncontent = '<i class="fa fa-check" aria-hidden="true"></i> Registered';
                            } else if (
                                array_key_exists($module->anchor, $this->registrations) &&
                                ($this->registrations[$module->anchor]["attendance_status"] == "ATTENDED" || $this->registrations[$module->anchor]["attendance_status"] == "LATE")
                            ) {
                                if ($this->registrations[$module->anchor]["feedback"]) {
                                    $btncontent = '<i class="fas fa-check-circle" aria-hidden="true"></i> Complete';
                                    $btnclass = 'btn-success';
                                } else {
                                    $btncontent = 'Provide Feedback <i class="fa fa-arrow-right" aria-hidden="true"></i>';
                                    $btnclass = 'btn-primary';
                                    $btnreg = false;
                                }
                            } else if ($absent) {
                                $btncontent = 'Change Registration <i class="fa fa-arrow-right" aria-hidden="true"></i>';
                            }
                            foreach ($module->lti as $lti) {
                                if (isset($lti->type)) {
                                    if (($btnreg && $lti->type == "REGISTRATION") || (!$btnreg && $lti->type == "FEEDBACK")) {
                                        $rest_path = U::rest_path();
                                        // $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id . '?redirect_url=' . $_SERVER['REQUEST_URI'];
                                        $launch_path = "{$rest_path->parent}/{$rest_path->controller}/{$this->category}/{$module->anchor}/lti-launch/" . $lti->resource_link_id . '?redirect_url=' . $_SERVER['REQUEST_URI'];
                                        echo '<a class="btn ' . $btnclass . ' btn-lg" href="' . $launch_path . '" role="button">' . $btncontent . '</a>';
                                    }
                                }
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $lessonurl = $CFG->apphome . '/sessions/' . $module->anchor;
        if ($nostyle) {
            self::nostyleUrl($module->title, $lessonurl);
            echo ("<hr/>\n");
        }

        if (LessonsOrchestrator::isInstructor()) {
            echo ('<button type="button" class="btn btn-sm btn-default" data-mdb-toggle="modal" data-mdb-target="#qrmodal" data-url="' . $lessonurl . '" data-title="Session Details Page" data-linktitle="' . htmlentities($module->title) . '"><i class="fa fa-qrcode" aria-hidden="true"></i> QR Code</button>');
        }

        if (isset($module->description)) {
            echo ('<h5 class="mt-4">Session Description</h5><p property="oer:description" class="tsugi-lessons-module-description">' . $module->description . "</p>\n");
        }

        if (isset($module->facilitators)) {
            echo "<h5><i class='fa fa-group fa-fw' aria-hidden='true'></i> Session Facilitator(s)</h5>";
            echo '<ul class="list-group list-group-light list-group-small mb-4" style="margin-left:calc(1.25em + 11px);">';
            foreach ($module->facilitators as $facilitator) {
        ?>
                <li class="list-group-item d-flex align-items-center">
                    <div class="image-container"><img class="profile" src="<?= $facilitator->image ?>" alt="<?= $facilitator->displayname ?>" /></div>
                    <div class="ms-3">
                        <h5 class="fw-normal mb-1"><?= $facilitator->displayname ?></h5>
                        <p class="text-muted mb-0"><?= $facilitator->title ?></p>
                    </div>
                </li>
            <?php
            }
            echo "</ul>";
        }

        if (isset($module->learningoutcomes)) {
            ?>
            <h5><i class="fas fa-chalkboard-teacher fa-fw" aria-hidden="true"></i> Learning Outcomes</h5>
            <p style="margin-left:calc(1.25em + 11px);">As a result of attending this session, participants will be able to:</p>
            <ol class="list-group list-group-light list-group-small list-group-numbered mb-2" style="margin-left:calc(1.25em + 11px);">
                <?php
                foreach ($module->learningoutcomes as $outcome) {
                    echo "<li class=\"list-group-item d-flex justify-content-between align-items-start px-4\"><div class=\"ms-2 me-auto\">" . $outcome . "</div></li>";
                }
                ?>
            </ol>
        <?php
        }

        // Session Resources
        if (isset($module->resources)) {
        ?>
            <h5><i class="fas fa-desktop fa-fw" aria-hidden="true"></i> Session Resources</h5>
            <p style="margin-left:calc(1.25em + 11px);">The resources below will be available once you've attended the session.</p>
            <ul class="list-group list-group-small mb-2" style="margin-left:calc(1.25em + 11px);">
                <?php
                foreach ($module->resources as $resource) {
                    // If attended
                    if (
                        array_key_exists($module->anchor, $this->registrations) &&
                        ($this->registrations[$module->anchor]["attendance_status"] == "ATTENDED" || $this->registrations[$module->anchor]["attendance_status"] == "LATE")
                    ) {
                ?>
                        <span>
                            <a class="ms-4" href="<?= filter_var($resource->url, FILTER_VALIDATE_URL) ? $resource->url : $this->contextRoot . $resource->url ?>" target="_blank"><i class="<?= $resource->icon ?>" aria-hidden="true"></i> <?= $resource->title ?></a>
                        </span>
                    <?php
                    } else {
                    ?>
                        <span class="ms-4"><i class="<?= $resource->icon ?>" aria-hidden="true"></i> <?= $resource->title ?> <em class="text-muted">(Attendance Required)</em></span>
                <?php
                    }
                }
                ?>
            </ul>
        <?php
        }

        echo '<hr>';

        echo ('<div class="discussions-and-tools-container">');
        echo ('<div class="discussions-container">');
        // DISCUSSIONs not logged in
        if (isset($CFG->tdiscus) && $CFG->tdiscus && isset($module->discussions) && !isset($_SESSION['secret'])) {
            $discussions = $module->discussions;
            echo ('<h6 typeof="oer:discussion" class="tsugi-lessons-module-discussions">');
            echo (__('Discussions'));
            echo ('</h6>');
            echo ('<ul class="tsugi-lessons-module-discussions-ul list-group list-group-light list-group-small"> <!-- start of discussions -->' . "\n");
            foreach ($discussions as $discussion) {
                $resource_link_title = isset($discussion->title) ? $discussion->title : $module->title;
                echo ('<li typeof="oer:discussion" class="tsugi-lessons-module-discussion list-group-item not-logged-in">' . htmlentities($resource_link_title) . ' (' . __('Login Required') . ') <br/>' . "\n");
                echo ("\n</li>\n");
            }
            echo ('</ul>');
        }

        // DISCUSSIONs logged in
        if (
            isset($CFG->tdiscus) && $CFG->tdiscus && isset($module->discussions)
            && U::get($_SESSION, 'secret') && U::get($_SESSION, 'context_key')
            && U::get($_SESSION, 'user_key') && U::get($_SESSION, 'displayname') && U::get($_SESSION, 'email')
        ) {
            $discussions = $module->discussions;
            echo ('<h6 typeof="oer:discussion" class="tsugi-lessons-module-discussions">');
            echo (__('Discussions'));
            echo ('</h6>');
            echo ('<ul class="tsugi-lessons-module-discussions-ul list-group list-group-light list-group-small"> <!-- start of discussions -->' . "\n");
            $count = 0;
            foreach ($discussions as $discussion) {
                $resource_link_title = isset($discussion->title) ? $discussion->title : $module->title;

                if ($nostyle) {
                    echo ('<li typeof="oer:discussion" class="tsugi-lessons-module-discussion list-group-item">' . htmlentities($resource_link_title) . ' (Login Required) <br/>' . "\n");
                    $discussionurl = U::add_url_parm($discussion->launch, 'inherit', $discussion->resource_link_id);
                    echo ('<span style="color:green">' . htmlentities($discussionurl) . "</span>\n");
                    if (isset($_SESSION['gc_count'])) {
                        echo ('<a href="' . $CFG->wwwroot . '/gclass/assign?rlid=' . $discussion->resource_link_id);
                        echo ('" title="Install Assignment in Classroom" target="iframe-frame"' . "\n");
                        echo ("onclick=\"showModalIframe(this.title, 'iframe-dialog', 'iframe-frame', _TSUGI.spinnerUrl, true);\" >\n");
                        echo ('<img height=16 width=16 src="https://www.gstatic.com/classroom/logo_square_48.svg"></a>' . "\n");
                    }
                    echo ("\n</li>\n");
                    continue;
                }

                $rest_path = U::rest_path();
                $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $discussion->resource_link_id;
                $title = isset($discussion->title) ? $discussion->title : "Discussion";
                echo ('<li class="tsugi-lessons-module-discussion list-group-item"><a href="' . $launch_path . '">' . htmlentities($title) . '</a></li>' . "\n");
                echo ("\n</li>\n");
            }

            echo ('</ul>');
        }
        echo ("</div><!-- end of discussions -->\n");

        echo ('<div class="tools-container">');
        // LTIs not logged in
        if (isset($module->lti) && !isset($_SESSION['secret'])) {
            $ltis = $module->lti;
            echo ('<h6 typeof="oer:assessment" class="tsugi-lessons-module-ltis">');
            echo (__('Tools'));
            echo ('</h6>');
            echo ('<ul class="tsugi-lessons-module-ltis-ul list-group list-group-light list-group-small"> <!-- start of ltis -->' . "\n");
            foreach ($ltis as $lti) {
                if ($lti->type != 'ADMINISTRATION') {
                    $resource_link_title = isset($lti->title) ? $lti->title : $module->title;
                    echo ('<li typeof="oer:assessment" class="tsugi-lessons-module-lti list-group-item not-logged-in">' . htmlentities($resource_link_title) . ' (' . __('Login Required') . ') <br/>' . "\n");
                    echo ("\n</li>\n");
                }
            }
            echo ('</ul>');
        }

        // LTIs logged in
        if (
            isset($module->lti) && U::get($_SESSION, 'secret') && U::get($_SESSION, 'context_key')
            && U::get($_SESSION, 'user_key') && U::get($_SESSION, 'displayname') && U::get($_SESSION, 'email')
        ) {
            $ltis = $module->lti;
            echo ('<h6 typeof="oer:assessment" class="tsugi-lessons-module-ltis">');
            echo (__('Tools'));
            echo ('</h6>');
            echo ('<ul class="tsugi-lessons-module-ltis-ul list-group list-group-light list-group-small"> <!-- start of ltis -->' . "\n");
            $count = 0;
            foreach ($ltis as $lti) {
                if ($lti->type != 'ADMINISTRATION') {
                    $resource_link_title = isset($lti->title) ? $lti->title : $module->title;

                    if ($nostyle) {
                        echo ('<li typeof="oer:assessment" class="tsugi-lessons-module-lti list-group-item">' . htmlentities($resource_link_title) . ' (Login Required) <br/>' . "\n");
                        $ltiurl = U::add_url_parm($lti->launch, 'inherit', $lti->resource_link_id);
                        echo ('<span style="color:green">' . htmlentities($ltiurl) . "</span>\n");
                        if (isset($_SESSION['gc_count'])) {
                            echo ('<a href="' . $CFG->wwwroot . '/gclass/assign?rlid=' . $lti->resource_link_id);
                            echo ('" title="Install Assignment in Classroom" target="iframe-frame"' . "\n");
                            echo ("onclick=\"showModalIframe(this.title, 'iframe-dialog', 'iframe-frame', _TSUGI.spinnerUrl, true);\" >\n");
                            echo ('<img height=16 width=16 src="https://www.gstatic.com/classroom/logo_square_48.svg"></a>' . "\n");
                        }
                        echo ("\n</li>\n");
                        continue;
                    }

                    $rest_path = U::rest_path();
                    $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id;
                    $full_url = $CFG->apphome . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id;
                    $title = isset($lti->title) ? $lti->title : "Autograder";
                    echo ('<li class="tsugi-lessons-module-lti list-group-item d-flex align-items-center   "><a href="' . $launch_path . '" class="flex-grow-1">' . htmlentities($title) . '</a>');
                    if (LessonsOrchestrator::isInstructor()) {
                        echo ('<button type="button" class="btn btn-sm btn-default flex-shrink-0" data-mdb-toggle="modal" data-mdb-target="#qrmodal" data-url="' . $full_url . '" data-title="' . htmlentities($module->title) . '" data-linktitle="' . htmlentities($title) . '"><i class="fa fa-qrcode" aria-hidden="true"></i> QR Code</button>');
                    }
                    echo ('</li>' . "\n");
                    echo ("\n</li>\n");
                }
            }

            echo ("</ul>");
        }
        echo ("</div><!-- end of ltis -->\n");
        echo ("</div>");

        if ($nostyle) {
            $styleoff = U::get_rest_path() . '?nostyle=no';
            echo ('<p><a href="' . $styleoff . '">');
            echo (__('Turn styling back on'));
            echo ("</a>\n");
        }

        ?>
        <!-- QR Modal -->
        <div class="modal top fade" id="qrmodal" tabindex="-1" aria-labelledby="qrcodeModalLabel" aria-hidden="true" data-mdb-backdrop="true" data-mdb-keyboard="true">
            <div class="modal-dialog   modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="qrcodeModalLabel"><span id="qr-code-title"></span></h5>
                        <button type="button" class="btn-close" data-mdb-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <h5 id="qr-link-title"></h5>
                        <p id="qr-code-url" class="text-muted small"></p>
                        <div id="qr-code-container"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-primary" data-mdb-dismiss="modal">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            const qrModalEl = document.getElementById('qrmodal')
            qrModalEl.addEventListener('show.mdb.modal', (e) => {
                const sessionUrl = e.relatedTarget.dataset.url;
                const sessionTitle = e.relatedTarget.dataset.title;
                const linkTitle = e.relatedTarget.dataset.linktitle;
                const qrContainer = document.getElementById("qr-code-container");
                qrContainer.innerHTML = ''; // Clear existing QR codes
                document.getElementById("qr-code-title").innerText = sessionTitle;
                document.getElementById("qr-link-title").innerText = linkTitle;
                document.getElementById("qr-code-url").innerText = sessionUrl;
                new QRCode(qrContainer, sessionUrl);
            });
        </script>
        <?php

        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    } // End of renderSingle

    public function renderAll($buffer = false)
    {
        global $CFG, $PDOX;
        ob_start();
        echo ('<div typeof="Course">' . "\n");
        echo '<h4>' . $this->course->title . '</h4><h1>All Sessions</h1>';
        echo ('<p class="lead" property="description">' . $this->course->description . "</p>\n");
        echo ('<hr class="my-2"><h4 class="text-center">Core Sessions</h4><hr class="my-2">');
        $count = 0;
        echo ('<div class="row session-box">');
        foreach ($this->course->modules as $module) {
            $instances = $PDOX->allRowsDie(
                "SELECT session_date, session_location, duration_minutes, module_launch_id, capacity
                FROM {$CFG->dbprefix}atls_module_instance
                WHERE module_launch_id = :moduleId",
                array(':moduleId' => $module->anchor)
            );

            if (isset($allreg)) {
                echo (json_encode($allreg)); // What is this for?
            }

            // Don't render hidden or auth-only modules
            if (isset($module->hidden) && $module->hidden) continue;
            if (isset($module->login) && $module->login && !isset($_SESSION['id'])) continue;
            $count++;

            $status = null;
            $regDate = null;

            $hasRegistered = array_key_exists($module->anchor, $this->registrations);
            if ($hasRegistered) {
                $status = $this->registrations[$module->anchor]["attendance_status"] == "LATE" ? "Attended" : ucwords(strtolower($this->registrations[$module->anchor]["attendance_status"]));
                $regDate = new DateTime($this->registrations[$module->anchor]["session_date"]);
                $absent = $this->registrations[$module->anchor]["attendance_status"] === "ABSENT";
            }

            $upcoming = array();
            foreach ($instances as $instance) {
                $theDate = date('m/d', strtotime($instance['session_date']));
                if (strtotime($instance['session_date']) >= strtotime('now')) {
                    array_push($upcoming, $theDate);
                }
            }


            $isOver = count($upcoming) <= 0;
            $showRegisterButton = !$isOver && !array_key_exists($module->anchor, $this->registrations);
            $attended = isset($this->registrations[$module->anchor]["attendance_status"]) && ($this->registrations[$module->anchor]["attendance_status"] == "ATTENDED" || $this->registrations[$module->anchor]["attendance_status"] == "LATE");
            $absent = isset($this->registrations[$module->anchor]["attendance_status"]) &&  $this->registrations[$module->anchor]["attendance_status"] === "ABSENT";

            $rest_path = U::rest_path();

            foreach ($module->lti as &$lti) {
                // TODO: Fix launch path
                $launch_path = $rest_path->full . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id . '?redirect_url=' . $_SERVER['REQUEST_URI'];
                $lti->calulated_launch_path = $launch_path;
            }

            $renderSessionCardConfig = (object)[
                'module' => $module,
                'contextRoot' => $this->contextRoot,
                'registered' => array_key_exists($module->anchor, $this->registrations),
                'attended' => $attended,
                'absent' => $absent,
                'status' => $status,
                'regDate' => $regDate,
                'feedbackGiven' => isset($this->registrations[$module->anchor]['feedback']) ? $this->registrations[$module->anchor]['feedback'] : false,
                'registeredLocation' => isset($this->registrations[$module->anchor]['session_location']) ? $this->registrations[$module->anchor]["session_location"] : null,
                'showRegisterButton' => $showRegisterButton,
                'detailsUrl' => U::get_rest_path() . '/' . urlencode($module->anchor),
                'isOver' => $isOver
            ];

            LessonsUIHelper::renderSessionCard($renderSessionCardConfig);
        }
        echo ('</div></div>');
    }

    public function renderAssignments($allgrades, $buffer = false)
    {
        ob_start();
        echo '<div class="container">';
        echo ('<h4>' . $this->course->title . "</h4><h1>Progress</h1>\n");
        echo '<h6 class="bg-light p-2 border-top border-bottom">Core Sessions</h6>';
        echo '<ul class="list-group list-group-light list-group-small">';
        $count = 0;
        foreach ($this->course->modules as $module) {
            $count++;
            if (!isset($module->lti)) continue;
            echo ('<li class="list-group-item d-flex justify-content-between align-items-start">' . "\n");
            $href = U::get_rest_parent() . '/sessions/' . urlencode($module->anchor);
            echo ('<div class="ps-4"><span class="text-muted">' . $module->session . '</span><br><a href="' . $href . '">' . "\n");
            echo ($module->title);
            echo ("</a></div><div class='pe-4'>");
            if (isset($module->lti)) {
                echo '<ul class="list-group list-group-light list-group-small">';
                foreach ($module->lti as $lti) {
                    if ($lti->type != 'ADMINISTRATION') {
                        echo ('<li class="list-group-item">');
                        if (isset($allgrades[$lti->resource_link_id])) {
                            if ($allgrades[$lti->resource_link_id] == 1.00) {
        ?>
                                <a href="#" data-mdb-toggle="tooltip" title="Complete">
                                    <i class="far fa-check-circle text-success" style="padding-right: 5px;"></i>
                                </a>
                            <?php
                            } else if ($allgrades[$lti->resource_link_id] > 0) {
                            ?>
                                <a href="#" data-mdb-toggle="tooltip" title="In Progress">
                                    <i class="fas fa-spinner text-info" aria-hidden="true" style="padding-right: 5px;"></i>
                                </a>
                            <?php
                            } else {
                            ?>
                                <a href="#" data-mdb-toggle="tooltip" title="Not started">
                                    <i class="far fa-circle text-danger" aria-hidden="true" style="padding-right: 5px;"></i>
                                </a>
                            <?php
                            }
                        } else {
                            ?>
                            <a href="#" data-mdb-toggle="tooltip" title="Not started">
                                <i class="far fa-circle text-danger" aria-hidden="true" style="padding-right: 5px;"></i>
                            </a>
        <?php
                        }
                        if (isset($lti->assignmenttitle)) {
                            echo $lti->assignmenttitle . "\n";
                        } else {
                            echo $lti->title . "\n";
                        }
                        // if ( isset($allgrades[$lti->resource_link_id]) ) {
                        //     echo("<span>Score: ".(100*$allgrades[$lti->resource_link_id])."</span>");
                        // }
                        echo ("</li></tr>\n");
                    }
                }
                echo '</ul>';
            }
            echo '</div></li>';
        }
        echo ('</ul>' . "\n");
        echo '<h6 class="bg-light p-2 border-top border-bottom">Elective Sessions</h6><p class="ps-4"><em>No elective sessions at this time.</em></p>';
        echo '</div>'; // Container
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }

    public static function makeUrlResource($type, $title, $url)
    {
        global $CFG;
        $RESOURCE_ICONS = array(
            'video' => 'fa-video-camera',
            'slides' => 'fa-file-powerpoint-o',
            'assignment' => 'fa-lock',
            'solution' => 'fa-unlock',
            'reference' => 'fa-external-link'
        );
        $retval = new \stdClass();
        $retval->type = $type;
        if (isset($RESOURCE_ICONS[$type])) {
            $retval->icon = $RESOURCE_ICONS[$type];
        } else {
            $retval->icon = 'fa-external-link';
        }
        $retval->thumbnail = $CFG->fontawesome . '/png/' . str_replace('fa-', '', $retval->icon) . '.png';

        if (strpos($title, ':') !== false) {
            $retval->title = $title;
        } else {
            $retval->title = ucwords($type) . ': ' . $title;
        }
        $retval->url = $url;
        return $retval;
    }

    /* After PHP 5.6
    const RESOURCE_ICONS = array(
        'video' => 'fa-video-camera',
        'slides' => 'fa-file-powerpoint-o',
        'assignment' => 'fa-lock',
        'solution' => 'fa-unlock',
        'reference' => 'fa-external-link'
    );
*/

    public static function getUrlResources($module)
    {
        $resources = array();
        if (isset($module->carousel)) {
            foreach ($module->carousel as $carousel) {
                $resources[] = self::makeUrlResource(
                    'video',
                    $carousel->title,
                    'https://www.youtube.com/watch?v=' . urlencode($carousel->youtube)
                );
            }
        }
        if (isset($module->videos)) {
            foreach ($module->videos as $video) {
                $resources[] = self::makeUrlResource(
                    'video',
                    $video->title,
                    'https://www.youtube.com/watch?v=' . urlencode($video->youtube)
                );
            }
        }
        if (isset($module->slides)) {
            $resources[] = self::makeUrlResource('slides', __('Slides') . ': ' . $module->title, $module->slides);
        }
        if (isset($module->assignment)) {
            $resources[] = self::makeUrlResource('assignment', 'Assignment Specification', $module->assignment);
        }
        if (isset($module->solution)) {
            $resources[] = self::makeUrlResource('solution', 'Assignment Solution', $module->solution);
        }
        if (isset($module->references)) {
            foreach ($module->references as $reference) {
                if (!isset($reference->title) || !isset($reference->href)) continue;
                $resources[] = self::makeUrlResource('reference', $reference->title, $reference->href);
            }
        }
        return $resources;
    }

    public function renderBadges($allgrades, $buffer = false)
    {
        global $CFG;
        ob_start();
        echo ('<h4>' . $this->course->title . "</h4><h1>Badges</h1>\n");
        $awarded = array();
        ?>
        <ul class="nav nav-tabs nav-fill mb-3" id="badgetabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link active" id="badgetabs-1" data-mdb-toggle="tab" href="#badge-tabs-1" role="tab" aria-controls="badge-tabs-1" aria-selected="true">
                    Badge Progress
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link" id="badgetabs-2" data-mdb-toggle="tab" href="#badge-tabs-2" role="tab" aria-controls="badge-tabs-2" aria-selected="false">
                    Badges Awarded
                </a>
            </li>
        </ul>
        <div id="badgeTabContent" class="tab-content mt-4 mb-4">
            <div class="tab-pane fade show active" id="badge-tabs-1" role="tabpanel" aria-labelledby="badgetabs-1">
                <div class="d-flex flex-wrap align-items-stretch justify-content-center">
                    <?php
                    foreach ($this->course->badges as $badge) {
                        $threshold = $badge->threshold;
                        $count = 0;
                        $total = 0;
                        $scores = array();
                        foreach ($badge->assignments as $resource_link_id) {
                            $score = 0;
                            if (isset($allgrades[$resource_link_id])) $score = 100 * $allgrades[$resource_link_id];
                            $scores[$resource_link_id] = $score;
                            $total = $total + $score;
                            $count = $count + 1;
                        }
                        $max = $count * 100;
                        $progress = $max <= 0 ? 100 : ($total / $max) * 100.0;
                        $kind = 'danger';
                        if ($progress < 5) $progress = 5;
                        if ($progress > 5) $kind = 'warning';
                        if ($progress > 50) $kind = 'info';
                        if ($progress >= $threshold * 100) {
                            $progress = 100;
                            $kind = 'success';
                            $awarded[] = $badge;
                        }
                        if (!isset($CFG->badge_url) || $kind != 'success') {
                            $img = $CFG->badge_url . '/not-earned.png';
                        } else {
                            $img = $CFG->badge_url . '/' . $badge->image;
                        }
                        self::renderBadge($badge, $progress, $kind, $img);
                        self::renderBadgeModal($badge, $kind, $progress, $allgrades);
                    }
                    ?>
                </div>
            </div>

            <div class="tab-pane fade" id="badge-tabs-2" role="tabpanel" aria-labelledby="badgetabs-2">
                <h4>Badges Awarded</h4>
                <p>These badges contain the official Open Badge metadata. You can download the badge and
                    put it on your own server, or add the badge to a "badge packpack". You could validate the badge
                    using <a href="http://www.dr-chuck.com/obi-sample/" target="_blank">A simple badge validator</a>.
                </p>
                <?php
                if (count($awarded) < 1) {
                    echo ("<p>No badges have been awarded yet.</p>");
                } else if (!isset($_SESSION['id']) || !isset($_SESSION['context_id'])) {
                    echo ("<p>You must be logged in to see your badges.</p>\n");
                } else {
                    echo ("<div class='row'>\n");
                    foreach ($awarded as $badge) {
                        echo ('<div class="col-sm-12 mb-4">');
                        echo ("<div class='d-flex'><div style='padding-left:1rem;'>");
                        $code = basename($badge->image, '.png');
                        $decrypted = $_SESSION['id'] . ':' . $code . ':' . $_SESSION['context_id'];
                        $encrypted = bin2hex(AesOpenSSL::encrypt($decrypted, $CFG->badge_encrypt_password));
                        echo ('<a href="' . $CFG->wwwroot . '/badges/images/' . $encrypted . '.png" target="_blank">');
                        echo ('<img src="' . $CFG->wwwroot . '/badges/images/' . $encrypted . '.png" style="width:90px;"></a>');
                        echo ("</div><div class='flex-grow-1' style='padding-left:1rem;'>\n");
                        echo ('<a class="h5" href="' . $CFG->wwwroot . '/badges/images/' . $encrypted . '.png" target="_blank">' . $badge->title . '</a>');
                        echo ('<p>' . $badge->description . '</p>');
                        echo ('</div></div></div>'); // End flex 2, end flex container, end col
                    }
                    echo ("</div>\n");
                }
                ?>
            </div>
        </div>
        <?php
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }

    public function renderDiscussions($buffer = false)
    {
        ob_start();
        global $CFG, $OUTPUT, $PDOX;

        echo '<div class="container">';

        // Flatten the discussions
        $discussions = array();
        if (isset($this->course->discussions)) {
            foreach ($this->course->discussions as $discussion) {
                $discussions[] = $discussion;
            }
        }

        foreach ($this->course->modules as $module) {
            if (isset($module->hidden) && $module->hidden) continue;
            if (isset($module->discussions) && is_array($module->discussions)) {
                foreach ($module->discussions as $discussion) {
                    $discussions[] = $discussion;
                }
            }
        }

        if (count($discussions) < 1 || !isset($CFG->tdiscus) || !$CFG->tdiscus) {
            echo ('<h1>' . __('Discussions not available') . "</h1>\n");
            $ob_output = ob_get_contents();
            ob_end_clean();
            if ($buffer) return $ob_output;
            echo ($ob_output);
            return;
        }

        echo ('<h4>' . $this->course->title . '</h4><h1>' . __('Discussions') . "</h1>\n");

        // TODO: Perhaps the tdiscus service will get promoted to Tsugi
        // but for now we bypass the abstraction and go straight to the source...
        $rows_dict = array();
        if (U::get($_SESSION, 'context_id') > 0) {
            $rows = $PDOX->allRowsDie(
                "SELECT L.link_key, L.link_sha256, count(L.link_sha256) AS thread_count,
                CONCAT(CONVERT_TZ(MAX(COALESCE(T.updated_at, T.created_at)), @@session.time_zone, '+00:00'), 'Z')
                AS modified_at
                FROM {$CFG->dbprefix}lti_link AS L
                JOIN {$CFG->dbprefix}tdiscus_thread AS T ON T.link_id = L.link_id
                WHERE L.context_id = :CID
                GROUP BY L.link_sha256
                ORDER BY L.link_sha256",
                array(':CID' => U::get($_SESSION, 'context_id'))
            );
            $rows_dict = array();
            foreach ($rows as $row) {
                $rows_dict[$row['link_key']] = $row;
            }
            // echo("<pre>\n");var_dump($rows_dict);echo("</pre>\n");
        }

        $launchable = U::get($_SESSION, 'secret') && U::get($_SESSION, 'context_key')
            && U::get($_SESSION, 'user_key') && U::get($_SESSION, 'displayname') && U::get($_SESSION, 'email');

        echo ('<div class="tsugi-lessons-module-discussions-ul list-group list-group-light"> <!-- start of discussions -->' . "\n");
        foreach ($discussions as $discussion) {
            $resource_link_title = $discussion->title;
            $rest_path = U::rest_path();
            $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $discussion->resource_link_id;
            $info = "";
            $row = U::get($rows_dict, $discussion->resource_link_id);
            if ($row) {
                $info = $row['thread_count'] . ' ' . __('threads') . ' - ' . __('last post') .
                    ' <time class="timeago" datetime="' . $row['modified_at'] . '">' . $row['modified_at'] . '</time>' .
                    "\n";
            }

            if ($launchable) {
                echo ('<a class="list-group-item list-group-item-action px-3 ripple d-flex justify-content-between align-items-center" href="' . $launch_path . '"><div class="text-primary">' . htmlentities($discussion->title) . "</div><div class=\"small text-muted\">" . $info . '</div></a>' . "\n");
            } else {
                echo ('<a href="#!" class="list-group-item list-group-item-action px-3 disabled d-flex justify-content-between align-items-center">' . "\n");
                echo (htmlentities($resource_link_title) . ' (' . __('Login Required') . ')' . $info . "\n");
                echo ("</a>\n");
            }
        }
        echo ("</div><!-- end of discussions -->\n");

        echo '</div>';

        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }
    public function footer($buffer = false)
    {
        global $CFG;
        ob_start();
        if ($this->isSingle()) {
            // http://bxslider.com/examples/video
        ?>
            <script>
                $(document).ready(function() {
                    $('.w3schools-overlay').on('click', function(event) {
                        if (event.target.id == event.currentTarget.id) {
                            // Sop our embedded YouTube Players
                            labnolStopPlayers();
                            // https://stackoverflow.com/questions/4071872/html5-video-force-abort-of-buffering
                            // https://stackoverflow.com/a/34058996
                            $('.w3schools-overlay audio, video').each(function(i, e) {
                                var tmp_src = this.src;
                                var playtime = this.currentTime;
                                this.src = '';
                                this.load();
                                this.src = tmp_src;
                                this.currentTime = playtime;

                            });
                            event.target.style.display = 'none';
                        } else {
                            event.stopPropagation();
                        }
                    })
                });
            </script>
            <script src="<?= $CFG->staticroot ?>/plugins/jquery.bxslider/plugins/jquery.fitvids.js">
            </script>
            <script src="<?= $CFG->staticroot ?>/plugins/jquery.bxslider/jquery.bxslider.js">
            </script>
            <script>
                $(document).ready(function() {
                    $('.bxslider').bxSlider({
                        video: true,
                        useCSS: false,
                        adaptiveHeight: false,
                        slideWidth: "350px",
                        infiniteLoop: false,
                        maxSlides: 2
                    });
                });
            </script>
        <?php
        }
        if (isset($this->course->footers) && is_array($this->course->footers)) {
            foreach ($this->course->footers as $footer) {
                $footer = self::expandLink($footer);
                echo ($footer);
                echo ("\n");
            }
        }
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    } // end footer

    /**
     * Check if a setting value is in a resource in a Lesson
     *
     * This solves the problems that (a) most LMS systems do not handle
     * custom well for Common Cartridge Imports and (b) some systems
     * do not handle custom at all when links are installed via
     * ContentItem.  Canvas has this problem for sure and others might
     * as well.
     *
     * The solution is to add the resource link from the Lesson as a GET
     * parameter on the launchurl URL to be a fallback:
     *
     * https://../mod/zap/?inherit=assn03
     *
     * Say the tool has custom key of "exercise" that it wants a default
     * for when the tool has not yet been configured.  First we check
     * if the LMS sent us a custom parameter and use it if present.
     *
     * If not, load up the LTI launch for the resource link id (assn03)
     * in the above example and see if there is a custom parameter set
     * in that launch and assume it was passed to us.
     *
     * Sample call:
     *
     *     $assn = Settings::linkGet('exercise');
     *     if ( ! $assn || ! isset($assignments[$assn]) ) {
     *         $rlid = isset($_GET['inherit']) ? $_GET['inherit'] : false;
     *         if ( $rlid && isset($CFG->lessons) ) {
     *             $l = new Lessons($CFG->lessons);
     *             $assn = $l->getCustomWithInherit($rlid, 'exercise');
     *         } else {
     *             $assn = LTIX::ltiCustomGet('exercise');
     *         }
     *         Settings::linkSet('exercise', $assn);
     *     }
     *
     */
    public function getCustomWithInherit($key, $rlid = false)
    {
        global $CFG;

        $custom = LTIX::ltiCustomGet($key);
        if (strlen($custom) > 0) return $custom;

        if ($rlid === false) return false;
        $lti = $this->getLtiByRlid($rlid);
        if (isset($lti->custom)) foreach ($lti->custom as $custom) {
            if (isset($custom->key) && isset($custom->value) && $custom->key == $key) {
                return $custom->value;
            }
        }
        return false;
    }

    public function renderTaskStatus($resource_link_id, $allgrades)
    {
        $score = 0;
        if (isset($allgrades[$resource_link_id])) $score = $allgrades[$resource_link_id];
        $progress = intval($score * 100);
        $kind = 'danger';
        if ($progress < 5) $progress = 5;
        if ($progress > 5) $kind = 'warning';
        if ($progress > 50) $kind = 'info';
        if ($progress >= 100) $kind = 'success';
        $lesson = $this->getModuleByRlid($resource_link_id);
        $lti = $this->getLtiByRlid($resource_link_id);
        echo ('<div class="d-flex"><div>');
        $rest_path = U::rest_path();
        $href = $rest_path->parent . '/sessions/' . urlencode($lesson->anchor);
        if ($kind == 'success') {
            echo ('<span class="far fa-check-square text-success" aria-hidden="true" style="padding-right: 5px;"></span>');
        } else if ($kind == 'warning' || $kind == 'info') {
            echo ('<span class="far fa-minus-square text-info" aria-hidden="true" style="padding-right: 5px;"></span>');
        } else {
            echo ('<span class="far fa-square text-info" aria-hidden="true" style="padding-right: 5px;"></span>');
        }
        echo ("</div>");
        echo ('<a class="flex-grow-1" href="' . $href . '">');
        echo ($lesson->session . ' - ' . $lti->assignmenttitle . "</a>\n");
        echo ('<div class="text-right" style="font-weight: bold;">');
        echo ('<a href="' . $href . '">');
        if ($kind == 'danger') {
            echo ('<span class="text-danger">Not Started</span>');
        } else if ($kind == 'warning') {
            echo ('<span class="text-warning">In Progress</span>');
        } else if ($kind == 'info') {
            echo ('<span class="text-info">In Progress</span>');
        } else if ($kind == 'success') {
            echo ('<span class="text-success">Complete</span>');
        }
        echo ('</a>');
        echo ("</div></div>\n");
    }

    public function renderBadgeModal($badge, $kind, $progress, $allgrades)
    {
        global $CFG;
        ?>
        <div id="<?= $badge->anchor ?>" class="modal fade" role="dialog">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel"><?= $badge->title ?></h5>
                        <button type="button" class="btn-close" data-mdb-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="d-flex align-items-center align-content-center">
                                    <div>
                                        <?php
                                        if (!isset($CFG->badge_url) || $kind != 'success') {
                                            echo ('<img src="' . $CFG->badge_url . '/not-earned.png" style="width:100%;max-width:120px;"/> ');
                                        } else {
                                            $image = $CFG->badge_url . '/' . $badge->image;
                                            echo ('<img src="' . $image . '" style="width:100%;max-width:120px;"/> ');
                                        }
                                        ?>
                                    </div>
                                    <div style="flex-grow:2;padding-left:2rem;">
                                        <h3><?= $badge->title ?></h3>
                                        <div class="progress" style="max-width:150px;margin-bottom:0; height: 10px;">
                                            <div class="progress-bar bg-<?= $kind ?>" style="width: <?= $progress ?>%"></div>
                                        </div>
                                        <strong>
                                            <?php
                                            if ($kind == 'danger') {
                                                echo ('<span class="text-danger">Not Started</span>');
                                            } else if ($kind == 'warning') {
                                                echo ('<span class="text-warning">In Progress</span>');
                                            } else if ($kind == 'info') {
                                                echo ('<span class="text-info">In Progress</span>');
                                            } else if ($kind == 'success') {
                                                echo ('<span class="text-success">Complete</span>');
                                            }
                                            ?>
                                        </strong>
                                    </div>
                                </div>
                                <h5 class="text-muted mt-4">Badge Details</h5>
                                <p><?= $badge->description ?></p>
                            </div>
                            <div class="col-sm-6">
                                <h5 class="text-muted">Session Tasks</h5>
                                <?php
                                foreach ($badge->assignments as $resource_link_id) {
                                    self::renderTaskStatus($resource_link_id, $allgrades);
                                }
                                if (count($badge->assignments) == 0) {
                                ?>
                                    <p class="text-muted"><em>This badge is earned through other actions not associated with an assignment.</em></p>
                                <?php
                                }
                                ?>
                            </div> <!-- End assignment column -->
                        </div> <!-- End row -->
                    </div> <!-- End modal body -->
                </div> <!-- End modal content -->
            </div> <!-- End modal dialog -->
        </div> <!-- End modal -->
    <?php
    }

    public function renderBadge($badge, $progress, $kind, $img)
    {
    ?>
        <div class="card text-center m-2 pt-4" data-mdb-toggle="modal" data-mdb-target="#<?= $badge->anchor ?>" style="cursor: pointer; width: 225px;">
            <div class="bg-image">
                <img src="<?= $img ?>" style="width:100%;max-width:90px;" />
            </div>
            <div class="card-body d-flex flex-column align-items-stretch justify-content-between">
                <h6 class="card-title pb-2"><?= $badge->title ?></h6>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar bg-<?= $kind ?>" role="progressbar" style="width: <?= $progress ?>%;" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        </div>
    <?php
    }

    public function renderLilBadge($badge)
    {
        global $CFG;
        $img = $CFG->badge_url . '/' . $badge->image;
    ?>
        <div class="m-1" data-mdb-toggle="tooltip" title="<?= $badge->title ?>">
            <div class="bg-image">
                <img src="<?= $img ?>" style="width:40px;" />
            </div>
        </div>
    <?php

    }

    public function renderBadgeAdmin($gradeMap, $buffer = false)
    {
        ob_start();
        global $CFG, $PDOX;
        echo ('<style type="text/css">
                div.the-badge {
                    padding-top: 1rem;
                }
                div.the-badge:hover {
                    cursor: pointer;
                    opacity: 0.7;
                }
              </style>');
    ?>
        <div class="container pb-4">
            <h1><?= $this->course->title ?></h1>
            <ul class="nav nav-tabs mb-3" id="badgeadmin" role="tablist">
                <li class="nav-item" role="presentation"><a class="nav-link active" href="#badgeadmin-by-badge" data-mdb-toggle="tab" aria-controls="badgeadmin-by-badge" aria-selected="true">By Badge</a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" href="#badgeadmin-by-user" data-mdb-toggle="tab" aria-controls="badgeadmin-by-user" aria-selected="false">By User</a></li>
            </ul>
            <div id="badgeadmin-content" class="tab-content">
                <div class="tab-pane fade show active" id="badgeadmin-by-badge" role="tabpanel" aria-labelledby="badgeadmin-by-badge">
                    <?php
                    echo ('<div class="row d-flex flex-wrap justify-content-center">' . "\n");
                    foreach ($this->course->badges as $badge) {
                        $threshold = $badge->threshold;
                        $awardedUsers = array();
                        foreach ($gradeMap as $user => $userGrades) {
                            $count = 0;
                            $total = 0;
                            $scores = array();
                            foreach ($badge->assignments as $resource_link_id) {
                                $score = 0;
                                if (isset($userGrades[$resource_link_id])) $score = 100 * $userGrades[$resource_link_id];
                                $scores[$resource_link_id] = $score;
                                $total = $total + $score;
                                $count = $count + 1;
                            }
                            $max = $count * 100;
                            $progress = $max <= 0 ? 100 : ($total / $max) * 100;
                            if ($progress >= $threshold * 100) {
                                $awardedUsers[] = $user;
                            }
                        }

                        echo ('<div class="col-sm-3 m-3"><div class="text-center the-badge" data-mdb-toggle="modal" data-mdb-target="#' . $badge->anchor . '">');
                        if (!isset($CFG->badge_url)) {
                            echo ('<img src="' . $CFG->badge_url . '/NA-new.png" style="width:100%;max-width:120px;"/> ');
                        } else {
                            $image = $CFG->badge_url . '/' . $badge->image;
                            echo ('<img src="' . $image . '" style="width:100%;max-width:120px;"/> <span style="position: absolute;background-color: var(--primary)" class="badge">' . count($awardedUsers) . '</span>');
                        }
                        echo ('<h5 class="pt-2 pb-2">' . $badge->title . '</h5>');
                        echo ('</div>');
                    ?>
                        <div id="<?= $badge->anchor ?>" class="modal fade" role="dialog">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-body">
                                        <div class="row">
                                            <div class="col-sm-12">
                                                <div class="flx-cntnr flx-row flx-nowrap">
                                                    <div class="d-flex flex-column justify-content-center">
                                                        <div class="d-flex justify-content-end">
                                                            <button type="button" class="btn-close" data-mdb-dismiss="modal"></button>
                                                        </div>
                                                        <div class="d-flex flex-column align-items-center">
                                                            <?php
                                                            if (!isset($CFG->badge_url)) {
                                                                echo ('<img src="' . $CFG->badge_url . '/NA-new.png" style="width:100%;max-width:120px;"/> ');
                                                            } else {
                                                                $image = $CFG->badge_url . '/' . $badge->image;
                                                                echo ('<img src="' . $image . '" style="width:100%;max-width:120px;"/> ');
                                                            }
                                                            ?>
                                                            <div style="flex-grow:2; margin: 25px">
                                                                <h3><?= $badge->title ?></h3>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-12">
                                                <h4 class="inline text-muted">Awarded To</h4>
                                                <div class="table-resposive">
                                                    <table class="table table-condensed table-striped">
                                                        <thead>
                                                            <th>Name</th>
                                                            <th>Email</th>
                                                        </thead>
                                                        <tbody>
                                                            <?php
                                                            foreach ($awardedUsers as $award) {
                                                                $namequery = "SELECT displayname, email FROM {$CFG->dbprefix}lti_user WHERE user_id = :user_id;";
                                                                $namearr = array(':user_id' => $award);
                                                                $userInfo = $PDOX->rowDie($namequery, $namearr);
                                                                echo ('<tr>');
                                                                echo ('<td>' . $userInfo["displayname"] . '</td>');
                                                                echo ('<td>' . $userInfo["email"] . '</td>');
                                                                echo ('</tr>');
                                                            }
                                                            ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div> <!-- End awarded column -->
                                        </div> <!-- End row -->
                                    </div> <!-- End modal body -->
                                </div> <!-- End modal content -->
                            </div> <!-- End modal dialog -->
                        </div> <!-- End modal -->
                    <?php
                        echo ('</div>'); // end column
                    }
                    echo ('</div>' . "\n");
                    ?>
                </div>
                <div class="tab-pane fade" id="badgeadmin-by-user" role="tabpanel" aria-labelledby="badgeadmin-by-user">
                    <h3>By User Page</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Email</th>
                                <th scope="col">Badges</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Loop over user grades (and keep track of userId)
                            foreach ($gradeMap as $user => $userGrades) {
                                $awardedBadges = array();
                                // Loop over all lesson badges to check what was earned
                                foreach ($this->course->badges as $badge) {
                                    $threshold = $badge->threshold;
                                    $count = 0;
                                    $total = 0;
                                    $scores = array();
                                    foreach ($badge->assignments as $resource_link_id) {
                                        $score = 0;
                                        if (isset($userGrades[$resource_link_id])) $score = 100 * $userGrades[$resource_link_id];
                                        $scores[$resource_link_id] = $score;
                                        $total = $total + $score;
                                        $count = $count + 1;
                                    }
                                    $max = $count * 100;
                                    $progress = $max <= 0 ? 100 : ($total / $max) * 100;
                                    if ($progress >= $threshold * 100) {

                                        $awardedBadges[] = $badge;
                                    }
                                }
                                $namequery = "SELECT displayname, email FROM {$CFG->dbprefix}lti_user WHERE user_id = :user_id;";
                                $namearr = array(':user_id' => $user);
                                $userData = $PDOX->rowDie($namequery, $namearr);
                                if (count($awardedBadges) > 0) {
                            ?>
                                    <tr>
                                        <th scope="row" style="vertical-align: middle;">
                                            <?= $userData["displayname"] ?>
                                        </th>
                                        <td style="vertical-align: middle;">
                                            <?= $userData["email"] ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap">
                                                <?php
                                                foreach ($awardedBadges as $badge) {
                                                    self::renderLilBadge($badge);
                                                }
                                                ?>
                                            </div>
                                        </td>
                                    </tr>
                            <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) {
            return $ob_output;
        }
        echo ($ob_output);
    }
}
