<?php
/*
Plugin Name: WordPress fail2ban MU
Plugin URI: https://github.com/szepeviktor/wordpress-plugin-construction
Description: Reports 404s and various attacks in error.log for fail2ban. <strong>This is a Must Use plugin, must be copied to <code>wp-content/mu-plugins</code>.</strong>
Version: 2.8
License: The MIT License (MIT)
Author: Viktor Szépe
Author URI: http://www.online1.hu/webdesign/
*/

if ( ! function_exists( 'add_filter' ) ) {
    error_log( 'Malicious sign detected by WPf2b: wpf2b_direct_access '
        . addslashes( $_SERVER['REQUEST_URI'] )
    );
    ob_get_level() && ob_end_clean();
    header( 'Status: 403 Forbidden' );
    header( 'HTTP/1.0 403 Forbidden' );
    exit();
}

class O1_WP_Fail2ban_MU {

    //private $prefix = 'Malicious sign detected by WPf2b: ';
    private $prefix = 'File does not exist: ';
    private $wp_die_ajax_handler;
    private $wp_die_xmlrpc_handler;
    private $wp_die_handler;
    private $is_redirect = false;

    public function __construct() {

        // exit on local access
        // don't run on install / upgrade
        if ( php_sapi_name() === 'cli'
            || $_SERVER['REMOTE_ADDR'] === $_SERVER['SERVER_ADDR']
            || defined( 'WP_INSTALLING' ) && WP_INSTALLING
        )
            return;

        // prevent usage as a normal plugin in wp-content/plugins
        if ( did_action( 'muplugins_loaded' ) )
            $this->exit_with_instructions();

        // don't redirect to admin
        remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

        // logins
        add_action( 'wp_login_failed', array( $this, 'login_failed' ) );
        add_action( 'wp_login', array( $this, 'login' ) );
        add_action( 'wp_logout', array( $this, 'logout' ) );
        add_action( 'retrieve_password', array( $this, 'lostpass' ) );

        // non-existent URLs
        add_action( 'init', array( $this, 'url_hack' ) );
        add_filter( 'redirect_canonical', array( $this, 'redirect' ), 1, 2 );

        // on robot and human 404
        add_action( 'plugins_loaded', array( $this, 'robot_403' ), 0 );
        add_action( 'template_redirect', array( $this, 'wp_404' ) );

        // on non-empty wp_die messages
        add_filter( 'wp_die_ajax_handler', array( $this, 'wp_die_ajax' ), 1 );
        add_filter( 'wp_die_xmlrpc_handler', array( $this, 'wp_die_xmlrpc' ), 1 );
        add_filter( 'wp_die_handler', array( $this, 'wp_die' ), 1 );

        // ban spammers (Contact Form 7 Robot Trap)
        add_action( 'robottrap_hiddenfield', array( $this, 'wpcf7_spam' ) );
        add_action( 'robottrap_mx', array( $this, 'wpcf7_spam_mx' ) );

    }

