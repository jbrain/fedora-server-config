<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */

?>
                </div>
                <div style="clear:both;">
                </div>
            </div>
        </div> <!-- end id="maincontainer"-->
        <?php
            $count_temp_playlist = 1;
            if (!isset($_SESSION['login']) || !$_SESSION['login']) {
                if ($GLOBALS['user']->playlist) {
                    $count_temp_playlist = count($GLOBALS['user']->playlist->get_items());
                }
            }
        ?>
        <div id="footer" class="<?php echo (($count_temp_playlist || AmpConfig::get('play_type') == 'localplay') ? '' : 'footer-wild'); ?>">
        <?php
        if (AmpConfig::get('custom_text_footer')) {
            echo AmpConfig::get('custom_text_footer');
        } else {
        ?>
            <a id="ampache_link" href="https://github.com/ampache/ampache#readme" target="_blank" title="Copyright © 2001 - 2015 Ampache.org">Ampache <?php echo AmpConfig::get('version'); ?></a>
        <?php } ?>
        <?php if (AmpConfig::get('show_footer_statistics')) { ?>
            <br />
            <?php echo T_('Queries:'); ?><?php echo Dba::$stats['query']; ?> <?php echo T_('Cache Hits:'); ?><?php echo database_object::$cache_hit; ?>
            <?php
                $load_time_end = microtime(true);
                $load_time = number_format(($load_time_end - AmpConfig::get('load_time_begin')), 4);
            ?>
            | <?php echo T_('Load time:'); ?><?php echo $load_time; ?>
        <?php } ?>
        </div>
        <?php if (AmpConfig::get('ajax_load') && (!isset($_SESSION['login']) || !$_SESSION['login'])) { ?>
        <div id="webplayer"></div>
        <?php
            require_once AmpConfig::get('prefix') . '/templates/uberviz.inc.php';
        }
        ?>
    </body>
</html>
