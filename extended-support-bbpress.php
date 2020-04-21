<?php
/*
Plugin Name: Extended Support functionality for bbPress
Plugin URI: https://expresstech.io/
Description: BBPress Extended Support
Version: 1.1.1
Author: Wpwave
Author URI: https://expresstech.io/
License: GPLv2 or later
Text Domain: wpw-api
GitHub Plugin URI: https://github.com/ExpressTech/extended-support-bbpress
*/

class BBP_API_MAIN {

	public function __construct() {
		add_action('admin_menu', array($this, 'bes_register_my_custom_menu_page'));
		add_action('bbp_template_after_user_details', array($this, 'bes_show_data'));
		add_action('bbp_theme_after_reply_author_admin_details', array($this, 'show_author_meta_details'));
		add_action('rest_api_init', array($this, 'wpw_api_register_endpoints'));
	}

	public function bes_show_data() {
		if (current_user_can('moderate')) {
			$site_url = get_option('esb_url', '');
			$url = trailingslashit($site_url) . "/edd-api/sales/";
			$email = bbp_get_displayed_user_field('user_email');
			$body = $this->api_call($email);
			if (!empty($body)) {
				$sales = $body->sales;
				echo "<style>.esb-license-info {border: 1px solid gray;margin-bottom: 10px;padding: 10px;clear: both;}</style>";
				echo "<h1>License Information</h1>";
				foreach ($body->sales as $sale) {
					$payment_link = trailingslashit($site_url) . 'wp-admin/edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $sale->ID;
					echo "<div class='esb-license-info'>";
					echo "<p>ID: <a href='{$payment_link}' target='_blank'>{$sale->ID}</a></p>";
					echo "<p>Customer ID: {$sale->customer_id}</p>";
					echo "<p>Date of Purchase: {$sale->date}</p>";
					echo "<p><strong>Products:</strong></p>";
					foreach ($sale->products as $product) {
						echo "<p>{$product->name} ({$product->price_name})</p>";
					}
					echo "<p><strong>Licenses:</strong></p>";
					if (!empty($sale->licenses)) {
						foreach ($sale->licenses as $license) {
							echo "<p>{$license->name}</p>";
							$color = "gray";
							if ($license->status == 'expired') {
								$color = "red";
							} elseif ($license->status == 'active') {
								$color = "green";
							}
							echo "<p >Status: <span style='color:$color'> {$license->status}</span></p>";
						}
					}
					echo "</div>";
				}
			}
		}
	}

	public function show_author_meta_details() {
		$license_text = '';
		$reply_id = bbp_get_reply_id();
		$email = bbp_get_reply_author_email($reply_id);
		$cookie_name = "license-status-". md5($email);
		if (isset($_COOKIE[$cookie_name])) {
			$license_text = stripslashes($_COOKIE[$cookie_name]);
		} else {
			$api_res = $this->api_call($email);
			if (!empty($api_res)) {
				$sales = $api_res->sales;
				$license_statuses = array();
				$active = $inactive = $expired = $disabled = 0;
				if (!empty($sales)) {
					foreach ($sales as $sale) {
						$saleuser = get_user_by('email', $sale->email);
						if (bbp_is_user_keymaster($saleuser->ID)) {
							return;
						}
						if (!empty($sale->licenses)) {
							foreach ($sale->licenses as $license) {
								$license_statuses[] = $license->status;
								switch ($license->status) {
									case 'active':
										$active++;
										break;
									case 'expired':
										$expired++;
										break;
									case 'inactive':
										$inactive++;
										break;
									default:
										$disabled++;
										break;
								}
							}
						}
					}
					if (count($license_statuses) > 0) {
						$final_status = '';
						$color = 'gray';
						if ($active == count($license_statuses)) {
							$final_status = 'Active';
							$color = 'green';
						} elseif ($inactive == count($license_statuses)) {
							$final_status = 'Inacive';
							$color = 'orange';
						} elseif ($expired == count($license_statuses)) {
							$final_status = 'Expired';
							$color = 'red';
						} elseif ($disabled == count($license_statuses)) {
							$final_status = 'Disabled';
						} else {
							$final_status = 'Mixed';
							$color = 'green';
						}
						$final_status_sm = strtolower($final_status);
						$license_text = "<div class='license-status-wrapper'>";
						$license_text .= "<span>License Status: </span>";
						$license_text .= "<span class='{$final_status_sm}' style='color:{$color}'>{$final_status}</span>";
						$license_text .= "</div>";

					}
				}
			}
			setcookie($cookie_name, stripslashes($license_text), time() + (86400), "/");
		}
		echo $license_text;
	}

	public function api_call($email = '') {
		$body = array();
		if (!empty($email)) {
			$site_url = get_option('esb_url', '');
			$url = trailingslashit($site_url) . "/edd-api/sales/";
			$esb_api_key = get_option('esb_api_key', '');
			$esb_hash = get_option('esb_hash', '');
			$output = wp_remote_get($url . '?key=' . $esb_api_key . '&token=' . $esb_hash . '&email=' . $email);
			if (isset($output['response']) && ($output['response']['code'] == 200 || $output['response']['code'] == 503)) {
				$body = json_decode($output['body']);
			}
		}
		return $body;
	}

	public function bes_register_my_custom_menu_page() {
		add_menu_page('Extended Support', 'Extended Support', 'manage_options', 'extended_support_bbpress', array($this, 'create_admin_settings'), 'dashicons-external');
	}

