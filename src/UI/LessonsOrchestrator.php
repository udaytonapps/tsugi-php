<?php

namespace Tsugi\UI;

require_once(__DIR__ . '/LessonsAdapters/CourseBase.php');
require_once(__DIR__ . '/LessonsAdapters/GenericLessonsAdapter.php');

use CourseBase;
use GenericAdapter;
use Tsugi\Util\U;
use Tsugi\Core\LTIX;

class LessonsOrchestrator
{
    public static function getOrchestratorRoot()
    {
        global $CFG;
        return $CFG->dirroot . '/vendor/tsugi/lib/src/UI';
    }
    public static function getRelativeContext($program)
    {
        return '/LessonsAdapters' . '/' . $program;
    }
    /**
     * New adapters should be added here
     * (In addition to the relevant files in the LessonsAdapters directory)
     */
    public static function getLessonsReference()
    {
        global $CFG;
        // Pull together any standard Async/Sync courses, and assign here with their adapters
        $adapterDirectory = $CFG->dirroot . '/vendor/tsugi/lib/src/UI/LessonsAdapters';
        $reference = (object)[
            'KoseuDefault' => (object)[
                'displayLabel' => 'Koseu Default',
                'adapter' => DefaultLessons::class,
                'adapterPath' => $adapterDirectory . '/KoseuDefault/DefaultLessons.php'
            ],
            'ATLS' => (object)[
                'displayLabel' => 'ATLS',
                'adapter' => GenericAdapter::class,
                'adapterPath' => $adapterDirectory . '/GenericLessonsAdapter.php',
            ],
            'col' => (object)[
                'displayLabel' => 'Center for Online Learning',
                'adapter' => GenericAdapter::class,
                'adapterPath' => $adapterDirectory . '/GenericLessonsAdapter.php',
            ]
        ];
        // If not in object, error out?
        return $reference;
    }

    public static function getLessons($program, $moduleAnchor = null, $pageAnchor = null, $index = null): CourseBase
    {
        try {
            // Instantiate and return the relevant Lessons class
            $adapterReference = self::getLessonsReference($program);
            require_once($adapterReference->{$program}->adapterPath);
            return new $adapterReference->{$program}->adapter(self::getRelativeContext($program), $moduleAnchor, $pageAnchor);
        } catch (\Exception $e) {
            echo ('Unable to retrieve Lessons Adapter!</br>');
            echo ($e->getMessage());
        }
    }

    public static function getLessonsJson($relativeContext)
    {
        // Require the relevant course PHP files
        try {
            $json_str = file_get_contents(self::getOrchestratorRoot() . $relativeContext . '/lessons.json');
            return json_decode($json_str);
        } catch (\Exception $e) {
            echo ('Unable to retrieve Lessons JSON!');
            echo ($e->getMessage());
        }
    }

    public static function getAllFacilitators()
    {
        global $CFG;
        $PDOX = LTIX::getConnection();
        $p = $CFG->dbprefix;
        $sql = "SELECT *
        FROM {$p}learn_facilitator ORDER BY SUBSTRING_INDEX(displayname, ' ', -1)";
        return $PDOX->allRowsDie($sql, []);
    }

    public static function getFacilitatorByEmail($facilitatorEmail)
    {
        global $CFG;

        // Check our Learn record (for title, at least)
        $PDOX = LTIX::getConnection();
        $p = $CFG->dbprefix;
        $sql = "SELECT *
        FROM {$p}learn_facilitator WHERE email = :email";
        $learnFacilitator = $PDOX->rowDie($sql, [':email' => $facilitatorEmail]);

        // Check against Tsugi record
        $PDOX = LTIX::getConnection();
        $p = $CFG->dbprefix;
        $sql = "SELECT *
        FROM {$p}lti_user WHERE email = :email";
        $tsugiUser = $PDOX->rowDie($sql, [':email' => $facilitatorEmail]);
        if ($tsugiUser) {
            $learnFacilitator['displayname'] = $tsugiUser['displayname'];
            $learnFacilitator['image_url'] = $tsugiUser['image'];
        } else {
        }
        return $learnFacilitator;
    }

