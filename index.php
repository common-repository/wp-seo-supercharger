<?php
/*
 * Plugin Name:   WP SEO Supercharger
 * Version:       0.3
 * Plugin URI:    http://www.wpactions.com/998/wp-seo-supercharger/
 * Description:	  Supercharge you wordpress seo.
 * Author:        Jesse
 * Author URI:    http://www.wpactions.com
 */
require_once(dirname(__FILE__).'/wp_seo_supercharger.php');
register_activation_hook(__FILE__, array($WPSEOSupercharger, 'install'));
//register_deactivation_hook(__FILE__, array($WPSEOSupercharger, 'uninstall'));
?>
