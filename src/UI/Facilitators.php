<?php

namespace Tsugi\UI;

use \Tsugi\Util\U;
use \Tsugi\Util\LTI;
use \Tsugi\Core\LTIX;
use \Tsugi\Crypt\AesOpenSSL;


class Facilitators {

    /**
     * All the facilitators
     */
    public $all_facilitators = [];

    public $lessons;

    /**
     * emit the header material
     */
    public function header($buffer=false) {
        global $CFG;
        ob_start();
        if ( isset($this->lessons->headers) && is_array($this->lessons->headers) ) {
            foreach($this->lessons->headers as $header) {
                $header = self::expandLink($header);
                echo($header);
                echo("\n");
            }
        }
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ( $buffer ) return $ob_output;
        echo($ob_output);
    }

    /*
     ** Load up the JSON from the file
     **/
    public function __construct($name='lessons.json', $anchor=null, $index=null)
    {
        global $CFG;

        $lessons = LessonsOrchestrator::getLessonsJson('/LessonsAdapters/ATLS'); // TODO: Update
        $this->resource_links = array();

        if ( $lessons === null ) {
            echo("<pre>\n");
            echo("Problem parsing lessons.json: ");
            echo(json_last_error_msg());
            echo("\n");
            echo($name);
            die();
        }

        // Filter modules based on login
        if ( !isset($_SESSION['id']) ) {
            $filtered_modules = array();
            $filtered = false;
            foreach($lessons->modules as $module) {
	            if ( isset($module->login) && $module->login ) {
                    $filtered = true;
                    continue;
                }
                $filtered_modules[] = $module;
            }
            if ( $filtered ) $lessons->modules = $filtered_modules;
        }
        $this->lessons = $lessons;

        // Pretty up the data structure
        for($i=0;$i<count($this->lessons->modules);$i++) {
            if ( isset($this->lessons->modules[$i]->facilitators) ) self::adjustArray($this->lessons->modules[$i]->facilitators);
        }

        $this->all_facilitators = LessonsOrchestrator::getAllFacilitatorsAndTheirModules();

        return true;
    }

    /**
     * Make non-array into an array and adjust paths
     */
    public static function adjustArray(&$entry) {
        global $CFG;
        if ( isset($entry) && !is_array($entry) ) {
            $entry = array($entry);
        }
    }

    /*
     ** render
     */
    public function render($buffer=false) {
        ob_start();

        echo '<h4>'.$this->lessons->title.'</h4><h1>All Facilitators</h1>';
        ?>
        <table class="table align-middle mb-0 bg-white">
            <thead class="bg-light">
            <tr>
                <th>Facilitator</th>
                <th>Session(s)</th>
            </tr>
            </thead>
            <tbody>
        <?php
        foreach ($this->all_facilitators as $facilitator) {
            ?>
            <tr>
            <td>
                <div class="d-flex align-items-center">
                    <img src="<?=$facilitator['image_url']?>"
                            alt="<?=$facilitator['displayname']?>"
                            style="object-fit: cover; object-position: 50% 30%; min-width: 70px; min-height: 70px; width: 70px; height: 70px;"
                            class="rounded-circle shadow-4"
                    />
                    <div class="ms-3">
                        <p class="fw-bold mb-1"><?=$facilitator['displayname']?></p>
                        <p class="text-muted mb-0"><?=$facilitator['title']?></p>
                    </div>
                </div>
            </td>
            <td>
                <ul class="list-group list-group-light list-group-small">
                <?php
                foreach ($facilitator['sessions'] as $session) {
                ?>
                <li class="list-group-item">
                    <a href="<?=$session->url?>"><?=$session->title?></a>
                </li>
                <?php
                }
                ?>
                </ul>
            </td>
            </tr>
            <?php
        }
        ?>
            </tbody>
        </table>
        <?php

        $ob_output = ob_get_contents();
        ob_end_clean();
        if ( $buffer ) return $ob_output;
        echo($ob_output);
    }

    public function footer($buffer=false)
    {
        global $CFG;
        ob_start();
        if ( isset($this->lessons->footers) && is_array($this->lessons->footers) ) {
            foreach($this->lessons->footers as $footer) {
                $footer = self::expandLink($footer);
                echo($footer);
                echo("\n");
            }
        }
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ( $buffer ) return $ob_output;
        echo($ob_output);

    } // end footer
}
