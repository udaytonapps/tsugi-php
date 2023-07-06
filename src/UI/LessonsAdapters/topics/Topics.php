<?php

namespace Tsugi\UI;

use CourseBase;
use ErrorException;
use Tsugi\Core\LTIX;
use Tsugi\Crypt\AesCtr;
use Tsugi\Grades\GradeUtil;
use Tsugi\Util\U;


class Topics extends CourseBase
{

    /**
     * All the topics
     */
    public $topics;

    /**
     * The course object
     */
    public $course;

    /**
     * The individual topic
     */
    public $topic;

    /*
     ** The anchor of the topic
     */
    public $anchor;

    /*
     ** The position of the topic
     */
    public $topicposition;

    public $contextRoot;
    public $contextKey;
    public int $contextId;


    /**
     * Index by resource_link
     */
    public $resource_links;

    public function __construct($relativeContext, $anchor = null, $index = null)
    {
        global $CFG;
        $this->contextRoot = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI' . $relativeContext;
        $this->category = substr($relativeContext, strrpos($relativeContext, '/') + 1);
        $course =  LessonsOrchestrator::getLessonsJson($relativeContext);
        $this->resource_links = array();

        if ($course === null) {
            echo ("<pre>\n");
            echo ("Problem parsing Topics lessons.json: ");
            echo (json_last_error_msg());
            echo ("\n");
            die();
        }

        // Demand that every topic have required elments
        foreach ($course->modules as $topic) {
            if (!isset($topic->title)) {
                die_with_error_log('All topics in a course must have a title');
            }
            if (!isset($topic->anchor)) {
                die_with_error_log('All topics must have an anchor: ' . $topic->title);
            }
            // Populate authors array with data from db
            if (isset($topic->authors) && count($topic->authors)) {
                // Populate facilitator list
                $populatedList = [];
                foreach ($topic->authors as &$author) {
                    if (is_string($author)) {
                        $populatedList[] = LessonsOrchestrator::getFacilitatorByEmail($author);
                    } else {
                        throw new ErrorException('authors should be an array of email strings!');
                    }
                }
                $topic->authors = $populatedList;
            }
        }

        // Filter topics based on login
        if (!isset($_SESSION['id'])) {
            $filtered_topics = array();
            $filtered = false;
            foreach ($course->modules as $topic) {
                if (isset($topic->login) && $topic->login) {
                    $filtered = true;
                    continue;
                }
                $filtered_topics[] = $topic;
            }
            if ($filtered) $course->modules = $filtered_topics;
        }
        $this->course = $course;

        // Pretty up the data structure

        for ($i = 0; $i < count($this->course->modules); $i++) {
            if (isset($this->course->modules[$i]->videos)) self::adjustArray($this->course->modules[$i]->videos);
            if (isset($this->course->modules[$i]->lti)) self::adjustArray($this->course->modules[$i]->lti);
        }

        // Make sure resource links are unique and remember them
        foreach ($this->course->modules as $topic) {
            if (isset($topic->lti)) {
                $ltis = $topic->lti;
                if (!is_array($ltis)) $ltis = array($ltis);
                foreach ($ltis as $lti) {
                    if (!isset($lti->title)) {
                        die_with_error_log('Missing lti title in topic:' . $topic->title);
                    }
                    if (!isset($lti->resource_link_id)) {
                        die_with_error_log('Missing resource link in Topics ' . $lti->title);
                    }
                    if (isset($this->resource_links[$lti->resource_link_id])) {
                        die_with_error_log('Duplicate resource link in Topics ' . $lti->resource_link_id);
                    }
                    $this->resource_links[$lti->resource_link_id] = $topic->anchor;
                }
            }
        }

        $anchor = isset($_GET['anchor']) ? $_GET['anchor'] : $anchor;
        $index = isset($_GET['index']) ? $_GET['index'] : $index;

        // Search for the selected anchor or index position
        $count = 0;
        $topic = false;
        if ($anchor || $index) {
            foreach ($course->modules as $topic) {
                $count++;
                if ($anchor !== null && isset($topic->anchor) && $anchor != $topic->anchor) continue;
                if ($index !== null && $index != $count) continue;
                if ($anchor == null && isset($topic->anchor)) $anchor = $topic->anchor;
                $this->topic = $topic;
                $this->topicposition = $count;
                $this->contextKey = "{$this->category}_{$topic->anchor}";
                if ($topic->anchor) $this->anchor = $topic->anchor;
            }
        }

        return true;
    }

