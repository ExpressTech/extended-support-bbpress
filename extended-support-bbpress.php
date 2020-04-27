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

define('BBP_PLUGIN_DIR', WP_PLUGIN_DIR . '/' . basename(__DIR__));
define('BBP_PLUGIN_URL', WP_PLUGIN_URL . '/' . basename(__DIR__));

class BBP_API_MAIN {

	public function __construct() {
		register_activation_hook(__FILE__, array($this, 'activation'));
		register_deactivation_hook(__FILE__, array($this, 'deactivation'));
		add_action('bbp_api_twicedaily_event', array($this, 'do_this_twicedaily'));
		add_filter('query_vars', array($this, 'query_vars'));
		add_action('template_redirect', array($this, 'parse_request'));

		add_action('init', array($this, 'init'));

		add_action('admin_menu', array($this, 'bes_register_my_custom_menu_page'));
		add_action('bbp_template_after_user_details', array($this, 'bes_show_data'));
		add_action('bbp_theme_after_reply_author_admin_details', array($this, 'show_author_meta_details'));
		add_action('rest_api_init', array($this, 'wpw_api_register_endpoints'));
	}

	public function init() {
		if (!bbp_is_user_keymaster()) {
			remove_action('bbp_template_before_single_topic', 'bbResolutions\topic_resolution_form');
		}
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
		$reply_id = bbp_get_reply_id();
		$email = bbp_get_reply_author_email($reply_id);
		$transient_name = "license-status-" . md5($email);
		$transient_data = get_transient($transient_name);
		if (false === $transient_data) {
			delete_transient($transient_name);

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
						$transient_data = "<div class='license-status-wrapper'>";
						$transient_data .= "<span>License Status: </span>";
						$transient_data .= "<span class='{$final_status_sm}' style='color:{$color}'>{$final_status}</span>";
						$transient_data .= "</div>";
					}
				}
			}
			set_transient($transient_name, $transient_data, (60 * 60 * 4));
		}
		echo stripslashes($transient_data);
		return;
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
				global $authordata;
				$topic_id = get_the_ID();
				$forum_id = wp_get_post_parent_id($topic_id);
				$topic_resolution = get_post_meta($topic_id, 'bbr_topic_resolution', true);
				$meta_resolution = false;
				if (!empty($topic_resolution) && $topic_resolution == "3") {
					$meta_resolution = true;
				}
				/* Last replay of topic */
				$last_replay_date = '';
				$reply_post_id = get_post_meta($topic_id, '_bbp_last_reply_id', true);
				if (!empty($reply_post_id)) {
					$last_replay_date = get_the_date('Y-m-d H:i:s', $reply_post_id);
				}
				/* Get all conversation */
				$replies = bbp_get_all_child_ids($topic_id, 'reply');
				$conversation = array();
				$conversation[] = array(
					'id' => $topic_id,
					'username' => $authordata->user_login,
					'content' => get_the_content(),
					'created_at' => get_the_date('Y-m-d H:i:s'),
					'private_reply' => get_post_meta($topic_id, '_bbp_reply_is_private', true) ? true : false
				);

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
				$topic_survey = 0;
				$topic_survey_comment = '';
				if ($meta_resolution) {
					/* Topic Survey/Feedback */
					$esbtopicsurvey = get_post_meta($topic_id, 'esb_topic_survey', true);
					$topic_survey = ($esbtopicsurvey ? intval($esbtopicsurvey) : 0);
					$topic_survey_comment = get_post_meta($topic_id, 'esb_topic_survey_comment', true);
				}

				$topics_arr[] = array(
					'id' => $topic_id,
					'title' => get_the_title(),
					'created_at' => get_the_date('Y-m-d H:i:s'),
					'resolved' => $meta_resolution,
					'forum_id' => $forum_id,
					'forum_name' => get_the_title($forum_id),
					'forum_url' => get_the_permalink($forum_id),
					'topic_url' => get_the_permalink($topic_id),
					'topic_author_id' => $authordata->ID,
					'topic_author_username' => $authordata->user_login,
					'last_comment_at' => $last_replay_date,
					'survey' => $topic_survey,
					'survey_comment' => $topic_survey_comment,
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

	function activation() {
		if (!wp_next_scheduled('bbp_api_twicedaily_event')) {
			wp_schedule_event(time(), 'twicedaily', 'bbp_api_twicedaily_event');
		}
	}

	function deactivation() {
		wp_clear_scheduled_hook('bbp_api_twicedaily_event');
	}

	function query_vars($query_vars) {
		$query_vars[] = 'do';
		$query_vars[] = 'rate';
		return $query_vars;
	}

	function parse_request() {
		global $wp;
		if (array_key_exists('do', $wp->query_vars)) {
			$queried_object = get_queried_object();
			$topic_link = get_permalink($queried_object->ID);
			if (array_key_exists('rate', $wp->query_vars)) {
				$rating = get_query_var('rate');
				$is_survey = get_post_meta($queried_object->ID, 'esb_topic_survey', true);
				$is_survey_email = get_post_meta($queried_object->ID, 'esb_topic_survey_email', true);
				if ($is_survey_email == 1 && (empty($is_survey) || $is_survey == 0)) {
					if ($queried_object->post_type == 'topic') {
						update_post_meta($queried_object->ID, 'esb_topic_survey', $rating);
					}
				}
			}
			$addedFeedback = false;
			if (isset($_REQUEST['topic_survey_nonce_field']) && wp_verify_nonce($_REQUEST['topic_survey_nonce_field'], 'topic_survey')) {
				if (isset($_POST['newrate']) && !empty($_POST['newrate'])) {
					update_post_meta($queried_object->ID, 'esb_topic_survey', $_POST['newrate']);
				}
				if (isset($_POST['feedback']) && !empty($_POST['feedback'])) {
					update_post_meta($queried_object->ID, 'esb_topic_survey_comment', $_POST['feedback']);
					$addedFeedback = true;
				}
				wp_redirect($topic_link);
				exit;
			} else {
				include('templates/feedbackform.php');
			}
			exit;
		}
		return;
	}

	function wp_mail_content_type() {
		return "text/html";
	}

	function do_this_twicedaily() {
		add_filter('wp_mail_content_type', array($this, 'wp_mail_content_type'));
		$args = array(
			'post_type' => 'topic',
			'posts_per_page' => -1,
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => 'bbr_topic_resolution',
					'value' => 3,
					'compare' => '='
				),
				array(
					'key' => 'esb_topic_survey_email',
					'compare' => 'NOT EXISTS'
				)
			),
			'date_query' => array(
				array(
					'column' => 'post_date_gmt',
					'after' => '1 month ago',
				),
			),
		);
		$allposts = get_posts($args);
		
		if (!empty($allposts)) {
			$email_template = $this->email_content();
			foreach ($allposts as $post) {
				$topic_id = $post->ID;
				$topic_link = get_permalink($topic_id);
				/* $feedback_link = add_query_arg('do','feedback',$topic_link); */

				$author = get_user_by('id', $post->post_author);
				$body = $email_template;
				$body = str_replace('{username}', $author->display_name, $body);
				$body = str_replace('{link}', $topic_link, $body);
				/* $body = str_replace('{feedback_link}', $feedback_link, $body); */

				$send_mail = $this->mail($author->user_email, "How would you rate the support you received?", $body);
				if ($send_mail) {
					update_post_meta($topic_id, 'esb_topic_survey_email', 1);
				}
			}
		}
		remove_filter('wp_mail_content_type', array($this, 'wp_mail_content_type'));
	}

	function mail($to, $subject, $body) {

		if (!empty($to)) {
			$headers = array('Content-Type: text/html; charset=UTF-8');

			if (wp_mail($to, $subject, $body, $headers)) {
				return true;
			}
		}
		return false;
	}

	function email_content() {
		$content = '<meta name="viewport" content="width=device-width">
		<table border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: collapse;width: 100%;">
			<tr>
				<td style="font-family: sans-serif; font-size: 16px; vertical-align: top;padding:10px;">
					<p style="font-family: sans-serif; font-size: 16px; font-weight: normal; margin: 0; margin-bottom: 20px;">Hello {username},</p>
					<p style="font-family: sans-serif; font-size: 16px; font-weight: normal; margin: 0; margin-bottom: 20px;">We love to hear what you think of our customer service. Please take a moment to answer one simple question by clicking either link below:</p>
					<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; box-sizing: border-box; margin-bottom: 20px;">
						<tbody>
							<tr>
								<td align="left" style="font-family: sans-serif; font-size: 16px; vertical-align: top;">
									<p style="font-family: sans-serif; font-size: 16px; font-weight: normal; margin: 0;margin-bottom: 5px;">How would you rate the support you received?</p>
									<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: auto;">
										<tbody>
											<tr>
												<td style="font-family: sans-serif; font-size: 16px; vertical-align: top;">
													<a href="{link}?do=feedback&rate=3" target="_blank" style="color: green;text-decoration: none;margin: 0 5px;margin-left: 0;">Great</a>
												</td>
												<td style="font-family: sans-serif; font-size: 16px; vertical-align: top;text-align: center;">
													<a href="{link}?do=feedback&rate=2" target="_blank" style="color: gray;text-decoration: none;margin: 0 5px;">Okay</a>
												</td>
												<td style="font-family: sans-serif; font-size: 16px; vertical-align: top;text-align: center;">
													<a href="{link}?do=feedback&rate=1" target="_blank" style="color: red;text-decoration: none;margin: 0 5px;">Not Good</a>
												</td>
											</tr>
										</tbody>
									</table>
								</td>
							</tr>
						</tbody>
					</table>
					<p style="font-family: sans-serif; font-size: 16px; font-weight: normal; margin: 0; margin-bottom: 20px;">Here is the link to the support ticket for your reference - <a href="{link}" target="_blank">{link}</a></p>
					<p style="font-family: sans-serif; font-size: 16px; font-weight: normal; margin: 0; margin-bottom: 20px;">QSM Team</p>
				</td>
			</tr>
		</table>';
		return $content;
	}

}

new BBP_API_MAIN();
