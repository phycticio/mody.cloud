<?php
/**
 * Creates or updates the main site's home page: the landing page that
 * presents Mody Cloud and holds the registration chat (the mody/register
 * block, M1-04), and makes it the front page.
 *
 * Run from the repository root, on the main site (local or production):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/home-page.php
 *
 * It is safe to run again: it finds the page by its slug (home) and replaces
 * its content, so edits made in the editor are lost. Edit this file instead,
 * or stop running it once the page is edited by hand.
 *
 * - The page: "Home", template "page-blank" (header and footer, no title),
 *   published, the site's front page.
 * - The header's menu (the main site's wp_navigation): the page's sections
 *   and Log in, instead of the list of every page.
 * - Layout: Spectra containers (sections, grid rows, columns), palette slugs
 *   and the theme's gradients only (rules 2 and 3); every Spectra block gets
 *   its spectraId (SpectraLayout::with_ids()).
 */

use Mody\Platform\AI\SpectraBlocks;
use Mody\Platform\AI\SpectraLayout;

if ( ! is_main_site() ) {
	WP_CLI::error( 'Run this on the main site.' );
}

$markup = <<<'HTML'
<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"anchor":"top","backgroundGradient":"var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dgradient\u002d\u002dprimary-to-contrast)","textColor":"base","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|80","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|60","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained","contentSize":"var(\u002d\u002dwp\u002d\u002dstyle\u002d\u002dglobal\u002d\u002dwide-size)"}} -->
<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|70"},"@mobile":{"layout":{"columnCount":1},"spacing":{"blockGap":"var:preset|spacing|50"}}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":null}} -->
<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"center"}} -->
<!-- wp:paragraph {"backgroundColor":"accent","textColor":"base","fontSize":"small","style":{"border":{"radius":"999px"},"spacing":{"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|30","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30"}},"typography":{"fontWeight":"600","letterSpacing":"0.06em","textTransform":"uppercase"}}} -->
<p class="has-base-color has-accent-background-color has-text-color has-background has-small-font-size" style="border-radius:999px;padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30);font-weight:600;letter-spacing:0.06em;text-transform:uppercase">Sovereign websites, made in Europe</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"textColor":"base","style":{"typography":{"fontSize":"clamp(2.5rem, 5vw, 4rem)","lineHeight":"1.08","letterSpacing":"-0.02em"}}} -->
<h1 class="wp-block-heading has-base-color has-text-color" style="font-size:clamp(2.5rem, 5vw, 4rem);letter-spacing:-0.02em;line-height:1.08">Your website, written by a sovereign AI. Owned by you.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"large"} -->
<p class="has-large-font-size">Answer four questions and your WordPress site is online. Then tell Mody, your AI agent, what you need: it writes the pages in your brand's voice and colors, and you edit everything yourself.</p>
<!-- /wp:paragraph -->

<!-- wp:list {"style":{"spacing":{"padding":{"left":"var:preset|spacing|40"}}}} -->
<ul style="padding-left:var(--wp--preset--spacing--40)" class="wp-block-list"><!-- wp:list-item -->
<li><strong>30 days free</strong>, no card needed</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Then from <strong>9.99 € a month</strong>, everything included</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Your AI runs in Switzerland, your site in Europe</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"textColor":"base","className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link has-base-color has-text-color wp-element-button" href="#how">See how it works</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|30"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch","verticalAlignment":"center"}} -->
<!-- wp:heading {"level":2,"textColor":"base","fontSize":"x-large"} -->
<h2 class="wp-block-heading has-base-color has-text-color has-x-large-font-size">Start your free trial</h2>
<!-- /wp:heading -->

