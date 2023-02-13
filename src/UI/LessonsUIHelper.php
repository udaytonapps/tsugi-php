<?php

namespace Tsugi\UI;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

class LessonsUIHelper
{
    protected static $_loader;
    protected static $_twig;

    protected static function twig()
    {
        if (!isset($_loader) || !isset($_twig)) {
            self::$_loader = new \Twig\Loader\FilesystemLoader([
                __DIR__ . '/Templates',
            ]);
            self::$_twig = new \Twig\Environment(self::$_loader, [
                // 'cache' => __DIR__ . '/Templates/_cache',
            ]);
        }
        return self::$_twig;
    }

    public static function renderMegaMenuOptions()
    {
        global $CFG;
        $R = $CFG->apphome . '/sessions/';
        $twig = self::twig();
        $lessonsReference = LessonsOrchestrator::getLessonsReference();

        ob_start();
        echo $twig->render('nav-mega-menu.html', [
            'sessions' => (array)$lessonsReference,
            'root' => $R
        ]);
        return ob_get_clean();
    }

    public static function renderGeneralHeader($adapter, $buffer = false)
    {
        global $CFG;
        ob_start();
?>
        <style>
            <?php include 'Lessons.css'; ?>
        </style>
        <?php
        // See if there are any carousels in the lessons
        $carousel = false;
        foreach ($adapter->lessons->modules as $module) {
            if (isset($module->carousel)) $carousel = true;
        }
        if ($carousel) {
        ?>
            <link rel="stylesheet" href="<?= $CFG->staticroot ?>/plugins/jquery.bxslider/jquery.bxslider.css" type="text/css" />
<?php
        }
        if (isset($adapter->lessons->headers) && is_array($adapter->lessons->headers)) {
            foreach ($adapter->lessons->headers as $header) {
                $header = LessonsOrchestrator::expandLink($header);
                echo ($header);
                echo ("\n");
            }
        }
        $ob_output = ob_get_contents();
        ob_end_clean();
        if ($buffer) return $ob_output;
        echo ($ob_output);
    }

    public static function renderSessionCard($config)
    {
        global $CFG;
        $twig = self::twig();

        self::debugLog($config);

        // Assign default BG image
        $config->genericImg = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png';

        echo $twig->render('session-card.html', (array)$config);
    }

    private static function debugLog($data)
    {
        echo "<script>console.debug('Debug Objects: ', " . json_encode($data) . ");</script>";
    }
}
