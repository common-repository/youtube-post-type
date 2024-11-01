<?php
/*
Plugin Name: YouTube Post Type
Plugin URI: http://blog.aizatto.com/youtube_post_type
Description: Allow the user to add YouTube videos into the WordPress. YouTube videos represent a post.
Author: Ezwan Aizat Bin Abdullah Faiz
Author URI: http://aizatto.com
Version: 0.2
License: LGPLv2
*/

class YouTubePostType {
	// Hooks
	function register_activation_hook() {
	}

	function init() {
		register_post_type('youtube',
			array(
				'label'   => __('YouTube'),
				'public'  => true,
				'show_ui' => true,
				'supports' => array('thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'title', 'editor')
			)
		);

	}

	function add_meta_boxes_youtube($post) {
		add_meta_box('youtube_meta_box', 'YouTube', array('YouTubePostType', 'meta_box'), 'youtube', 'normal', 'high');
	}

	function meta_box($post) {
		$customs = get_post_custom($post->ID);
		if(!empty($customs['youtube_v_id'][0])) {
?>
	<div class="inside" style="text-align: center;">
		<object width="384" height="313">
			<param name="movie" value="http://www.youtube.com/v/<?php echo $customs['youtube_v_id'][0]; ?>&hl=en_US&fs=1"></param>
			<param name="allowFullScreen" value="true"></param>
			<param name="allowscriptaccess" value="always"></param>
			<embed src="http://www.youtube.com/v/<?php echo $customs['youtube_v_id'][0]; ?>&hl=en_US&fs=1" type="application/x-shockwave-flash" width="384" height="313" allowscriptaccess="always" allowfullscreen="true"></embed>
		</object>
	</div>
<?php
		}
		else {
			echo "Error showing video";
		}
	}
	
	function restrict_manage_posts() {
		global $typenow;
		switch($typenow) { 
		case "youtube":
			echo "<style type='text/css'>.add-new-h2 { display: none; }</style>";
			break;
		default:
			$filters = array();
		}
	}


	function admin_menu() {
		global $submenu;
		// Hack to remove the original "Add New" inserted by register_post_type
		unset($submenu['edit.php?post_type=youtube'][10]);
		$menu_handle = add_submenu_page('edit.php?post_type=youtube', 'Add New', 'Add New', 'publish_posts', 'youtube_new_video', array('YouTubePostType', 'add_submenu_page'));
	}

	function add_submenu_page() {
		if (isset($_POST['id'])) {
			$id = trim($_POST['id']);

			if (substr($id, 0, 7) == 'http://') {
				$url = parse_url($id);
				if ($url['host'] == 'www.youtube.com' || $url['host']  == 'youtube.com') {
					parse_str($url['query'], $query);
					
					if (substr($url['path'], 0, 3) == '/v/') {
						$id = substr($url['path'], 3);
					} else if ($url['path'] == '/watch' && isset($query['v'])) {
						$id = $query['v'];
					}
				}
			}

			if (isset($id)) {
				$response = self::get_details($id);
				if (array_key_exists('entry', $response)) {
					self::insert_youtube($response['entry'], $id);
				} else {
					unset($id);
				}
			}

			if (isset($id)) { ?>
<div id="message" class="updated"><p>YouTube submitted. Edit <a href="<?php echo get_edit_post_link($id); ?>"><?php echo get_the_title($id); ?></a></p></div>
<?php       } else {  ?>
<div id="notice" class="error"><p>Unable to load video.</p></div>
<?php
			}
		}
?>
	<div class="wrap">
		<h2>Add New</h2>
		
		<form action="edit.php?post_type=youtube&page=youtube_new_video" method="post">
			<table class="form-table">
				<tr>
					<th>YouTube URL or ID</th>
					<td>
						<input type="text" class="regular-text" name="id" />
						<span class="description">ex: http://www.youtube.com/watch?v=<strong>FgyFtDKttMk</strong></span>
					</td>
				</tr>
			</table>	
			<div class="submit"><input type="submit" name="add_via_id" value="Import Video" /></div>
		</form>
	</div>
<?php
	}

	// Supporting Functions
	function load_dependencies() {
		if (! class_exists('Services_JSON')) {
			require_once ABSPATH . 'wp-includes/class-json.php';
		}

/*
		if (! class_exists('Snoopy')) {
			require_once ABSPATH . 'wp-includes/class-snoopy.php';
		}
*/
	}

	// This code was developed through understanding:
	//  * wp-admin/includes.file.php:228#wp_handle_upload
	function insert_youtube($response, $id) {
		$post = array();

		//$post['guid']		  = $id;
		$post['post_type']    = 'youtube';
		$post['post_title']   = $response['title']['$t'];
		$post['post_name']    = sanitize_title($post['post_title']);
		$post['post_content'] = $response['content']['$t'];
		$post['post_status']  = 'publish';

		$post_id = wp_insert_post($post);

		update_post_meta($post_id, '_rating', $response['gd$rating']);
		update_post_meta($post_id, '_duration', $response['gd$duration']);
		update_post_meta($post_id, 'youtube_v_id', $id);

		// Get Thumbnails
		if (array_key_exists('media$group', $response) &&
			array_key_exists('media$thumbnail', $response['media$group'])) {
			$thumbnail_set = false;

			foreach($response['media$group']['media$thumbnail'] as $key => $thumbnail) {
				$file = download_url($thumbnail['url']);

				// Copied and modified from #wp_handle_upload
				if ( ! $uploads = wp_upload_dir())
					return call_user_func('wp_handle_upload_error', $file, $uploads['error'] ); 

				$filename = wp_unique_filename( $uploads['path'], $id . '.jpg');

				// Move the file to the uploads dir
				$new_file = $uploads['path'] . "/$filename";
				if ( false === @ rename( $file, $new_file ) )
					return wp_handle_upload_error( $file, sprintf( __('The uploaded file could not be moved to %s.' ), $uploads['path'] ) );

				// Set correct file permissions
				$stat = stat( dirname( $new_file ));
				$perms = $stat['mode'] & 0000666;
				@ chmod( $new_file, $perms );

				// Compute the URL
				$url = $uploads['url'] . "/$filename";

				if ( is_multisite() )
					delete_transient( 'dirsize_cache' );

				$type = '';
				$handle = apply_filters( 'wp_handle_upload', array( 'file' => $new_file, 'url' => $url, 'type' => $type ) );
				
				// cannot assign the guid to the path to the file as
				// wordpress uses the guid to determine where the file is on the server
				$attachment = array(
					'guid'           => $handle['url'],
					'post_title'     => $post['post_title'],
					'post_parent'    => $post_id,
					'post_mime_type' => 'image/jpeg',
					'post_content'   => $thumbnail['time']
				);

				$id = wp_insert_attachment($attachment, $new_file, $post_id);
				if ( !is_wp_error($id) ) {
					wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $new_file));
				}
				update_post_meta($id, 'source', $thumbnail['url']);

				// 
				if (! $thumbnail_set) {
					update_post_meta($post_id, '_thumbnail_id', $id);
					$thumbnail_set = true;
				}
			}
		}

