<?php

class ESB_IMAP {

	function __construct() {
		add_action('init', array($this, 'init'));
		add_filter('cron_schedules', array($this, 'cron_schedules'));
		add_action('esb_imap_check_email_pipe', array($this, 'esb_imap_check_email_pipe_func'));

		add_filter('bbpnns_extra_topic_tags', array($this, 'bbpnns_extra_topic_tags'), 10, 3);
		add_filter('bbpnns_extra_reply_tags', array($this, 'bbpnns_extra_reply_tags'), 10, 3);
		add_filter('bbpnns_filter_email_subject_in_build', array($this, 'bbpnns_filter_email_content_in_build'), 10, 3);
		add_filter('bbpnns_filter_email_body_in_build', array($this, 'bbpnns_filter_email_content_in_build'), 10, 3);
		add_filter('bbpnns_extra_headers_recipient', array($this, 'bbpnns_extra_headers_recipient'), 10, 4);

		add_action('wp_ajax_esb_test_piping', array($this, 'test_piping'));
	}

	function init() {
		if (!wp_next_scheduled('esb_imap_check_email_pipe')) {
			wp_schedule_event(time(), 'every_minute', 'esb_imap_check_email_pipe');
		}
	}

	function cron_schedules($schedules) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display' => __('Every Minute', 'cqpim')
		);
		return $schedules;
	}

	function bbpnns_extra_topic_tags($tags = '') {
		$tags = '[PIPING_ID]';
		return $tags;
	}

	function bbpnns_extra_reply_tags($tags = '') {
		$tags = '[PIPING_ID]';
		return $tags;
	}

	function bbpnns_filter_email_content_in_build($content, $type, $post_id) {
		$string_prefix = get_option('esb_piping_string_prefix');
		if ('topic' === $type) {
			$topic_id = $post_id;
		}
		if ('reply' === $type) {
			$topic_id = bbp_get_reply_topic_id($post_id);
		}
		$content = str_replace("[PIPING_ID]", '[' . $string_prefix . ':' . $topic_id . ']', $content);
		return $content;
	}

	function bbpnns_extra_headers_recipient($headers, $user_info, $filtered_subject, $filtered_body) {
		$blogname = get_option('blogname');
		$piping_address = get_option('esb_piping_address');
		if (is_array($headers) && !empty($piping_address)) {
			$headers[] = "Reply-To: {$blogname} <{$piping_address}>";
		}
		return $headers;
	}

	function esb_imap_check_email_pipe_func() {
		/**
		 * Check imap extension is exist ot not.
		 */
		if (!function_exists('imap_open')) {
			return;
		}
		$mail_server = get_option('esb_piping_mail_server');
		$mailbox_name = get_option('esb_piping_mailbox_name');
		$mailbox_pass = get_option('esb_piping_mailbox_pass');
		if (empty($mail_server) || empty($mailbox_name) || empty($mailbox_pass)) {
			return;
		}
		$mail_server = '{' . $mail_server . '}';
		$test = imap_open($mail_server, $mailbox_name, $mailbox_pass);
		if (!empty($test)) {
			require_once(ESB_INCLUDES_DIR . '/php-imap/src/PhpImap/IncomingMail.php');
			require_once(ESB_INCLUDES_DIR . '/php-imap/src/PhpImap/Mailbox.php');

			$mailbox = new PhpImap\Mailbox($mail_server, $mailbox_name, $mailbox_pass, ESB_UPLOAD_DIR);
			$mailsIds = $mailbox->searchMailbox('UNSEEN');
			if (!$mailsIds) {
				die('Mailbox is empty');
			}
			$string_prefix = get_option('esb_piping_string_prefix');
			foreach ($mailsIds as $key => $message) {
				$mail = $mailbox->getMail($message);
				$attached_media = $mail->getAttachments();
				$fromName = $mail->fromName;
				$fromEmail = $mail->fromAddress;
				$toName = $mail->toString;
				$toEmail = $mail->to;
				$subject = $mail->subject;
				$subject_full = $mail->subject;
				$body = $mail->textPlain;
				/**
				 * Get the latest message content
				 */
				$body_array = explode("\n", $mail->textPlain);
				$reply_content = "";
				foreach ($body_array as $key => $value) {
					if ($value == "_________________________________________________________________") {
						break;
					} elseif (preg_match("/^From:(.*)/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^-*(.*)Original Message(.*)-*/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^On(.*)wrote:(.*)/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^On(.*)$fromName(.*)/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^On(.*)$toName(.*)/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^(.*)$toName(.*)wrote:(.*)/i", $value, $matches)) {
						break;
					} elseif (is_string($toEmail) && preg_match("/^(.*)$toEmail(.*)wrote:(.*)/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^(.*)$fromEmail(.*)wrote:(.*)/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^>(.*)/i", $value, $matches)) {
						break;
					} elseif (preg_match("/^---(.*)On(.*)wrote:(.*)/i", $value, $matches)) {
						break;
					} else {
						$reply_content .= "$value\n";
					}
				}
				/**
				 * Get the ID of the Topic Or Reply
				 */
				preg_match("/\[{$string_prefix}(.*?)\]/", $subject, $match_data);
				if (!empty($match_data) && !empty($match_data[1])) {
					$topic_id = preg_replace('/[^0-9]/', '', $match_data[1]);
					$post = get_post($topic_id);
					if (!empty($post) && $post->post_type == bbp_get_topic_post_type()) {
						$topic_url = bbp_get_topic_permalink($topic_id);
						$user = get_user_by('email', $fromEmail);
						if (empty($user)) {
							$this->send_unknown_account_email($fromEmail, $fromName, $topic_url);
							$mailbox->markMailAsRead($message);
							continue;
						} else {
							$forum_id = bbp_get_topic_forum_id($topic_id);
							$reply_content = apply_filters('bbp_new_reply_pre_content', $reply_content);

							$replyData = array('post_parent' => $topic_id, 'post_author' => $user->ID, 'post_content' => $reply_content);
							$replyMeta = array('forum_id' => $forum_id, 'topic_id' => $topic_id);
							$reply_id = bbp_insert_reply($replyData, $replyMeta);
							if (!empty($reply_id)) {
								/** Update counts, etc... ******************************************** */
								do_action('bbp_new_reply', $reply_id, $topic_id, $forum_id, array(), $user->ID, false, 0);
								/** Additional Actions (After Save) ********************************** */
								do_action('bbp_new_reply_post_extras', $reply_id);
							}
						}
					}
				} else {
					/*
					 * Create Topic
					 */
					$create_topic = get_option('esb_piping_create_topic');
					if ($create_topic == '1') {
						$user = get_user_by('email', $fromEmail);
						if (empty($user)) {
							$home_url = home_url();
							$this->send_unknown_account_email($fromEmail, $fromName, $home_url);
							$mailbox->markMailAsRead($message);
							continue;
						} else {
							$anonymous_data = array();
							$topic_title = apply_filters( 'bbp_new_topic_pre_title', sanitize_text_field($subject_full) );
							$topic_content = apply_filters( 'bbp_new_topic_pre_content', $reply_content );
							$topic_id = bbp_insert_topic(
								array(
								'post_author' => $user->ID,
								'post_parent' => 0,
								'post_title' => $topic_title,
								'post_content' => $topic_content,
								), array('forum_id' => 0)
							);
							if (!empty($topic_id)) {
								/** Update counts, etc... ******************************************** */
								do_action('bbp_new_topic', $topic_id, 0, $anonymous_data, $user->ID);
								/** Additional Actions (After Save) ********************************** */
								do_action('bbp_new_topic_post_extras', $topic_id);
							}
						}
					}
				}
			}
		}
		exit;
	}
	
	function send_unknown_account_email($to, $fromName, $url) {
		$email_subject = "Email Address Not Recognised";
		
		$email_content = "Sorry we couldn't recognize your account, please create a new account and post a topic/reply manually at '{$url}'";

		$headers = array();
		$subject = str_replace('\\', '', $email_subject);
		$message = str_replace('\\', '', $email_content);
		$sender_name = get_option('blogname');
		$sender_email = get_option('admin_email');

		$headers[] .= 'MIME-Version: 1.0';
		$headers[] .= 'Content-Type: text/html; charset=UTF-8';
		$headers[] = 'From: ' . $sender_name . ' <' . $sender_email . '>';
		$headers[] = 'X-Originating-IP: ' . $_SERVER['SERVER_ADDR'];
		$message = nl2br($message);
		
		return wp_mail($to, $subject, $message, $headers);
	}

	function test_piping() {
		$mail_server2 = isset($_POST['mail_server']) ? $_POST['mail_server'] : '';
		$mail_server = '{' . $mail_server2 . '}';
		$mailbox_name = isset($_POST['mailbox_name']) ? $_POST['mailbox_name'] : '';
		$mailbox_pass = isset($_POST['mailbox_pass']) ? $_POST['mailbox_pass'] : '';
		if (empty($mail_server2) || empty($mailbox_name) || empty($mailbox_pass)) {
			$return = array(
				'error' => true,
				'message' => __('You must complete all fields', 'cqpim'),
			);
			header('Content-type: application/json');
			echo json_encode($return);
			exit();
		}
		$mbox = imap_open($mail_server, $mailbox_name, $mailbox_pass);
		if (!empty($mbox)) {
			$return = array(
				'error' => false,
				'message' => __('Settings are correct.', 'cqpim'),
			);
			header('Content-type: application/json');
			echo json_encode($return);
			exit();
		} else {
			$return = array(
				'error' => true,
				'message' => __('Failed to connect to mailbox', 'cqpim'),
			);
			header('Content-type: application/json');
			echo json_encode($return);
			exit();
		}
	}

}

new ESB_IMAP();
