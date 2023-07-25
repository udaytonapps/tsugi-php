<?php

namespace Tsugi\UI;

use \Tsugi\Util\U;
use \Tsugi\Util\LTI;
use \Tsugi\Core\LTIX;
use \Tsugi\Crypt\AesOpenSSL;


class Facilitators
{

    /**
     * All the facilitators
     */
    public $all_facilitators = [];

    public $lessons;

    /**
     * emit the header material
     */
    public function header($buffer = false)
    {
        global $CFG;
        ob_start();
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
        $this->all_facilitators = LessonsOrchestrator::getAllFacilitatorsAndTheirModules();
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
        ob_start();

        if ($facilitatorId) {

            $facilitator = LessonsOrchestrator::getFacilitatorWithModulesById($facilitatorId);
            if ($facilitator && $facilitator['displayname']) {
                echo '</br><h1></h1></br>';
?>
                <div class="d-flex flex-column align-items-center gap-3">
                    <img src="<?= $facilitator['image_url'] ? $facilitator['image_url'] : "https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png" ?>" alt="<?= $facilitator['displayname'] ?>" style="object-fit: cover; object-position: 50% 30%; min-width: 150px; min-height: 150px; width: 150px; height: 150px;" class="rounded-circle shadow-4" />
                    <h1 class="fw-bold mb-1"><?= $facilitator['displayname'] ?></h1>
                    <p class="text-muted mb-5"><?= $facilitator['title'] ?></p>
                </div>
                <?php
                if (isset($facilitator['sessions'])) {
                ?>
                    <h3 class="mb-3">Courses and Topics</h3>
                    <ul class="list-group list-group-light list-group-small mb-5">
                        <?php
                        foreach ($facilitator['sessions'] as $session) {
                        ?>
                            <li class="list-group-item">
                                <a href="<?= str_replace('/facilitators', '', $session->url) ?>"><?= $session->title ?></a>
                            </li>
                        <?php
                        }
                        ?>
                    </ul>
                <?php } ?>
            <?php
            }
        } else {
            echo '</br><h1>Experts</h1></br>';
            ?>
            <table class="table align-middle mb-0 bg-white" aria-label="A list of every subject matter expert who facilitates a session or authored content." >
                <thead>
                    <tr>
                        <th scope="col">Name, Title, & Department</th>
                        <th scope="col">Sessions Facilitated</th>
                    </tr>
                </thead>    
                <tbody>
                    <?php
                    foreach ($this->all_facilitators as $facilitator) {
                    ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="<?= $facilitator['image_url'] ? $facilitator['image_url'] : "https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png" ?>" alt="<?= $facilitator['displayname'] ?>" style="object-fit: cover; object-position: 50% 30%; min-width: 70px; min-height: 70px; width: 70px; height: 70px;" class="rounded-circle shadow-4" />
                                    <div class="ms-3">
                                        <a href="./facilitators/<?= $facilitator['facilitator_id']; ?>" class="fw-bold mb-1"><?= $facilitator['displayname'] ?></a>
                                        <p class="text-muted mb-0"><?= $facilitator['title'] . ($facilitator['department'] ? ', ' . $facilitator['department'] : '') ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <ul class="list-group list-group-light list-group-small">
                                    <?php
                                    if (isset($facilitator['sessions'])) {
                                        $count = 0;
                                        foreach ($facilitator['sessions'] as $session) {
                                            $count++;
                                            if ($count <= 3) {
                                    ?>
                                                <li class="list-group-item">
                                                    <a href="<?= $session->url ?>"><?= $session->title ?></a>
                                                </li>
                                            <?php

                                            }
                                        }
                                        if (count($facilitator['sessions']) > 3) {
                                            ?>
                                            <a href="./facilitators/<?= $facilitator['facilitator_id']; ?>" class="fw-bold mb-1">See more...</a>
                                    <?php
                                        }
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
}
