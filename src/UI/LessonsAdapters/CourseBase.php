<?php

use Tsugi\Grades\GradeUtil;
use Tsugi\UI\LessonsOrchestrator;
use \Tsugi\Crypt\AesOpenSSL;
abstract class CourseBase
{
    public string $category;
    protected string $base_url_warpwire = 'https://udayton.warpwire.com';

    public function header()
    {
        echo ("Did you set up the header method for {$this->category} yet?");
    }

    public function render()
    {
        echo ("Did you set up the render method for {$this->category} yet?");
    }

    public function footer()
    {
        echo ("Did you set up the footer method for {$this->category} yet?");
    }

    public function getAllProgramsPageData()
    {
        echo ("Did you set up the getAllProgramsPageData method for {$this->category} yet?");
    }

    public function getModuleCardData()
    {
        echo ("Did you set up the getModules method for {$this->category} yet?");
    }

    public function getBadges()
    {
        echo ("Did you set up the getBadges method for {$this->category} yet?");
    }

    public function getProgress()
    {
        echo ("Did you set up the getProgress method for {$this->category} yet?");
    }

    abstract public function getModuleByRlid($resource_link_id);
    abstract public function getLtiByRlid($ltiAnchor);

    public function getProgressData($program, $adapter)
    {
        global $CFG;
        $ltiItems = [];
        $moduleData = [];

        foreach ($adapter->course->modules as $module) {
            $moduleGrades = [];
            $contextKey = "{$program}_{$module->anchor}";
            $contextId = LessonsOrchestrator::getOrInitContextId($module->title, $contextKey);
            $ltiItems = LessonsOrchestrator::getModuleLtiItems($module);
            $rows = GradeUtil::loadGradesForCourse($_SESSION['id'], $contextId);
            foreach ($rows as $row) {
                $moduleGrades[$row['resource_link_id']] = $row['grade'];
            }
            if (count($ltiItems) > 0) {
                $encodedAnchor = urlencode($module->anchor);
                $moduleData[] = (object)[
                    'title' => $module->title,
                    'session' => $module->session ?? null, // TODO: decide on naming
                    'anchor' => $module->anchor,
                    'ltiItems' => $ltiItems,
                    'grades' => $moduleGrades,
                    'url' => "{$CFG->apphome}/programs/{$program}/{$encodedAnchor}"
                ];
            }
        }

        return (object)[
            'program' => $program,
            'courseTitle' => $adapter->course->title,
            'moduleData' => $moduleData,
        ];
    }