    public static function getAllFacilitatorsAndTheirModules()
    {
        $facilitators = self::getAllFacilitators();
        foreach ($facilitators as &$facilitator) {
            $facilitator['sessions'] = [];
            // Loop over all lessons and check Facilitator references
            $references = self::getLessonsReference();
            foreach ($references as $program => $reference) {
                $context = self::getRelativeContext($program);
                $lessons = self::getLessonsJson($context);
                // Find all modules related to that facilitator
                // If module has facilitator email, add that module name to the facilitators module array
                foreach ($lessons->modules as $module) {
                    if (isset($module->facilitators)) {
                        if (in_array($facilitator['email'], $module->facilitators)) {
                            $facilitator['sessions'][] = (object)[
                                'title' => $reference->displayLabel . (isset($module->session) && strlen($module->session) > 0 ? ' - ' . $module->session . ': ' : ': ') . $module->title,
                                'url' => U::get_rest_parent() . "/programs/{$program}/" . urlencode($module->anchor),
                            ];
                        }
                    }
                }
            }
        }
        return $facilitators;
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
                FROM {$p}learn_auto_reg AS ar
                INNER JOIN {$p}lti_user AS u ON ar.user_email = u.email
                INNER JOIN {$p}learn_module_instance AS mi ON ar.instance_id = mi.instance_id
                LEFT OUTER JOIN  {$p}learn_registration as reg ON ar.instance_id = reg.instance_id AND reg.user_id = u.user_id
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
                $query = "  UPDATE {$p}learn_registration
                        SET attendance_status = 'ATTENDED' WHERE user_id = :userId AND instance_id = :instanceId;";
                $arr = array(':userId' => $userId, ':instanceId' => $instanceId);
                $PDOX->queryDie($query, $arr);
            } else {
                // Record doesn't exist, create it
                $query = "INSERT INTO {$p}learn_registration (user_id, context_id, link_id, module_launch_id, instance_id, attendance_status)
                VALUES (:userId, :contextId, :linkId, :moduleLaunchId, :instanceId, :attendanceStatus);";
                $arr = array(':userId' => $userId, ':contextId' => $contextId, ':linkId' => $linkId, ':moduleLaunchId' => $moduleLaunchId, ':instanceId' => $instanceId, ':attendanceStatus' => 'ATTENDED');
                $PDOX->queryDie($query, $arr);
            }
        }
    }

    public static function modifyLessonsAndLinks($lessons, $resource_links)
    {
        global $CFG;
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

            // TODO: Determine if needed - is tsugi deployed at the root level?
            if (isset($lessons->modules[$i]->lti)) {
                if (isset($CFG->local_dev_server) && $CFG->local_dev_server) {
                    foreach ($lessons->modules[$i]->lti as $lti) {
                        $lti->launch = "/tsugi{$lti->launch}";
                    }
                }
                self::adjustArray($lessons->modules[$i]->lti);
            }
            // Look all the way down to modules->lessons->pages->contents - they may have LTI content
            $module = $lessons->modules[$i];
            if (isset($module->lessons)) {
                foreach ($module->lessons as $lesson) {
                    if (isset($lesson->pages)) {
                        foreach ($lesson->pages as $page) {
                            if (isset($page->contents)) {
                                foreach ($page->contents as $content) {
                                    if (isset($content->lti)) {
                                        if (isset($CFG->external_store) && isset($content->lti->external) && $content->lti->external) {
                                            $content->lti->launch = "{$CFG->external_store}{$content->lti->launch}";
                                        } else {
                                            if (isset($CFG->local_dev_server) && $CFG->local_dev_server) {
                                                $content->lti->launch = "/tsugi{$content->lti->launch}";
                                            }
                                            self::absolute_url_ref($content->lti->launch);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
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

    /** Get an LTI or Discussion associated with a resource link ID */
    public static function getLtiByRlid($course, $resource_link_id)
    {
        // Sync
        if (isset($course->discussions)) {
            foreach ($course->discussions as $discussion) {
                if ($discussion->resource_link_id == $resource_link_id) return $discussion;
            }
        }

        foreach ($course->modules as $mod) {
            if (isset($mod->lti)) {
                foreach ($mod->lti as $lti) {
                    if ($lti->resource_link_id == $resource_link_id) return $lti;
                }
            }
            if (isset($mod->discussions)) {
                foreach ($mod->discussions as $discussion) {
                    if ($discussion->resource_link_id == $resource_link_id) return $discussion;
                }
            }
        }

        // Async
        foreach ($course->modules as $mod) {
            if (isset($mod->lessons)) {
                foreach ($mod->lessons as $lesson) {
                    if (isset($lesson->pages)) {
                        foreach ($lesson->pages as $page) {
                            if (isset($page->contents)) {
                                foreach ($page->contents as $content) {
                                    if (isset($content->lti)) {
                                        if ($content->lti->resource_link_id == $resource_link_id) return $content->lti;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    /** Get a module associated with a resource link ID */
    public static function getModuleByRlid($course, $resource_link_id)
    {
        // Sync
        foreach ($course->modules as $mod) {
            if (isset($mod->lti)) {
                foreach ($mod->lti as $lti) {
                    if ($lti->resource_link_id == $resource_link_id) return $mod;
                }
            }
            if (isset($mod->discussions)) {
                foreach ($mod->discussions as $discussion) {
                    if ($discussion->resource_link_id == $resource_link_id) return $mod;
                }
            }
        }

        // Async
        foreach ($course->modules as $mod) {
            if (isset($mod->lessons)) {
                foreach ($mod->lessons as $lesson) {
                    if (isset($lesson->pages)) {
                        foreach ($lesson->pages as $page) {
                            if (isset($page->contents)) {
                                foreach ($page->contents as $content) {
                                    if (isset($content->lti)) {
                                        if ($content->lti->resource_link_id == $resource_link_id) return $mod;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    public static function getModuleLtiItems($module)
    {
        $ltiItems = [];
        // Sync
        if (isset($module->lti)) {
            foreach ($module->lti as $lti) {
                $ltiItems[] = $lti;
            }
        }

        // Async
        if (isset($module->lessons)) {
            foreach ($module->lessons as $lesson) {
                if (isset($lesson->pages)) {
                    foreach ($lesson->pages as $page) {
                        if (isset($page->contents)) {
                            foreach ($page->contents as $content) {
                                if (isset($content->lti)) {
                                    $ltiItems[] = $content->lti;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $ltiItems;
    }

    public static function getOrInitAllAdapterContextIds($program, $adapter)
    {
        $contextIds = [];
        foreach ($adapter->course->modules as $module) {
            $contextKey = "{$program}_{$module->anchor}";
            $contextId = self::getOrInitContextId($module->title, $contextKey);
            $contextIds[] = (int)$contextId;
        }
        return $contextIds;
    }

    public static function getOrInitContextId($courseTitle, $contextKey)
    {
        global $CFG, $PDOX;
        $oauth_consumer_key = 'google.com';

        // First we make sure that there is a google.com key
        $stmt = $PDOX->queryDie(
            "SELECT key_id, secret FROM {$CFG->dbprefix}lti_key
        WHERE key_sha256 = :SHA LIMIT 1",
            array('SHA' => lti_sha256($oauth_consumer_key))
        );
        $key_row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($key_row === false) {
            die_with_error_log('Error: No key defined for accounts from google.com');
        }
        $google_key_id = $key_row['key_id'] + 0;
        $google_secret = $key_row['secret'];
        if ($google_key_id < 1) {
            die_with_error_log('Error: No key for accounts from google.com');
        }

        $context_key = false;
        $context_id = false;
        $context_key = 'course:' . md5($contextKey);

        $row = $PDOX->rowDie(
            "SELECT context_id FROM {$CFG->dbprefix}lti_context
            WHERE context_sha256 = :SHA AND key_id = :KID LIMIT 1",
            array(':SHA' => lti_sha256($context_key), ':KID' => $google_key_id)
        );

        if ($row != false) {
            $context_id = $row['context_id'];
        } else {
            $sql = "INSERT INTO {$CFG->dbprefix}lti_context
                ( context_key, context_sha256, title, key_id, created_at, updated_at ) VALUES
                ( :context_key, :context_sha256, :title, :key_id, NOW(), NOW() )";
            $PDOX->queryDie($sql, array(
                ':context_key' => $context_key,
                ':context_sha256' => lti_sha256($context_key),
                ':title' => $courseTitle,
                ':key_id' => $google_key_id
            ));
            $context_id = $PDOX->lastInsertId();
        }
        return $context_id;
    }
}
