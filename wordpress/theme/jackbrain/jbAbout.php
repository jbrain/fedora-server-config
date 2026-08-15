<?php
/**
 * @package JackBrain
 * Template Name: jbAbout
 */
?>
<?php get_header(); ?>

<div id="about-container">

    <div id="content" class="hfeed">

        <?php while (have_posts()) : the_post(); ?>

                <div class="about-content about-main-text">
                    <?php the_content('<span class="more-link">' . __('Read More &raquo;', 'jackbrain') . '</span>'); ?>
                    <?php wp_link_pages(array('before' => '<div class="page-link">' . __('Pages: ', 'jackbrain'), 'after' => '</div>')); ?>
                </div>


        <?php endwhile ?>
        <div id="the-cube"></div>
        <section id="entropy-study" class="entropy-study">
            <button class="entropy-study__toggle" type="button" aria-expanded="false" aria-controls="entropy-study-panel">Explore entropy</button>
            <div id="entropy-study-panel" class="entropy-study__panel" aria-labelledby="entropy-study-heading" hidden>
                <div class="entropy-study__heading-row">
                    <h2 id="entropy-study-heading" class="entropy-study__heading">Interaction unpredictability</h2>
                    <output class="entropy-study__status">Waiting for cube</output>
                </div>
                <div class="entropy-study__controls">
                    <button type="button" data-action="start" disabled>Start</button>
                    <button type="button" data-action="reset" disabled>Reset</button>
                    <button type="button" data-action="methodology" aria-haspopup="dialog" aria-controls="entropy-methodology">Methodology</button>
                    <button type="button" data-action="qr" aria-haspopup="dialog" aria-controls="entropy-result-qr" disabled>QR result</button>
                </div>
                <div class="entropy-study__meter-row">
                    <label for="entropy-study-meter">Estimated interaction unpredictability</label>
                    <output data-value="score">0.0 demo bits</output>
                </div>
                <progress id="entropy-study-meter" max="32" value="0">0 of 32 demo bits</progress>
                <div class="entropy-study__ticks" aria-hidden="true"><span>0</span><span>8</span><span>16</span><span>24</span><span>32</span></div>
                <dl class="entropy-study__stats">
                    <div><dt>Moves</dt><dd data-value="moves">0</dd></div>
                    <div><dt>Layer turns</dt><dd data-value="layers">0</dd></div>
                    <div><dt>Orbit context</dt><dd data-value="orbits">0</dd></div>
                    <div><dt>Path novelty</dt><dd data-value="transitions">0 transitions</dd></div>
                </dl>
                <dl class="entropy-study__crypto">
                    <div><dt>128-bit browser salt</dt><dd><output data-value="salt">Not started</output></dd></div>
                    <div><dt>Conditioned interaction digest</dt><dd><output data-value="digest">Not started</output></dd></div>
                </dl>
                <p class="entropy-study__qualification">Model-based educational heuristic, capped at 32 demo bits. Not NIST-validated entropy and not used as a key.</p>
                <p class="entropy-study__local">Processed locally in this page. Reset or navigation clears the session.</p>
                <span class="entropy-study__live" aria-live="polite"></span>
            </div>
            <dialog id="entropy-methodology" class="entropy-study__dialog" aria-label="Methodology">
                <ul>
                    <li><a href="https://doi.org/10.6028/NIST.SP.800-90B">NIST SP 800-90B: Entropy Sources</a></li>
                    <li><a href="https://www.rfc-editor.org/rfc/rfc4086">RFC 4086: Randomness Requirements for Security</a></li>
                    <li><a href="https://www.w3.org/TR/WebCryptoAPI/">W3C Web Cryptography API</a></li>
                </ul>
                <form method="dialog"><button type="submit">Close</button></form>
            </dialog>
            <dialog id="entropy-result-qr" class="entropy-study__dialog entropy-study__qr-dialog" aria-labelledby="entropy-result-qr-heading">
                <h3 id="entropy-result-qr-heading">Entropy result QR</h3>
                <p>Scanning this code shares the displayed session result, including its salt and digest.</p>
                <canvas data-qr-canvas width="240" height="240" role="img" aria-label="QR code containing the entropy session result"></canvas>
                <details>
                    <summary>Encoded result</summary>
                    <output data-qr-payload></output>
                </details>
                <form method="dialog"><button type="submit">Close</button></form>
            </dialog>
        </section>
        <?php // Turn off the clock: <div id="the-clock"></div> ?>

        <div id="nav-below" class="navigation">
            <div class="nav-previous"><?php next_posts_link(__('&laquo; Older posts', 'jackbrain')); ?></div>
            <div class="nav-next"><?php previous_posts_link(__('Newer posts &raquo;', 'jackbrain')); ?></div>
        </div>

    </div><!-- #content .hfeed -->
</div><!-- #container -->
