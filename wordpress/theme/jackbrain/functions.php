<?php
/**
 * @package JackBrain
 */
/**
 * Set the content width based on the theme's design and stylesheet.
 */
if (!isset($content_width))
    $content_width = 510; /* pixels */

// I dont want to see the generator tag in my shit
remove_action('wp_head', 'wp_generator');

if (!function_exists('jackbrain_setup')) :

    /**
     * Sets up theme defaults and registers support for various WordPress features.
     *
     * Note that this function is hooked into the after_setup_theme hook, which runs
     * before the init hook. The init hook is too late for some features, such as indicating
     * support post thumbnails.
     */
    function jackbrain_setup() {

        /**
         * Make theme available for translation
         * Translations can be filed in the /languages/ directory
         * If you're building a theme based on jackbrain, use a find and replace
         * to change 'jackbrain' to the name of your theme in all the template files
         */
        load_theme_textdomain('jackbrain', get_template_directory() . '/languages');

        /**
         * Add default posts and comments RSS feed links to head
         */
        add_theme_support('automatic-feed-links');

        /**
         * Enable support for Post Thumbnails on posts and pages
         *
         * @link http://codex.wordpress.org/Function_Reference/add_theme_support#Post_Thumbnails
         */
        add_theme_support('post-thumbnails');
        set_post_thumbnail_size(get_custom_header()->width, get_custom_header()->height, true);

        /**
         * This theme uses wp_nav_menu() in one location.
         */
        register_nav_menus(array(
            'primary' => __('Primary Navigation', 'jackbrain'),
        ));
    }

endif; // jackbrain_setup
add_action('after_setup_theme', 'jackbrain_setup');

/**
 * Setup the WordPress core custom background feature.
 *
 * Use add_theme_support to register support for WordPress 3.4+
 * as well as provide backward compatibility for previous versions.
 * Use feature detection of wp_get_theme() which was introduced
 * in WordPress 3.4.
 *
 * Hooks into the after_setup_theme action.
 */
function jackbrain_custom_background() {
    $args = array(
        'default-color' => '',
        'default-image' => '',
    );

    $args = apply_filters('jackbrain_custom_background_args', $args);

    if (function_exists('wp_get_theme')) {
        add_theme_support('custom-background', $args);
    } else {
        define('BACKGROUND_COLOR', $args['default-color']);
        define('BACKGROUND_IMAGE', $args['default-image']);
        add_custom_background();
    }
}

add_action('after_setup_theme', 'jackbrain_custom_background');

function jackbrain_widgets_init() {
    register_sidebars(2, array(
        'before_title' => '<h3 class="widgettitle">',
        'after_title' => '</h3>',
    ));

    unregister_widget('WP_Widget_Search');
    unregister_widget('WP_Widget_Links');
    unregister_widget('WP_Widget_Meta');

    wp_register_sidebar_widget('search', __('Search', 'jackbrain'), 'widget_jackbrain_search');
    wp_register_sidebar_widget('meta', __('Meta', 'jackbrain'), 'widget_jackbrain_meta');
    wp_register_sidebar_widget('links', __('Links', 'jackbrain'), 'widget_jackbrain_links');
    wp_register_sidebar_widget('home-link', __('Home Link', 'jackbrain'), 'widget_sandbox_homelink');
    wp_register_sidebar_widget('rss-links', __('RSS Links', 'jackbrain'), 'widget_sandbox_rsslinks');
}

add_action('widgets_init', 'jackbrain_widgets_init');

/**
 * Enqueue scripts and styles
 */
function jackbrain_page_has_contact_form() {
    global $post;

    return is_singular() && $post instanceof WP_Post
        && has_shortcode($post->post_content, 'contact-form-7');
}

