<?php

namespace Tsugi\UI;

use Tsugi\Util\U;
use Tsugi\Core\LTIX;
use Tsugi\UI\StandardAsync\StandardAsyncAdapter;

class LessonsOrchestrator
{
    /**
     * New adapters should be added here
     * (In addition to the relevant files in the LessonsAdapters directory)
     */
    public static function getLessonsReference()
    {
        global $CFG;
        $adapterDirectory = $CFG->dirroot . '/vendor/tsugi/lib/src/UI/LessonsAdapters';
        $reference = (object)[
            'KoseuDefault' => (object)[
                'displayLabel' => 'Koseu Default',
                'adapter' => DefaultLessons::class,
                'adapterPath' => $adapterDirectory . '/KoseuDefault/DefaultLessons.php'
            ],
            'ATLS' => (object)[
                'displayLabel' => 'ATLS',
                'adapter' => AtlsLessons::class,
                'adapterPath' => $adapterDirectory . '/Atls/AtlsLessons.php'
            ],
            'isidore' => (object)[
                'displayLabel' => 'Isidore Training',
                'adapter' => StandardAsyncAdapter::class,
                'adapterPath' => $adapterDirectory . '/StandardAsync/StandardAsyncAdapter.php'
            ],
        ];
        // If not in object, error out?
        return $reference;
    }

    public static function getCategoryName($nameKey)
    {
        try {
            // Instantiate and return the relevant Lessons class
            $adapterReference = self::getLessonsReference($nameKey);
            require_once($adapterReference->{$nameKey}->adapterPath);
            return $adapterReference->{$nameKey}->adapter;
        } catch (\Exception $e) {
            echo ('Unable to retrieve Lessons Adapter!</br>');
            echo ($e->getMessage());
        }
    }

    public static function getLessons($nameKey = 'ATLS', $moduleAnchor = null, $pageAnchor = null, $index = null)
    {
        try {
            // Instantiate and return the relevant Lessons class
            $relativeContext = '/LessonsAdapters' . '/' . $nameKey;
            $adapterReference = self::getLessonsReference($nameKey);
            require_once($adapterReference->{$nameKey}->adapterPath);
            return new $adapterReference->{$nameKey}->adapter($relativeContext, $moduleAnchor, $pageAnchor);
        } catch (\Exception $e) {
            echo ('Unable to retrieve Lessons Adapter!</br>');
            echo ($e->getMessage());
        }
    }

    public static function getLessonsJson($relativeContext)
    {
        global $CFG;
        // Require the relevant course PHP files
        try {
            $orchestratorRoot = $CFG->dirroot . '/vendor/tsugi/lib/src/UI';
            $json_str = file_get_contents($orchestratorRoot . $relativeContext . '/lessons.json');
            return json_decode($json_str);
        } catch (\Exception $e) {
            echo ('Unable to retrieve Lessons JSON!');
            echo ($e->getMessage());
        }
    }


    public static function isInstructor()
    {
        global $LTI;
        return (isset($LTI['role']) && $LTI['role'] >= LTIX::ROLE_INSTRUCTOR) || (isset($_SESSION['role']) && $_SESSION['role'] >= LTIX::ROLE_INSTRUCTOR);
    }

    public static function absolute_url_ref(&$url)
    {
        $url = trim($url);
        $url = self::expandLink($url);
        $url = U::absolute_url($url);
    }

    /*
     * Do macro substitution on a link
     */
    public static function expandLink($url)
    {
        global $CFG;
        $search = array(
            "{apphome}",
            "{wwwroot}",
        );
        $replace = array(
            $CFG->apphome,
            $CFG->wwwroot,
        );
        $url = str_replace($search, $replace, $url);
        return $url;
    }

    /*
     * A Nostyle URL Link with title
     */
    public static function nostyleUrl($title, $url)
    {
        $url = self::expandLink($url);
        echo ('<a href="' . $url . '" target="_blank" typeof="oer:SupportingMaterial">' . htmlentities($url) . "</a>\n");
        if (isset($_SESSION['gc_count'])) {
            echo ('<div class="g-sharetoclassroom" data-size="16" data-url="' . $url . '" ');
            echo (' data-title="' . htmlentities($title) . '" ');
            echo ('></div>');
        }
    }

