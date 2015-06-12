<?php  
/* 
Plugin Name: Responsive Flickr Slideshow 
Plugin URI: http://wordpress.org/plugins/mobile-friendly-flickr-slideshow/
Description: Use the <code>[fshow]</code> shorttag with <code>username=</code>, <code>photosetid=</code>, and <code>thumburl=</code> parameters to display a mobile-friendly Flickr slideshow
Author: Robert Peake
Version: 2.1.4
Author URI: http://www.msia.org/ 
Text Domain: flickr_slideshow
Domain Path: /languages/
*/ 
if ( ! defined( 'WPINC' ) ) {
    die();
}
class FlickrSlideshow {

    private static $instance;
    private $slideshow_url;
    private $photos = array();
    private $flickr;
    private $users = array();

    public static function init() {
        if( !is_object(self::$instance) ) {
            self::$instance = new FlickrSlideshow;
            register_activation_hook( __FILE__, array(self::$instance, 'activate' ) );
            register_deactivation_hook( __FILE__, array(self::$instance, 'deactivate') );
        }
        return self::$instance;
    }

    public function __construct() {
        require_once 'classes/simple_flickr_photo_api.php';
        $this->flickr = new SimpleFlickrPhotoApi();
        if (!$uniqid = get_option('fshow_uniqid')) {
            $uniqid = uniqid();
            add_option('fshow_uniqid', $uniqid);
        }
        $this->slideshow_url = 'fshow_orbit_'.$uniqid;
        $this->users = get_transient('fshow_users_'.$uniqid) ? get_transient('fshow_users_'.$uniqid) : array();
        add_action( 'plugins_loaded', array($this, 'load_textdomain' ));
        add_action( 'admin_menu', array($this, 'register_menu_page' ));
        add_action( 'admin_init', array($this, 'register_settings' ));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('parse_request', array($this, 'url_handler' ));
        add_shortcode( 'fshow', array($this, 'fshow' ));
    }

    public function activate() {
        add_option('fshow_uniqid', uniqid());
    }

    public function deactivate() {
        delete_option('fshow_uniqid');
    }

    public function url_handler() {
        if (false !== strstr($_SERVER['REQUEST_URI'], $this->slideshow_url)) {
            $atts = shortcode_atts( array(
                        'username' => get_option('fshow_default_username'),
                        'user_id' => $this->get_user_id(get_option('fshow_default_username')),
                        'photosetid' => get_option('fshow_default_photosetid'),
                    ), $_GET );
            $this->load_photos($this->string_filter($atts['user_id']),$this->string_filter($atts['photosetid']),$this->string_filter($atts['username']));
            include 'orbit.php';
            exit;
        }
    }

    public function get_photos() {
        $photos = $this->photos;
        $photos = apply_filters('fshow_photos',$photos);
        return $photos;
    }

    public function get_gallery_url() {
        $gallery_url = $this->gallery_url;
        $gallery_url = apply_filters('fshow_gallery_url',$gallery_url);
        return $gallery_url;
    }

    private function load_photos($user_id, $photosetid, $username) {
        if (strlen($user_id) > 3) {
            $url_base = 'https://www.flickr.com/photos/'.$user_id;
            $this->gallery_url = $url_base .'/sets/'.$photosetid;
            $photos = $this->flickr->get_photos($photosetid, $user_id);
        } else {
            $url_base = 'https://www.flickr.com/photos/'.$username;
            $this->gallery_url = $url_base .'/sets/'.$photosetid;
            $photos = $this->flickr->get_photos($photosetid);
        }
        // https://farm{$photo->farm}.staticflickr.com/{$photo->server}/{$photo->id}_{$photo->secret}{$size_flag}.jpg
        $pattern = 'https://farm%s.staticflickr.com/%s/%s_%s%s.jpg';
        // /{$url_base}/{$photo->id}/in/album-{$photosetid}/
        $page_pattern = $url_base . '/%s/in/album-%s';
        $size_flag = apply_filters('fshow_size_flag', '_b'); //default 1024px on one side
        if ($photos) {
            foreach ($photos as $photo) {
                $this->photos[] = array('url' => sprintf($pattern, $photo->farm, $photo->server, $photo->id, $photo->secret, $size_flag ),
                                        'page_url' => sprintf($page_pattern, $photo->id, $photosetid),
                                        'title' => $photo->title);
            }
        }
    }

