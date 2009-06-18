<?php
/*
Plugin Name: CF Global Posts 
Plugin URI:  
Description: Generates a 'shadow blog' where posts mu-install-wide are conglomorated into one posts table for data compilation and retrieval 
Version: 1.1 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

/* Defining Shadow Blog's Site ID */
define('CFGP_SITE_ID', 999999);


// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

if (is_file(trailingslashit(ABSPATH.PLUGINDIR).basename(__FILE__))) {
	define('CFGP_FILE', trailingslashit(ABSPATH.PLUGINDIR).basename(__FILE__));
}
else if (is_file(dirname(__FILE__).'/'.basename(__FILE__))) {
	define('CFGP_FILE', dirname(__FILE__).'/'.basename(__FILE__));
}

load_plugin_textdomain('cf-global-posts');


/*************************
* Installation Functions *
*************************/
function cfgp_install() {
	/* Make domain a subdomain to example.com so there's 
	* 	no possible way to navigate to it from admin or
	* 	front-end */
	$domain = 'cf-global-posts.example.com';
	$path = '/';
	if (!domain_exists($domain, $path, $site)) {
		$new_blog_id = create_empty_blog( $domain, $path, 'CF Global Posts Blog', CFGP_SITE_ID );

		/* Store the shadow blog's id for future reference */
		update_site_option('cfgp_blog_id', $new_blog_id);
		
		/* Make the blog private */
		update_blog_status( $new_blog_id, 'public', 0 );
	}
	else {
		error_log('domain does exists');
	}
}




/**************************
* Post Updating Functions *
**************************/
function cfgp_remove_post_save_actions() {
	remove_action('save_post', 'cfgp_clone_post_on_publish'); // If you remove this the world will stop (it goes into an infinite loop if this isn't here)
	remove_action('publish_post', '_publish_post_hook', 5, 1); // This *does* require the '5', '1' parameters
}
function cfgp_add_post_save_actions() {
	add_action('publish_post', '_publish_post_hook', 5, 1);
	add_action('save_post', 'cfgp_clone_post_on_publish', 10, 2);
}
function cfgp_get_shadow_blog_id() {
	/* We have to switch to the Shadow Blog's Site ID */
	switch_to_site(CFGP_SITE_ID);

	/* Get the shadow blog's id */
	$cfgp_blog_id = get_site_option('cfgp_blog_id');

	restore_current_site(); 
	
	return $cfgp_blog_id;
}
function cfgp_are_we_inserting($post_id) {
	/* Grab the clone's id */
	return get_post_meta($post_id, '_cfgp_clone_id', true);
}
function cfgp_do_the_post($post, $clone_post_id) {
	cfgp_remove_post_save_actions();
	if ($clone_post_id == '') {
		/* INSERTING NEW */
		/* This post has not yet been cloned,
		* 	time to insert the clone post into shadow blog */
	
		/* remove the original post_id so we can create the clone */
		unset($post->ID);

		$clone_id = wp_insert_post($post);
	}
	else {
		/* UPDATING */
		/* This will be updating the clone's post with the 
		* 	post_id from the original blog's post's post_meta */
		$post->ID = $clone_post_id;
		$clone_id = wp_update_post($post);
	}
	cfgp_add_post_save_actions();
	return $clone_id; 
}
function cfgp_do_categories($clone_id, $cur_cats_names) {
	/* $cur_cats_names should be an array of category names only */
	
	if (!function_exists('wp_create_categories')) {
		/* INCLUDE ALL ADMIN FUNCTIONS */
		require_once(ABSPATH . 'wp-admin/includes/admin.php');
	}
	/* This function creates the cats if they don't exist, and 
	* 	then assigns them to the post ID that's passed. */ 	
	$cats_results = wp_create_categories($cur_cats_names, $clone_id);

	if (is_array($cats_results) && !empty($cats_results)) {
		return true;
	}
	else {
		return false;
	}
}
function cfgp_do_tags($clone_id, $tags) {
	/* $tags should a comma-seperated string of tags */
	
	/* Add or remove tags as needed.  We aren't
	* 	doing checking, b/c WP does it for us */
	
	$result = wp_set_post_tags($clone_id, $tags);
	if ($result === false) {
		return false;
	}
	else {
		return true;
	}
}
function _cfgp_push_all_post_meta($all_post_meta, $clone_id) {
	/* We should already be switched to blog!! */
	if (!is_array($all_post_meta)) {
		/* Require an array */
		return false;
	}
	
	$excluded_values = array(
		'_edit_last',
		'_edit_lock',
		'_encloseme',
		'_pingme',
		'_cfgp_clone_id'
	);
	$excluded_values = apply_filters('cfgp_exluded_post_meta_values', $excluded_values);
	foreach ($all_post_meta as $key => $value) {
		if (in_array($key, $excluded_values)) { 
			/* we don't need to update that key */
			continue; 
		}

		if (is_array($value) && count($value) > 1) {
			/* The original value was an array, so store it as such */
			$results[$key] = update_post_meta($clone_id, $key, $value);
		}
		else {
			/* The original value wasn't an array, so store it as $value's first value */
			$results[$key] = update_post_meta($clone_id, $key, $value[0]);
		}
	}
	return $results;
}
function cfgp_do_post_meta($clone_id, $original_blog_id, $all_post_meta) {
	global $wpdb;
	
	/* Now add all post_meta to clone post */
	$results = _cfgp_push_all_post_meta($all_post_meta, $clone_id);
	
	/* Add the original blog's id to the clone's post meta */
	$results['_cfgp_original_blog_id'] = update_post_meta($clone_id, '_cfgp_original_blog_id', $original_blog_id);
	
	return $results;
}