    public static function getUrlResources($module)
    {
        $resources = array();
        if (isset($module->carousel)) {
            foreach ($module->carousel as $carousel) {
                $resources[] = self::makeUrlResource(
                    'video',
                    $carousel->title,
                    'https://www.youtube.com/watch?v=' . urlencode($carousel->youtube)
                );
            }
        }
        if (isset($module->videos)) {
            foreach ($module->videos as $video) {
                $resources[] = self::makeUrlResource(
                    'video',
                    $video->title,
                    'https://www.youtube.com/watch?v=' . urlencode($video->youtube)
                );
            }
        }
        if (isset($module->slides)) {
            $resources[] = self::makeUrlResource('slides', __('Slides') . ': ' . $module->title, $module->slides);
        }
        if (isset($module->assignment)) {
            $resources[] = self::makeUrlResource('assignment', 'Assignment Specification', $module->assignment);
        }
        if (isset($module->solution)) {
            $resources[] = self::makeUrlResource('solution', 'Assignment Solution', $module->solution);
        }
        if (isset($module->references)) {
            foreach ($module->references as $reference) {
                if (!isset($reference->title) || !isset($reference->href)) continue;
                $resources[] = self::makeUrlResource('reference', $reference->title, $reference->href);
            }
        }
        return $resources;
    }

