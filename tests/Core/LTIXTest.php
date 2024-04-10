<?php

require_once "src/Core/LTIX.php";
require_once "src/Util/PDOX.php";
require_once "src/Config/ConfigInfo.php";
require_once "tests/Mock/MockSession.php";

use \Tsugi\Core\LTIX;

class LTIXTest extends \PHPUnit\Framework\TestCase
{
    public function testSecretEncrypt() {
        global $CFG;
        $CFG = $CFG ?? new \Tsugi\Config\ConfigInfo("dir", "www");
        $CFG->cookiesecret = "hockey";
        $zap = LTIX::encrypt_secret("apereo");
        $this->assertTrue(strpos($zap,"AES::") === 0 );
        $zot = LTIX::decrypt_secret($zap);
        $this->assertEquals($zot,"apereo");
    }

    public function testCSRF() {
        $this->assertEquals(LTIX::ltiParameter('bob', 'sam'), 'sam');
    }

    // Mostly make sure this does not blow up with a traceback
    // The null code paths depends on the existence of the $_SESSION superglobal
    // Which probably is not there in a unit test
    public function testWrappedSessionNothing() {
        $sess = null;
        $this->assertEquals(LTIX::wrapped_session_get($sess,'x', 'sam'), 'sam');
        LTIX::wrapped_session_put($sess,'x', 'y');
        LTIX::wrapped_session_forget($sess,'x');
        LTIX::wrapped_session_put($sess,'a', 'b');
        LTIX::wrapped_session_put($sess,'a', 'c');
        LTIX::wrapped_session_flush($sess);
    }

    public function exercise($sess) {
        LTIX::wrapped_session_put($sess,'x', 'y');
        $this->assertEquals(LTIX::wrapped_session_get($sess,'x', 'sam'), 'y');
        $s = LTIX::wrapped_session_all($sess);
        $this->assertArrayHasKey('x',$s);
        $this->assertArrayNotHasKey('tsugi',$s);
        $s['x']=42;  // Make sure we have a copy and cannot change x
        $this->assertEquals(LTIX::wrapped_session_get($sess,'x', 'sam'), 'y');
        $s = LTIX::wrapped_session_all($sess);
        $this->assertArrayHasKey('x',$s);
        $this->assertArrayNotHasKey('tsugi',$s);
        LTIX::wrapped_session_forget($sess,'x');
        $this->assertEquals(LTIX::wrapped_session_get($sess,'x', 'sam'), 'sam');
        LTIX::wrapped_session_put($sess,'a', 'b');
        $this->assertEquals(LTIX::wrapped_session_get($sess,'a', 'sam'), 'b');
        LTIX::wrapped_session_put($sess,'a', 'c');
        $this->assertEquals(LTIX::wrapped_session_get($sess,'a', 'sam'), 'c');
        LTIX::wrapped_session_flush($sess);
        $this->assertEquals(LTIX::wrapped_session_get($sess,'a', 'sam'), 'sam');
        for($i=1; $i< 100; $i++) {
            LTIX::wrapped_session_put($sess, $i, $i*$i);
        }
        $this->assertEquals(LTIX::wrapped_session_get($sess, 10, 42), 100);
        LTIX::wrapped_session_flush($sess);
        $this->assertEquals(LTIX::wrapped_session_get($sess, 10, 42), 42);
    }

    public function testWrappedSessionArray() {
        $sess = array();
        $this->exercise($sess);
    }

    public function testWrappedSessionObject() {
        $sess = new MockSession();
        $this->exercise($sess);
    }

}