function jackbrain_scripts() {
    // this themes CSS
    wp_enqueue_style('jackbrain', get_stylesheet_uri(), array(), null);
    if (jackbrain_page_has_contact_form()) {
        wp_add_inline_style(
            'jackbrain',
            '.grecaptcha-badge { visibility: hidden; } .recaptcha-attribution { clear: both; display: block; width: 100%; margin: 1em 0; font-size: 0.75em; text-align: center; }'
        );
    }

    // load the google fonts
    wp_enqueue_style('google-fonts', 'https://fonts.googleapis.com/css?family=Courgette|Chango|Jura:500', array(), null);

    if (is_singular() && comments_open() && get_option('thread_comments'))
        wp_enqueue_script('comment-reply');

    // the prettyprint JS
    //wp_enqueue_script(
    //        'prettyprint', 'https://google-code-prettify.googlecode.com/svn/loader/run_prettify.js', array(), null
    //);
    // the raphael code from here
    wp_enqueue_script(
            'extra_libs', get_template_directory_uri() . '/js/libs.js', array(), null
    );
    
    // site specific stuff - jbrain.js is now jQuery-free (plain DOM + WAAPI), see
    // js/jbrain.js; jQuery/jQueryUI CDN loads removed accordingly
    wp_enqueue_script(
            'jbrain', get_template_directory_uri() . '/js/jbrain.js', array('extra_libs'), null
    );
    if (jackbrain_page_has_contact_form()) {
        wp_add_inline_script(
            'jbrain',
            '(function(){function addRecaptchaAttribution(){if(!document.body||document.querySelector(".recaptcha-attribution"))return;const p=document.createElement("p");p.className="recaptcha-attribution";p.append("This site is protected by reCAPTCHA and the Google ");const privacy=document.createElement("a");privacy.href="https://policies.google.com/privacy";privacy.textContent="Privacy Policy";p.append(privacy," and ");const terms=document.createElement("a");terms.href="https://policies.google.com/terms";terms.textContent="Terms of Service";p.append(terms," apply.");(document.getElementById("footer")||document.getElementById("wrapper")||document.body).append(p)}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",addRecaptchaAttribution)}else{addRecaptchaAttribution()}})();',
            'after'
        );
    }

    // the rotating cube (about page only) - Segment 3 rewrite: plain ES modules,
    // bundled + minified for production via esbuild (JS/cuber/package.json's
    // `npm run build`; source with comments lives in JS/cuber/src/, never deployed
    // directly - see plans/cuber-modernization/README.md).
    if (is_page('about')) {
        $cuber_dir = get_template_directory() . '/js/cuber';
        $cuber_uri = get_template_directory_uri() . '/js/cuber';

        wp_enqueue_style(
            'cuber',
            $cuber_uri . '/style.css',
            array(),
            file_exists($cuber_dir . '/style.css') ? filemtime($cuber_dir . '/style.css') : null
        );

        // The logo sticker's background-image URL is resolved against the
        // DOCUMENT's base URI when set via JS (el.style.background = 'url(...)'),
        // not the script's own URL - so it can't be a relative path baked into
        // render.js, it has to be injected as an absolute URL from PHP.
        // wp_register_script(..., false, ...) registers a source-less handle for
        // an inline-only script (standard WP pattern).
        wp_register_script('cuber-config', false, array(), null, false);
        wp_enqueue_script('cuber-config');
        wp_add_inline_script(
            'cuber-config',
            'window.CUBER_LOGO_URL = ' . wp_json_encode(get_template_directory_uri() . '/images/jbrown.png') . ';'
        );

        // esbuild's bundle output is a plain self-executing script (IIFE), not an
        // ES module, so it no longer gets automatic module-script deferral - it
        // must explicitly defer (WP 6.3+ script-loading strategy) so it runs AFTER
        // #the-cube exists in the DOM, since it's placed in <head> (in_footer=true
        // silently prints nothing at all in this theme - a separate, already-found
        // bug - so head placement + explicit defer is used instead of footer
        // placement). Missing this caused a live `Cannot read properties of null
        // (reading 'querySelector')` on `document.getElementById('the-cube')`.
        wp_enqueue_script(
            'cuber-main',
            $cuber_uri . '/main.js',
            array(),
            file_exists($cuber_dir . '/main.js') ? filemtime($cuber_dir . '/main.js') : time(),
            array('strategy' => 'defer', 'in_footer' => false)
        );
    }

    
}

function jackbrain_disable_recaptcha_without_form() {
    if (!jackbrain_page_has_contact_form()) {
        wp_dequeue_script('google-recaptcha');
        wp_dequeue_script('wpcf7-recaptcha');
        wp_deregister_script('google-recaptcha');
        wp_deregister_script('wpcf7-recaptcha');
    }
}

function jackbrain_remove_recaptcha_enqueue_without_form() {
    if (!jackbrain_page_has_contact_form()) {
        remove_action('wp_enqueue_scripts', 'wpcf7_recaptcha_enqueue_scripts', 20);
    }
}

function jackbrain_filter_recaptcha_script($tag, $handle) {
    if (!jackbrain_page_has_contact_form()
        && in_array($handle, array('google-recaptcha', 'wpcf7-recaptcha'), true)) {
        return '';
    }

    return $tag;
}

function jackbrain_filter_recaptcha_script_handles($handles) {
    if (!jackbrain_page_has_contact_form()) {
        $handles = array_diff($handles, array('google-recaptcha', 'wpcf7-recaptcha'));
    }

    return $handles;
}

add_action('wp_enqueue_scripts', 'jackbrain_disable_recaptcha_without_form', 100);
add_action('wp_enqueue_scripts', 'jackbrain_remove_recaptcha_enqueue_without_form', 1);
add_action('wp_print_scripts', 'jackbrain_disable_recaptcha_without_form', 100);
add_action('wp_print_footer_scripts', 'jackbrain_disable_recaptcha_without_form', 100);
add_filter('wp_print_scripts_array', 'jackbrain_filter_recaptcha_script_handles', 100);
add_filter('script_loader_tag', 'jackbrain_filter_recaptcha_script', 10, 2);