<!-- wp:mody/register {"anchor":"join","backgroundColor":"base","textColor":"contrast","fontSize":"medium","style":{"border":{"radius":"20px"},"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}},"shadow":"var:preset|shadow|deep"}} /-->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"backgroundColor":"base","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|30","bottom":"var:preset|spacing|40","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained","contentSize":"var(\u002d\u002dwp\u002d\u002dstyle\u002d\u002dglobal\u002d\u002dwide-size)"}} -->
<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"@tablet":{"layout":{"columnCount":2}},"@mobile":{"layout":{"columnCount":1}}},"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":null}} -->
<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
<!-- wp:paragraph {"textColor":"primary","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-primary-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1.1">2 minutes</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>From four answers to a live site at <em>your-name</em>.mody.cloud.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
<!-- wp:paragraph {"textColor":"primary","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-primary-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1.1">30 days</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Free, with up to three sites. No card to start.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
<!-- wp:paragraph {"textColor":"primary","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-primary-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1.1">3 GB</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Of space for every site, with every feature included.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|20"}}} -->
<!-- wp:paragraph {"textColor":"primary","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-primary-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1.1">EU + CH</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Your site lives in Europe. The AI that writes it runs in Switzerland.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"anchor":"how","backgroundGradient":"var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dgradient\u002d\u002dbase-to-soft-primary)","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"blockGap":"var:preset|spacing|50","padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained","contentSize":"var(\u002d\u002dwp\u002d\u002dstyle\u002d\u002dglobal\u002d\u002dwide-size)"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"small","style":{"typography":{"textAlign":"center","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-align-center has-accent-color has-text-color has-small-font-size" style="font-weight:600;letter-spacing:0.08em;text-transform:uppercase">How it works</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->
<h2 class="wp-block-heading has-text-align-center">From an idea to a website, in a conversation</h2>
<!-- /wp:heading -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"@tablet":{"layout":{"columnCount":1}},"@mobile":{"layout":{"columnCount":1}}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":null}} -->
<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1"}}} -->
<p class="has-accent-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1">01</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">Answer four questions</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Your name, your email, your project's name and the address you want. Mody asks them one at a time in the chat above, and your site exists in about two minutes.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1"}}} -->
<p class="has-accent-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1">02</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">Tell Mody what you need</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Choose your password from the welcome email and you land in your site's editor. Ask Mody, your AI agent, for a page, new colors or a better tagline. It works in your brand and shows you around.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1"}}} -->
<p class="has-accent-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1">03</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">Make it yours</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Everything stays editable in WordPress's own Site Editor: every page, every color, every font. Mody suggests; you decide.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"anchor":"agent","backgroundColor":"contrast","textColor":"base","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained","contentSize":"var(\u002d\u002dwp\u002d\u002dstyle\u002d\u002dglobal\u002d\u002dwide-size)"}} -->
<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|70"},"@mobile":{"layout":{"columnCount":1},"spacing":{"blockGap":"var:preset|spacing|50"}}},"layout":{"type":"grid","columnCount":2,"minimumColumnWidth":null}} -->
<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"center"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"small","style":{"typography":{"fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-accent-color has-text-color has-small-font-size" style="font-weight:600;letter-spacing:0.08em;text-transform:uppercase">Meet Mody</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"textColor":"base"} -->
<h2 class="wp-block-heading has-base-color has-text-color">An AI agent that knows your brand</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Before every answer, Mody reads your site's name, tagline, mission and tone of voice, so every page sounds like you and uses your colors. It lives in a chat bubble inside your editor.</p>
<!-- /wp:paragraph -->

<!-- wp:list {"style":{"spacing":{"padding":{"left":"var:preset|spacing|40"}}}} -->
<ul style="padding-left:var(--wp--preset--spacing--40)" class="wp-block-list"><!-- wp:list-item -->
<li><strong>Writes pages</strong> in real WordPress blocks, as drafts for you to check</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Changes the design</strong>: colors, fonts, spacing and styles</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Publishes</strong> pages and updates your title and tagline</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Opens the right screen</strong> in the editor when you'd rather do it yourself</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Knows only this site</strong>: each site has its own Mody, and nothing passes between your sites</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"secondary","style":{"border":{"radius":"20px"},"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch","verticalAlignment":"center"}} -->
<!-- wp:paragraph {"backgroundColor":"accent","textColor":"base","style":{"border":{"radius":"16px"},"spacing":{"margin":{"left":"var:preset|spacing|60"},"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|30","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30"}}}} -->
<p class="has-base-color has-accent-background-color has-text-color has-background" style="border-radius:16px;margin-left:var(--wp--preset--spacing--60);padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">Write an About page that tells how we started the bakery in 2019.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"backgroundColor":"primary","textColor":"base","style":{"border":{"radius":"16px"},"spacing":{"margin":{"right":"var:preset|spacing|60"},"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|30","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30"}}}} -->
<p class="has-base-color has-primary-background-color has-text-color has-background" style="border-radius:16px;margin-right:var(--wp--preset--spacing--60);padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">Your About page is ready as a draft, in your friendly tone. Shall I open it so you can read it?</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"backgroundColor":"accent","textColor":"base","style":{"border":{"radius":"16px"},"spacing":{"margin":{"left":"var:preset|spacing|60"},"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|30","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30"}}}} -->
<p class="has-base-color has-accent-background-color has-text-color has-background" style="border-radius:16px;margin-left:var(--wp--preset--spacing--60);padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">Yes. And make the main color a bit warmer.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"backgroundColor":"primary","textColor":"base","style":{"border":{"radius":"16px"},"spacing":{"margin":{"right":"var:preset|spacing|60"},"padding":{"top":"var:preset|spacing|20","right":"var:preset|spacing|30","bottom":"var:preset|spacing|20","left":"var:preset|spacing|30"}}}} -->
<p class="has-base-color has-primary-background-color has-text-color has-background" style="border-radius:16px;margin-right:var(--wp--preset--spacing--60);padding-top:var(--wp--preset--spacing--20);padding-right:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--20);padding-left:var(--wp--preset--spacing--30)">Done: your primary color is now a warmer teal across the whole site. Here is your About page.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"anchor":"sovereignty","backgroundColor":"base","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"blockGap":"var:preset|spacing|50","padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained","contentSize":"var(\u002d\u002dwp\u002d\u002dstyle\u002d\u002dglobal\u002d\u002dwide-size)"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"small","style":{"typography":{"textAlign":"center","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-align-center has-accent-color has-text-color has-small-font-size" style="font-weight:600;letter-spacing:0.08em;text-transform:uppercase">Digital sovereignty</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->
<h2 class="wp-block-heading has-text-align-center">European where it counts, and honest about the rest</h2>
<!-- /wp:heading -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"@tablet":{"layout":{"columnCount":1}},"@mobile":{"layout":{"columnCount":1}}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":null}} -->
<!-- wp:spectra/container {"variationSelected":true,"style":{"border":{"top":{"color":"var:preset|color|primary","width":"4px","style":"solid"}},"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">AI from Switzerland</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Mody runs on Infomaniak AI Services, in Swiss data centers. Your requests are never sent to OpenAI, Google or another US AI provider.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"border":{"top":{"color":"var:preset|color|primary","width":"4px","style":"solid"}},"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">No trackers, no Google Fonts</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Your site's fonts, patterns and images are stored on our own servers in Europe, never fetched from Google Fonts, and we add no analytics or trackers.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"border":{"top":{"color":"var:preset|color|primary","width":"4px","style":"solid"}},"spacing":{"blockGap":"var:preset|spacing|30","padding":{"top":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">No lock-in</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Your site is WordPress: a block theme with Gutenberg and Spectra blocks, both free and open source, and no proprietary page builder. Download your theme as a zip, export your content, and move your site wherever you like.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->

<!-- wp:paragraph {"textColor":"secondary","fontSize":"small","style":{"typography":{"textAlign":"center"}}} -->
<p class="has-text-align-center has-secondary-color has-text-color has-small-font-size"><strong>Where we are not there yet:</strong> while Mody Cloud is small, four things still fall outside our European-only rule. Cloudflare, a US company, sits in front of our network and sees its traffic. Wordfence, also American, scans for security threats. Brevo, a French company, sends our emails; it is on this list because we have not yet checked where it sends from. WordPress's default avatars still load from Gravatar. We will replace them as soon as we can afford European ones.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"anchor":"included","backgroundGradient":"var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dgradient\u002d\u002dbase-to-soft-primary)","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"blockGap":"var:preset|spacing|50","padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained","contentSize":"var(\u002d\u002dwp\u002d\u002dstyle\u002d\u002dglobal\u002d\u002dwide-size)"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"small","style":{"typography":{"textAlign":"center","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-align-center has-accent-color has-text-color has-small-font-size" style="font-weight:600;letter-spacing:0.08em;text-transform:uppercase">What you get</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->
<h2 class="wp-block-heading has-text-align-center">Everything included, on every site</h2>
<!-- /wp:heading -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"@tablet":{"layout":{"columnCount":2}},"@mobile":{"layout":{"columnCount":1}}},"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":null}} -->
<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">3 GB for every site</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Room for your images, documents and pages on each site, whatever your plan.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">Its own Mody</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Each site has its own AI assistant, which knows only that site. Nothing passes between the sites in your account.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">Every plugin in our market</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Switch on any plugin from our market, on any site. We check each one against our European rules first.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">Your own domain</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Each site starts at <em>your-name</em>.mody.cloud. Soon you can point your own domain to our servers, and your site answers at it.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">The full Site Editor</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Pages, header, footer and styles, edited the WordPress way. You are the administrator of your site.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">Six designs, ten font families</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Start from Mody Teal, Forest, Ocean, Plum, Sand or Graphite, with open-licensed fonts served from our servers instead of Google's.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">Spectra blocks</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Sections, grids, accordions, tabs, sliders, counters and more, with everything that called outside Europe switched off.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">Take it with you</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Download your theme as a zip from the Site Editor and your content with Tools → Export, and move your site to another WordPress host.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|40","right":"var:preset|spacing|40","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"primary","fontSize":"large"} -->
<h3 class="wp-block-heading has-primary-color has-text-color has-large-font-size">Updates and security</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We keep WordPress and every plugin up to date for the whole network. You never press an update button.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"anchor":"pricing","backgroundColor":"base","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"blockGap":"var:preset|spacing|50","padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained","contentSize":"var(\u002d\u002dwp\u002d\u002dstyle\u002d\u002dglobal\u002d\u002dwide-size)"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"small","style":{"typography":{"textAlign":"center","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-align-center has-accent-color has-text-color has-small-font-size" style="font-weight:600;letter-spacing:0.08em;text-transform:uppercase">Plans</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->
<h2 class="wp-block-heading has-text-align-center">Three plans, the same features</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"typography":{"textAlign":"center"}}} -->
<p class="has-text-align-center">Every site gets the same: 3 GB of space, every plugin in our market, its own Mody and the full Site Editor. Your plan only sets how many sites you have. Pay monthly, or yearly for less.</p>
<!-- /wp:paragraph -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"spacing":{"blockGap":"var:preset|spacing|40"},"@tablet":{"layout":{"columnCount":2}},"@mobile":{"layout":{"columnCount":1}}},"layout":{"type":"grid","columnCount":4,"minimumColumnWidth":null}} -->
<!-- wp:spectra/container {"variationSelected":true,"backgroundColor":"accent","textColor":"base","style":{"border":{"radius":"16px"},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"textColor":"base","fontSize":"large"} -->
<h3 class="wp-block-heading has-base-color has-text-color has-large-font-size">Free trial</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-xx-large-font-size" style="font-weight:700;line-height:1.1">0 €</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><strong>30 days, up to 3 sites.</strong> No card needed to start.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"border":{"radius":"16px","top":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"right":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"bottom":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"left":{"color":"var:preset|color|primary","width":"1px","style":"solid"}},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">1 site</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"primary","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-primary-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1.1">9.99 €</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">a month, or <strong>89.99 € a year</strong>, saving 29.89 €</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>For a person, a project or a small business.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"border":{"radius":"16px","top":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"right":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"bottom":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"left":{"color":"var:preset|color|primary","width":"1px","style":"solid"}},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">3 sites</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"primary","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-primary-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1.1">25.99 €</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">a month, or <strong>249.99 € a year</strong>, saving 61.89 €</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>For freelancers and anyone with several projects.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"style":{"border":{"radius":"16px","top":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"right":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"bottom":{"color":"var:preset|color|primary","width":"1px","style":"solid"},"left":{"color":"var:preset|color|primary","width":"1px","style":"solid"}},"spacing":{"blockGap":"var:preset|spacing|20","padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|40","bottom":"var:preset|spacing|50","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"left","verticalAlignment":"top"}} -->
<!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">10 sites</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"primary","fontSize":"xx-large","style":{"typography":{"fontWeight":"700","lineHeight":"1.1"}}} -->
<p class="has-primary-color has-text-color has-xx-large-font-size" style="font-weight:700;line-height:1.1">49.99 €</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">a month, or <strong>499.99 € a year</strong>, saving 99.89 €</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>For agencies and studios that build sites for clients.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->
<!-- /wp:spectra/container -->