    public static function makeUrlResource($type, $title, $url)
    {
        global $CFG;
        $RESOURCE_ICONS = array(
            'video' => 'fa-video-camera',
            'slides' => 'fa-file-powerpoint-o',
            'assignment' => 'fa-lock',
            'solution' => 'fa-unlock',
            'reference' => 'fa-external-link'
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

    public static function autoRegisterAttendee($regKey)
    {
        global $CFG;

        $PDOX = LTIX::getConnection();
        $p = $CFG->dbprefix;

        $sql = "SELECT u.user_id, ar.instance_id, mi.module_launch_id, ar.context_id, ar.link_id, reg.registration_id
                FROM {$p}atls_auto_reg AS ar
                INNER JOIN {$p}lti_user AS u ON ar.user_email = u.email
                INNER JOIN {$p}atls_module_instance AS mi ON ar.instance_id = mi.instance_id
                LEFT OUTER JOIN  {$p}atls_registration as reg ON ar.instance_id = reg.instance_id AND reg.user_id = u.user_id
                WHERE ar.auto_reg_key = :regKey";
        $row = $PDOX->rowDie($sql, array(':regKey' => $regKey));

        if ($row) {
            $contextId = $row['context_id'];
            $linkId = $row['link_id'];
            $userId = $row['user_id'];
            $instanceId = $row['instance_id'];
            $moduleLaunchId = $row['module_launch_id'];
            $regId = $row['registration_id'];

            if (isset($regId)) {
                // Record exists, need to update for some reason
                $query = "  UPDATE {$p}atls_registration
                        SET attendance_status = 'ATTENDED' WHERE user_id = :userId AND instance_id = :instanceId;";
                $arr = array(':userId' => $userId, ':instanceId' => $instanceId);
                $PDOX->queryDie($query, $arr);
            } else {
                // Record doesn't exist, create it
                $query = "INSERT INTO {$p}atls_registration (user_id, context_id, link_id, module_launch_id, instance_id, attendance_status)
                VALUES (:userId, :contextId, :linkId, :moduleLaunchId, :instanceId, :attendanceStatus);";
                $arr = array(':userId' => $userId, ':contextId' => $contextId, ':linkId' => $linkId, ':moduleLaunchId' => $moduleLaunchId, ':instanceId' => $instanceId, ':attendanceStatus' => 'ATTENDED');
                $PDOX->queryDie($query, $arr);
            }
        }
    }

    public static function modifyLessonsAndLinks($lessons, $resource_links)
    {
        if ($lessons === null) {
            echo ("<pre>\n");
            echo ("Problem parsing lessons.json: ");
            echo (json_last_error_msg());
            echo ("\n");
            die();
        }

        // Demand that every module have required elments
        foreach ($lessons->modules as $module) {
            if (!isset($module->title)) {
                die_with_error_log('All modules in a lesson must have a title');
            }
            if (!isset($module->anchor)) {
                die_with_error_log('All modules must have an anchor: ' . $module->title);
            }
        }

        // Demand that every module have required elments
        if (isset($lessons->badges)) foreach ($lessons->badges as $badge) {
            if (!isset($badge->title)) {
                die_with_error_log('All badges in a lesson must have a title');
            }
            if (!isset($badge->assignments)) {
                die_with_error_log('All badges must have assignments: ' . $badge->title);
            }
        }
        // Filter modules based on login
        if (!isset($_SESSION['id'])) {
            $filtered_modules = array();
            $filtered = false;
            foreach ($lessons->modules as $module) {
                if (isset($module->login) && $module->login) {
                    $filtered = true;
                    continue;
                }
                $filtered_modules[] = $module;
            }
            if ($filtered) $lessons->modules = $filtered_modules;
        }

        // Pretty up the data structure
        for ($i = 0; $i < count($lessons->modules); $i++) {
            if (isset($lessons->modules[$i]->carousel)) self::adjustArray($lessons->modules[$i]->carousel);
            if (isset($lessons->modules[$i]->videos)) self::adjustArray($lessons->modules[$i]->videos);
            if (isset($lessons->modules[$i]->references)) self::adjustArray($lessons->modules[$i]->references);
            if (isset($lessons->modules[$i]->assignments)) self::adjustArray($lessons->modules[$i]->assignments);
            if (isset($lessons->modules[$i]->slides)) self::adjustArray($lessons->modules[$i]->slides);
            if (isset($lessons->modules[$i]->lti)) self::adjustArray($lessons->modules[$i]->lti);
            if (isset($lessons->modules[$i]->discussions)) self::adjustArray($lessons->modules[$i]->discussions);

            // Non arrays
            if (isset($lessons->modules[$i]->assignment)) {
                if (!is_string($lessons->modules[$i]->assignment)) die_with_error_log('Assignment must be a string: ' . $module->title);
                self::absolute_url_ref($lessons->modules[$i]->assignment);
            }
            if (isset($lessons->modules[$i]->solution)) {
                if (!is_string($lessons->modules[$i]->solution)) die_with_error_log('Solution must be a string: ' . $module->title);
                self::absolute_url_ref($lessons->modules[$i]->solution);
            }
        }

        // Patch badges
        if (isset($lessons->badges)) for ($i = 0; $i < count($lessons->badges); $i++) {
            if (!isset($lessons->badges[$i]->threshold)) {
                $lessons->badges[$i]->threshold = 1.0;
            }
        }

        // Make sure resource links are unique and remember them
        foreach ($lessons->modules as $module) {
            if (isset($module->lti)) {
                $ltis = $module->lti;
                if (!is_array($ltis)) $ltis = array($ltis);
                foreach ($ltis as $lti) {
                    if ($lti->type != 'ADMINISTRATION') {
                        if (!isset($lti->title)) {
                            die_with_error_log('Missing lti title in module:' . $module->title);
                        }
                        if (!isset($lti->resource_link_id)) {
                            die_with_error_log('Missing resource link in Lessons ' . $lti->title);
                        }
                        if (isset($resource_links[$lti->resource_link_id])) {
                            die_with_error_log('Duplicate resource link in Lessons ' . $lti->resource_link_id);
                        }
                        $resource_links[$lti->resource_link_id] = $module->anchor;
                    }
                }
            }
            if (isset($module->discussions)) {
                $discussions = $module->discussions;
                if (!is_array($discussions)) $discussions = array($discussions);
                foreach ($discussions as $discussion) {
                    if (!isset($discussion->title)) {
                        die_with_error_log('Missing discussion title in module:' . $module->title);
                    }
                    if (!isset($discussion->resource_link_id)) {
                        die_with_error_log('Missing resource link in Lessons ' . $discussion->title);
                    }
                    if (isset($resource_links[$discussion->resource_link_id])) {
                        die_with_error_log('Duplicate resource link in Lessons ' . $discussion->resource_link_id);
                    }
                    $resource_links[$discussion->resource_link_id] = $module->anchor;
                }
            }
        }
    }

    /** Make non-array into an array and adjust paths */
    public static function adjustArray(&$entry)
    {
        global $CFG;
        if (isset($entry) && !is_array($entry)) {
            $entry = array($entry);
        }
        for ($i = 0; $i < count($entry); $i++) {
            if (is_string($entry[$i])) self::absolute_url_ref($entry[$i]);
            if (isset($entry[$i]->href) && is_string($entry[$i]->href)) self::absolute_url_ref($entry[$i]->href);
            if (isset($entry[$i]->launch) && is_string($entry[$i]->launch)) self::absolute_url_ref($entry[$i]->launch);
        }
    }
}