    /*
     ** Load up the JSON from the file
     **/

    /**
     * Make non-array into an array and adjust paths
     */
    public static function adjustArray(&$entry)
    {
        global $CFG;
        if (isset($entry) && !is_array($entry)) {
            $entry = array($entry);
        }
        for ($i = 0; $i < count($entry); $i++) {
            if (is_string($entry[$i])) U::absolute_url_ref($entry[$i]);
            if (isset($entry[$i]->href) && is_string($entry[$i]->href)) U::absolute_url_ref($entry[$i]->href);
            if (isset($entry[$i]->launch) && is_string($entry[$i]->launch)) U::absolute_url_ref($entry[$i]->launch);
        }
    }

    /**
     * emit the header material
     */
    public function header($buffer = false)
    {
        global $CFG;
        ob_start();
?>
        <style type="text/css">
            #loader {
                position: fixed;
                left: 0px;
                top: 0px;
                width: 100%;
                height: 100%;
                background-color: white;
                margin: 0;
                z-index: 100;
            }

            div.videoWrapper {
                position: relative;
                padding-bottom: 56.25%;
                /* 16:9 */
                padding-top: 25px;
                height: 0;
                margin-bottom: 1em;
                border: 1px solid lightgray;
            }

            div.videoWrapper:after,
            div.videoWrapper:before {
                content: "";
                position: absolute;
                z-index: -1;
                top: 0;
                bottom: 0;
                left: 10px;
                right: 10px;
                -moz-border-radius: 100px / 10px;
                border-radius: 100px / 10px;
            }

            div.videoWrapper:after {
                right: 10px;
                left: auto;
            }

            div.videoWrapper iframe {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
            }

            .panel-heading .accordion-toggle:before {
                font-family: "Font Awesome 5 Free";
                font-weight: 900;
                margin-right: 0.75rem;
                content: "\f078";
            }

            .panel-heading .accordion-toggle.collapsed:before {
                /* symbol for "collapsed" panels */
                content: "\f054";
                /* adjust as needed, taken from bootstrap.css */
            }

            .list-group-item {
                border: none;
                padding-top: 4px;
                padding-bottom: 4px;
            }

            .navbar-inverse .nav>li>a.disabled,
            .navbar-inverse .nav>li>a.disabled:hover {
                cursor: not-allowed;
                border-bottom-color: transparent;
                opacity: 0.6;
            }

            .nav-popover+.popover .popover-content {
                font-style: italic;
                color: var(--text-light);
                width: max-content;
                width: -moz-max-content;
                width: -webkit-max-content;
            }

