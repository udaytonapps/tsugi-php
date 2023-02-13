<?php

namespace Tsugi\UI;

use \Tsugi\Util\U;
use \Tsugi\Core\LTIX;
use \Tsugi\Crypt\AesOpenSSL;

use \DateTime;


class AtlsLessons
{


    /** All the lessons */
    public $lessons;

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
    private $contextRoot;

    /** RENDER HEADER */
    public function header($buffer = false)
    {
        LessonsUIHelper::renderGeneralHeader($this, $buffer);
    }

    /** RENDER LESSON DETAILS */
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
            $prev = $all . '/' . urlencode($this->lessons->modules[$this->position - 2]->anchor);
            echo ('<li class="nav-item previous"><a class="nav-link" href="' . $prev . '"><i class="fa fa-arrow-left" aria-hidden="true"></i> ' . __('Previous') . '</a></li>' . "\n");
        }
        echo ('<li class="nav-item"><a class="nav-link" href="' . $all . '">' . __('All') . ' (' . $this->position . ' / ' . count($this->lessons->modules) . ')</a></li>');
        if ($this->position >= count($this->lessons->modules)) {
            echo ('<li class="nav-item next disabled"><a class="nav-link disabled text-muted" href="#" onclick="return false;">' . __('Next') . ' <i class="fa fa-ban" aria-hidden="true"></i></a></li>' . "\n");
        } else {
            $next = $all . '/' . urlencode($this->lessons->modules[$this->position]->anchor);
            echo ('<li class="nav-item next"><a class="nav-link" href="' . $next . '">' . __('Next') . ' <i class="fa fa-arrow-right" aria-hidden="true"></i></a></li>' . "\n");
        }
        echo ("</ul></div>\n");
        ?>
        <div class="p-5 text-center bg-image" style="background-image: url('<?= LessonsOrchestrator::expandLink($this->contextRoot . $module->image) ?>');height: 400px;">
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
                                        $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id . '?redirect_url=' . $_SERVER['REQUEST_URI'];
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
            LessonsOrchestrator::nostyleUrl($module->title, $lessonurl);
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

    /** RENDER LIST OF ALL SESSIONS */
    public function renderAll($buffer = false)
    {
        global $CFG, $PDOX;
        ob_start();
        echo ('<div typeof="Course">' . "\n");
        echo '<h4>' . $this->lessons->title . '</h4><h1>All Sessions</h1>';
        echo ('<p class="lead" property="description">' . $this->lessons->description . "</p>\n");
        echo ('<hr class="my-2"><h4 class="text-center">Core Sessions</h4><hr class="my-2">');
        $count = 0;
        echo ('<div class="row session-box">');
        foreach ($this->lessons->modules as $module) {

            if (isset($allreg)) {
                echo (json_encode($allreg));
            }

            $instances = $PDOX->allRowsDie(
                "SELECT session_date, session_location, duration_minutes, module_launch_id, capacity
                FROM {$CFG->dbprefix}atls_module_instance
                WHERE module_launch_id = :moduleId",
                array(':moduleId' => $module->anchor)
            );
            if (isset($module->hidden) && $module->hidden) continue;
            if (isset($module->login) && $module->login && !isset($_SESSION['id'])) continue;
            $count++;
            // LessonsUIHelper::renderSessionCard($module);
        }
        echo ('</div>');
        echo ('<hr class="my-2"><h4 class="text-center">Elective Sessions</h4><hr class="my-2"><div class="row session-box"><p><em>No elective sessions at this time.</em></p></div>');
        echo ('</div> <!-- dflex typeof="Course" -->' . "\n");
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }

    /** RENDER ASSIGNMENTS */
    public function renderAssignments($allgrades, $buffer = false)
    {
        ob_start();
        echo '<div class="container">';
        echo ('<h4>' . $this->lessons->title . "</h4><h1>Progress</h1>\n");
        echo '<h6 class="bg-light p-2 border-top border-bottom">Core Sessions</h6>';
        echo '<ul class="list-group list-group-light list-group-small">';
        $count = 0;
        foreach ($this->lessons->modules as $module) {
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

    /** RENDER BADGES */
    public function renderBadges($allgrades, $buffer = false)
    {
        global $CFG;
        ob_start();
        echo ('<h4>' . $this->lessons->title . "</h4><h1>Badges</h1>\n");
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
                    foreach ($this->lessons->badges as $badge) {
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

    /** RENDER DISCUSSIONS */
    public function renderDiscussions($buffer = false)
    {
        ob_start();
        global $CFG, $OUTPUT, $PDOX;

        echo '<div class="container">';

        // Flatten the discussions
        $discussions = array();
        if (isset($this->lessons->discussions)) {
            foreach ($this->lessons->discussions as $discussion) {
                $discussions[] = $discussion;
            }
        }

        foreach ($this->lessons->modules as $module) {
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

        echo ('<h4>' . $this->lessons->title . '</h4><h1>' . __('Discussions') . "</h1>\n");

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

    /** RENDER FOOTER */
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
        if (isset($this->lessons->footers) && is_array($this->lessons->footers)) {
            foreach ($this->lessons->footers as $footer) {
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

    /** RENDER TASK STATUSES */
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

    /** RENDER BADGE MODAL */
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

    /** RENDER BADGE */
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

    /** RENDER SMALL BADGE */
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

    /** RENDER BADGE ADMIN */
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
            <h1><?= $this->lessons->title ?></h1>
            <ul class="nav nav-tabs mb-3" id="badgeadmin" role="tablist">
                <li class="nav-item" role="presentation"><a class="nav-link active" href="#badgeadmin-by-badge" data-mdb-toggle="tab" aria-controls="badgeadmin-by-badge" aria-selected="true">By Badge</a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" href="#badgeadmin-by-user" data-mdb-toggle="tab" aria-controls="badgeadmin-by-user" aria-selected="false">By User</a></li>
            </ul>
            <div id="badgeadmin-content" class="tab-content">
                <div class="tab-pane fade show active" id="badgeadmin-by-badge" role="tabpanel" aria-labelledby="badgeadmin-by-badge">
                    <?php
                    echo ('<div class="row d-flex flex-wrap justify-content-center">' . "\n");
                    foreach ($this->lessons->badges as $badge) {
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
                                foreach ($this->lessons->badges as $badge) {
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