/***************************
* Functions called from WP *
***************************/
function cfgp_clone_post_on_publish($post_id, $post) {
	global $wpdb;
	
	/* If it's a draft, get the heck out of dodge */
	if ($post->post_status == 'draft') { return; }
	
	/* This is a revision, not something that needs to get cloned */
	if ($post->post_status == 'inherit') { return; }

	/* Get the Shadow Blog's ID */
	$cfgp_blog_id = cfgp_get_shadow_blog_id();
	
	/* Get the current blog's id */
	$current_blog_id = $wpdb->blogid;
	
	/* Check to see if we're inserting the post, or updating an existing */
	$clone_post_id = cfgp_are_we_inserting($post->ID);
	
	/* Get all the post_meta for current post */
	$all_post_meta = get_post_custom($post->ID);

	switch_to_blog($cfgp_blog_id);
	
	/************
	* POST WORK *
	************/
	$old_post_id = $post->ID;
	$clone_id = cfgp_do_the_post($post,$clone_post_id);
	$post->ID = $old_post_id;
	

	/****************
	* CATEGORY WORK *
	****************/
	/* Grab category names that the current post belongs to. */
	if (isset($_POST['post_category']) && is_array($_POST['post_category']) && count($_POST['post_category']) > 0) {
		/* Post has categories */
		$cur_cats = $_POST['post_category'];
	}
	else {
		/* Post doesn't have any categories, assign to 'Uncategorized' */
		$cur_cats = array( get_cat_ID('Uncategorized') );
	}
	/* We have id's, now get the names */
	foreach ($cur_cats as $cat) {
		$cur_cats_names[] = get_catname( $cat );	
	}
	$cat_results = cfgp_do_categories($clone_id, $cur_cats_names);


	/***********
	* TAG WORK *
	***********/
	$tags = $_POST['tags_input'];
	$tag_results = cfgp_do_tags($clone_id, $tags);

	/*****************
	* POST META WORK *
	*****************/
	$post_meta_results = cfgp_do_post_meta($clone_id, $current_blog_id, $all_post_meta);

	restore_current_blog();

	/* first add post_meta to the original 
	* 	post of the clone's post id */
	update_post_meta($post->ID, '_cfgp_clone_id', $clone_id);

	/* This is a handy array of results, for troubleshooting
	* 	they're not returned on post publish, but can be put
	* 	out to the error log */
	$single_post_results[] = array(
		'original_post' => $post->ID,
		'clone_id' => $clone_id,
		'cat_results' => $cat_results, 
		'tag_results' => $tag_results, 
		'post_meta_results' => $post_meta_results
	);
}
add_action('save_post', 'cfgp_clone_post_on_publish', 10, 2);

