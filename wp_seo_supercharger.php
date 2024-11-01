<?php

if ( !class_exists( "WPSEOSupercharger" ) ) {
	class WPSEOSupercharger {
		private $plugin_url = '' ;
		private $plugin_path = '' ;
		private $lib_path = '';
		private $db =  array();
		private $version = '0.3';
		private $setting_page = '';
		private $options = '';
		private $option_name = 'WPSEOSupercharger';
		private $plugin_name = 'wp_seo_supercharger';
		private $error ='';
		private $notify=false;

		function __construct() {
			global $wpdb;
			$this->plugin_path = dirname( __FILE__ );
			$this->lib_path = $this->plugin_path.'/lib';
			$this->db = array();
			$this->plugin_url = WP_PLUGIN_URL . '/' . basename( dirname( __FILE__ ) );
			add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
			add_action( 'admin_init', array( $this, 'install' ) );
			add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );
			add_action( 'admin_notices', array($this,'admin_notice') );

		}

		function admin_notice() {
			$screen = get_current_screen();

			if(!in_array($screen->id, $this->menus))
				return;

			if($this->is_redirected()){
				$class = 'updated';
				$msg = 'Done!';
				if($error = $this->get_error()){
					$msg = $error;
					$class="error";
				}
				echo '<div class="'.$class.'">';
				echo '<p><strong>'.$msg.'</strong></p>';
				echo '</div>';
			}

		}

		function get_current_url() {
			return 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}/{$_SERVER['REQUEST_URI']}";
		}

		function template_redirect() {
			if(is_search()||is_robots())
				return false;

			if(!$search_query = $this->get_search_query())
				return false;

			if(!$search_query = $this->filter_search_query( $search_query ))
				return false;

			if(!$this->allow_post( $search_query ))
				return false;


			if(!$related_posts = $this->get_related_post( $search_query ))
				return false;


			if(!$content = $this->create_new_post_content($related_posts))
				return false;

			if(!$newid = $this->insert_post( $search_query, $content ))
				return false;

			$this->mark_post($newid);

			$newurl = get_permalink( $newid );

			$cururl = $this->get_current_url();

			$this->email_notification($newurl,$cururl,$search_query);

			//$this->redirect_to_new($newurl);
		}

		function redirect_to_new($newurl){

			if($this->get_option('redirect_to_new') && 'publish' == $this->get_option('post_status')){
				$this->redirect_to_page( $newurl );
				exit;
			}
		}

		function email_notification($newurl,$cururl,$q){

			if(!$this->get_option('email_notification'))
				return false;

			if(!$email = $this->get_option('email'))
				return false;


			$subject = 'WP SEO Supercharger: New Search Query Post Created.';

			$message .= "We've created a post {$newurl} for search query <strong>{$q}</strong>\n";

			if($keyword_position = $this->get_keyword_position()){
				$message .= "Your page {$cururl} ranked <strong>#{$keyword_position}</strong> on Google for this search query.\n";
			}

			$message .= "Brought to You by WP SEO Supercharger(http://www.wpactions.com)\n";

			$this->send_mail($email,$subject,$message);
		}

		function send_mail($email,$subject,$message){
			add_filter( 'wp_mail_content_type', array($this,'set_html_content_type') );
			wp_mail( $email,$subject,$message );
			remove_filter( 'wp_mail_content_type', array($this,'set_html_content_type') );
		}


		function set_html_content_type(){
			return 'text/html';
		}

		function mark_post($id){
			update_post_meta( $id,'wpss_search_query_post', true );
		}

		function allow_post($q){

			$allow_duplicate_post = $this->get_option('allow_duplicate_post');

			if($allow_duplicate_post)
				return true;

			if($this->get_post_by_title($q))
				return false;

			return true;

		}

		function get_post_by_title($title){
			global $wpdb;
			$title = strtolower($title);
			$sql = $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_title like '%s' ",$title);
			$wpdb->get_row( $sql );

			return $wpdb->num_rows;
		}

		function insert_post($q,$content){

			$post_title = $this->get_option('post_title','[search_query]');
			$post_title = str_replace('[search_query]',$q,$post_title);
			$post_status = $this->get_option('post_status','draft');


			$args = array(
						'post_type'=>'post',
						'post_status' => $post_status,
						'post_title' => ucwords(strtolower($post_title)),
						'post_content' =>$content,
						'post_category' => (array)$this->get_option('post_category'),
						'tags_input' => $q
						);

			$rtn = wp_insert_post($args);

			return $rtn;
		}

		function create_new_post_content($related_posts) {
			$tpl = $this->get_option('post_template');
			if(!$tpl) return false;
			$content = '';
			foreach($related_posts as $p){
				$p->filter = 'sample';
				$content .= $this->handle_tpl($tpl,$p);
			}

			return $content;
		}



		function handle_tpl($tpl,$post){
			if(false !== strpos($tpl,'[rpost_title]'))
				$tpl = str_replace('[rpost_title]',$post->post_title,$tpl);

			if(false !== strpos($tpl,'[rimage_url]'))
				$tpl = $this->handle_related_post_image($tpl,$post);

			if(preg_match('@\[rpost_content\:?(\d*?)\]@',$tpl,$m))
				$tpl = $this->handle_related_post_content($tpl,$post,$m);

			if(false !== strpos($tpl,'[rpost_link]'))
				$tpl = str_replace('[rpost_link]',get_permalink( $post ),$tpl);
			return $tpl;
		}

		function handle_related_post_content($tpl,$post,$match){
			$content = $post->post_content;
			if(!$match[1]){

			}else{
				$content = wp_strip_all_tags( $content,true );
				$content = $this->safe_truncate($content,$match[1]);
			}


			return str_replace($match[0],$content,$tpl);
		}

		function safe_truncate( $string, $length ){
			$length     = absint($length);
			$ret        = substr( $string, 0, $length );
			$last_space = strrpos( $ret, ' ' );

	            if ( $last_space !== FALSE && $string != $ret ) {
	                $ret     = substr( $ret, 0, $last_space );
	            }

	            return $ret;
	    }

		function handle_related_post_image($tpl,$post){
			$image_url = $this->get_related_post_image($post);

			if(!$image_url)
				$tpl = preg_replace("/<img[^>]+\>/i", "", $tpl);
			else
				$tpl = str_replace('[rimage_url]',$image_url,$tpl);


			return $tpl;
		}

		function get_related_post_image($post){
			preg_match("@<img.+?src=[\"'](.+?)[\"'].+?>@",$post->post_content,$m);

			if($m[1])
				if(filter_var($m[1], FILTER_VALIDATE_URL))
					return $m[1];
			return false;
		}

		function get_related_post( $q ) {

			$methods= $this->get_option( 'methods', 'search');

			$posts = false;
			add_filter( 'posts_where', array($this,'exclude_posts') );
			foreach ( (array)$methods as $method ) {
				$func = 'get_'.$method.'_related_posts';
				$posts = $this->$func( $q );
				if ( $posts )
					break;
			}
			remove_filter( 'posts_where', array($this,'exclude_posts'));
			if($posts){
				$post_num = $this->get_option( 'post_num', 5 );
				$posts = array_slice($posts, 0, $post_num);
			}


			return $posts;
		}

		function exclude_posts($where){
			global $wpdb;
    		return $where . " AND $wpdb->posts.ID NOT IN ( SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key = 'wpss_search_query_post')";
		}

		function get_search_related_posts( $q ) {
			$args = array(
				's' => $q
			);

			$posts = $this->_get_related_posts( $args );

			return $posts;
		}

		function get_category_related_posts() {
			if(!is_single())
				return false;
			global $post;

			$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'ids' ) );
			if(!$cats)
				return false;

			shuffle( $cats );

			$args = array();

			$posts = false;

			foreach ( $cats as $cat ) {
				$args['cat'] = $cat;
				$posts = $this->_get_related_posts( $args );
				if ( $posts )
					break;
			}

			return $posts;
		}

		function get_tag_related_posts() {
			if(!is_single())
				return false;
			global $post;

			$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );

			if(!$tags)
				return false;

			shuffle( $tags );

			$args = array();

			$posts = false;

			foreach ( $tags as $tag ) {
				$args['tag_id'] = $tag;
				$posts = $this->_get_related_posts( $args );
				if ( $posts )
					break;
			}

			return $posts;

		}

		function _get_related_posts( $args ) {
			$defaults = array(
				'post_type' => 'post',
				'post_status' => 'publish',
				'posts_per_page' => 20,
				'orderby' => 'rand',
				'no_found_rows'=>true,
			);

			if($exclude_posts = $this->get_option('exclude_posts'))
				$defaults['post__not_in'] = $exclude_posts;

			if($exclude_category = $this->get_option('exclude_category'))
				$defaults['category__not_in'] = $exclude_category;

			$args = wp_parse_args( $args, $defaults );

			$posts = false;

			$query = new WP_Query( $args );

			$query->post_count && $posts = $query->posts;

			wp_reset_query();

			if ( !$posts )
				return false;

			shuffle( $posts );

			return $posts;

		}

		function filter_search_query( $q ) {

			$blacklist = $this->get_option( 'search_query_blacklist' );


			if ( !$blacklist )
				return $q;

			foreach ( (array)$blacklist as $word ) {
				$word = trim( $word );
				if ( !$word )
					continue;

				$word = preg_quote( $word, '#' );
				$pattern = "#$word#i";

				if ( preg_match( $pattern, $q ) ){

					return true;
				}

			}

			return $q;
		}




		function get_search_query() {

			if ( !$this->is_from_search_engine() )
				return false;

			$this->parse_search_url();

			if(!$query = $this->_get_search_query())
				return false;

			if ( $this->is_search_operator( $query ) )
				return false;

			return $query;

		}

		function parse_search_url(){
			$referrer = explode( '?', $_SERVER['HTTP_REFERER'] );
			if($referrer[1])
				$this->search_url_args = wp_parse_args( $referrer[1] );
		}

		function get_keyword_position(){
			if($this->search_url_args['cd'])
				return absint($this->search_url_args['cd']);
			return false;
		}


		function _get_search_query() {

			if($this->search_url_args['q'])
				return urldecode($this->search_url_args['q']);
			return false;
		}

		function is_from_search_engine() {

			if ( !$_SERVER['HTTP_REFERER'] )return false;
			$host=parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST );

			$search_egnines = array( '.bing.com', 'google.', '.yahoo.', '.ask.' );

			foreach ( $search_egnines as $search_egnine ) {
				if ( stripos( $host, $search_egnine ) !== false )
					return true;
			}

			return false;

		}

		function is_search_operator( $query ) {
			$search_operators = $this->get_search_operators();

			foreach ( $search_operators as $search_operator ) {
				if ( stripos( $query, $search_operator ) !== false )
					return true;
			}
			return false;
		}

		function get_search_operators() {
			$siteurl = str_replace( array( 'http://', 'https://' ), '', get_option( 'siteurl' ) );
			return array(
				$siteurl,
				'allinanchor:',
				'allintext:',
				'allintitle:',
				'allinurl:',
				'author:',
				'cache:',
				'define:',
				'ext:',
				'filetype:',
				'group:',
				'id:',
				'inanchor:',
				'info:',
				'insubject:',
				'intext:',
				'intitle:',
				'inurl:',
				'link:',
				'location:',
				'related:',
				'site:',
				'source:',
			);

		}

		function add_menu_pages() {

			add_menu_page( 'WP SEO Supercharger', 'WP SEO Supercharger', 'manage_options', 'wp_seo_supercharger_settings_page',  array( $this, 'settings_page' ) );

			$menu=add_submenu_page( 'wp_seo_supercharger_settings_page', 'Settings Page', 'Settings Page', 'manage_options', 'wp_seo_supercharger_settings_page',  array( $this, 'settings_page' ) );

			$this->menus[]=$menu;

			add_action( "admin_action_wp_seo_supercharger_settings_page", array($this, 'settings_page') );

			add_action( "admin_print_scripts-$menu", array($this, 'js_scripts') );

			add_action( "admin_print_styles-$menu", array($this, 'css_styles') );

		}


		function js_scripts() {

			wp_enqueue_script( 'wp_seo_supercharger_js', $this->plugin_url.'/static/wp_seo_supercharger.js', array( 'jquery', 'jquery-ui-dialog', 'jquery-ui-tabs' ) );
		}

		function css_styles() {
			wp_enqueue_style( 'wp_seo_supercharger_style', $this->plugin_url.'/static/wp_seo_supercharger.css' );
		}

		function install() {

			$o = $this->get_option();

			if ( !$o['version'] || $o['version'] != $this->version ) {
				$o = array(
					'version'=>$this->version,
					'search_query_blacklist'=>$o['search_query_blacklist']?$o['search_query_blacklist']:array( 'porn', 'sex' ),
					'email_notification'=>isset( $o['email_notification'] )?$o['email_notification']:false,
					'email'=>$o['email']?$o['email']:get_bloginfo( 'admin_email' ),
					'methods'=>$o['methods']?$o['methods']:array( 'search', 'category', 'tag' ),
					'post_num'=>$o['post_num']?$o['post_num']:5,
					'exclude_posts'=>$o['exclude_posts']?$o['exclude_posts']:array(),
					'post_template'=>$o['post_template']?$o['post_template']:'<h2>[rpost_title]</h2>
<p><img style="width:128px;float:left;margin:0 10px 10px 0;" src="[rimage_url]" />[rpost_content:200]...<a href="[rpost_link]">Continue reading</a></p>
<hr style="clear:both"></hr>',
					'post_title'=>$o['post_title']?$o['post_title']:'[search_query]',
					'post_status'=>$o['post_status']?$o['post_status']:'draft',
					'redirect_to_new'=>isset( $o['redirect_to_new'] )?$o['redirect_to_new']:false,
					'allow_duplicate_post'=>isset( $o['allow_duplicate_post'] )?$o['allow_duplicate_post']:false,
					'exclude_category'=>$o['exclude_category']?$o['exclude_category']:array(),
					'post_category'=>$o['post_category']?$o['post_category']:get_option('default_category')
				);
				$this->update_option( $o );
			}


		}

		function uninstall() {
			global $wpdb;
			foreach ( $this->db as $table )
				$wpdb->query(  "DROP TABLE {$table}" );
			delete_option( $this->option_name );
		}


		function get_option( $name='',$default='' ) {

			if ( empty( $this->options ) ) {

				$options = get_option( $this->option_name );

			}else {

				$options = $this->options;
			}
			if ( !$options ) return false;
			if ( $name ){
				if($value = $options[$name])
					return $value;
				return $default;
			}

			return $options;
		}

		function update_option( $ops ) {

			if ( is_array( $ops ) ) {

				$options = $this->get_option();

				foreach ( $ops as $key => $value ) {

					$options[$key] = $value;

				}
				update_option( $this->option_name, $options );
				$this->options = $options;
			}


		}

		function save_post_settings() {
			$o = $this->get_option();
			$supproted_methods = array( 'search', 'category', 'tag' );
			if($_POST['methods'])
				$methods = explode( ',', wp_strip_all_tags( $_POST['methods'] ) );
			else
				$methods = array('search');
			$o['methods'] = array_intersect( $supproted_methods, $methods );
			$o['post_num'] = absint( $_POST['post_num'] );
			if ( !$o['post_num'] )
				$o['post_num'] = 5;

			if($_POST['exclude_posts'])
				$o['exclude_posts'] = explode( ',', wp_strip_all_tags( $_POST['exclude_posts'] ) );
			else
				$o['exclude_posts'] = array();

			if($_POST['exclude_category'])
				$o['exclude_category'] = array_map('absint', $_POST['exclude_category']);
			else
				$o['exclude_category'] = array();

			$o['post_template'] = stripslashes_deep( wp_filter_post_kses( $_POST['post_template'] ) );
			$o['post_title'] = wp_strip_all_tags( $_POST['post_title'] );
			$o['post_category'] = absint( $_POST['post_category'] );
			$o['post_status'] = $_POST['post_status'] == 'publish'?'publish':'draft';
			$o['allow_duplicate_post'] = $_POST['allow_duplicate_post']?true:false;
			$o['redirect_to_new'] = $_POST['redirect_to_new']?true:false;
			$this->update_option( $o );
		}

		function save_general_settings() {

			$o = $this->get_option();
			$o['search_query_blacklist'] = explode( ',', wp_strip_all_tags( $_POST['search_query_blacklist'] ) );
			$o['email_notification'] = $_POST['email_notification']?true:false;
			$o['email'] = wp_strip_all_tags( $_POST['email'] );
			$this->update_option( $o );


		}


		function settings_page() {

			$o = $this->get_option();
			if ( wp_verify_nonce( $_POST['wp_seo_supercharger_settings_page'], 'wp_seo_supercharger_settings_page' ) ) {


				if ( $_POST['save_post_settings'] ) {
					$this->save_post_settings();
					$tab='post-settings';
				}elseif ( $_POST['save_general_settings'] ) {
					$this->save_general_settings();
				}

				$this->redirect_to_current_page( );
			}
			extract( $o );
			@include $this->plugin_path.'/wp_seo_supercharger_settings_page.php';
		}


		function print_exclude_category($selected=array()){
			$cats = $this->get_categories();
			foreach($cats as $i => $cat){

				echo '<input type="checkbox" name="exclude_category[]" value="'.$cat->term_id.'"';

				if($selected && in_array($cat->term_id, $selected))
					echo ' checked="checked"';

				echo ' />'.$cat->name;
				echo '&nbsp;&nbsp;&nbsp;';

			}

		}

		function print_post_category($selected=''){
			$cats = $this->get_categories();
			echo '<select name="post_category">';
			foreach($cats as $i => $cat){

				echo '<option value="'.$cat->term_id.'"';
				if($cat->term_id == $selected)
					echo ' selected';
				echo '>'.$cat->name.'</option>';
			}
			echo '</select>';

		}


		function get_categories(){
			$args = array( 'orderby' => 'name','order'=>'ASC','hide_empty' => false);
			return get_terms('category',$args);

		}

		function error_log($msg){
			$_SESSION[$this->plugin_name]['error'] = $msg;
			return false;
		}

		function get_error(){
			$error = $_SESSION[$this->plugin_name]['error'];
			$_SESSION[$this->plugin_name]['error'] = '';
			return $error;
		}

		function redirect_to_current_page(){

			$this->set_redirect_flag();
			$callers= debug_backtrace();
			$page = $this->plugin_name.'_'.$callers[1]['function'];
			$this->redirect_to_page(self_admin_url('admin.php?page='.$page));
		}

		function redirect_to_page($redir){
			wp_redirect($redir);
			exit;
		}

		function is_redirected(){
			if($_SESSION[$this->plugin_name]['redirect']){
				$_SESSION[$this->plugin_name]['redirect'] = false;
				return true;
			}
			return false;
		}

		function set_redirect_flag(){
			$_SESSION[$this->plugin_name]['redirect'] = true;
		}



	}


}

if ( !isset( $WPSEOSupercharger ) ) {
	$WPSEOSupercharger = new WPSEOSupercharger();
}
?>