	public function create_admin_settings() {
		if (isset($_POST['esb_submit'])) {
			$url = sanitize_text_field($_POST['esb_url']);
			$esb_api_key = sanitize_text_field($_POST['esb_api_key']);
			$esb_hash = sanitize_text_field($_POST['esb_hash']);
			$esb_stats_api_key = sanitize_text_field($_POST['esb_stats_api_key']);
			update_option('esb_url', $url);
			update_option('esb_api_key', $esb_api_key);
			update_option('esb_hash', $esb_hash);
			update_option('esb_stats_api_key', $esb_stats_api_key);
			?>
			<div id="message" class="updated notice notice-success is-dismissible">
				<p>Settings updated. </p>
			</div>
			<?php
		}
		$url = get_option('esb_url', '');
		$esb_api_key = get_option('esb_api_key', '');
		$esb_hash = get_option('esb_hash', '');
		$esb_stats_api_key = get_option('esb_stats_api_key', '');
		?>
		<div class="wrap">
			<h2>Extended Support BBPress</h2>
			<form method="post">
				<h3>EDD Integration</h3>
				<table>
					<tr>
						<th>EDD Site URL</th>
						<td><input type="text" value="<?php echo $url; ?>" name="esb_url"></td>
					</tr>
					<tr>
						<th>EDD API Key</th>
						<td><input type="text" value="<?php echo $esb_api_key; ?>" name="esb_api_key"></td>
					</tr>
					<tr>
						<th>EDD Hash</th>
						<td>
							<input type="text" value="<?php echo $esb_hash; ?>" name="esb_hash">
						</td>
					</tr>
					<tr>
						<td>
							<input class="button button-primary" type="submit" name="esb_submit">
						</td>
					</tr>
				</table>
				<h3>Stats API</h3>
				<table>
					<tr>
						<th>Define API Key</th>
						<td><input type="text" value="<?php echo $esb_stats_api_key; ?>" name="esb_stats_api_key">
							<small>The api is accessible at domain.com/wp-json/esbbp/bbp-topics/__API__KEY__</small>
						</td>

					</tr>
					<tr>
						<td>
							<input class="button button-primary" type="submit" name="esb_submit">
						</td>
					</tr>
				</table>
			</form>
		</div>
		<?php
	}

	/**
	 * @version 1.0.0
	 * Register API endpoints
	 */
	public function wpw_api_register_endpoints() {
		register_rest_route('esbbp', '/bbp-topics/(?P<key>[\a-z]+)', array(
			'methods' => 'GET',
			'callback' => array($this, 'wpw_api_insert_ip_callback'),
		));
	}

	public function cmp($a, $b) {

		return $a['id'] > $b['id'];
	}

	/**
	 * 
	 */
	public function wpw_api_insert_ip_callback($request) {
		$args = array(
			'key' => $request['key']
		);
		if ($request['key'] == get_option('esb_stats_api_key', '') && get_option('esb_stats_api_key', '') != '') {
			global $wpdb;
			$args_query = array(
				'post_type' => 'topic',
				'date_query' => array(
					array(
						'column' => 'post_date_gmt',
						'after' => '-90 days',
					)
				),
				'posts_per_page' => -1
			);
			$query1 = new WP_Query($args_query);
			$topics_arr = array();
			while ($query1->have_posts()) {
				$query1->the_post();
				$forum_id = wp_get_post_parent_id(get_the_ID());
				$topic_author_un = get_the_author();
				$authordata = get_user_by('login', $topic_author_un);
				$meta_resolution = get_post_meta(get_the_ID(), 'bbr_topic_resolution');
				if (count($meta_resolution) > 0 && $meta_resolution[0] == "3") {
					$meta_resolution = true;
				} else {
					$meta_resolution = false;
				}

				//Last replay of topic
				$last_replay_date = '';
				$reply_post_id = get_post_meta(get_the_ID(), '_bbp_last_reply_id', true);
				if (!empty($reply_post_id)) {
					$last_replay_date = get_the_date('Y-m-d H:i:s', $reply_post_id);
				}
				// echo  get_the_ID();exit;
				//Get all conversation
				$replies = bbp_get_all_child_ids(get_the_ID(), 'reply');
				$conversation = array();
				$conversation[] = array(
					'id' => get_the_ID(),
					'username' => $topic_author_un,
					'content' => get_the_content(),
					'created_at' => get_the_date('Y-m-d H:i:s'),
					'private_reply' => get_post_meta(get_the_ID(), '_bbp_reply_is_private', true) ? true : false
				);
				// var_dump($replies);exit;
				if ($replies) {
					foreach ($replies as $replay_id) {
						$content_post = get_post($replay_id);
						$replay_user_id = $content_post->post_author;
						$replay_authordata = get_user_by('id', $replay_user_id);

						$conversation[] = array(
							'id' => intval($replay_id),
							'username' => $replay_authordata->user_login,
							'content' => $content_post->post_content,
							'created_at' => $content_post->post_date,
							'private_reply' => get_post_meta($replay_id, '_bbp_reply_is_private', true) ? true : false
						);
					}
				}

				usort($conversation, [$this, "cmp"]);

				$topics_arr[] = array(
					'id' => get_the_ID(),
					'title' => get_the_title(),
					'created_at' => get_the_date('Y-m-d H:i:s'),
					'resolved' => $meta_resolution,
					'forum_id' => $forum_id,
					'forum_name' => get_the_title($forum_id),
					'forum_url' => get_the_permalink($forum_id),
					'topic_url' => get_the_permalink(get_the_ID()),
					'topic_author_id' => $authordata->ID,
					'topic_author_username' => $topic_author_un,
					'last_comment_at' => $last_replay_date,
					'conversation' => $conversation,
					'total_converstations' => count($replies)
				);
			}
			wp_reset_postdata();
			return rest_ensure_response($topics_arr);
		} else {
			return rest_ensure_response('Invalid Key');
		}
	}

}

new BBP_API_MAIN();