function cfgp_batch_import_blog($blog_id, $offset, $increment) {
	switch_to_blog($blog_id);
	
	// Get the shadow blog ID
	$cfgp_blog_id = cfgp_get_shadow_blog_id();

	/* http://codex.wordpress.org/Template_Tags/query_posts#Offset_Parameter */
	$args = array(
		'offset' => $offset,
		'showposts' => $increment
	);

	/* Grab posts */
	query_posts($args);
	
	if (have_posts()) {
		global $post;

		// Setup a global variable for handling
		$posts = array();

		$batch_status = 'running';
		while (have_posts()) {
			/************
			* POST WORK *
			************/
			/* Setup post data */
			the_post(); 

			/* Get the category names into array */
			$categories = get_the_category($post->ID);
			
			/* Get the tag information */
			$tags = get_the_tags($post->ID);
			
			/* Get all the post_meta for current post */
			$all_post_meta = get_post_custom($post->ID);
			
			/* Check to see if we're inserting the post, or updating an existing */
			$clone_post_id = cfgp_are_we_inserting($post->ID);
			
			// Gather all of the info to be processed into one place
			$posts[$post->ID]['post'] = $post;
			$posts[$post->ID]['categories'] = $categories;
			$posts[$post->ID]['tags'] = $tags;
			$posts[$post->ID]['post_meta'] = $all_post_meta;
			$posts[$post->ID]['clone_post_id'] = $clone_post_id;
		}
		
		// Gather the clone ids into this array
		$clone_info = array();
		$post = '';
		
		switch_to_blog($cfgp_blog_id);
		foreach ($posts as $post) {
			$clone_post_id = $post['clone_post_id'];
			$the_post = $post['post'];
			$categories = $post['categories'];
			$tags = $post['tags'];
			$post_meta = $post['post_meta'];
			
			/************
			* POST WORK *
			************/
			$old_post_id = $post['post']->ID;
			$clone_id = cfgp_do_the_post($the_post,$clone_post_id);


			/****************
			* CATEGORY WORK *
			****************/
			
			if (is_array($categories)) {
				$cur_cat_names = array();
				foreach ($categories as $cat) {
					$cur_cats_names[] = $cat->name;
				}
				$cat_results = cfgp_do_categories($clone_id, $cur_cats_names);
			}

			/***********
			* TAG WORK *
			***********/

			if (is_array($tags)) {
				foreach ($tags as $tag) {
					$tag_names[] = $tag->name;
				}
				$tag_name_string = implode(', ', $tag_names);
				$tag_results = cfgp_do_tags($clone_id, $tag_name_string);
			}

			/*****************
			* POST META WORK *
			*****************/
			$post_meta_results = cfgp_do_post_meta($clone_id, $blog_id, $post_meta);
			
			$clone_info[] = array(
				'post_id' => $old_post_id,
				'clone_id' => $clone_id
			);
			
			/* Add the return values for this post */
			$single_post_results[] = array(
				'original_post' => $old_post_id,
				'clone_id' => $clone_id,
				'cat_results' => $cat_results, 
				'tag_results' => $tag_results, 
				'post_meta_results' => $post_meta_results
			);
		}
		restore_current_blog();
		
		foreach ($clone_info as $clone) {
			/* Finally add post_meta to the original 
			* 	post of the clone's post id */
			update_post_meta($clone['post_id'], '_cfgp_clone_id', $clone['clone_id']);
		}
	}
	else {
		$batch_status = 'finished';
	}

	$results = array(
		'status' => $batch_status, 
		'blog' => $blog_id, 
		'posts' => $my_posts, 
		'result_details' => $single_post_results,
		'next_offset' => ($offset + $increment),
	);
	restore_current_blog();
	return $results;
}
function cfgp_do_delete_post($cfgp_clone_id) {
	/* remove the delete action, so not to infinite loop */
	remove_action('delete_post', 'cfgp_delete_post_from_global');
	
	/* actually delete the clone post */
	$delete_results = wp_delete_post($cfgp_clone_id);
	
	/* put action back */
	add_action('delete_post', 'cfgp_delete_post_from_global');
	
	return $delete_results;
}
function cfgp_delete_post_from_global($post_id) {
	/* grab shadow blog's post id */
	$cfgp_clone_id = get_post_meta($post_id, '_cfgp_clone_id', true);
	
	/* grab right blog id */
	$cfgp_blog_id = cfgp_get_shadow_blog_id();
	
	/* switch to blog */
	switch_to_blog($cfgp_blog_id);
	
	/* do some wp_delete_post on that blog */
	$delete_result = cfgp_do_delete_post($cfgp_clone_id);
	
	restore_current_blog();
}
add_action('delete_post', 'cfgp_delete_post_from_global');