            #topics {
                display: flex;
                justify-content: center;
            }

            .topic-card-parent {
                display: none;
                padding: 20px;
            }

            .topiccard {
                height: 415px;
                vertical-align: top;
                white-space: normal;
                cursor: pointer;
                border: 1px solid rgba(0, 0, 0, .2);
                box-shadow: 0 4px 6px -6px #111;
                overflow: hidden;
            }

            .topiccard-container {
                padding-top: 56.25%;
                background-color: var(--primary);
                background-position: initial initial;
                background-repeat: initial initial;
                position: relative !important;
                width: 100% !important;
                z-index: 0 !important;
            }

            .topiccard-header {
                position: absolute !important;
                top: 0px !important;
                bottom: 0px !important;
                left: 0px !important;
                right: 0px !important;
                height: 100% !important;
                width: 100% !important;
            }

            .topiccard-image {
                background-position: center center;
                background-repeat: no-repeat;
                background-size: cover;
                height: 100%;
                width: 100%;
                position: relative;
            }

            .topiccard-finished {
                position: absolute;
                left: 10px;
                bottom: 10px;
                background: rgba(0, 0, 0, .7);
                color: #fff;
                font-size: 13px;
                line-height: 14px;
                padding: 3px 5px 3px 5px;
                font-weight: 700;
            }

            .topiccard-time {
                position: absolute;
                right: 10px;
                bottom: 10px;
                background: rgba(0, 0, 0, .7);
                color: #fff;
                font-size: 13px;
                line-height: 14px;
                padding: 3px 5px 3px 5px;
                font-weight: 700;
            }

            .topiccard-info {
                color: var(--pimary);
                padding: 1rem;
            }

            .topiccard-info h4 {
                margin: .64rem 0;
                line-height: 1.59rem;
            }

            .topiccard-info p {
                line-height: 1.5rem;
                overflow: hidden;
            }
        </style>
        <?php
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }

    public static function getUrlResources($topic)
    {
        $resources = array();
        if (isset($topic->videos)) {
            foreach ($topic->videos as $video) {
                $resources[] = self::makeUrlResource(
                    'video',
                    $video->title,
                    'https://www.youtube.com/watch?v=' . urlencode($video->youtube)
                );
            }
        }
        return $resources;
    }

    public static function makeUrlResource($type, $title, $url)
    {
        global $CFG;
        $RESOURCE_ICONS = array(
            'video' => 'fa-video-camera'
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

    /**
     * Get a topic associated with an anchor
     */
    public function getTopicByAnchor($anchor)
    {
        foreach ($this->course->modules as $topic) {
            if ($topic->anchor == $anchor) return $topic;
        }
        return null;
    }

    public function render($buffer = false)
    {
        if ($this->isSingle()) {
            return $this->renderSingle($buffer);
        } else {
            return $this->renderAll($buffer);
        }
    }

    /*
     ** render
     */

    /**
     * Indicate we are in a single topic
     */
    public function isSingle()
    {
        return ($this->anchor !== null || $this->topicposition !== null);
    }

    /*
     * A Nostyle URL Link with title
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

        $topic = $this->topic;
        $this->updateTopicProgress($this->category, $this->topic->anchor);

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
        <div class="container mb-4">
            <?php
            $twig = LessonsUIHelper::twig();
            echo $twig->render('breadcrumbs.twig', [
                'breadcrumbs' => $this->getBreadcrumbs(),
            ]);
            echo ('<div class="topics-content" style="padding-bottom: 60px;">');
            echo ('<h2><small class="text-muted" style="font-weight:300;">' . $topic->category . '</small></h1>');
            echo ('<h1 property="oer:name" style="font-weight:500;line-height: 2.64rem;margin: .795rem 0;">' . $topic->title . "</h1>\n");
            $topicnurl = $CFG->apphome . U::get_rest_path();
            if ($nostyle) {
                self::nostyleUrl($topic->title, $topicnurl);
                echo ("<hr/>\n");
            }
            if (isset($topic->authors) && count($topic->authors) > 0) {
                echo '<h5 style="font-weight:500;margin-top:0;" class="text-muted">' . $this->formatAuthors($topic->authors, true) . '</h5>';
            }
            ?>
            <div class="row">
                <div class="col-lg-9 col-md-10 col-sm-11">
                    <?php
                    if (isset($topic->content)) {
                        echo ('<p style="font-size: 1.26rem;font-weight:300;margin-top:0.613rem;">' . $topic->content . '</p>');
                    }
                    if (isset($topic->videos)) {
                        $videos = $topic->videos;
                        foreach ($videos as $video) {
                            if (isset($video->youtube)) {
                                if ($nostyle) {
                                    echo (htmlentities($video->title) . "<br/>");
                                    $yurl = 'https://www.youtube.com/watch?v=' . $video->youtube;
                                    self::nostyleUrl($video->title, $yurl);
                                } else {
                                    $OUTPUT->embedYouTube($video->youtube, $video->title);
                                }
                            } else if (isset($video->warpwire)) {
                                echo '<div class="videoWrapper">';
                                echo ('<iframe src="https://udayton.warpwire.com/w/' . $video->warpwire . '/?share=0&title=0" frameborder="0" scrolling="0" allow="autoplay; encrypted-media; fullscreen;  picture-in-picture;" allowfullscreen></iframe>');
                                echo '</div>';
                            } else {
                                echo '<div class="videoWrapper">';
                                echo ($video->embed);
                                echo '</div>';
                            }
                        }
                    }
                    ?>
                </div>
                <?php
                if (isset($topic->references)) {
                    echo '<div class="col-lg-3 col-md-10 col-sm-11">';
                    echo ('<div class="panel panel-info">
                <div class="panel-heading"><h4 class="panel-title">' . $topic->references->header . '</h4></div>
                <div class="panel-body">');
                    echo ('<ul class="list-group">');
                    foreach ($topic->references->links as $link) {
                        echo ('<li class="list-group-item" style="display:flex;align-items:start;">');
                        if (isset($link->icon)) {
                            echo ('<span style="margin-right:8px;line-height: 1.93rem;" class="fa ' . $link->icon . '" aria-hidden="true"></span>');
                        }
                        echo ('<a href="' . $link->href . '" target="_blank">' . $link->title . '</a>');
                    }
                    echo ('</ul>');
                    echo ('</div></div></div>');
                }
                ?>
            </div>
            <div class="row">
                <div class="col-sm-10 col-md-8">
                    <?php
                    // LTIs not logged in
                    if (isset($topic->lti) && !isset($_SESSION['secret'])) {
                    ?>
                        <h4><em>What Do You Think? (Login Required)</em></h4>
                        <?php
                    }

                    // LTIs logged in
                    if (
                        isset($topic->lti) && U::get($_SESSION, 'secret') && U::get($_SESSION, 'context_key')
                        && U::get($_SESSION, 'user_key') && U::get($_SESSION, 'displayname') && U::get($_SESSION, 'email')
                    ) {
                        $ltis = $topic->lti;
                        foreach ($ltis as $lti) {
                            $resource_link_title = isset($lti->title) ? $lti->title : $topic->title;

                            $rest_path = U::rest_path();
                            $launch_path = $rest_path->parent . '/' . $rest_path->controller . '_launch/' . $lti->resource_link_id;
                            $title = isset($lti->title) ? $lti->title : $topic->title;
                        ?>
                            <div class="videoWrapper">
                                <iframe src="<?= $launch_path ?>" style="border:none;width:100%;"></iframe>
                            </div>
                    <?php
                        }
                    }
                    ?>
                </div>
            </div>
            <?php
            echo ('</div></div>');

            if ($nostyle) {
                $styleoff = U::get_rest_path() . '?nostyle=no';
                echo ('<p><a href="' . $styleoff . '">');
                echo (__('Turn styling back on'));
                echo ("</a>\n");
            }

            if (!isset($topic->anchor)) $topic->anchor = $this->topicposition;

            $ob_output = ob_get_contents();
            ob_end_clean();
            if ($buffer) return $ob_output;
            echo ($ob_output);
        }

        /*
     * render a topic
     */

        public static function nostyleUrl($title, $url)
        {
            echo ('<a href="' . $url . '" target="_blank" typeof="oer:SupportingMaterial">' . htmlentities($url) . "</a>\n");
            if (isset($_SESSION['gc_count'])) {
                echo ('<div class="g-sharetoclassroom" data-size="16" data-url="' . $url . '" ');
                echo (' data-title="' . htmlentities($title) . '" ');
                echo ('></div>');
            }
        } // End of renderSingle

        public function renderAll($buffer = false)
        {
            global $CFG;
            ob_start();
            $twig = LessonsUIHelper::twig();

            echo ('<div class="container mb-4"><div>' . "\n");
            echo $twig->render('breadcrumbs.twig', [
                'breadcrumbs' => $this->getBreadcrumbs(),
            ]);
            echo ('<div class="pt-4">');
            echo $twig->render('generic-splash.twig', [
                'contextRoot' => $this->contextRoot,
                'splash' => $this->course->splash
            ]);
            echo ('<div>');
            echo '<form class="form-inline text-right mt-3" style="padding-bottom: 1rem;">
                <div class="form-group" style="display: flex; justify-content: flex-end; align-items: center; gap: 20px;">
                    <label for="categorySelect">Filter Topics by Category: </label>
                    <div>
                        <select class="form-control" id="categorySelect">
                            <option value="all">All Categories</option>
                        </select>
                    </div>
                </div>
            </form>';
            echo ('<div id="topics" class="row">' . "\n");
            foreach ($this->course->modules as $topic) {
            ?>
                <div class="col-sm-12 col-md-6 col-lg-4 topic-card-parent">
                    <div class="topiccard" data-category="<?= $topic->category ?>">
                        <a href="<?= U::get_rest_path() . '/' . urlencode($topic->anchor) ?>">
                            <div class="topiccard-container">
                                <div class="topiccard-header">
                                    <div class="topiccard-image" style="background-image: url('<?= $CFG->apphome; ?>/thumbnails/<?= $topic->thumbnail ?>');">
                                        <div class="topiccard-time">
                                            <span class="far fa-clock" aria-hidden="true"></span> <?= $topic->duration ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="topiccard-info">
                                <h5 style="height:70px;overflow:hidden;"><small><?= $topic->category ?></small><br /><?= $topic->title ?></h5>
                                <p style="font-size: smaller"><?= $topic->description ?></p>
                            </div>
                        </a>
                    </div>
                </div>
            <?php
            }
            echo ('</div> <!-- box -->' . "\n");
            echo ('</div></div> <!-- typeof="Course" -->' . "\n");

            $ob_output = ob_get_contents();
            ob_end_clean();
            if ($buffer) return $ob_output;
            echo ($ob_output);
        }

        /**
         * Get a topic associated with a resource link ID
         */
        public function getTopicByRlid($resource_link_id)
        {
            foreach ($this->course->modules as $topic) {
                if (!isset($topic->lti)) continue;
                foreach ($topic->lti as $lti) {
                    if ($lti->resource_link_id == $resource_link_id) return $topic;
                }
            }
            return null;
        }

        /**
         * Get an LTI associated with a resource link ID
         */
        public function getLtiByRlid($resource_link_id)
        {
            foreach ($this->course->modules as $topic) {
                if (!isset($topic->lti)) continue;
                foreach ($topic->lti as $lti) {
                    if ($lti->resource_link_id == $resource_link_id) return $lti;
                }
            }
            return null;
        }

        public function footer($buffer = false)
        {
            global $CFG;
            ob_start();
            echo ('<script src="' . $CFG->staticroot . '/js/jquery-1.11.3.js"></script>' . "\n");
            if (!$this->isSingle()) {
            ?>
                <script>
                    $(document).ready(function() {
                        function handleCategoryChange() {
                            var selectedCat = $('#categorySelect').val();
                            $('.topiccard').each(function() {
                                var category = $(this).data('category');
                                var $parent = $(this).parent();

                                if (selectedCat === 'all' || category === selectedCat) {
                                    $parent.fadeIn();
                                } else {
                                    $parent.hide();
                                }
                            });
                        }
                        let categories = [];
                        $(".topiccard").each(function() {
                            let cat = $(this).data("category");
                            if (!categories.includes(cat)) {
                                categories.push(cat);
                            }
                        });

                        var select = $('#categorySelect');
                        categories.sort();
                        categories.forEach(cat => {
                            select.append('<option value="' + cat + '">' + cat + '</option>');
                        });

                        $("#categorySelect").on("change", handleCategoryChange);

                        setTimeout(function() {
                            $('#categorySelect').val('all');
                        }, 10);
                        handleCategoryChange();
                    });
                </script>
    <?php
            }
            $ob_output = ob_get_contents();
            ob_end_clean();
            if ($buffer) return $ob_output;
            echo ($ob_output);
        } // end footer

        /**
         * Check if a setting value is in a resource in a Topic
         *
         * This solves the problems that (a) most LMS systems do not handle
         * custom well for Common Cartridge Imports and (b) some systems
         * do not handle custom at all when links are installed via
         * ContentItem.  Canvas has this problem for sure and others might
         * as well.
         *
         * The solution is to add the resource link from the Topic as a GET
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
         *         if ( $rlid && isset($CFG->topics) ) {
         *             $l = new Topics($CFG->topics);
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

        public function getModuleByRlid($resource_link_id)
        {
        }

        public function getAllProgramsPageData()
        {
            global $CFG;
            $modules = $this->assembleAllowedModules();

            return (object)[
                'genericImg' => $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png',
                'course' => $this->course,
                'moduleData' => $modules,
                'courseUrl' => $CFG->apphome . '/programs/' . $this->category
            ];
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

                $moduleMetadata = $this->getTopicsModuleMetadata($module);

                $encodedAnchor = urlencode($module->anchor);

                if (isset($module->async) && $module->async) {
                    $type = 'async';
                } else {
                    $type = 'sync';
                }

                array_push($moduleCardData, (object)[
                    'module' => $module,
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

        private function getTopicsModuleMetadata($module)
        {
            if (!isset($this->contextId)) {
                $contextKey = "{$this->category}_{$module->anchor}";
                $contextId = LessonsOrchestrator::getOrInitContextId($module->title, $contextKey);
            }

            $pageStatus = null;


            // Check page progress
            $pageProgressObj = $this->getPagesProgress($module);
            if (isset($pageProgressObj->{$contextKey})) {
                $pageStatus =  'COMPLETE';
            } else {
                $pageStatus =  null;
            }

            return (object)[
                // Status to determine whether to render
                'status' => $pageStatus,
            ];
        }

        public function getPagesProgress($module)
        {
            global $CFG, $PDOX;
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

                        $progress = $profile->asyncProgress;
                        // Initialize the module anchor if it doesn't exist (it may be null in the json)
                        if (!isset($progress->{$this->contextKey})) $progress->{$this->contextKey} = (object)[];
                    }
                }
            }
            return $progress;
        }

        public function updateTopicProgress($program, $moduleAnchor)
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
                    $asyncProgress->{$moduleKey} = null;
                }
                // Only update if it doesn't already exist at the page level
                if (!isset($asyncProgress->{$moduleKey})) {
                    $asyncProgress->{$moduleKey} =  "02-02-2023-01:02:12";
                    $new_json = json_encode($profile);
                    $stmt = $PDOX->queryDie(
                        "UPDATE {$CFG->dbprefix}profile SET json= :JSON
                    WHERE profile_id = :PID",
                        array('JSON' => $new_json, 'PID' => $_SESSION['profile_id'])
                    );
                }
            }
        }

        private function formatAuthors($authors, $withTitle = false)
        {
            $numAuthors = count($authors);

            if ($numAuthors == 1) {
                if ($authors[0] && $authors[0]['displayname'] && $authors[0]['title']) {
                    $url = $this->getFacilitatorUrl($authors[0]);
                    // If there's only one author, return their name (and title, if applicable)
                    if ($withTitle) {
                        return 'By <a href="' . $url . '">' . $authors[0]['displayname'] . '</a> - ' . $authors[0]['title'];
                    } else {
                        return 'By <a href="' . $url . '">' . $authors[0]['displayname'] . '</a>';
                    }
                } else {
                    return '';
                }
            }

            $namesAndTitles = array_map(function ($author) {

                if ($author && $author['displayname'] && $author['title']) {
                    $url = $this->getFacilitatorUrl($author);
                    // This will return a new array containing all the author names and their titles
                    if ($author && $author['displayname'] && $author['title']) {
                        return '<a href="' . $url . '">' . $author['displayname'] . '</a> - ' . $author['title'];
                    } else {
                        return '<a href="' . $url . '">' . $author['displayname'] . '</a>';
                    }
                } else {
                    return '';
                }
            }, $authors);

            $lastAuthor = array_pop($namesAndTitles); // Extract the last author, including their title

            // Join all names with a comma, and append the last author with 'and'
            return 'By ' . implode(', ', $namesAndTitles) . ' and ' . $lastAuthor;
        }

        private function getFacilitatorUrl($author)
        {
            global $CFG;
            $url = $CFG->apphome . '/facilitators/' . $author['facilitator_id'];
            return $url;
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

                if (isset($this->topic)) {
                    $crumb = (object)['path' => $CFG->apphome . '/programs' . '/' . $this->category . '/' . $this->topic->anchor, 'label' => $this->topic->title];
                    $breadcrumbs[] = $crumb;
                }
            }
            return $breadcrumbs;
        }
    }
