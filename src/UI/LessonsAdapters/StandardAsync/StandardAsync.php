<?php

// Courses[] ~ [Isidore 101]
//      -> Modules[] ~ [Isidore Onboarding]
//          -> Lessons[] ~ [What is Isidore?, ..., Setting Up Your Course Site, ...]
//              -> Page[] ~ [Site Creation, Overview, Syllabus and Schedule]
//                  -> Contents[] ~ [{Header, Description, Video}]
//                  -> OR Exercises[] ~ [{Header, Description, LTI}]

// Note: For single lessons (What is Isidore?), maybe convert to 4 Sections: Overview, Video, Quiz, Additional Resources, etc.

namespace Tsugi\UI\StandardAsync;

use CourseBase;

class AsyncBase extends CourseBase
{
    public AsyncCourse $course;

    protected function findPreviousPage(int $lessonIndex, int $pageIndex, Module $module)
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

    protected function findNextPage(int $lessonIndex, int $pageIndex, Module $module)
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

class AsyncCourse
{
    /** @var string */
    public $title;
    /** @var string */
    public $description;
    /** @var Badge[] */
    public $badges;
    /** @var Module[] */
    public $modules;

    function __construct($course)
    {
        $this->title = $course->title;
        $this->description = $course->description;

        $badges = array();
        foreach ($course->badges as $badge) {
            array_push($badges, new Badge($badge));
        }
        $this->badges = $badges;

        $modules = array();
        foreach ($course->modules as $module) {
            array_push($modules, new Module($module));
        }
        $this->modules = $modules;
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

class Module
{
    /** @var string */
    public $title;
    /** @var string */
    public $anchor;
    /** @var string */
    public $duration;
    /** @var Lesson[] */
    public $lessons;
    /** @var Content[] Typically a title, description, and video */
    public $landingContents;

    function __construct($module)
    {
        $this->title = $module->title ?? null;
        $this->anchor  = $module->anchor ?? null;
        $this->duration  = $module->duration ?? null;

        $contents = [];
        if (isset($module->landingContents)) {
            foreach ($module->landingContents as $content) {
                $newLesson = new Content($content);
                array_push($contents, $content);
            }
        }
        $this->landingContents = $contents;

        $lessons = [];
        if (isset($module->lessons)) {
            foreach ($module->lessons as $lesson) {
                if (!isset($lesson->sublesson) || $lesson->sublesson == false) { // TODO: Remove
                    $newLesson = new Lesson($lesson);
                    array_push($lessons, $newLesson);
                }
            }
        }
        $this->lessons = $lessons;
    }
}

class Lesson
{
    /** @var string */
    public $title;
    /** @var string */
    public $anchor;
    /** @var string */
    public $icon;
    /** @var string */
    public $teaser;
    /** @var Page[] */
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
                $newPage = new Page($page);
                array_push($pages, $newPage);
            }
        }
        $this->pages = $pages;
    }
}

class Page
{
    /** @var string */
    public $title;
    /** @var string */
    public $anchor;
    /** @var Content[]|Exercise[] */
    public $contents;

    function __construct($page)
    {
        $this->title  = $page->title ?? null;
        $this->anchor  = $page->anchor ?? null;

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

    function __construct($lti)
    {
        $this->header = $lti->header ?? null;
        $this->description = $lti->description ?? null;
        $this->icon = $lti->icon ?? null;
        $this->title = $lti->title ?? null;
        $this->launch = $lti->launch ?? null;
        $this->resource_link_id = $lti->resource_link_id ?? null;
    }
}