function cfgp_flush_blog_data_from_shadow($blog_id) {
	global $wpdb;
	
	/* Grab all the clone id's for the related posts from 
	* 	the incomming blog */
	$sql = '
		SELECT 
			post_id AS original_id, 
			meta_value AS clone_id
		FROM 
			wp_'.$blog_id.'_postmeta
		WHERE
			meta_key = "_cfgp_clone_id"
	';
	$post_clone_mashup = $wpdb->get_results($sql, 'ARRAY_A');
	
	/* Loop through all those clone id's and delete the 
	* 	clone'd post with cfgp_do_delete_post function */
	if (is_array($post_clone_mashup) && count($post_clone_mashup) > 0) {
		$delete_result = array();
		$cfgp_blog_id = cfgp_get_shadow_blog_id();		
		switch_to_blog($cfgp_blog_id);
		foreach ($post_clone_mashup as $row) {
			$delete_result[$row['original_id']] = cfgp_do_delete_post($row['clone_id']);
		}
		restore_current_blog();
	}
	
	

	/* Erase all the post_meta records, relating to the 
	* 	shadow blog, from the incomming blog */
	$sql = '
		DELETE FROM 
			wp_'.$blog_id.'_postmeta
		WHERE
			meta_key = "_cfgp_clone_id"
	';
	$delete_postmeta_results = $wpdb->query($sql);
	
	if (count($delete_result) == $delete_postmeta_results) {
		error_log('SUCCESS!'."\n".'They both removed the same '.$delete_postmeta_results.' records');
	}
	else {
		error_log('FAIL'."\n".'posts_deleted: '.count($delete_result)."\n".'meta_deleted: '.$delete_postmeta_results);
	}
}



function cfgp_is_installed() {
	$cfgp_blog_id = cfgp_get_shadow_blog_id();
	if (empty($cfgp_blog_id)) {
		return false;
	}
	return true;
}
function cfgp_request_handler() {
	if (!empty($_GET['cf_action'])) {
		switch ($_GET['cf_action']) {
			case 'cfgp_admin_js':
				cfgp_admin_js();
				die();
				break;
		}
	}
	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {

			case 'cfgp_update_settings':
				cfgp_save_settings();
				wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page='.basename(__FILE__).'&updated=true');
				die();
				break;
				
			case 'add_blog_to_shadow_blog':
				set_time_limit(0);
				/* Set how many blog posts to do at once */
				$increment = 5;
				
				/* Grab the ID of the blog we're pulling from */
				$blog_id =  (int) $_POST['blog_id'];
				
				/* Grab our offset */
				$offset = (int) $_POST['offset'];
				
				/* Check if we're doing the first batch, if so, flush the 
				* 	incoming's blog data from shadow blog, so we can start fresh */
				if ($offset == 0) {
					cfgp_flush_blog_data_from_shadow($blog_id);
				}
				
				/* Admin page won't let somebody into this functionality,
				* 	but in case someone hacks the url, don't try to do
				* 	the import w/o the cf-compat plugin */
				if (!function_exists('cf_json_encode')) { exit(); }
				
				echo cf_json_encode( cfgp_batch_import_blog( $blog_id, $offset, $increment ) );
				
				exit();
				break;
			case 'cfgp_setup_shadow_blog':
				cfgp_install();
				/* We don't want to exit, b/c we want the page to refresh */
				break;
		}
	}
}
add_action('init', 'cfgp_request_handler');


