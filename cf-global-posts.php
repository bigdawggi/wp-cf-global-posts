<?php
/*
Plugin Name: CF Global Posts 
Description: Generates a 'shadow blog' where posts mu-install-wide are conglomorated into one posts table for fast data compilation and retrieval.
Version: 1.8 (trunk)
Requires: 3.0
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

define('CFGP_VER', '1.8 (trunk)');

load_plugin_textdomain('cf-global-posts');

/* We need to not cause a Fatal PHP Error if this is ran when multisite isn't enabled. */
if (!is_multisite()) {
	// Throw an admin_notice if we're not in Network Mode and user can manage_options
	add_action('admin_notices', create_function('',"
		if (current_user_can('manage_options')) {
			echo 
				'<div class=\"error\"><p>'
					.__('The <strong>Global Posts</strong> plugin requires WordPress to be configured as a Network.</p><p>See <a href=\"http://codex.wordpress.org/Create_A_Network\">Create A Network</a> for information on how to do this.', 'cf-global-posts')
				.'</p></div>
			';
		}
	"));
	return;
}

/* Defining Shadow Blog's Site ID */
define('CFGP_SITE_ID', apply_filters('cfgp_define_site_id', 999999));
define('CFGP_BLOG_ID', apply_filters('cfgp_define_blog_id', 19));
define('CFGP_SITE_DOMAIN', apply_filters('cfgp_define_domain_name', 'cf-global-posts.example.com'));
define('CFGP_SITE_IMPORT_INCREMENT', apply_filters('cfgp_define_import_increment', 10));

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

/*************************
* Installation Functions *
*************************/
function cfgp_install() {
	/* Make domain a subdomain to example.com so there's 
	* 	no possible way to navigate to it from admin or
	* 	front-end */
	$domain = CFGP_SITE_DOMAIN;
	$path = '/';
	if (!domain_exists($domain, $path, $site)) {
		$new_blog_id = create_empty_blog( $domain, $path, 'CF Global Posts Blog', CFGP_SITE_ID );

		/* Make the blog private */
		update_blog_status( $new_blog_id, 'public', 0 );
	}
	else {
		error_log('Domain Already Exists');
	}
}

/***************************************
* Post Information Retrieval Functions *
***************************************/
/* Return the original permalink of the clone'd post */
function cfgp_get_permalink($post_id = null) {
	if (!$post_id) {
		global $post;
		$post_id = $post->ID;
	}
	return get_post_meta($post_id, '_cfgp_original_permalink', true);
}
function cfgp_the_permalink($post_id = null) {
	echo cfgp_get_permalink($post_id);
}
function cfgp_get_home_url($path = '', $scheme = null, $post_id = null) {
	/* Figure out the post ID */
	if (!$post_id) {
		global $post;
		$post_id = $post->ID;
	}
	
	/* Get the original blog's id */
	$blog_id = get_post_meta($post_id, '_cfgp_original_blog_id', true);
	
	/* Grab the info, we can just pass in the blog id, no need to switch */
	$home_url = get_home_url($blog_id, $path, $scheme);
	return $home_url;
}
function cfgp_home_url($path = '', $scheme = null, $post_id = null) {
	echo cfgp_get_home_url($path, $scheme, $post_id);
}
function cfgp_get_bloginfo($info = '', $post_id = null) {
	/* Figure out the post ID */
	if (!$post_id) {
		global $post;
		$post_id = $post->ID;
	}
	
	/* Get the original blog's id */
	$blog_id = get_post_meta($post_id, '_cfgp_original_blog_id', true);
	
	/* Go to the blog and get the info */
	switch_to_blog($blog_id);
	$blog_info = get_bloginfo($info);
	restore_current_blog();
	return $blog_info;
}
function cfgp_bloginfo($info = '', $post_id = null) {
	echo cfgp_get_bloginfo($info, $post_id);
}
function cfgp_the_author_posts_link() {
	global $post;
	$post_id = $post->ID;
	
	/* Get the original blog's id */
	$blog_id = get_post_meta($post_id, '_cfgp_original_blog_id', true);
	
	/* Go to the blog and get the info */
	switch_to_blog($blog_id);
	the_author_posts_link();
	restore_current_blog();
}
function cfgp_comments_popup_link($zero = false, $one = false, $more = false, $css_class = '', $none = false) {
	global $post;
	$post_id = $post->ID;
	
	/* Get the original blog's id */
	$blog_id = get_post_meta($post_id, '_cfgp_original_blog_id', true);
	
	/* Go to the blog and get the info */
	switch_to_blog($blog_id);
	comments_popup_link($zero, $one, $more, $css_class, $none);
	restore_current_blog();
}
function cfgp_edit_post_link($link = 'Edit This', $before = '', $after = '', $id = 0) {
	global $post;
	$post_id = $post->ID;
	
	/* Get the original blog's id */
	$blog_id = get_post_meta($post_id, '_cfgp_original_blog_id', true);
	
	/* Get the original post's id */
	$original_post_id = get_post_meta($post_id, '_cfgp_original_post_id', true);
	
	/* Go to the blog and get the info */
	switch_to_blog($blog_id);
	edit_post_link($link, $before, $after, $original_post_id);
	restore_current_blog();
}

/**************************
* Post Updating Functions *
**************************/
function cfgp_remove_post_save_actions() {
		remove_action('publish_post', '_publish_post_hook', 5, 1); // This *does* require the '5', '1' parameters
		remove_action('save_post', 'cfgp_clone_post_on_publish', 99999999, 2);

		global $wp_filter,$cf_wp_filter;

		$cf_wp_filter = $wp_filter;
		$wp_filter = array();
}
function cfgp_add_post_save_actions() {
		add_action('publish_post', '_publish_post_hook', 5, 1);
		add_action('save_post', 'cfgp_clone_post_on_publish', 99999999, 2);

		global $wp_filter,$cf_wp_filter;
		$wp_filter = $cf_wp_filter;
}
/**
 * cfgp_get_shadow_blog_id
 *
 * "get_blog_id_from_url" function may return a zero, so we want to force a bool false if that's the case.  Otherwise return the blog id.
 *
 * @return bool/int
 */
function cfgp_get_shadow_blog_id() {
	/* Allow an override here */
	if ( defined( 'CFGP_BLOG_ID' ) && CFGP_BLOG_ID )
	{
		return CFGP_BLOG_ID;
	}

	/* Utilize the domain to get the blog id */
	$cfgp_blog_id = get_blog_id_from_url(CFGP_SITE_DOMAIN);
	
	return ($cfgp_blog_id === 0) ? false : $cfgp_blog_id;
}
function cfgp_are_we_inserting($post_id) {
	/* Grab the clone's id */
	return get_post_meta($post_id, '_cfgp_clone_id', true);
}
function cfgp_do_the_post($post, $clone_post_id) {
	/* Remove actions, so we don't have an infinite loop */
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
	
	/* Add our save actions back in */
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
function cfgp_push_all_post_meta(
	$results,
	$clone_id,
	$original_blog_id,
	$all_post_meta,
	$permalink,
	$original_post_id
	) {

	/* We should already be switched to blog!! */
	if (!is_array($all_post_meta)) {
		/* Require an array */
		return false;
	}
	
	$excluded_values = apply_filters('cfgp_exluded_post_meta_values', array(
		'_edit_last',
		'_edit_lock',
		'_encloseme',
		'_pingme',
		'_cfgp_clone_id'
	));
	
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
add_filter('cfgp_do_post_meta', 'cfgp_push_all_post_meta', null, 6);

function cfgp_do_global_blog_post_meta(
	$results,
	$clone_id,
	$original_blog_id,
	$all_post_meta,
	$permalink,
	$original_post_id
	) {
	
	/* Add the original blog's id to the clone's post meta */
	$results['_cfgp_original_blog_id'] = update_post_meta($clone_id, '_cfgp_original_blog_id', $original_blog_id);
	
	/* Add the original blog post's permalink for an easy way back */
	$results['_cfgp_original_permalink'] = update_post_meta($clone_id, '_cfgp_original_permalink', $permalink);
	
	/* Add the original blog posts's id for an easy way to reference that */
	$results['_cfgp_original_post_id'] = update_post_meta($clone_id, '_cfgp_original_post_id', $original_post_id);
	
	return $results;
}
add_filter('cfgp_do_post_meta', 'cfgp_do_global_blog_post_meta', null, 6);

/***************************
* Functions called from WP *
***************************/
function cfgp_clone_post_on_publish($post_id, $post) {
	global $wpdb;
	
	/* Allow for draft, pending, trash as well as publish - a published post can transition into these */
	if (!in_array($post->post_status, array('publish', 'draft', 'pending', 'trash'))) { return; }
	
	/* Get the Shadow Blog's ID */
	$cfgp_blog_id = cfgp_get_shadow_blog_id();
	
	/* Get the current blog's id */
	$current_blog_id = $wpdb->blogid;
	
	/* Check to see if we're inserting the post, or updating an existing */
	$clone_post_id = cfgp_are_we_inserting($post->ID);
	
	/* Get all the post_meta for current post */
	$all_post_meta = get_post_custom($post->ID);
	
	/* Grab the Permalink of the post, so the shadow blog knows how to get back to the post */
	$permalink = get_permalink($post->ID);

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
	
	/* Add categories to clone post */
	$cat_results = cfgp_do_categories($clone_id, $cur_cats_names);

	/***********
	* TAG WORK *
	***********/
	/* tags changed in 2.8, so we need to see if we're >= 2.8 */
	global $wp_version;
	if (version_compare($wp_version, '2.8', '>=')) {
		$tags = $_POST['tax_input']['post_tag'];
	}
	else {
		$tags = $_POST['tags_input'];
	}
	/* Add tags to clone post */
	$tag_results = cfgp_do_tags($clone_id, $tags);

	/******************
	* MORE TAXONOMIES *
	******************/
	do_action('cfgp_clone_post_taxonomies', compact('clone_id', 'post'));

	/*****************
	* POST META WORK *
	*****************/
	/* Add original post's postmeta to clone post */
	$post_meta_results = apply_filters('cfgp_do_post_meta', array(),
		$clone_id,
		$current_blog_id,
		$all_post_meta,
		$permalink,
		$old_post_id
	);

	restore_current_blog();

	/* Add post_meta to the original 
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
if (cfgp_is_installed()) {
	add_action('save_post', 'cfgp_clone_post_on_publish', 99999999, 2);
}


/**********************************
* Comment Count Updating Function *
**********************************/
function cfgp_update_comment_count($post_id, $new_count, $old_count) {
	global $wpdb;
		
	/* get blog id for shadow blog */
	$cfgp_blog_id = cfgp_get_shadow_blog_id();
	
	/* If we're not already on the shadow blog, get clone's id from the 
	* 	passed $post_id, and utilize the switch_to_blog functionality */
	if ($wpdb->blogid != $cfgp_blog_id) {
		/* Grab clone's post id from current post's meta data */
		$clone_post_id = get_post_meta($post_id, '_cfgp_clone_id', true);

		if ($clone_post_id == '') { 
			/* No clone for this post, don't try to update comment count */
			return; 
		}
		/* switch to shadow blog */
		switch_to_blog($cfgp_blog_id);
		$switched = true;
	}
	/* If we are on the shadow blog already, we're doing an import, and the 
	* 	passed post_id is the clone's post id */
	else {
		$clone_post_id = $post_id;
		$switched = false;
	}
		
	if ($new_count === 0) {
		/* delete post meta, so we don't have a ton of empty rows in the post meta table */
		delete_post_meta($clone_post_id, '_cfgp_comment_count');
	}
	else {
		/* update comment count meta data for cloned post */
		update_post_meta($clone_post_id, '_cfgp_comment_count', $new_count);
	}
	
	if ($switched) {
		/* restore current blog */
		restore_current_blog();
	}
}
if (cfgp_is_installed()) {
	/* The action "update_comment_count" is called on new comment or comment deletion
	* 	so we only need one action to track comment counts */
	add_action('wp_update_comment_count', 'cfgp_update_comment_count', 10, 3);
}


/***************************
* Importing blog functions *
***************************/
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
			
			/* Grab the Permalink of the post, so the shadow blog knows how to get back to the post */
			$permalink = get_permalink($post->ID);
			
			/* Grab the comment count, so we can insert it into clone's post meta */
			$comment_count = get_comment_count($post->ID);
			
			// Gather all of the info to be processed into one place
			$posts[$post->ID]['post'] = $post;
			$posts[$post->ID]['categories'] = $categories;
			$posts[$post->ID]['tags'] = $tags;
			$posts[$post->ID]['post_meta'] = $all_post_meta;
			$posts[$post->ID]['clone_post_id'] = $clone_post_id;
			$posts[$post->ID]['permalink'] = $permalink;
			$posts[$post->ID]['comment_count'] = $comment_count['approved'];
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
			$permalink = $post['permalink'];
			$comment_number = $post['comment_count'];
			
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
			$post_meta_results = apply_filters('cfgp_do_post_meta', array(), 
				$clone_id,
				$blog_id,
				$post_meta,
				$permalink,
				$old_post_id
			);
			
			$clone_info[] = array(
				'post_id' => $old_post_id,
				'clone_id' => $clone_id
			);
			
			/*********************
			* COMMENT COUNT WORK *
			*********************/
			$comment_update_results = cfgp_update_comment_count($clone_id, $comment_number, 0);
			
			/* Add the return values for this post */
			$single_post_results[] = array(
				'original_post' => $old_post_id,
				'clone_id' => $clone_id,
				'cat_results' => $cat_results, 
				'tag_results' => $tag_results, 
				'post_meta_results' => $post_meta_results,
				'permalink' => $permalink,
				'comment_count' => $comment_update_results
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
	remove_action('before_delete_post', 'cfgp_delete_post_from_global');
	
	/* actually delete the clone post */
	$delete_results = wp_delete_post($cfgp_clone_id);
	
	/* put action back */
	add_action('before_delete_post', 'cfgp_delete_post_from_global');
	
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
if (cfgp_is_installed()) {
	add_action('before_delete_post', 'cfgp_delete_post_from_global');
}



function cfgp_delete_cfgp_post_meta($blog_id) {
	global $wpdb;
	
	$prefix = ($blog_id == 1) ? $wpdb->prefix : $wpdb->prefix.$blog_id.'_';
	
	/* Erase all the post_meta records, relating to the 
	* 	shadow blog, from the incomming blog */
	$sql = '
		DELETE FROM 
			'.$prefix.'postmeta
		WHERE
			meta_key = "_cfgp_clone_id"
	';
 	return $wpdb->query($sql);
}

/**
 * cfgp_delete_blog_from_shadow_blog
 *
 * Convenience Wrapper for deleting a blog from shadow blog 
 *
 * @param int $blog_id 
 * @return void
 */
function cfgp_delete_blog_from_shadow_blog($blog_id) {
	cfgp_flush_blog_data_from_shadow($blog_id);
}

/**
 * cfgp_flush_blog_data_from_shadow
 *
 * Deletes all references (posts, post-meta, etc...) to specified blog
 * in the shadow blog.
 *
 * @param int $blog_id 
 * @return void
 */
function cfgp_flush_blog_data_from_shadow($blog_id) {
	global $wpdb;
	
	$prefix = ($blog_id == 1) ? $wpdb->prefix : $wpdb->prefix.$blog_id.'_';
	
	/* Grab all the clone id's for the related posts from 
	* 	the incomming blog */
	$sql = '
		SELECT 
			post_id AS original_id, 
			meta_value AS clone_id
		FROM 
			'.$prefix.'postmeta
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
	
	$delete_postmeta_results = cfgp_delete_cfgp_post_meta($blog_id);
	
	if (count($delete_result) == $delete_postmeta_results) {
		error_log('SUCCESS!'."\n".'They both removed the same '.$delete_postmeta_results.' records');
	}
	else {
		error_log('FAIL'."\n".'posts_deleted: '.count($delete_result)."\n".'meta_deleted: '.$delete_postmeta_results);
	}
}

/* Returns False if it's not there, otherwise returns blog id */
function cfgp_is_installed() {
	if (!cfgp_get_shadow_blog_id()) {
		return false;
	}
	return true;
}
function cfgp_reset_shadow_blog() {
	$results = array();
	
	/* Get list of all blogs */
	$blog_list = get_blog_list(null, 'all');
	
	/* Delete all post_meta in all blogs */
	foreach ($blog_list as $blog_info) {
		cfgp_delete_cfgp_post_meta($blog_info['blog_id']);
	}
	$results['meta_deleted'] = true;
	
	/* Delete the sitemeta, if this is a legacy install of plugin */
	global $wpdb;
	$sql = '
		DELETE FROM 
			wp_sitemeta
		WHERE
			meta_key = "cfgp_blog_id"
	';
 	$results['sitemeta_deleted'] = $wpdb->query($sql);
	
	/* Delete the shadow blog */
	if (!function_exists('wpmu_delete_blog')) {
		require_once(ABSPATH.'wp-admin/includes/ms.php');
	}
	wpmu_delete_blog(cfgp_get_shadow_blog_id(), true);
	
	$results['success'] = 'true';
	
	return $results;
}
function cfgp_request_handler() {
	if (!empty($_GET['cf_action'])) {
		switch ($_GET['cf_action']) {
			case 'cfgp_admin_js':
				header('Content-type: text/javascript');
				require 'assets/js/admin.js';
				exit;
			break;
			case 'cfgp_admin_css':
				header('Content-type: text/css');
				require 'assets/css/admin.css';
				exit;
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
				/* Don't have php timeout on us */
				set_time_limit(0);
				
				/* We don't want error displaying corrupting the json */
				ini_set('display_errors', 0);
				
				/* Set how many blog posts to do at once */
				$increment = CFGP_SITE_IMPORT_INCREMENT;
				
				/* Grab the ID of the blog we're pulling from */
				$blog_id =  (int) $_POST['blog_id'];
				
				/* Grab our offset */
				$offset = (int) $_POST['offset'];
				
				/* Check if we're doing the first batch, if so, flush the 
				* 	incoming's blog data from shadow blog, so we can start fresh */
				if ($offset == 0) {
					cfgp_flush_blog_data_from_shadow($blog_id);
				}
				
				echo json_encode( cfgp_batch_import_blog( $blog_id, $offset, $increment ) );
				exit;
			break;
			case 'cfgp_setup_shadow_blog':
				cfgp_install();
				/* We don't want to exit, b/c we want the page to refresh */
			break;
			case 'reset_entire_shadow_blog':
				echo json_encode(cfgp_reset_shadow_blog());
				exit;
			break;
		}
	}
}
add_action('init', 'cfgp_request_handler');

function cfgp_load_view($file, $data = array()) {
	extract($data);
	require 'views/'.$file.'.php';
}

function cfgp_operations_form() {
	global $wpdb, $userdata;
	
	$current_site = get_current_site();
	
	$blog_ids = array();
	
	$shadow_blog = cfgp_get_shadow_blog_id();
	$sql = 'SELECT * FROM '.$wpdb->blogs.' WHERE site_id='.$current_site->id.' ORDER BY site_id, blog_id';
	error_log($sql);
	$results = $wpdb->get_results($sql);
	
	$show_reset_button = in_array($userdata->user_login, apply_filters('cfgp_big_admins', array('crowdfavorite')));
	
	cfgp_load_view('operations-page', compact(
		'shadow_blog',
		'blog_ids',
		'results',
		'show_reset_button'
	));
}

function cfgp_admin_head($hook_suffix) {
	if ($hook_suffix == 'settings_page_cf-global-posts') {
		wp_enqueue_style('cfgp_admin_css', admin_url('?cf_action=cfgp_admin_css'), array(), CFGP_VER);
		wp_enqueue_script('cfgp_admin_js', admin_url('?cf_action=cfgp_admin_js'), array('jquery'), CFGP_VER);
		wp_localize_script('cfgp_admin_js', 'CFGPAdminJs', array(
			'ajaxEndpoint'		=> admin_url(),
			'langProcessing'	=> __('Processing&hellip;', 'cf-global-posts'),
			'langAreUSure'		=> __('Are you sure that you want to reset the entire shadow blog?? '."\n\n".'Existing blogs will NOT be automatically added back in.  You will need to use the form above', 'cf-global-posts'),
			'langResettingNow'	=> __('Resetting Shadow Blog now&hellip;', 'cf-global-posts'),
			'langResetSuccess'	=> __('Shadow blog successfully reset!  Refreshing page now&hellip;', 'cf-global-posts'),
			'langResetError'	=> __('something went wrong, Please try again', 'cf-global-posts'),
			'langResetCancel'	=> __('Reset of Shadow Blog Cancelled', 'cf-global-posts'),
			'langComplete'		=> __('Complete!', 'cf-global-posts'),
		));
	}
}
add_action('admin_enqueue_scripts', 'cfgp_admin_head');

function cfgp_admin_menu() {
	global $wpdb;
	
	// force this to be only visible to site admins
	if (!is_super_admin()) { return; }
	
	if (current_user_can('manage_options')) {
		add_options_page(
			__('CF Global Posts Functions', 'cf-global-posts')
			, __('CF Global Posts', 'cf-global-posts')
			, 'manage_options'
			, basename(__FILE__)
			, 'cfgp_operations_form'
		);
	}
}
add_action('admin_menu', 'cfgp_admin_menu');

/********************************************************************
* Below are the default functions with the plugin, and till we get  *
* this finalize a little more, we'll leave them in until we know we *
* don't need them.                                                  *
* ******************************************************************/
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
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Settings', 'cf-global-posts').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'cfgp_plugin_action_links', 10, 2);

if (!function_exists('cfgp_settings_field')) {
	function cfgp_settings_field($key, $config) {
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
		echo cfgp_settings_field($key, $config);
	}
	print('
		</fieldset>
		<p class="submit">
			<input type="submit" name="submit" value="'.__('Save Settings', 'cf-global-posts').'" />
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

?>