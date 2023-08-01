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
        global $CFG;
        if (!isset($_loader) || !isset($_twig)) {
            self::$_loader = new \Twig\Loader\FilesystemLoader([
                __DIR__ . '/Templates',
                __DIR__ . '/Templates/pages',
            ]);
            // Not using the cache for now - not working in deployed env
            // Don't use the twig cache locally
            // if (isset($CFG->local_dev_server) && $CFG->local_dev_server) {
                self::$_twig = new \Twig\Environment(self::$_loader);
            // } else {
            //     self::$_twig = new \Twig\Environment(self::$_loader, [
            //         'cache' => __DIR__ . '/Templates/_cache',
            //     ]);
            // }
            self::$_twig->addExtension(new CssInlinerExtension());
        }
        return self::$_twig;
    }

    public static function renderMegaMenuOptions()
    {
        global $CFG;
        $R = $CFG->apphome . '/programs/';
        $twig = self::twig();
        $lessonsReference = LessonsOrchestrator::getLessonsReference();

        ob_start();
        echo $twig->render('nav-mega-menu.twig', [
            'programs' => (array)$lessonsReference,
            'root' => $R
        ]);
        return ob_get_clean();
    }

    public static function renderQRCodeLink()
    {
        global $CFG;
        ob_start();
        echo('<li><a href="javascript:void;" class="nav-link" data-mdb-toggle="modal" data-mdb-target="#qrmodal"><i class="fa fa-qrcode" aria-hidden="true"></i> QR Code</a></li>');
        return ob_get_clean();
    }

    public static function renderSessionCard($cardConfig)
    {
        global $CFG;
        $twig = self::twig();

        // Assign default BG image
        $cardConfig->genericImg = $CFG->wwwroot . '/vendor/tsugi/lib/src/UI/assets/general_session.png';

        echo $twig->render('session-card.html', (array)$cardConfig);
    }

    public static function debugLog($data)
    {
        echo "<script>console.debug('Debug Helper: ', " . json_encode($data) . ");</script>";
    }

    public static function errorLog(\Throwable $e)
    {
        echo "<script>console.error('Error Helper: ', " . json_encode(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]) . ");</script>";
    }

    public static function renderGeneralHeader($adapter = null, $buffer = false)
    {
        global $CFG;
        ob_start(); ?>
        <style>
            <?php include 'Lessons.css'; ?>
        </style>
        <?php
        if ($adapter) {
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
    }

    public static function renderGeneralFooter()
    {
        // ???
    }
}