wp_enqueue_script('jquery');


function cfgp_operations_form() {
	global $wpdb;
	?>
	<style type="text/css">
		.cfgp_status {
			vertical-align:middle;
			text-align:center;
		}
	</style>
	<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php echo __('CF Global Posts Operations', 'cf-global-posts'); ?></h2>
		<?php
		if (!function_exists('cf_json_encode')) {
			?>
			<p><?php _e('This plugin requires functionality contained in the \'cf-compat\' plugin.  This plugin must be activated before utilizing this page.', 'cf-global-posts'); ?></p>
			<?php
			return;
		}
		else {
			if (!cfgp_is_installed()) {
				?>
				<h3><?php _e('Global Blog has not been setup','cf-global-posts'); ?></h3>
				<h4><?php _e('Click the button below to set up the Global Blog', 'cf-global-posts'); ?></h4>
				<form method="post">
					<input type="hidden" name="cf_action" value="cfgp_setup_shadow_blog" />
					<button class="button-primary" type="submit"><?php _e('Setup Global Blog Now', 'cf-global-posts'); ?></button>
				</form>
				<?php
			}
			else {
				?>
				<div id="doing-import" style="border: 1px solid #464646; margin: 20px 0; padding: 10px 20px;">
					<h3></h3>
					<p id="import-ticks"></p>
				</div>
				<table class="widefat" style="width: 450px; margin: 20px 0">
					<thead>
						<tr>
							<th scope="col"><?php _e('Blog Name', 'cf-global-posts'); ?></th>
							<th scope="col" style="width: 50px; text-align:center;"><?php _e('Action', 'cf-global-posts'); ?></th>
							<th scope="col" style="width: 150px; text-align:center;"><?php _e('Status', 'cf-global-posts'); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$shadow_blog = get_site_option('cfgp_blog_id');
					$blog_ids = array();
					$sql = 'SELECT * FROM '.$wpdb->blogs.' ORDER BY site_id, blog_id';
		
					$results = $wpdb->get_results($sql);
					if (is_array($results)) {
						foreach ($results as $blog) {
							if ($blog->blog_id == $shadow_blog) { continue; }
							$details = get_blog_details($blog->blog_id);
							$blog_ids[] = $blog->blog_id;
							?>
							<tr id="blogrow-<?php echo $blog->blog_id; ?>">
								<td style="vertical-align:middle;"><?php echo $details->blogname; ?></th>
								<td>
									<form method="post" name="blog_import_<?php echo attribute_escape($blog->blog_id); ?>" id="blog_import_<?php echo attribute_escape($blog->blog_id); ?>">
									<input type="hidden" name="blog_id" value="<?php echo attribute_escape($blog->blog_id); ?>" />
									<input type="hidden" name="cf_action" value="add_blog_to_shadow_blog">
									<button class="button" id="start_import_blog_<?php echo attribute_escape($blog->blog_id); ?>"/><?php _e('Import', 'cf-global-posts'); ?></button>
									</form>
								</td>
								<td class="cfgp_status" style="vertical-align:middle;">
									<div id="status-<?php echo $blog->blog_id; ?>">
										<?php _e('Click Import to proceed', 'cf-global-posts'); ?>
									</div>
								</td>
							</tr>
							<?php
						}
						?>
						<tr>
							<td colspan="3">
								<input type="hidden" id="all_blog_ids" name="all_blog_ids" value="<?php echo implode(',',$blog_ids); ?>" />
								<button class="button-primary" id="start_import_all_blogs"><?php _e('Import All','cf-global-posts'); ?></button>
							</td>
						</tr>
						<?php
					}
					else {
						_e('No Blogs available', 'cf-global-posts');
					}
					?>
					</tbody>
				</table>
			<?php
			}
		}
		?>
	</div><!--/wrap-->
	<?php
}