    private function get_url($user_id,$photosetid,$username) {
        $query = http_build_query( array( 
                                 'username' => $username,
                                 'user_id' => $user_id,
                                 'photosetid' => $photosetid) );
        return get_site_url().'/'.$this->slideshow_url.'?'.$query;
    }

    public function fshow( $atts ) {
        if (strlen(get_option('fshow_flickr_api_key')) == 0) {
            return $this->fshow_legacy($atts);
        }
        $atts = shortcode_atts( array(
                    'username' => get_option('fshow_default_username'),
                    'photosetid' => get_option('fshow_default_photosetid'),
                    'thumburl' => get_option('fshow_default_thumburl'),
                    'width' => get_option('fshow_default_width'),
                    'height' => get_option('fshow_default_height'),
                ), $atts, 'fshow' );
        if (strlen($atts['width']) == 0) {
            $atts['width'] = 640;
        }
        if (strlen($atts['height']) == 0) {
            $atts['height'] = 400;
        }
        $user_id = $this->get_user_id($atts['username']);
        $galleryURL = 'http://www.flickr.com/photos/'.$atts['username'].'/sets/'.$atts['photosetid'].'/';
        $simpleLink = sprintf('<a href="http://www.flickr.com/photos/%s/sets/%s/" target="_blank">'.__('Click to View','flickr_slideshow').'</a>',$atts['username'], $atts['photosetid'], $atts['thumburl']);
        $return = sprintf('<div style="max-width: %spx; height: %spx" class="fshow-wrapper">',$atts['width'],$atts['height'])."\n";
        $return .= '<iframe src="'.$this->get_url($user_id,$atts['photosetid'],$atts['username']).'" style="width: 100%; height: '.$atts['height'].'px" allowfullscreen="true" webkitallowfullscreen="true" mozallowfullscreen="true" border="0">'."\n";
        $return .= '</iframe>'."\n";
        $return .= '<noframes>'."\n";
        $return .= $simpleLink;
        $return .= '</noframes>'."\n";
        $return .= '</div>'."\n";
        return $return;
    }

    function fshow_legacy( $atts ) {
            extract( shortcode_atts( array(
                        'username' => get_option('fshow_default_username'),
                        'photosetid' => get_option('fshow_default_photosetid'),
                        'thumburl' => get_option('fshow_default_thumburl'),
                    ), $atts, 'fshow' ) );
            $galleryURL = 'http://www.flickr.com/photos/'.$username.'/sets/'.$photosetid.'/';
            $slideshowURL = $galleryURL . 'show/';
            $slideshow = sprintf('<a href="%s" target="_blank" />&#9658; '.__('Play Slideshow','flickr_slideshow').'</a>', $slideshowURL);
            $gallery = sprintf('<a href="%s" target="_blank"><span class="galleryIcon">&#9633;&#9633;</span> '.__('View Gallery and Share','flickr_slideshow').'</a>', $galleryURL);
            $shareCode = $slideshow.' '.$gallery;
            $objectCode = '<div id="'.$photosetid.'" class="slideshowWrapper"><object width="100%" height="480"> <param name="flashvars" value="offsite=true&lang=en-us&page_show_url=%2Fphotos%2F'.$username.'%2Fsets%2F'.$photosetid.'%2Fshow%2F&page_show_back_url=%2Fphotos%2F'.$username.'%2Fsets%2F'.$photosetid.'%2F&set_id='.$photosetid.'&jump_to="></param> <param name="movie" value="http://www.flickr.com/apps/slideshow/show.swf?v=109615"></param> <param name="allowFullScreen" value="true"></param><embed type="application/x-shockwave-flash" src="http://www.flickr.com/apps/slideshow/show.swf?v=109615" allowFullScreen="true" flashvars="offsite=true&lang=en-us&page_show_url=%2Fphotos%2F'.$username.'%2Fsets%2F'.$photosetid.'%2Fshow%2F&page_show_back_url=%2Fphotos%2F'.$username.'%2Fsets%2F'.$photosetid.'%2F&set_id='.$photosetid.'&jump_to=" width="100%" height="480"></embed></object><div class="shareLinks" style="width: 100%">'.$gallery.'</div></div>';
            $simpleLink = sprintf('<a href="http://www.flickr.com/photos/%s/sets/%s/show/" class="mobileSlideshowSimpleLink" target="_blank">'.__('Click to Play','flickr_slideshow').'</a>',$username, $photosetid, $thumburl);
            $return = '<script src="'.plugins_url('mobile-friendly-flickr-slideshow/js/swfobject.js', dirname(__FILE__)).'" type="text/javascript"></script>'."\n";
            $return .= '<script type="text/javascript">'."\n";
            $return .= 'if(swfobject.hasFlashPlayerVersion("1")) {'."\n";
            $return .= '    document.write(\'';
            $return .= $objectCode;
            $return .= '\');'."\n";
            $return .= '} else {'."\n";
            $return .= '    document.write(\'';
            $return .= sprintf('<div class="slideshowWrapper"><a href="http://www.flickr.com/photos/%s/sets/%s/show/" class="mobileSlideshowLink" target="_blank"><div width="100%%" height="400" class="mobileSlideshow" style="background: url('."\'".'%s'."\'".') #000 no-repeat; background-size: 100%%"><br/><span class="circle"><span class="play">&#9658;</span></span></div></a><p class="bottomText">'.$simpleLink.'</p></div>', $username, $photosetid, $thumburl, $shareCode);
            $return .= '\');'."\n";
            $return .= '}'."\n";
            $return .= '</script>'."\n";
            $return .= '<noscript>'."\n";
            $return .= $simpleLink;
            $return .= '</noscript>'."\n";
            return $return;
    }

