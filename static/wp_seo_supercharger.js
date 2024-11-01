jQuery(document).ready(function() {
	jQuery("#wp_seo_supercharger-tabs").tabs();

	jQuery('input[name="email_notification"]').change(function(){
        jQuery('#your_email').toggle(this.checked);
    }).change();

    jQuery('input[name="post_status"]').change(function(){
    	showOrHide = jQuery("input[name='post_status']:checked").val() == 'publish' ? true : false;
        jQuery('#redirect_visitor').toggle(showOrHide);
    }).change();

    jQuery(".click_general_settings").click(function(e) {
        e.preventDefault();
        jQuery("#wp_seo_supercharger-tabs").tabs("select", 1);
    });

    jQuery(".click_post_settings").click(function(e) {
        e.preventDefault();
        jQuery("#wp_seo_supercharger-tabs").tabs("select", 2);
    });

    jQuery(".click_howitworks").click(function(e) {
        e.preventDefault();
        jQuery("#wp_seo_supercharger-tabs").tabs("select", 0);
    });
});