function cfgp_admin_js() {
	$wpserver = get_bloginfo('url');
	if(strpos($_SERVER['SERVER_NAME'],'www.') !== false && strpos($wpserver,'www.') === false) {
		$wpserver = str_replace('http://','http://www.',$wpserver);
	}
	
	header('Content-type: text/javascript');
	?>
	jQuery(function($) {
		var ajaxSpinner = '<div class="ajax-spinner"><img src="images/loading.gif" style="margin: 0 6px 0 0; vertical-align: middle" /> <span class="ajax-loading"><?php _e('Processing...','cf-global-posts'); ?></span></div>';
		var ajaxComplete = 'Complete!';
		var originalBGColortr = jQuery("#blogrow-1");
		var originalBGColor = originalBGColortr.children("td:first").css("backgroundColor");
		import_box = $("#doing-import");
		import_box.hide();
	
		import_buttons = $("button[id^='start_import_blog_']");
		import_all_button = $("button[id^='start_import_all_blogs']");
	
		import_buttons.click(function(){
			//$(document).scrollTop(0);
			blogId = $(this).siblings("input[name='blog_id']").val();
			import_buttons.attr('disabled','disabled');
			var start_tr = jQuery("#blogrow-"+blogId);
			start_tr.children("td").css({backgroundColor:"#FAEDC3"});
			jQuery('#status-'+blogId).html(ajaxSpinner);
			do_batch(blogId, 0);
			//import_box.show().removeClass('updated fade').children('h3').text('Import in progress, do not navigate away from this page...').siblings("#import-ticks").text('#');
			return false;
		});

		import_all_button.click(function() {
			import_buttons.attr('disabled','disabled');
			blogIds = $("#all_blog_ids").val().split(',');
			for (var i in blogIds) {
				var blogId = blogIds[i];
				var start_tr = jQuery("#blogrow-"+blogId);
				start_tr.children("td").css({backgroundColor:"#FAEDC3"});
				jQuery('#status-'+blogId).html(ajaxSpinner);
				do_batch(blogId, 0);
			}
			return false;
		});

		function do_batch(blogId, offset_amount) {
			$.post(
				'index.php',
				{
					cf_action:'add_blog_to_shadow_blog',
					blog_id: blogId,
					offset: offset_amount
				},
				function(r){
					if (r.status == 'finished') {
						//import_box.addClass('updated fade').children('h3').text('Finished Importing!').siblings("#import-ticks").text('');
						import_buttons.removeAttr('disabled');
						var finished_tr = jQuery("#blogrow-"+blogId);
						finished_tr.children("td").css({backgroundColor:originalBGColor});				
						jQuery('#status-'+blogId).html(ajaxComplete);
						return;
					}
					else {
						//import_box.children("#import-ticks").text(import_box.children("#import-ticks").text()+' # ');
						do_batch(blogId, r.next_offset);
					}
				},
				'json'
			);
		}
	});	<?php
	die();
}

function cfgp_admin_head() {
	echo '<script type="text/javascript" src="'.trailingslashit(get_bloginfo('wpurl')).'index.php?cf_action=cfgp_admin_js"></script>';
}
if (isset($_GET['page']) && $_GET['page'] == basename(__FILE__)) {
	add_action('admin_head', 'cfgp_admin_head');
}

function cfgp_admin_menu() {
	global $wpdb;
	
	// force this to be only visible to site admins
	if (!is_site_admin()) { return; }
	
	if (current_user_can('manage_options')) {
		add_options_page(
			__('CF Global Posts Functions', '')
			, __('CF Global Posts', '')
			, 10
			, basename(__FILE__)
			, 'cfgp_operations_form'
		);
	}
}
add_action('admin_menu', 'cfgp_admin_menu');







/***********************************************
* SWITCH TO SITE FUNCTIONS                     *
* (brought from the "MU Multi-Site" plugin)    *
* "MU Multi-Site" Plugin Credited Here:        *
* 	author: David Dean                         *
* 	http://www.jerseyconnect.net/              *
***********************************************/

