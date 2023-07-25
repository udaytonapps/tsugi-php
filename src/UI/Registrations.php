<?php

namespace Tsugi\UI;

use \Tsugi\Util\U;
use \Tsugi\Util\LTI;
use \Tsugi\Core\LTIX;
use \Tsugi\Crypt\AesOpenSSL;


class Registrations
{

    /**
     * All the facilitators
     */
    public $all_registrations = [];

    public $lessons;

    /**
     * emit the header material
     */
    public function header($buffer = false)
    {
        global $CFG;
        ob_start(); ?>
        <style>
            <?php include 'tsugi/vendor/tsugi/lib/src/UI/Lessons.css'; ?>
        </style><?php
        if (isset($this->lessons->headers) && is_array($this->lessons->headers)) {
            foreach ($this->lessons->headers as $header) {
                $header = self::expandLink($header);
                echo ($header);
                echo ("\n");
            }
        }
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }

    /*
     ** Load up the JSON from the file
     **/
    public function __construct($name = 'lessons.json', $anchor = null, $index = null)
    {
        // $this->all_registrations = LessonsOrchestrator::getAllFacilitatorsAndTheirModules();
        return true;
    }

    /**
     * Make non-array into an array and adjust paths
     */
    public static function adjustArray(&$entry)
    {
        global $CFG;
        if (isset($entry) && !is_array($entry)) {
            $entry = array($entry);
        }
    }

    /*
     ** render
     */
    public function render($buffer = false, $facilitatorId = null)
    {
        echo '</br><h1>Registrations</h1></br>';
        global $USER;
        ob_start();

        // get all registration records
        $registrations = LessonsOrchestrator::getUserRegistrations($USER->id);

        if ($registrations && count($registrations) > 0) {
            global $CFG;
            $twig = LessonsUIHelper::twig();

            $activeRegistrations = [];
            $previousRegistrations = [];

            $lessonsReference = LessonsOrchestrator::getLessonsReference();
            foreach ($registrations as $registration) {
                $registration['displaydate'] = self::formatDateDuration($registration['session_date'], $registration['duration_minutes']);
                $registration['displaytime'] = self::formatTimeDuration($registration['session_date'], $registration['duration_minutes']);

                foreach ($lessonsReference as $program => $reference) {

                    $context = LessonsOrchestrator::getRelativeContext($program);
                    $lessons = LessonsOrchestrator::getLessonsJson($context);
                    // Find all modules related to that facilitator
                    // If module has facilitator email, add that module name to the facilitators module array
                    if ($program == $registration['module_program']) {
                        $registration['program'] = $lessonsReference->$program->displayLabel;
                        foreach ($lessons->modules as $module) {

                            if (isset($module->anchor) && $module->anchor == $registration['module_launch_id']) {
                                $encodedAnchor = urlencode($module->anchor);
                                $registration['title'] = $module->title ?? null;
                                $registration['session'] = $module->session ?? null;
                                $registration['moduletype'] = isset($module->async) && $module->async ? 'async' : 'sync';
                                $registration['moduleUrl'] = "{$CFG->apphome}/programs/{$program}/{$encodedAnchor}";
                                $registration['programUrl'] = "{$CFG->apphome}/programs/{$program}";
                                $registration['icon'] = $module->icon ?? null;
                                if ($registration['attendance_status'] == 'REGISTERED') {
                                    $activeRegistrations[] = $registration;
                                } else {
                                    $previousRegistrations[] = $registration;
                                }
                            }
                        }
                    }
                }
            }

            echo $twig->render('registrations.twig', [
                'activeRegistrations' => $activeRegistrations,
                'previousRegistrations' => $previousRegistrations
            ]);
        } else {
            echo ('<h6 style="text-align: center;" class="p-5">No registrations found.</h6>');
        }

        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }

    public function footer($buffer = false)
    {
        global $CFG;
        ob_start();
        if (isset($this->lessons->footers) && is_array($this->lessons->footers)) {
            foreach ($this->lessons->footers as $footer) {
                $footer = self::expandLink($footer);
                echo ($footer);
                echo ("\n");
            }
        }
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    } // end footer

    function formatDateDuration($timestamp, $durationMinutes)
    {
        // Convert the timestamp to a DateTime object
        $startDateTime = new \DateTime($timestamp);

        // Calculate the end DateTime by adding the duration in minutes
        $endDateTime = clone $startDateTime;
        $endDateTime->add(new \DateInterval('PT' . $durationMinutes . 'M'));

        // Format the date, start time, and end time as strings
        $formattedDate = $startDateTime->format('F j, Y');

        // Construct the final formatted string
        $formattedString = $formattedDate;

        return $formattedString;
    }

    function formatTimeDuration($timestamp, $durationMinutes)
    {
        // Convert the timestamp to a DateTime object
        $startDateTime = new \DateTime($timestamp);

        // Calculate the end DateTime by adding the duration in minutes
        $endDateTime = clone $startDateTime;
        $endDateTime->add(new \DateInterval('PT' . $durationMinutes . 'M'));

        // Format the date, start time, and end time as strings
        $formattedStartTime = $startDateTime->format('g:i A');
        $formattedEndTime = $endDateTime->format('g:i A');

        // Construct the final formatted string
        $formattedString = $formattedStartTime . ' - ' . $formattedEndTime;

        return $formattedString;
    }
}