    public function enqueue_scripts() {
        if (strlen(get_option('fshow_flickr_api_key')) == 0) {
            wp_register_style('fshow_css', plugins_url('mobile-friendly-flickr-slideshow/css/legacy_style.css', dirname(__FILE__)));
            wp_enqueue_style('fshow_css');
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'flickr_slideshow', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    public function register_menu_page(){
        add_options_page( __('Flickr Slideshow Options','flickr_slideshow'), __('Flickr Slideshow','flickr_slideshow'), 'manage_options', plugin_dir_path(  __FILE__ ).'admin.php');
    }

    public function register_settings() {
        add_option('fshow_flickr_api_key','');
        add_option('fshow_default_username', 'photomatt');
        add_option('fshow_default_user_id', '44124424984@N01');
        add_option('fshow_default_photosetid', '72157600645614333');
        add_option('fshow_default_thumburl', includes_url('images/wlw/wp-watermark.png'));
        add_option('fshow_default_width', '640');
        add_option('fshow_default_height', '400');
        add_option('fshow_performance_mode', 1);
        register_setting( 'flickr_slideshow', 'fshow_flickr_api_key', array($this, 'string_filter' ));
        register_setting( 'flickr_slideshow', 'fshow_default_username', array($this,'string_filter' )); 
        register_setting( 'flickr_slideshow', 'fshow_default_user_id', array($this,'string_filter' )); 
        register_setting( 'flickr_slideshow', 'fshow_default_photosetid', 'intval' ); 
        register_setting( 'flickr_slideshow', 'fshow_default_thumburl', array($this,'url_filter' )); 
        register_setting( 'flickr_slideshow', 'fshow_default_width', 'intval' ); 
        register_setting( 'flickr_slideshow', 'fshow_default_height', 'intval' ); 
        register_setting( 'flickr_slideshow', 'fshow_performance_mode', 'intval' ); 
    }

    public function string_filter( $string ) {
        return filter_var($string, FILTER_SANITIZE_STRING);
    }

    public function url_filter( $url ) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }

    private function get_user_id($username) {
        if (isset($this->users[$username])) {
            return $this->users[$username];
        } else {
            $user_id = $this->flickr->get_user_id_from_username($username);
            if(strlen($user_id) > 0) {
                $this->users[$username] = $user_id;
                $this->save_users();
                return $user_id;
            } else {
                return false;
            }
        }
    }

    private function save_users() {
        $uniqid = get_option('fshow_uniqid');
        set_transient('fshow_users_'.$uniqid, $this->users);
    }

}
$flickr_slideshow = FlickrSlideshow::init();