if(!function_exists('switch_to_site')) {

	/**
	 * Problem: the various *_site_options() functions operate only on the current site
	 * Workaround: change the current site
	 * @param integer $new_site ID of site to manipulate
	 */
	function switch_to_site($new_site) {
		global $tmpoldsitedetails, $wpdb, $site_id, $switched_site, $switched_site_stack, $current_site, $sites;

		if ( !site_exists($new_site) )
			$new_site = $site_id;

		if ( empty($switched_site_stack) )
			$switched_site_stack = array();

		$switched_site_stack[] = $site_id;

		if ( $new_site == $site_id )
			return;

		// backup
		$tmpoldsitedetails[ 'site_id' ] 	= $site_id;
		$tmpoldsitedetails[ 'id']			= $current_site->id;
		$tmpoldsitedetails[ 'domain' ]		= $current_site->domain;
		$tmpoldsitedetails[ 'path' ]		= $current_site->path;
		$tmpoldsitedetails[ 'site_name' ]	= $current_site->site_name;

		
		foreach($sites as $site) {
			if($site->id == $new_site) {
				$current_site = $site;
				break;
			}
		}


		$wpdb->siteid			 = $new_site;
		$current_site->site_name = get_site_option('site_name');
		$site_id = $new_site;

		do_action('switch_site', $site_id, $tmpoldsitedetails[ 'site_id' ]);

		$switched_site = true;
	}
}

if(!function_exists('restore_current_site')) {

	/**
	 * Return to the operational site after our operations
	 */
	function restore_current_site() {
		global $tmpoldsitedetails, $wpdb, $site_id, $switched_site, $switched_site_stack;

		if ( !$switched_site )
			return;

		$site_id = array_pop($switched_site_stack);

		if ( $site_id == $current_site->id )
			return;

		// backup

		$prev_site_id = $wpdb->site_id;

		$wpdb->siteid = $site_id;
		$current_site->id = $tmpoldsitedetails[ 'id' ];
		$current_site->domain = $tmpoldsitedetails[ 'domain' ];
		$current_site->path = $tmpoldsitedetails[ 'path' ];
		$current_site->site_name = $tmpoldsitedetails[ 'site_name' ];

		unset( $tmpoldsitedetails );

		do_action('switch_site', $site_id, $prev_site_id);

		$switched_site = false;
		
	}
}
if(!function_exists('site_exists')) {

	/**
	 * Check to see if a site exists. Will check the sites object before checking the database.
	 * @param integer $site_id ID of site to verify
	 * @return boolean TRUE if found, FALSE otherwise
	 */
	function site_exists($site_id) {
		global $sites, $wpdb;
		$site_id = (int)$site_id;
		foreach($sites as $site) {
			if($site_id == $site->id) {
				return TRUE;
			}
		}
		
		/* check db just to be sure */
		$site_list = $wpdb->get_results('SELECT id FROM ' . $wpdb->site);
		if($site_list) {
			foreach($site_list as $site) {
				if($site->id == $site_id) {
					return TRUE;
				}
			}
		}
		
		return FALSE;
	}
}

/****************************************************************
* END SWITCH TO SITE FUNCTIONS used from "MU Multi-Site" Plugin *
****************************************************************/

















/********************************************************************
* Below are the default functions with the plugin, and till we get  *
* this finalize a little more, we'll leave them in until we know we *
* don't need them.                                                  *
* ******************************************************************/



function cfgp_init() {
// TODO
}
add_action('init', 'cfgp_init');







function cfgp_save_comment($comment_id) {
// TODO
}
add_action('comment_post', 'cfgp_save_comment');


/*
$example_settings = array(
	'key' => array(
		'type' => 'int',
		'label' => 'Label',
		'default' => 5,
		'help' => 'Some help text here',
	),
	'key' => array(
		'type' => 'select',
		'label' => 'Label',
		'default' => 'val',
		'help' => 'Some help text here',
		'options' => array(
			'value' => 'Display'
		),
	),
);
*/
$cfgp_settings = array(
	'cfgp_' => array(
		'type' => 'string',
		'label' => '',
		'default' => '',
		'help' => '',
	),
	'cfgp_' => array(
		'type' => 'int',
		'label' => '',
		'default' => 5,
		'help' => '',
	),
	'cfgp_' => array(
		'type' => 'select',
		'label' => '',
		'default' => '',
		'help' => '',
		'options' => array(
			'' => ''
		),
	),
	'cfgp_cat' => array(
		'type' => 'select',
		'label' => 'Category:',
		'default' => '',
		'help' => '',
		'options' => array(),
	),

);

