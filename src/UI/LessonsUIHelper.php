<?php

namespace Tsugi\UI;

use Twig\Extra\CssInliner\CssInlinerExtension;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

class LessonsUIHelper
{
    protected static $_loader;
    protected static \Twig\Environment $_twig;

    public static function twig()
    {
        if (!isset($_loader) || !isset($_twig)) {
            self::$_loader = new \Twig\Loader\FilesystemLoader([
                __DIR__ . '/Templates',
                __DIR__ . '/Templates/pages',
            ]);
            self::$_twig = new \Twig\Environment(self::$_loader, [
                // 'cache' => __DIR__ . '/Templates/_cache',
            ]);
            self::$_twig->addExtension(new CssInlinerExtension());
        }
        return self::$_twig;
    }

    public static function renderMegaMenuOptions()
    {
        global $CFG;
        $R = $CFG->apphome . '/categories/';
        $twig = self::twig();
        $lessonsReference = LessonsOrchestrator::getLessonsReference();

        ob_start();
        echo $twig->render('nav-mega-menu.twig', [
            'courses' => (array)$lessonsReference,
            'root' => $R
        ]);
        return ob_get_clean();
    }

    public static function renderSessionCard($config)
    {
        global $CFG;
        $twig = self::twig();

        // Assign default BG image
        $config->genericImg = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png';

        echo $twig->render('session-card.html', (array)$config);
    }

    public static function debugLog($data)
    {
        echo "<script>console.debug('Debug Helper: ', " . json_encode($data) . ");</script>";
    }

    public static function errorLog(\Throwable $e)
    {
        echo "<script>console.error('Error Helper: ', " . json_encode(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]) . ");</script>";
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
        foreach ($adapter->course->modules as $module) {
            if (isset($module->carousel)) $carousel = true;
        }
        if ($carousel) {
        ?>
            <link rel="stylesheet" href="<?= $CFG->staticroot ?>/plugins/jquery.bxslider/jquery.bxslider.css" type="text/css" />
<?php
        }
        if (isset($adapter->course->headers) && is_array($adapter->course->headers)) {
            foreach ($adapter->course->headers as $header) {
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

    public static function renderGeneralFooter()
    {
        // ???
    }
}