    private function trigger( $slug, $message, $level = 'error', $prefix = '' ) {

        if ( empty( $prefix ) )
            $prefix = $this->prefix;

        // when error messages are sent to a file (aka. PHP error log)
        // IP address and referer are not logged
        $log_enabled = '1' === ini_get( 'log_errors' );
        $log_destination = ini_get( 'error_log' );

        // log_errors option does not disable logging
        //if ( ! $log_enabled || empty( $log_destination ) ) {
        if ( empty( $log_destination ) ) {
            // SAPI should add client data
            error_log( $prefix
                . $slug
                . $this->esc_log( $message )
            );

        } else {
            // add client data to log message
            if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
                $referer = $this->esc_log( $_SERVER['HTTP_REFERER'] );
            } else {
                $referer = false;
            }

            error_log( '[' . $level . '] '
                . '[client ' . @$_SERVER['REMOTE_ADDR'] . '] '
                . $prefix
                . $slug
                . $this->esc_log( $message )
                // space after "referer:" comes from esc_log()
                . ( $referer ? ', referer:' . $referer : '' )
            );
        }
    }

    public function wp_404() {

        if ( ! is_404() )
            return;

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $request_uri = $_SERVER['REQUEST_URI'];

        // don't show the 404 page for robots
        if ( ! is_user_logged_in() && $this->is_robot( $ua ) ) {

            ob_get_level() && ob_end_clean();
            $this->trigger( 'wpf2b_robot404', $request_uri, 'info' );
            header( 'Status: 404 Not Found' );
            header( 'HTTP/1.0 404 Not Found' );
            exit();
        }

        // humans
        $this->trigger( 'wpf2b_404', $request_uri, 'info' );
    }

    public function url_hack() {

        $request_uri = $_SERVER['REQUEST_URI'];

        if ( substr( $request_uri, 0, 2 ) === '//'
            || strstr( $request_uri, '../' ) !== false
            || strstr( $request_uri, '/..' ) !== false
        ) {
            // remember this to prevent double-logging in redirect()
            $this->is_redirect = true;
            $this->trigger( 'wpf2b_url_hack', $request_uri );
        }
    }

    public function redirect( $redirect_url, $requested_url ) {

        if ( false === $this->is_redirect )
            $this->trigger( 'wpf2b_redirect', $requested_url, 'notice' );

        return $redirect_url;
    }

    public function login_failed( $username ) {

        $this->trigger( 'wpf2b_login_failed', $username );
    }

    public function login( $username ) {

        trigger( 'logged in', $username, 'info', 'Wordpress auth: ' );
    }

    public function logout() {

        if ( is_user_logged_in() ) {
            $current_user = wp_get_current_user();
            $user = $current_user->user_login;
        } else {
            $user = '""';
        }

        trigger( 'logout', $user, 'info', 'Wordpress auth: ' );
    }

    public function lostpass( $username ) {

        if ( empty( $username ) ) {
            //FIXME higher score !!!
        }

        trigger( 'lost password', $username, 'warn', 'Wordpress auth: ' );
    }

    /**
     * Non-frontend requests from robots.
     */
    public function robot_403() {

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
        $admin_path = parse_url( admin_url(), PHP_URL_PATH );
        $wp_dirs = 'wp-admin|wp-includes|wp-content|' . basename( WP_CONTENT_DIR );
        $uploads = wp_upload_dir();

        if ( ! is_user_logged_in()
            // a robot or < IE8
            && $this->is_robot( $ua )

            // robots may only enter on the frontend (index.php)
            // trigger only in WP dirs: wp-admin, wp-includes, wp-content
            && 1 === preg_match( '/\/(' . $wp_dirs . ')\//i', $request_path )

            // exclude missing media files but not '.php'
            && ( false === strstr( $request_path, basename( $uploads['baseurl'] ) )
                || false !== stristr( $request_path, '.php' )
            )

            // exclude XML RPC (xmlrpc.php)
            && ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )

            // exclude trackback (wp-trackback.php)
            && 1 !== preg_match( '/\/wp-trackback\.php$/i', $request_path )
        ) {

            //FIXME wp-includes/ms-files.php:12
            ob_get_level() && ob_end_clean();
            $this->trigger( 'wpf2b_robot403', $request_path );
            header( 'Status: 403 Forbidden' );
            header( 'HTTP/1.0 403 Forbidden' );
            exit();
        }
    }

    public function wp_die_ajax( $arg ) {

        // remember the previous handler
        $this->wp_die_ajax_handler = $arg;

        return array( $this, 'wp_die_ajax_handler' );
    }

    public function wp_die_ajax_handler( $message, $title, $args ) {

        // wp-admin/includes/ajax-actions.php returns -1 of security breach
        if ( ! is_scalar( $message ) || (int) $message < 0 )
            $this->trigger( 'wpf2b_wpdie_ajax', $message );

        // call previous handler
        call_user_func( $this->wp_die_ajax_handler, $message, $title, $args );
    }

    public function wp_die_xmlrpc( $arg ) {

        // remember the previous handler
        $this->wp_die_xmlrpc_handler = $arg;

        return array( $this, 'wp_die_xmlrpc_handler' );
    }

    public function wp_die_xmlrpc_handler( $message, $title, $args ) {

        if ( ! empty( $message ) )
            $this->trigger( 'wpf2b_wpdie_xmlrpc', $message );

        // call previous handler
        call_user_func( $this->wp_die_xmlrpc_handler, $message, $title, $args );
    }

    public function wp_die( $arg ) {

        // remember the previous handler
        $this->wp_die_handler = $arg;

        return array( $this, 'wp_die_handler' );
    }

    public function wp_die_handler( $message, $title, $args ) {

        if ( ! empty( $message ) )
            $this->trigger( 'wpf2b_wpdie', $message );

        // call previous handler
        call_user_func( $this->wp_die_handler, $message, $title, $args );
    }

    public function wpcf7_spam( $text ) {

        $this->trigger( 'wpf2b_wpcf7_spam', $text );
    }

    public function wpcf7_spam_mx( $domain ) {

        $this->trigger( 'wpf2b_wpcf7_spam_mx', $domain, 'warn' );
    }

    private function is_robot( $ua ) {

        // test user agent string (robot = not modern browser)
        // based on: http://www.useragentstring.com/pages/Browserlist/
        return ( ( 'Mozilla/5.0' !== substr( $ua, 0, 11 ) )
            && ( 'Mozilla/4.0 (compatible; MSIE 8.0;' !== substr( $ua, 0, 34 ) )
            && ( 'Mozilla/4.0 (compatible; MSIE 7.0;' !== substr( $ua, 0, 34 ) )
            && ( 'Opera/9.80' !== substr( $ua, 0, 10 ) )
        );
    }

    private function esc_log( $string ) {

        $string = serialize( $string ) ;
        // trim long data
        $string = mb_substr( $string, 0, 200, 'utf-8' );
        // replace non-printables with "¿" - sprintf( '%c%c', 194, 191 )
        $string = preg_replace( '/[^\P{C}]+/u', "\xC2\xBF", $string );

        return ' (' . $string . ')';
    }

    private function exit_with_instructions() {

        $doc_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? $_SERVER['DOCUMENT_ROOT'] : ABSPATH;

        $iframe_msg = sprintf( '<p style="font:14px \'Open Sans\',sans-serif">
            <strong style="color:#DD3D36">ERROR:</strong> This is <em>not</em> a normal plugin,
            and it should not be activated as one.<br />
            Instead, <code style="font-family:Consolas,Monaco,monospace;background:rgba(0,0,0,0.07)">%s</code>
            must be copied to <code style="font-family:Consolas,Monaco,monospace;background:rgba(0,0,0,0.07)">%s</code></p>',

            str_replace( $doc_root, '', __FILE__ ),
            str_replace( $doc_root, '', trailingslashit( WPMU_PLUGIN_DIR ) ) . basename( __FILE__ )
        );
        exit( $iframe_msg );
    }

}

new O1_WP_Fail2ban_MU();

/*
- write test.sh
- append: http://plugins.svn.wordpress.org/block-bad-queries/trunk/block-bad-queries.php
- option to immediately ban on non-WP scripts (\.php$ \.aspx?$)
- update non-mu plugin
- new: invalid user/email during registration
- new: invalid user during lost password
- new: invalid "lost password" token
- (as wp-config.inc) robots&errors in /wp-comments-post.php
- log xmlrpc? add_action( 'xmlrpc_call', function( $call ) { if ( 'pingback.ping' == $call ) {} } );
- log proxy IP: HTTP_X_FORWARDED_FOR, HTTP_INCAP_CLIENT_IP, HTTP_CF_CONNECTING_IP (could be faked)
- scores system:
    double score for
        robots, humans ???
        human non-GET 404 (robots get 403)
    403 immediate ban
    rob/hum score-pair templates in <select>
    fake Googlebot, Referer: http://www.google.com ???

// registration errors: dirty way
add_filter( 'login_errors', function ($em) {
    error_log( 'em:' . $em );
    return $em;
}, 0 );
*/