function cfgp_setting($option) {
	$value = get_option($option);
	if (empty($value)) {
		global $cfgp_settings;
		$value = $cfgp_settings[$option]['default'];
	}
	return $value;
}



function cfgp_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if (basename($file) == $plugin_file) {
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Settings', '').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'cfgp_plugin_action_links', 10, 2);

if (!function_exists('cf_settings_field')) {
	function cf_settings_field($key, $config) {
		$option = get_option($key);
		if (empty($option) && !empty($config['default'])) {
			$option = $config['default'];
		}
		$label = '<label for="'.$key.'">'.$config['label'].'</label>';
		$help = '<span class="help">'.$config['help'].'</span>';
		switch ($config['type']) {
			case 'select':
				$output = $label.'<select name="'.$key.'" id="'.$key.'">';
				foreach ($config['options'] as $val => $display) {
					$option == $val ? $sel = ' selected="selected"' : $sel = '';
					$output .= '<option value="'.$val.'"'.$sel.'>'.htmlspecialchars($display).'</option>';
				}
				$output .= '</select>'.$help;
				break;
			case 'textarea':
				$output = $label.'<textarea name="'.$key.'" id="'.$key.'">'.htmlspecialchars($option).'</textarea>'.$help;
				break;
			case 'string':
			case 'int':
			default:
				$output = $label.'<input name="'.$key.'" id="'.$key.'" value="'.htmlspecialchars($option).'" />'.$help;
				break;
		}
		return '<div class="option">'.$output.'<div class="clear"></div></div>';
	}
}

function cfgp_settings_form() {
	global $cfgp_settings;


	$cat_options = array();
	$categories = get_categories('hide_empty=0');
	foreach ($categories as $category) {
		$cat_options[$category->term_id] = htmlspecialchars($category->name);
	}
	$cfgp_settings['cfgp_cat']['options'] = $cat_options;


	print('
<div class="wrap">
	<h2>'.__('CF Global Posts Settings', '').'</h2>
	<form id="cfgp_settings_form" name="cfgp_settings_form" action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="post">
		<input type="hidden" name="cf_action" value="cfgp_update_settings" />
		<fieldset class="options">
	');
	foreach ($cfgp_settings as $key => $config) {
		echo cf_settings_field($key, $config);
	}
	print('
		</fieldset>
		<p class="submit">
			<input type="submit" name="submit" value="'.__('Save Settings', '').'" />
		</p>
	</form>
</div>
	');
}

function cfgp_save_settings() {
	if (!current_user_can('manage_options')) {
		return;
	}
	global $cfgp_settings;
	foreach ($cfgp_settings as $key => $option) {
		$value = '';
		switch ($option['type']) {
			case 'int':
				$value = intval($_POST[$key]);
				break;
			case 'select':
				$test = stripslashes($_POST[$key]);
				if (isset($option['options'][$test])) {
					$value = $test;
				}
				break;
			case 'string':
			case 'textarea':
			default:
				$value = stripslashes($_POST[$key]);
				break;
		}
		update_option($key, $value);
	}
}

//a:21:{s:11:"plugin_name";s:15:"CF Global Posts";s:10:"plugin_uri";N;s:18:"plugin_description";s:132:"Generates a 'shadow blog' where posts mu-install-wide are conglomorated into one posts table for each data compilation and retrieval";s:14:"plugin_version";s:3:"0.1";s:6:"prefix";s:4:"cfgp";s:12:"localization";N;s:14:"settings_title";s:24:"CF Global Posts Settings";s:13:"settings_link";s:15:"CF Global Posts";s:4:"init";s:1:"1";s:7:"install";s:1:"1";s:9:"post_edit";s:1:"1";s:12:"comment_edit";s:1:"1";s:6:"jquery";s:1:"1";s:6:"wp_css";b:0;s:5:"wp_js";b:0;s:9:"admin_css";b:0;s:8:"admin_js";b:0;s:15:"request_handler";s:1:"1";s:6:"snoopy";b:0;s:11:"setting_cat";s:1:"1";s:14:"setting_author";b:0;}

?>