    public function getBadgeData($program, $adapter)
    {
        global $CFG;
        $awarded = [];
        $badgeData = [];
        $programGrades = [];
        // Calculate contextIds
        $contextIds = LessonsOrchestrator::getOrInitAllAdapterContextIds($program, $adapter);
        if (isset($_SESSION['id']) && count($contextIds) > 0) {
            $rows = GradeUtil::loadGradesForCourses($_SESSION['id'], $contextIds);
            foreach ($rows as $row) {
                $programGrades[$row['resource_link_id']] = $row['grade'];
            }
        }
        foreach ($adapter->course->badges as $badge) {
            $threshold = $badge->threshold;
            $count = 0;
            $total = 0;
            $scores = array();
            foreach ($badge->assignments as $resource_link_id) {
                $score = 0;
                if (isset($programGrades[$resource_link_id])) $score = 100 * $programGrades[$resource_link_id];
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
            }
            if (!isset($CFG->badge_url) || $kind != 'success') {
                $img = $CFG->badge_url . '/not-earned.png';
            } else {
                $code = basename($badge->image,'.png');
                $decrypted = $_SESSION['id'] . ':' . $code . ':' . $_SESSION['context_id'];
                $encrypted = bin2hex(AesOpenSSL::encrypt($decrypted, $CFG->badge_encrypt_password));
                $img = $CFG->wwwroot.'/badges/images/'.$encrypted.'.png';
                $badge->img = $CFG->wwwroot.'/badges/images/'.$encrypted.'.png';
                $awarded[] = $badge;
            }

            $badgeData[] = [
                'program' => $program,
                'badge' => $badge,
                'progress' => $progress,
                'kind' => $kind,
                'img' => $img,
                'parentCourse' => $adapter->course,
            ];
        }

        return (object)[
            'badgeData' => $badgeData,
            'awarded' => $awarded,
            'programGrades' => $programGrades,
            'title' => $adapter->course->title
        ];
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

    public function renderBadgeAdmin($gradeMap, $adapter, $buffer = false)
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
            <h1><?= $adapter->course->title ?></h1>
            <ul class="nav nav-tabs mb-3" id="badgeadmin" role="tablist">
                <li class="nav-item" role="presentation"><a class="nav-link active" href="#badgeadmin-by-badge-<?= $adapter->category ?>" data-mdb-toggle="tab" aria-controls="badgeadmin-by-badge-<?= $adapter->category ?>" aria-selected="true">By Badge</a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" href="#badgeadmin-by-user-<?= $adapter->category ?>" data-mdb-toggle="tab" aria-controls="badgeadmin-by-user-<?= $adapter->category ?>" aria-selected="false">By User</a></li>
            </ul>
            <div id="badgeadmin-content" class="tab-content">
                <div class="tab-pane fade show active" id="badgeadmin-by-badge-<?= $adapter->category ?>" role="tabpanel" aria-labelledby="badgeadmin-by-badge-<?= $adapter->category ?>">
                    <?php
                    echo ('<div class="row d-flex flex-wrap justify-content-center">' . "\n");
                    foreach ($adapter->course->badges as $badge) {
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
                            /** TODO: Update badge image URLs to work */
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
                <div class="tab-pane fade" id="badgeadmin-by-user-<?= $adapter->category ?>" role="tabpanel" aria-labelledby="badgeadmin-by-user-<?= $adapter->category ?>">
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
                                foreach ($adapter->course->badges as $badge) {
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

class Course
{
    /** @var string */
    public $title;
    /** @var string */
    public $description;
    /** @var Splash */
    public $splash;
    /** @var Badge[] */
    public $badges;
    /** @var Module[] */
    public $modules;

    public $discussions; // TODO

    function __construct($course)
    {
        $this->title = $course->title;
        $this->description = $course->description;
        $this->splash = $course->splash ?? null;

        $badges = array();
        foreach ($course->badges as $badge) {
            array_push($badges, new Badge($badge));
        }
        $this->badges = $badges;

        $modules = array();
        foreach ($course->modules as $module) {
            if (isset($module->async) && $module->async) {
                array_push($modules, new AsyncModule($module));
            } else {
                array_push($modules, new SyncModule($module));
            }
        }
        $this->modules = $modules;
    }
}

class AsyncModule
{
    /** @var string */
    public $title;
    /** @var string */
    public $anchor;
    /** @var string */
    public $session;
    /** @var string */
    public $duration;
    /** @var AsyncLesson[] */
    public $lessons;
    /** @var Content[] Typically a title, description, and video */
    public $landingContents;
    /** @var string */
    public $image;
    /** @var string */
    public $icon;
    /** @var boolean */
    public $async = true;

    function __construct($module)
    {
        $this->title = $module->title ?? null;
        $this->session = $module->session ?? null;
        $this->anchor  = $module->anchor ?? null;
        $this->icon = $module->icon ?? null;
        $this->duration  = $module->duration ?? null;
        $this->image = $module->image ?? null;
        $this->imagealt = $module->imagealt ?? null;

        $contents = [];
        if (isset($module->landingContents)) {
            foreach ($module->landingContents as $content) {
                $newLesson = new Content($content);
                array_push($contents, $newLesson);
            }
        }
        $this->landingContents = $contents;

        $lessons = [];
        if (isset($module->lessons)) {
            foreach ($module->lessons as $lesson) {
                if (!isset($lesson->sublesson) || $lesson->sublesson == false) { // TODO: Remove
                    $newLesson = new AsyncLesson($lesson);
                    array_push($lessons, $newLesson);
                }
            }
        }
        $this->lessons = $lessons;
    }
}

class AsyncLesson
{
    /** @var string */
    public $title;
    /** @var string */
    public $anchor;
    /** @var string */
    public $icon;
    /** @var string */
    public $teaser;
    /** @var AsyncPage[] */
    public $pages;

    function __construct($lesson)
    {
        $this->title  = $lesson->title ?? null;
        $this->anchor  = $lesson->anchor ?? null;
        $this->icon  = $lesson->icon ?? null;
        $this->teaser  = $lesson->teaser ?? null;

        $pages = [];
        if (isset($lesson->pages)) {
            foreach ($lesson->pages as $page) {
                $newPage = new AsyncPage($page);
                array_push($pages, $newPage);
            }
        }
        $this->pages = $pages;
    }
}

class AsyncPage
{
    /** @var string */
    public $title;
    /** @var string */
    public $anchor;
    /** @var Content[]|Exercise[] */
    public $contents;
    public $subpage;

    function __construct($page)
    {
        $this->title  = $page->title ?? null;
        $this->anchor  = $page->anchor ?? null;
        $this->subpage  = $page->subpage ?? null;

        $contents = [];
        if (isset($page->contents)) {
            foreach ($page->contents as $content) {
                $newContent = new Content($content);
                array_push($contents, $newContent);
            }
        }
        $this->contents = $contents;
    }
}

class SyncModule
{
    /** @var string */
    public $title;
    /** @var string */
    public $description;
    /** @var string */
    public $session;
    /** @var string */
    public $anchor;
    /** @var string */
    public $duration;
    /** @var string */
    public $image;
    /** @var string */
    public $imagealt;
    /** @var string */
    public $icon;
    /** @var string */
    public $calendar;
    // /** @var Content[] Typically a title, description, and video */
    // public $landingContents;
    /** @var LtiContent[] */
    public $lti;
    //   "core": true,
    public $learningoutcomes;
    public $facilitators;
    public $resources;
    /** @var boolean */
    public $async = false;

    function __construct($module)
    {
        $this->title = $module->title ?? null;
        $this->description = $module->description ?? null;
        $this->session = $module->session ?? null;
        $this->anchor  = $module->anchor ?? null;
        $this->duration  = $module->duration ?? null;
        $this->image = $module->image ?? null;
        $this->imagealt = $module->imagealt ?? null;
        $this->icon = $module->icon ?? null;
        $this->calendar = $module->calendar ?? null;
        $this->learningoutcomes = $module->learningoutcomes ?? null;
        $this->facilitators = $module->facilitators ?? null;
        $this->resources = $module->resources ?? null;

        $contents = [];
        // if (isset($module->landingContents)) {
        //     foreach ($module->landingContents as $content) {
        //         $newLesson = new Content($content);
        //         array_push($contents, $newLesson);
        //     }
        // }
        $ltiContent = [];
        if (isset($module->lti)) {
            foreach ($module->lti as $lti) {
                $newLti = new LtiContent($lti);
                $ltiContent[] = $newLti;
            }
        }
        $this->lti = $ltiContent;
    }
}

class Badge
{
    /** @var string */
    public $title;
    /** @var string */
    public $description;
    /** @var string */
    public $image;
    /** @var string */
    public $anchor;
    /** @var string */
    public $threshold;
    /** @var string[] */
    public $assignments;

    function __construct($badge)
    {
        $this->title = $badge->title;
        $this->description = $badge->description;
        $this->image = $badge->image;
        $this->anchor = $badge->anchor;
        $this->threshold = $badge->threshold;
        $this->assignments = $badge->assignments;
    }
}

class Splash
{
    /** @var string */
    public $title;
    /** @var string */
    public $text;
    /** @var string */
    public $image;
    public $alttext;
    /** @var Content[] */
    public $contents;
    /** @var Content */
    public $video;

    function __construct($splash)
    {
        $this->title = $splash->title;
        $this->text = $splash->text;
        $this->image = $splash->image;
        $this->alttext = $splash->alttext;
        $this->video = $splash->video;
        $newContents = [];
        foreach ($splash->contents as $content) {
            array_push($newContents, new Content($content));
        }
        $this->contents = $newContents;
    }
}

class UserProfile
{
    // If Topic -> Author
    // If Sync -> Facilitator or Instructor
    // If Async -> Creator/Author/Instructor?
    // They are still a Tsugi User, but this has a reference to any ancillary info
}

class CourseProgress
{
    // At the page-level to determine progress - do we need this?
}

class Exercise
{
    /** @var 'QUICK_WRITE' | 'QUICK_QUIZ' | 'INTERACTIVE_VIDEO' | Other? */
    public $type;
    /** @var string */
    public $header;
    /** @var string[] */
    public $text;
    /** @var string */
    public $ltiUrl;

    function __construct($exercise)
    {
        // Need to build out what makes an exercise here...
        // ContextId? LinkId?
    }
}

class Content
{
    /** @var 'TEXT' | 'VIDEO' | 'LTI' | 'LINK' | 'UNORDERED_LIST' | 'ORDERED_LIST' */
    public $type;
    /** @var string[] */
    public $paragraphs;
    /** @var VideoContent */
    public $video;
    /** @var LtiContent */
    public $lti;
    /** @var LinkContent */
    public $link;
    /** @var Content[] */
    public $unorderedList;
    /** @var Content[] */
    public $orderedList;

    function __construct($content)
    {
        $this->type = $content->type;
        if ($content->type == 'TEXT') {
            $this->paragraphs = $content->paragraphs ?? [];
        } else if ($content->type == 'VIDEO') {
            $this->video = new VideoContent($content->video->title, $content->video->warpwire);
        } else if ($content->type == 'LTI') {
            $this->lti = new LtiContent($content->lti);
        } else if ($content->type == 'LINK') {
            $this->link = new LinkContent($content->title, $content->icon, $content->href);
        } else if ($content->type == 'ORDERED_LIST') {
            $this->orderedList = new ListContent($content->listItems);
        }
    }
}

class ListContent
{
    /** @var Content[] */
    public $contents;

    function __construct($contentItems)
    {
        $newContents = [];
        foreach ($contentItems as $content) {
            array_push($newContents, new Content($content));
        }
        $this->contents = $newContents;
    }
}

class VideoContent
{
    /** @var string */
    public $title;
    /** @var string */
    public $warpwire;

    function __construct($title, $warpwire)
    {
        $this->title = $title ?? null;
        $this->warpwire = $warpwire ?? null;
    }
}

class LinkContent
{
    /** @var string */
    public $title;
    /** @var string */
    public $icon;
    /** @var string */
    public $url;

    function __construct($title, $icon, $url)
    {
        $this->title = $title ?? null;
        $this->icon = $icon ?? null;
        $this->url = $url ?? null;
    }
}

class LtiContent
{
    /** @var string */
    public $header;
    /** @var string */
    public $description;
    /** @var string */
    public $icon;
    /** @var string */
    public $title;
    /** @var string */
    public $launch;
    /** @var string */
    public $resource_link_id;
    /** @var boolean */
    public $external;
    /** @var int */
    public $threshold;
    /** @var boolean */
    public $hidden;
    /** @var string */ // TODO: Remove
    public $type; // TODO: remove


    function __construct($lti)
    {
        $this->header = $lti->header ?? null;
        $this->description = $lti->description ?? null;
        $this->icon = $lti->icon ?? null;
        $this->title = $lti->title ?? null;
        $this->assignmenttitle = $lti->assignmenttitle ?? null;
        $this->launch = $lti->launch ?? null;
        $this->external = $lti->external ?? null;
        $this->resource_link_id = $lti->resource_link_id ?? null;
        $this->threshold = $lti->threshold ?? null;
        $this->hidden = $lti->hidden ?? null;
        $this->type = $lti->type ?? null; // TODO: remove
    }
}
