<?php

// Courses[] ~ [Isidore 101]
//      -> Modules[] ~ [Isidore Onboarding]
//          -> Lessons[] ~ [What is Isidore?, ..., Setting Up Your Course Site, ...]
//              -> AsyncPage[] ~ [Site Creation, Overview, Syllabus and Schedule]
//                  -> Contents[] ~ [{Header, Description, Video}]
//                  -> OR Exercises[] ~ [{Header, Description, LTI}]

// Note: For single lessons (What is Isidore?), maybe convert to 4 Sections: Overview, Video, Quiz, Additional Resources, etc.

namespace Tsugi\UI\StandardAsync;

use Badge;
use Content;
use CourseBase;

class AsyncBase extends CourseBase
{
    public AsyncCourse $course;

    protected function findPreviousPage(int $lessonIndex, int $pageIndex, AsyncModule $module)
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

    protected function findNextPage(int $lessonIndex, int $pageIndex, AsyncModule $module)
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
    /** @var AsyncModule[] */
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
            array_push($modules, new AsyncModule($module));
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
    public $duration;
    /** @var AsyncLesson[] */
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


