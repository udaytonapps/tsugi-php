<?php

// Courses[] ~ [Isidore 101]
//      -> Modules[] ~ [Isidore Onboarding]
//          -> Lessons[] ~ [What is Isidore?, ..., Setting Up Your Course Site, ...]
//              -> Page[] ~ [Site Creation, Overview, Syllabus and Schedule]
//                  -> Contents[] ~ [{Header, Description, Video}]
//                  -> OR Exercises[] ~ [{Header, Description, LTI}]

namespace Tsugi\UI\StandardSync;

use Badge;
use Content;
use LtiContent;

class SyncCourse
{
    /** @var string */
    public $title;
    /** @var string */
    public $description;
    /** @var Badge[] */
    public $badges;
    /** @var Module[] */
    public $modules;

    public $discussions; // TODO

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
            array_push($modules, new SyncModule($module));
        }
        $this->modules = $modules;
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

    function __construct($module)
    {
        $this->title = $module->title ?? null;
        $this->description = $module->description ?? null;
        $this->session = $module->session ?? null;
        $this->anchor  = $module->anchor ?? null;
        $this->duration  = $module->duration ?? null;
        $this->image = $module->image ?? null;
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