		return $post_id;
	}

	function get_details($id) {
		$functionName = "/feeds/api/videos/" . $id;
		$payload = "";
		$results = self::request($functionName, $payload);
		//gdata.youtube.com/feeds/api/videos/gzDS-Kfd5XQ?v=2&alt=jsonc&prettyprint=true
		return $results;
	}

	function request($functionName, $payload) {
		self::load_dependencies();

		$json   = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);

		$url    = self::build_query($functionName, $payload);
		$results = wp_remote_get($url);
		return $json->decode($results['body']);
	}

	function build_query($functionName, $payload) {
		$payloadString = "";
		if ($payload != "") {
			foreach ($payload as $name => $value) {
				$payloadString .= '&'.$name.'='.$value;
			}
		}
		$url = 'http://gdata.youtube.com'.$functionName.'?alt=json'.$payloadString;
		return $url;
	}
}

register_activation_hook(__FILE__,   array('YouTubePostType', 'register_activation_hook'));
add_action('init',                   array('YouTubePostType', 'init'));
add_action('admin_menu',             array('YouTubePostType', 'admin_menu'));
add_action('add_meta_boxes_youtube', array('YouTubePostType', 'add_meta_boxes_youtube'));
add_action('restrict_manage_posts',  array('YouTubePostType', 'restrict_manage_posts') );
