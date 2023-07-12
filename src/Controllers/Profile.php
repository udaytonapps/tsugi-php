<?php

namespace Tsugi\Controllers;

use Laravel\Lumen\Routing\Controller;
use Tsugi\Lumen\Application;
use Symfony\Component\HttpFoundation\Request;
use Tsugi\Blob\BlobUtil;
use Tsugi\Core\LTIX;
use Tsugi\UI\LessonsOrchestrator;
use Tsugi\Util\U;

class Profile extends Controller {

    const ROUTE = '/profile';
    const REDIRECT = 'tsugi_controllers_profile';
    // Just a toggle for now to hide form that isn't very useful on LEARN yet
    const SHOW_FORM = false;

    public static function routes(Application $app, $prefix=self::ROUTE) {
        $app->router->get($prefix, function(Request $request) use ($app) {
            return Profile::getProfile($app);
        });
        $app->router->get('/'.self::REDIRECT, function(Request $request) use ($app) {
            return Profile::getProfile($app);
        });
        $app->router->get($prefix.'/', function(Request $request) use ($app) {
            return Profile::getProfile($app);
        });
        $app->router->post($prefix, function(Request $request) use ($app) {
            return Profile::postProfile($app);
        });
        $app->router->post($prefix.'/', function(Request $request) use ($app) {
            return Profile::postProfile($app);
        });
    }
    public static function getProfile(Application $app)
    {

        global $CFG, $PDOX, $OUTPUT;
        $home = isset($CFG->apphome) ? $CFG->apphome : $CFG->wwwroot;

        if ( ! isset($_SESSION['profile_id']) ) {
            return redirect($home);
        }
        $stmt = $PDOX->queryDie(
                "SELECT json FROM {$CFG->dbprefix}profile WHERE profile_id = :PID",
                array('PID' => $_SESSION['profile_id'])
                );
        $profile_row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ( $profile_row === false ) {
            $app->tsugiFlashError(__('Unable to load profile'));
            return redirect($home);
        }

        $profile = json_decode($profile_row['json']);
        if ( ! is_object($profile) ) $profile = new \stdClass();

        $themeId = 0;
        // Load data from the profile, if it exists
        if (isset($profile->theme_override)) {
            if ($profile->theme_override == 'light') {
                $themeId = 1;
            } else if ($profile->theme_override == 'dark') {
                $themeId = 2;
            }
        }
        $subscribe = isset($profile->subscribe) ? $profile->subscribe+0 : false;
        $map = isset($profile->map) ? $profile->map+0 : false;
        $lat = isset($profile->lat) ? $profile->lat+0.0 : 0.0;
        $lng = isset($profile->lng) ? $profile->lng+0.0 : 0.0;

        $defaultLat = $lat != 0.0 ? $lat : 42.279070216140425;
        $defaultLng = $lng != 0.0 ? $lng : -83.73981015789798;

        $context = array();
        $context['defaultLat'] = $defaultLat;
        $context['defaultLng'] = $defaultLng;

        $OUTPUT->header();
        $OUTPUT->bodyStart();
        $OUTPUT->topNav();

        if ( isset($CFG->google_map_api_key) && ! $CFG->OFFLINE ) { ?>
                <script src="https://maps.googleapis.com/maps/api/js?v=3.exp&key=<?= $CFG->google_map_api_key ?>"></script>
                <script type="text/javascript">
                var map;

            function initialize() {
                var myLatlng = new google.maps.LatLng(<?php echo($defaultLat.", ".$defaultLng); ?>);

                var myOptions = {
zoom: 2,
      center: myLatlng,
      mapTypeId: google.maps.MapTypeId.ROADMAP
                }
                map = new google.maps.Map(document.getElementById("map_canvas"), myOptions);

                var marker = new google.maps.Marker({
draggable: true,
position: myLatlng,
map: map,
title: "Your location"
});

google.maps.event.addListener(marker, 'dragend', function (event) {
        document.getElementById("latbox").value = this.getPosition().lat();
        document.getElementById("lngbox").value = this.getPosition().lng();
        });

}
</script>
<?php } else { ?>
    <script type="text/javascript">
        var map;

    function initialize() { }

    </script>
    <?php } ?>

    <div class="container">
    <?php
        $person = LessonsOrchestrator::getFacilitatorByEmail($_SESSION['email']);

        if ($person && isset($person['displayname'])) {
            echo "</br><h1></h1></br>";
        ?>
            <div class="d-flex flex-column align-items-center gap-3">
                <div style="position: relative;">
                    <?php
                    if (isset($person['title']) || isset($person['department'])) {
                    ?>
                    <button style="position: absolute; right: -60px;" type="button" class="btn btn-primary btn-floating" data-mdb-toggle="modal" data-mdb-target="#profile-modal">
                        <i class="fas fa-pen"></i>
                    </button>
                    <?php
                    }
                    ?>
                    <img src="<?= isset($person['image_url']) ? $person['image_url'] : "https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png" ?>" alt="<?= $person['displayname'] ?>" style="object-fit: cover; object-position: 50% 30%; min-width: 150px; min-height: 150px; width: 150px; height: 150px;" class="rounded-circle shadow-4" />
                </div>
                <h1 class="fw-bold mb-0"><?= $_SESSION['displayname'] ?? '' ?></h1>
                <?php
                    if (isset($person['title'])) {
                        ?>
                        <div class="d-flex align-items-center">
                            <h5 class="text-muted m-0"><?= $person['title'] ?? '' ?></h5>
                        </div>
                        <?php
                    }
                    if (isset($person['department'])) {
                        ?>
                        <div class="d-flex align-items-center">
                            <h5 class="text-muted m-0"><?= $person['department'] ?? '' ?></h5>
                        </div>
                        <?php
                    }
                ?>
                
                <span class="mb-5"></span>
            </div>
            <div class="modal fade" id="profile-modal" tabindex="-1" aria-labelledby="profile-modal" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="profile-modal">Edit Profile</h5>
                        <button type="button" class="btn-close" data-mdb-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body d-flex flex-column gap-4 p-5">
                        <div>
                            <div class="d-flex justify-content-center pb-3">
                                <img id="profile-preview" src="<?= isset($person['image_url']) ? $person['image_url'] : "https://upload.wikimedia.org/wikipedia/commons/7/7c/Profile_avatar_placeholder_large.png" ?>" alt="<?= $person['displayname'] ?>" style="object-fit: cover; object-position: 50% 30%; min-width: 100px; min-height: 100px; width: 100px; height: 100px;" class="rounded-circle shadow-4" />
                            </div>
                            <label class="form-label" for="profile-image-file">Profile Image</label>
                            <input type="file" class="form-control" id="profile-image-file" />
                        </div>
                        <!-- <div>
                            <label for="profile-name">Name</label>
                            <input type="text" id="profile-name" class="form-control" value="<?= $person['displayname'] ?? '' ?>" />
                        </div> -->
                        <div>
                            <label for="profile-title">Title</label>
                            <input type="text" id="profile-title" class="form-control" value="<?= $person['title'] ?? '' ?>" />
                        </div>
                        <div>
                            <label for="profile-department">Department</label>
                            <input type="text" id="profile-department" class="form-control" value="<?= $person['department'] ?? '' ?>" />
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-mdb-dismiss="modal">Cancel</button>
                        <button id="profile-modal-save" type="button" class="btn btn-primary">Save</button>
                    </div>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>


        <form method="POST" style="display: <?= self::SHOW_FORM ? 'block' : 'none'; ?>">
            <div style="display: flex; justify-content: space-between;">
                <div class="control-group">
                    <div class="controls">
                        <p>Would you like to set a theme override?</p>
                        <em>Overrides will only show if theme information is set in the launch data (such as from an LMS) or configured.</em>
                        <label class="radio">
                            <?php self::radio('theme',0,$themeId); ?> >
                            Use the default configuration
                        </label>
                        <label class="radio">
                            <?php self::radio('theme',1,$themeId); ?> >
                            Use light theme
                        </label>
                        <label class="radio">
                            <?php self::radio('theme',2,$themeId); ?> >
                            Use dark theme
                        </label>
                    </div>
                </div>
                <div class="control-group pull-right" style="margin-top: 20px">
                    <button type="submit" class="btn btn-primary visible-phone">Save</button>
                    <input class="btn btn-warning" type="button" onclick="location.href='<?= $CFG->apphome ?>/index.php'; return false;" value="Cancel"/>
                </div>
            </div>
            <hr class="hidden-phone"/>
            <div style="display: flex; justify-content: space-between;">
                <div class="control-group">
                    <div class="controls">
                        How much mail would you like us to send?
                        <label class="radio">
                            <?php self::radio('subscribe',-1,$subscribe); ?> >
                            No mail will be sent.
                        </label>
                        <label class="radio">
                            <?php self::radio('subscribe',0,$subscribe); ?> >
                            Keep the mail level as low as possible.
                        </label>
                        <label class="radio">
                            <?php self::radio('subscribe',1,$subscribe); ?> >
                            Send only announcements.
                        </label>
                        <label class="radio">
                            <?php self::radio('subscribe',2,$subscribe); ?> >
                            Send me notification mail for important things like my assignment was graded.
                        </label>
                    </div>
                </div>
                <div class="control-group pull-right" style="margin-top: 20px">
                    <button type="submit" class="btn btn-primary visible-phone">Save</button>
                    <input class="btn btn-warning" type="button" onclick="location.href='<?= $CFG->apphome ?>/index.php'; return false;" value="Cancel"/>
                </div>
            </div>
<?php if ( isset($CFG->google_map_api_key) && ! $CFG->OFFLINE ) { ?>
    <hr class="hidden-phone"/>
        How would you like to be shown in maps.<br/>
        <select name="map">
        <option value="0">--- Please Select ---</option>
        <option <?php self::option(1,$map); ?>>Don't show me at all</option>
        <option <?php self::option(2,$map); ?>>Show only my location with no identifying information</option>
        <option <?php self::option(3,$map); ?>>Show my name (<?php echo($_SESSION['displayname']); ?>)</option>
        </select>
        <p>
        Move the pointer on the map below until it is at the correct location.
        If you are concerned about privacy, simply put the
        location somewhere <i>near</i> where you live.  Perhaps in the same country, state, or city
        instead of your exact location.
        </p>
        <div class="control-group pull-right hidden-phone">
        <button type="submit" style="margin-top: 40px" class="btn btn-primary">Save Profile Data</button>
        </div>

        <div id="map_canvas" style="margin: 10px; width:400px; max-width: 100%; height:400px"></div>

        <div id="latlong" style="display:none" class="control-group">
        <p>Latitude: <input size="30" type="text" id="latbox" name="lat" class="disabled"
        <?php echo(' value="'.htmlent_utf8($lat).'" '); ?>
        ></p>
        <p>Longitude: <input size="30" type="text" id="lngbox" name="lng" class="disabled"
        <?php echo(' value="'.htmlent_utf8($lng).'" '); ?>
        ></p>
        </div>

        <p>
        If you don't even want to reveal your country, put yourself
        in Greenland in the middle of a glacier. One person put their location
        in the middle of a bar.  :)
        </p>
        <?php } ?>
        </form>
        </div>
        <?php

        // After jquery gets loaded at the *very* end...
        $OUTPUT->footerStart();
        ?>
        <script type="text/javascript">
        $(document).ready(function() {
            initialize();

            // Profile Edit Modal
            $('#profile-image-file').on('change', function() {
                var file = this.files[0];
                var reader = new FileReader();

                reader.onload = function(e) {
                    $('#profile-preview').attr('src', e.target.result);
                    $('#profile-preview').show();
                }

                reader.readAsDataURL(file);
            });

            // Save button click event
            $('#profile-modal-save').on('click', function() {
                var profileImage = $('#profile-image-file').val();
                // var name = $('#profile-name').val();
                var title = $('#profile-title').val();
                var department = $('#profile-department').val();

                var fileInput = $('#profile-image-file')[0];
                var file = fileInput.files[0];
                var formData = new FormData();

                formData.append('profileImage', file);
                // formData.append('name', name);
                formData.append('title', title);
                formData.append('department', department);

                // Perform AJAX request to send the form data to the server
                $.ajax({
                    url: '<?= U::addSession($CFG->apphome."/profile") ?>',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        location.reload();
                    },
                    error: function(xhr, status, error) {
                        // Handle the error
                        console.error(error);
                    }
                });
            });
        });
        </script>
        <?php
        $OUTPUT->footerEnd();
        return "";
    }

    public static function radio($var, $num, $val) {
        $ret =  '<input type="radio" name="'.$var.'" id="'.$var.$num.'" value="'.$num.'" ';
        if ( $num == $val ) $ret .= ' checked ';
        echo($ret);
    }

    public static function option($num, $val) {
        echo(' value="'.$num.'" ');
        if ( $num == $val ) echo(' selected ');
    }

    public static function checkbox($var, $num, $initialVal) {
        $ret = '<input type="checkbox" name="'.$var.'" id="'.$var.$num.'" value="'.$num.'" ';
        if ( $num == $initialVal ) $ret .= ' checked ';
        echo($ret);
    }

    public static function postProfile(Application $app)
    {
        global $CFG, $PDOX;
        $p = $CFG->dbprefix;

        $home = isset($CFG->apphome) ? $CFG->apphome : $CFG->wwwroot;

        if ( ! isset($_SESSION['profile_id']) ) {
            return redirect($home);
        }

        // Only update if default Koseu form isn't hidden
        if (self::SHOW_FORM) {
            $stmt = $PDOX->queryDie(
                "SELECT json FROM {$CFG->dbprefix}profile WHERE profile_id = :PID",
                array('PID' => $_SESSION['profile_id'])
                );
            $profile_row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ( $profile_row === false ) {
                $app->tsugiFlashError(__('Unable to load profile'));
                return redirect($home);
            }
    
            $profile = json_decode($profile_row['json']);
            if ( ! is_object($profile) ) $profile = new \stdClass();
    
            $profile->subscribe = $_POST['subscribe']+0 ;
    
            if ($_POST['theme'] == 1) {
                $profile->theme_override = 'light';
            } else if ($_POST['theme'] == 2) {
                $profile->theme_override = 'dark';
            } else {
                $profile->theme_override = null;
            }
            
            if ( isset($_POST['map']) ) {
                $profile->map = $_POST['map']+0 ;
                $profile->lat = $_POST['lat']+0.0 ;
                $profile->lng = $_POST['lng']+0.0 ;
            }
            $new_json = json_encode($profile);
            $stmt = $PDOX->queryDie(
                    "UPDATE {$p}profile SET json= :JSON
                    WHERE profile_id = :PID",
                    array('JSON' => $new_json, 'PID' => $_SESSION['profile_id'])
                    );
        }
        if (isset($_POST['title']) && isset($_POST['department'])) {
            if (isset($_FILES['profileImage']) && $_FILES['profileImage']['error'] === UPLOAD_ERR_OK) {

                // Sanity-check the file
                $safety = BlobUtil::validateUpload($_FILES['profileImage']);
                if ($safety !== true) {
                    $_SESSION['error'] = "Error: " . $safety;
                    error_log("Upload Error: " . $safety);
                    // header('Location: ' . U::addSession($CFG->apphome."/profile"));
                    return;
                }

                $blob_id = BlobUtil::uploadToBlob($_FILES['profileImage']);
                if ($blob_id === false) {
                    $_SESSION['error'] = 'Problem storing file in server';
                    echo('Problem');
                    // header('Location: ' . U::addSession($CFG->apphome."/profile"));
                    return;
                }

                // Get the previous blob_id (if it exists)
                $query = "SELECT * FROM {$p}learn_facilitator
                WHERE email = :email";
                $arr = array(':email' => $_SESSION['email']);
                $row = $PDOX->rowDie($query, $arr);
                
                // If it exists, delete previous blob image from blob_blob and blob_file
                if ($row && isset($row['image_blob_id'])) {
                    BlobUtil::deleteBlob($row['image_blob_id']);
                }

                // Add the new blob_id
                $query = "UPDATE {$p}learn_facilitator
                SET image_blob_id = :blob_id
                WHERE email = :email";
                $arr = array(':blob_id' => $blob_id, ':email' => $_SESSION['email']);
                $PDOX->queryDie($query, $arr);
            }

            $query = "UPDATE {$p}learn_facilitator
            SET title = :title, department = :department
            WHERE email = :email";
            $arr = array(':title' => $_POST['title'], ':department' => $_POST['department'], ':email' => $_SESSION['email']);
            $PDOX->queryDie($query, $arr);
        }
    }
}

