<?php

use Tsugi\UI\LessonsUIHelper;
use Tsugi\UI\StandardAsync\Module;

abstract class CourseBase
{
    protected string $category;
    protected string $base_url_warpwire = 'https://udayton.warpwire.com';

    public function header()
    {
        echo ("Did you set up header method for {$this->category} yet?");
    }

    public function render()
    {
        echo ("Did you set up render method for {$this->category} yet?");
    }

    public function footer()
    {
        echo ("Did you set up footer method for {$this->category} yet?");
    }

    public function getModuleData()
    {
        echo ("Did you set up getModules method for {$this->category} yet?");
    }

    public function getBadges()
    {
        echo ("Did you set up getBadges method for {$this->category} yet?");
    }

    public function getProgress()
    {
        echo ("Did you set up getProgress method for {$this->category} yet?");
    }

    public function getBadgeData($adapter)
    {
        global $CFG;
        $awarded = [];
        $badgeData = [];
        foreach ($adapter->course->badges as $badge) {
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
            }
            if (!isset($CFG->badge_url) || $kind != 'success') {
                $img = $CFG->badge_url . '/not-earned.png';
            } else {
                $img = $adapter->contextRoot . '/assets/bimages/' . $badge->image;
                $badge->img = $img;
                $awarded[] = $badge;
            }

            $badgeData[] = [
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

    function __construct($lti)
    {
        $this->header = $lti->header ?? null;
        $this->description = $lti->description ?? null;
        $this->icon = $lti->icon ?? null;
        $this->title = $lti->title ?? null;
        $this->launch = $lti->launch ?? null;
        $this->external = $lti->external ?? null;
        $this->resource_link_id = $lti->resource_link_id ?? null;
    }
}