add_action('wp_enqueue_scripts', 'jackbrain_scripts');

// Nav fallback
function jackbrain_globalnav() {
    ?>
    <div id="globalnav">
        <ul id="menu">
            <?php wp_list_pages('title_li=&sort_column=menu_order&depth=1'); ?>
        </ul>
    </div>
    <?php
}

function jackbrain_comment($comment, $args, $depth) {
    $GLOBALS['comment'] = $comment;
    extract($args, EXTR_SKIP);
    ?>
    <li id="comment-<?php comment_ID(); ?>" <?php comment_class(); ?>>
        <div id="div-comment-<?php comment_ID(); ?>">
            <ul class="comment-meta">
                <li class="comment-author vcard">
                    <div class="comment-avatar"><?php if ($args['avatar_size'] != 0) echo get_avatar($comment, $args['avatar_size']); ?></div>
                    <span class="fn"><?php comment_author_link(); ?></span></li>
                <?php
                printf(__('<li>Posted %1$s at %2$s</li> <li><a href="%3$s" title="Permalink to this comment">Permalink</a></li>', 'jackbrain'), get_comment_date(), get_comment_time(), '#comment-' . get_comment_ID());
                ?> <li><?php edit_comment_link(__('(Edit)', 'jackbrain'), ' ', ''); ?> <?php comment_reply_link(array_merge($args, array('add_below' => 'div-comment', 'depth' => $depth, 'max_depth' => $args['max_depth']))); ?></li>
            </ul>
            <div class="comment-content">
                <?php if ($comment->comment_approved == '0') : ?><span class="unapproved"><?php _e('Your comment is awaiting moderation.', 'jackbrain'); ?></span><?php endif; ?>
                <?php comment_text(); ?>
            </div>
        </div>
        <?php
    }

    function jackbrain_ping($comment, $args, $depth) {
        $GLOBALS['comment'] = $comment;
        extract($args, EXTR_SKIP);
        ?>
    <li id="comment-<?php comment_ID(); ?>" <?php comment_class(); ?>>
        <div id="div-comment-<?php comment_ID(); ?>">
            <div class="comment-meta">
                <?php
                printf(__('By %1$s on %2$s at %3$s', 'jackbrain'), get_comment_author_link(), get_comment_date('d M Y'), get_comment_time('g:i a'));
                ?>
                <?php edit_comment_link(__('(Edit)', 'jackbrain'), ' ', ''); ?>
            </div>
            <div class="trackback-content">
                <div class="comment-mod"><?php if ($comment->comment_approved == '0') _e('<em>Your trackback/pingback is awaiting moderation.</em>', 'jackbrain'); ?></div>
                <?php comment_text(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Filters wp_title to print a neat <title> tag based on what is being viewed.
     *
     * @since JackBrain 1.0
     */
    function jackbrain_wp_title($title, $sep) {
        global $page, $paged;

        if (is_feed())
            return $title;

        // Add the blog name
        $title .= get_bloginfo('name');

        // Add the blog description for the home/front page.
        $site_description = get_bloginfo('description', 'display');
        if ($site_description && ( is_home() || is_front_page() ))
            $title .= " $sep $site_description";

        // Add a page number if necessary:
        if ($paged >= 2 || $page >= 2)
            $title .= " $sep " . sprintf(__('Page %s', 'jackbrain'), max($paged, $page));

        return $title;
    }

    add_filter('wp_title', 'jackbrain_wp_title', 10, 2);

    // login page mod
    function my_login_stylesheet() {
        $css = '<link rel="stylesheet" id="custom_wp_admin_css"  href="' . get_bloginfo('stylesheet_directory') . '/admin.css" type="text/css" media="all" />';
        echo $css;
    }

    add_action('login_enqueue_scripts', 'my_login_stylesheet');

    // change admin login links
    function my_login_logo_url() {
        return get_bloginfo('http://jackson-brain.com');
    }

    add_filter('login_headerurl', 'my_login_logo_url');

    function my_login_logo_url_title() {
        return 'Jackson Fielding Brain';
    }

    add_filter('login_headertitle', 'my_login_logo_url_title');
    // end change links

    /**
     * Load custom widgets.
     */
    require_once( get_template_directory() . '/inc/widgets.php' );

    /**
     * Implement the Custom Header feature.
     */
    require( get_template_directory() . '/inc/custom-header.php' );

    // do not load the local jQuery
    wp_deregister_script('jquery');
    define('WPCF7_LOAD_JS', false);
    wp_deregister_script('contact-form-7');
    /**
     * Load Jetpack compatibility file. On second thought, fuck JetPack.
     */
//require( get_template_directory() . '/inc/jetpack.compat.php' );
// remove the auto <p> bullshit from posts, it fuxors code snippets
//remove_filter( 'the_content', 'wpautop' );
//remove_filter( 'the_excerpt', 'wpautop' );
