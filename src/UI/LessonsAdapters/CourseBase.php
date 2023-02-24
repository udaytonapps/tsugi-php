<?php

use Tsugi\UI\LessonsUIHelper;
use Tsugi\UI\StandardAsync\Module;

abstract class CourseBase
{
    protected $category;
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
