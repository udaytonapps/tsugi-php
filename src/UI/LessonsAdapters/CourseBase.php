<?php

use Tsugi\Grades\GradeUtil;
use Tsugi\UI\LessonsOrchestrator;

abstract class CourseBase
{
    protected string $category;
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
                $img = $adapter->contextRoot . '/assets/bimages/' . $badge->image;
                $badge->img = $img;
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
    /** @var string */ // TODO: Remove
    public $type; // TODO: remove


    function __construct($lti)
    {
        $this->header = $lti->header ?? null;
        $this->description = $lti->description ?? null;
        $this->icon = $lti->icon ?? null;
        $this->title = $lti->title ?? null;
        $this->launch = $lti->launch ?? null;
        $this->external = $lti->external ?? null;
        $this->resource_link_id = $lti->resource_link_id ?? null;
        $this->threshold = $lti->threshold ?? null;
        $this->type = $lti->type ?? null; // TODO: remove
    }
}
