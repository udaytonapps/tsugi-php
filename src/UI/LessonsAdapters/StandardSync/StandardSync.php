<?php

// Courses[] ~ [Isidore 101]
//      -> Modules[] ~ [Isidore Onboarding]
//          -> Lessons[] ~ [What is Isidore?, ..., Setting Up Your Course Site, ...]
//              -> Page[] ~ [Site Creation, Overview, Syllabus and Schedule]
//                  -> Contents[] ~ [{Header, Description, Video}]
//                  -> OR Exercises[] ~ [{Header, Description, LTI}]

namespace Tsugi\UI\StandardSync;

use CourseBase;

class SyncBase extends CourseBase
{
    // protected SyncCourse $course;
}

class SyncCourse
{
    // /** @var string */
    // public $title;
    // /** @var string */
    // public $description;
    // /** @var Badge[] */
    // public $badges;
    // /** @var Module[] */
    // public $modules;

    // function __construct($course)
    // {
    //     $this->title = $course->title;
    //     $this->description = $course->description;

    //     $badges = array();
    //     foreach ($course->badges as $badge) {
    //         array_push($badges, new Badge($badge));
    //     }
    //     $this->badges = $badges;

    //     $modules = array();
    //     foreach ($course->modules as $module) {
    //         array_push($modules, new Module($module));
    //     }
    //     $this->modules = $modules;
    // }
}
