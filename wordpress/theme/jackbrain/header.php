<?php
/**
 * @package JackBrain
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="google-site-verification" content="C4705HFnPrJ6vMMcpEPOgOrp68m6Z2ChDX8qLPIK5xQ" />
        <link rel="pingback" href="<?php bloginfo('pingback_url'); ?>" />
        <?php wp_head(); ?>

        <script type="text/javascript">

            var _gaq = _gaq || [];
            _gaq.push(['_setAccount', 'UA-42675490-1']);
            _gaq.push(['_trackPageview']);

            (function() {
                var ga = document.createElement('script');
                ga.type = 'text/javascript';
                ga.async = true;
                ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
                var s = document.getElementsByTagName('script')[0];
                s.parentNode.insertBefore(ga, s);
            })();

        </script>
        <?php if (function_exists('jackbrain_page_has_contact_form') && jackbrain_page_has_contact_form()) : ?>
            <script src="https://www.google.com/recaptcha/api.js?render=6LdF2OgZAAAAABPmLmp6wUqtq7hvWLQqKl23moOL"></script>
        <?php endif; ?>
    </head>

    <body <?php body_class(); ?>>
        <?php wp_body_open(); ?>

        <div id="wrapper">
            <div id="header">
                <div id="innerheader">
                    <h1 id="blog-title"><a href="<?php echo home_url('/'); ?>" title="<?php bloginfo('name'); ?>"><?php bloginfo('name'); ?></a></h1>
                    <div id="blog-description"><?php bloginfo('description'); ?></div>

                    <?php if (is_page( 'about-OFF' )): ?>
                        <input type="hidden" id="the-trigger" value="the-clock" />
                        <div id="the-calendar">
                            <span id="h"></span>:<span id="m"></span>:<span id="s"></span> <span id="ampm"></span> | <span id="mnth"></span>/<span id="d"></span>
                        </div>
                    <?php endif; ?>

                    <?php if ( is_page( 'home' ) ): ?><input type="hidden" id="the-trigger" value="the-title" /><div id="the-title"></div><?php endif; ?>

                    <?php if ( is_page( 'contact' ) ): ?><input type="hidden" id="the-trigger" value="contact-icons" /><?php endif; ?>

                    <?php if ( is_page( 'about' ) ): ?><input type="hidden" id="the-trigger" value="about-me-icons" /><?php endif; ?>

                    <?php // fuck you wordpress, the notes page is not my home page but you wont work any other way ?>
                    <?php if ( is_home() ): ?><input type="hidden" id="the-trigger" value="notes-icons" /><div id="notes-icons"></div><?php endif; ?>

                    <?php if ( is_single() ): ?><input type="hidden" id="the-trigger" value="single-post" /><div id="notes-icons"></div><?php endif; ?>
                    <div id="header-image">
                        <a href="<?php echo esc_url(home_url('/')); ?>">
                            <?php
                            $header_image = get_header_image();
                            if (is_singular() &&
                                    has_post_thumbnail() &&
                                    ( $image = wp_get_attachment_image_src(get_post_thumbnail_id(get_the_ID()), array(get_custom_header()->width, get_custom_header()->width)) ) &&
                                    $image[1] >= get_custom_header()->width) :
                                the_post_thumbnail();

                            elseif (!empty($header_image)) :
                                ?>
                                <img src="<?php header_image(); ?>" width="<?php echo get_custom_header()->width; ?>" height="<?php echo get_custom_header()->height; ?>" alt="" />
                                <?php
                            endif;
                            ?>
                        </a>
                    </div>

                </div>
            </div><!--  #header -->

            <p class="access"><a href="#content" title="<?php esc_attr_e('Skip navigation to the content', 'jackbrain'); ?>"><?php _e('Skip navigation', 'jackbrain'); ?></a></p>
            <?php wp_nav_menu(array('container' => 'div', 'container_id' => 'globalnav', 'theme_location' => 'primary', 'menu_id' => 'menu', 'fallback_cb' => 'jackbrain_globalnav')); ?>