<!-- wp:paragraph {"textColor":"secondary","fontSize":"small","style":{"typography":{"textAlign":"center"}}} -->
<p class="has-text-align-center has-secondary-color has-text-color has-small-font-size">Billing is not open yet. Before your trial ends, we will ask you to choose a plan, monthly or yearly, and pay through Mollie, a European payment provider. A plan change applies from your next billing date.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"anchor":"faq","backgroundGradient":"var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dgradient\u002d\u002dbase-to-soft-primary)","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"blockGap":"var:preset|spacing|50","padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained"}} -->
<!-- wp:paragraph {"textColor":"accent","fontSize":"small","style":{"typography":{"textAlign":"center","fontWeight":"600","letterSpacing":"0.08em","textTransform":"uppercase"}}} -->
<p class="has-text-align-center has-accent-color has-text-color has-small-font-size" style="font-weight:600;letter-spacing:0.08em;text-transform:uppercase">Questions</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"style":{"typography":{"textAlign":"center"}}} -->
<h2 class="wp-block-heading has-text-align-center">Before you start</h2>
<!-- /wp:heading -->

<!-- wp:spectra/accordion {"style":{"spacing":{"blockGap":"var:preset|spacing|30"}}} -->
<!-- wp:spectra/accordion-child-item {"openByDefault":true,"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Do I need a card to start?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>No. Your trial lasts 30 days and lets you create up to three sites, with no card. Before it ends, we will ask you to choose a plan, and tell you clearly what happens to your sites if you don't.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Who is behind Mody Cloud?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>Mody Cloud is run by an independent professional, self-employed in Spain, who builds and looks after the whole platform. Prices are public and the same for everyone, and every limit is a real one: 3 GB per site, and the number of sites in your plan.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Which AI writes my pages, and where does it run?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>A Qwen model, run by Infomaniak AI Services in Switzerland. Before each request, Mody adds your site's brand, so the result fits your site. Every page it writes is checked block by block before it reaches your editor.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Can I take my site elsewhere?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>Yes. Download your theme as a zip from the Site Editor, and your pages and posts with Tools → Export. Your site is built with Gutenberg and Spectra blocks, both free and open source, so it runs on any WordPress host with Spectra installed.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Can I add plugins?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>Yes: every plugin in our market is available on every site and every plan, and you switch each one on for the site that needs it. You can't upload your own: we check each plugin against our European rules first, and we keep them all up to date.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Can I use my own domain?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>Yes, soon. Every site starts at <em>your-name</em>.mody.cloud. When domains open, you point your domain to our servers and your site answers at it.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Do my sites share one Mody?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>No. Each site has its own Mody, which reads only that site's brand and content. Nothing passes from one site to another, even within your account.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"What happens if a payment fails?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>Your sites stay online but read-only while we retry, for up to 60 days, with a reminder to update your payment. Before anything is deleted, we send you a link to export your content.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->

<!-- wp:spectra/accordion-child-item {"backgroundColor":"base","style":{"border":{"radius":"12px"}}} -->
<!-- wp:spectra/accordion-child-header {"style":{"spacing":{"padding":{"top":"var:preset|spacing|30","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between","verticalAlignment":"center"}} -->
<!-- wp:spectra/accordion-child-header-content {"text":"Which languages can my site use?","style":{"typography":{"fontWeight":"600"}}} /-->

<!-- wp:spectra/accordion-child-header-icon /-->
<!-- /wp:spectra/accordion-child-header -->

<!-- wp:spectra/accordion-child-details {"style":{"spacing":{"padding":{"top":"0","right":"var:preset|spacing|40","bottom":"var:preset|spacing|30","left":"var:preset|spacing|40"}}}} -->
<!-- wp:paragraph -->
<p>As many as you need: add Spanish, German, Italian, French, Portuguese, Dutch or Catalan in Settings → Languages, and each page gets its translations, at addresses like /es/, with a language switcher next to your menu. Your editor is in English, and Mody writes in the language you write to it.</p>
<!-- /wp:paragraph -->
<!-- /wp:spectra/accordion-child-details -->
<!-- /wp:spectra/accordion-child-item -->
<!-- /wp:spectra/accordion -->
<!-- /wp:spectra/container -->

<!-- wp:spectra/container {"variationSelected":true,"htmlTag":"section","align":"full","isBlockRootParent":true,"backgroundGradient":"var(\u002d\u002dwp\u002d\u002dpreset\u002d\u002dgradient\u002d\u002dprimary-to-accent)","textColor":"base","style":{"spacing":{"margin":{"top":"0","bottom":"0"},"blockGap":"var:preset|spacing|40","padding":{"top":"var:preset|spacing|70","right":"var:preset|spacing|40","bottom":"var:preset|spacing|70","left":"var:preset|spacing|40"}},"@mobile":{"spacing":{"padding":{"top":"var:preset|spacing|50","right":"var:preset|spacing|30","bottom":"var:preset|spacing|50","left":"var:preset|spacing|30"}}}},"layout":{"type":"constrained"}} -->
<!-- wp:heading {"textColor":"base","style":{"typography":{"textAlign":"center"}}} -->
<h2 class="wp-block-heading has-text-align-center has-base-color has-text-color">Your website is four questions away</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"large","style":{"typography":{"textAlign":"center"}}} -->
<p class="has-text-align-center has-large-font-size">Free for 30 days. No card. Sovereign from the first page.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background wp-element-button" href="#join">Start your free trial</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- /wp:spectra/container -->
HTML;

$markup = SpectraLayout::with_ids( SpectraBlocks::normalize( $markup ) );

$existing = get_page_by_path( 'home', OBJECT, 'page' );
$page     = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Home',
	'post_name'    => 'home',
	'post_content' => $markup,
	'post_excerpt' => 'Mody Cloud: WordPress sites written in your brand by an AI agent that runs in Switzerland. From 9.99 € a month, everything included; 30 days free, no card.',
);
if ( $existing ) {
	$page['ID'] = $existing->ID;
	// Keep the spectraIds already saved, so Spectra's cached CSS stays valid.
	$page['post_content'] = $existing->post_content && str_contains( $existing->post_content, 'spectraId' ) && md5( preg_replace( '/,?"spectraId":"[^"]*"/', '', $existing->post_content ) ) === md5( preg_replace( '/,?"spectraId":"[^"]*"/', '', $markup ) ) ? $existing->post_content : $markup;
	$id                   = wp_update_post( wp_slash( $page ), true );
} else {
	$supers = get_super_admins();
	$author = $supers ? get_user_by( 'login', $supers[0] ) : false;
	$page['post_author'] = $author ? $author->ID : 0;
	$id                  = wp_insert_post( wp_slash( $page ), true );
}
if ( is_wp_error( $id ) ) {
	WP_CLI::error( $id->get_error_message() );
}
update_post_meta( $id, '_wp_page_template', 'page-blank' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $id );

// The header's menu: the sections of the home page, then Log in.
$links = array(
	'How it works' => home_url( '/#how' ),
	'Mody'         => home_url( '/#agent' ),
	'Plans'        => home_url( '/#pricing' ),
	'Questions'    => home_url( '/#faq' ),
	'Log in'       => wp_login_url(),
);
$menu  = '';
foreach ( $links as $label => $url ) {
	$menu .= '<!-- wp:navigation-link ' . wp_json_encode( array( 'label' => $label, 'url' => $url, 'kind' => 'custom', 'isTopLevelLink' => true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->' . "\n\n";
}
// The newest menu without a language: the translated ones (scripts/home-page-translations.php) carry mody_lang.
$navigation = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => 'mody_lang', 'compare' => 'NOT EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
$nav        = array(
	'post_type'    => 'wp_navigation',
	'post_status'  => 'publish',
	'post_title'   => 'Navigation',
	'post_content' => trim( $menu ),
);
if ( $navigation ) {
	$nav['ID'] = $navigation[0]->ID;
	$nav_id    = wp_update_post( wp_slash( $nav ), true );
} else {
	$nav_id = wp_insert_post( wp_slash( $nav ), true );
}
if ( is_wp_error( $nav_id ) ) {
	WP_CLI::error( $nav_id->get_error_message() );
}

WP_CLI::success( sprintf( 'Home page %d is the front page: %s (menu %d).', $id, get_permalink( $id ), $nav_id ) );
