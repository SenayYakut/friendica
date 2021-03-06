<?php
/**
 * @file include/ping.php
 */

use Friendica\App;
use Friendica\Content\Feature;
use Friendica\Content\ForumManager;
use Friendica\Content\Text\BBCode;
use Friendica\Core\Addon;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Group;
use Friendica\Model\Item;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Temporal;
use Friendica\Util\Proxy as ProxyUtils;
use Friendica\Util\XML;

require_once 'include/enotify.php';

/**
 * @brief Outputs the counts and the lists of various notifications
 *
 * The output format can be controlled via the GET parameter 'format'. It can be
 * - xml (deprecated legacy default)
 * - json (outputs JSONP with the 'callback' GET parameter)
 *
 * Expected JSON structure:
 * {
 *		"result": {
 *			"intro": 0,
 *			"mail": 0,
 *			"net": 0,
 *			"home": 0,
 *			"register": 0,
 *			"all-events": 0,
 *			"all-events-today": 0,
 *			"events": 0,
 *			"events-today": 0,
 *			"birthdays": 0,
 *			"birthdays-today": 0,
 *			"groups": [ ],
 *			"forums": [ ],
 *			"notify": 0,
 *			"notifications": [ ],
 *			"sysmsgs": {
 *				"notice": [ ],
 *				"info": [ ]
 *			}
 *		}
 *	}
 *
 * @param App $a The Friendica App instance
 */
function ping_init(App $a)
{
	$format = 'xml';

	if (isset($_GET['format']) && $_GET['format'] == 'json') {
		$format = 'json';
	}

	$tags          = [];
	$comments      = [];
	$likes         = [];
	$dislikes      = [];
	$friends       = [];
	$posts         = [];
	$regs          = [];
	$mails         = [];
	$notifications = [];

	$intro_count    = 0;
	$mail_count     = 0;
	$home_count     = 0;
	$network_count  = 0;
	$register_count = 0;
	$sysnotify_count = 0;
	$groups_unseen  = [];
	$forums_unseen  = [];

	$all_events       = 0;
	$all_events_today = 0;
	$events           = 0;
	$events_today     = 0;
	$birthdays        = 0;
	$birthdays_today  = 0;

	$data = [];
	$data['intro']    = $intro_count;
	$data['mail']     = $mail_count;
	$data['net']      = $network_count;
	$data['home']     = $home_count;
	$data['register'] = $register_count;

	$data['all-events']       = $all_events;
	$data['all-events-today'] = $all_events_today;
	$data['events']           = $events;
	$data['events-today']     = $events_today;
	$data['birthdays']        = $birthdays;
	$data['birthdays-today']  = $birthdays_today;

	if (local_user()) {
		// Different login session than the page that is calling us.
		if (!empty($_GET['uid']) && intval($_GET['uid']) != local_user()) {
			$data = ['result' => ['invalid' => 1]];

			if ($format == 'json') {
				if (isset($_GET['callback'])) {
					// JSONP support
					header("Content-type: application/javascript");
					echo $_GET['callback'] . '(' . json_encode($data) . ')';
				} else {
					header("Content-type: application/json");
					echo json_encode($data);
				}
			} else {
				header("Content-type: text/xml");
				echo XML::fromArray($data, $xml);
			}
			killme();
		}

		$notifs = ping_get_notifications(local_user());

		$condition = ["`unseen` AND `uid` = ? AND `contact-id` != ?", local_user(), local_user()];
		$fields = ['id', 'parent', 'verb', 'author-name', 'unseen', 'author-link', 'author-avatar', 'contact-avatar',
			'network', 'created', 'object', 'parent-author-name', 'parent-author-link', 'parent-guid', 'wall'];
		$params = ['order' => ['created' => true]];
		$items = Item::selectForUser(local_user(), $fields, $condition, $params);

		if (DBA::isResult($items)) {
			$items_unseen = Item::inArray($items);
			$arr = ['items' => $items_unseen];
			Addon::callHooks('network_ping', $arr);

			foreach ($items_unseen as $item) {
				if ($item['wall']) {
					$home_count++;
				} else {
					$network_count++;
				}
			}
		}

		if ($network_count) {
			if (intval(Feature::isEnabled(local_user(), 'groups'))) {
				// Find out how unseen network posts are spread across groups
				$group_counts = Group::countUnseen();
				if (DBA::isResult($group_counts)) {
					foreach ($group_counts as $group_count) {
						if ($group_count['count'] > 0) {
							$groups_unseen[] = $group_count;
						}
					}
				}
			}

			if (intval(Feature::isEnabled(local_user(), 'forumlist_widget'))) {
				$forum_counts = ForumManager::countUnseenItems();
				if (DBA::isResult($forum_counts)) {
					foreach ($forum_counts as $forum_count) {
						if ($forum_count['count'] > 0) {
							$forums_unseen[] = $forum_count;
						}
					}
				}
			}
		}

		$intros1 = q(
			"SELECT  `intro`.`id`, `intro`.`datetime`,
			`fcontact`.`name`, `fcontact`.`url`, `fcontact`.`photo`
			FROM `intro` LEFT JOIN `fcontact` ON `intro`.`fid` = `fcontact`.`id`
			WHERE `intro`.`uid` = %d  AND `intro`.`blocked` = 0 AND `intro`.`ignore` = 0 AND `intro`.`fid` != 0",
			intval(local_user())
		);
		$intros2 = q(
			"SELECT `intro`.`id`, `intro`.`datetime`,
			`contact`.`name`, `contact`.`url`, `contact`.`photo`
			FROM `intro` LEFT JOIN `contact` ON `intro`.`contact-id` = `contact`.`id`
			WHERE `intro`.`uid` = %d  AND `intro`.`blocked` = 0 AND `intro`.`ignore` = 0 AND `intro`.`contact-id` != 0",
			intval(local_user())
		);

		$intro_count = count($intros1) + count($intros2);
		$intros = $intros1 + $intros2;

		$myurl = System::baseUrl() . '/profile/' . $a->user['nickname'] ;
		$mails = q(
			"SELECT `id`, `from-name`, `from-url`, `from-photo`, `created` FROM `mail`
			WHERE `uid` = %d AND `seen` = 0 AND `from-url` != '%s' ",
			intval(local_user()),
			DBA::escape($myurl)
		);
		$mail_count = count($mails);

		if (intval(Config::get('config', 'register_policy')) === REGISTER_APPROVE && is_site_admin()) {
			$regs = Friendica\Model\Register::getPending();

			if (DBA::isResult($regs)) {
				$register_count = count($regs);
			}
		}

		$cachekey = "ping_init:".local_user();
		$ev = Cache::get($cachekey);
		if (is_null($ev)) {
			$ev = q(
				"SELECT type, start, adjust FROM `event`
				WHERE `event`.`uid` = %d AND `start` < '%s' AND `finish` > '%s' and `ignore` = 0
				ORDER BY `start` ASC ",
				intval(local_user()),
				DBA::escape(DateTimeFormat::utc('now + 7 days')),
				DBA::escape(DateTimeFormat::utcNow())
			);
			if (DBA::isResult($ev)) {
				Cache::set($cachekey, $ev, Cache::HOUR);
			}
		}

		if (DBA::isResult($ev)) {
			$all_events = count($ev);

			if ($all_events) {
				$str_now = DateTimeFormat::timezoneNow($a->timezone, 'Y-m-d');
				foreach ($ev as $x) {
					$bd = false;
					if ($x['type'] === 'birthday') {
						$birthdays ++;
						$bd = true;
					} else {
						$events ++;
					}
					if (DateTimeFormat::convert($x['start'], ((intval($x['adjust'])) ? $a->timezone : 'UTC'), 'UTC', 'Y-m-d') === $str_now) {
						$all_events_today ++;
						if ($bd) {
							$birthdays_today ++;
						} else {
							$events_today ++;
						}
					}
				}
			}
		}

		$data['intro']    = $intro_count;
		$data['mail']     = $mail_count;
		$data['net']      = $network_count;
		$data['home']     = $home_count;
		$data['register'] = $register_count;

		$data['all-events']       = $all_events;
		$data['all-events-today'] = $all_events_today;
		$data['events']           = $events;
		$data['events-today']     = $events_today;
		$data['birthdays']        = $birthdays;
		$data['birthdays-today']  = $birthdays_today;

		if (DBA::isResult($notifs)) {
			foreach ($notifs as $notif) {
				if ($notif['seen'] == 0) {
					$sysnotify_count ++;
				}
			}
		}

		// merge all notification types in one array
		if (DBA::isResult($intros)) {
			foreach ($intros as $intro) {
				$notif = [
					'id'      => 0,
					'href'    => System::baseUrl() . '/notifications/intros/' . $intro['id'],
					'name'    => $intro['name'],
					'url'     => $intro['url'],
					'photo'   => $intro['photo'],
					'date'    => $intro['datetime'],
					'seen'    => false,
					'message' => L10n::t('{0} wants to be your friend'),
				];
				$notifs[] = $notif;
			}
		}

		if (DBA::isResult($mails)) {
			foreach ($mails as $mail) {
				$notif = [
					'id'      => 0,
					'href'    => System::baseUrl() . '/message/' . $mail['id'],
					'name'    => $mail['from-name'],
					'url'     => $mail['from-url'],
					'photo'   => $mail['from-photo'],
					'date'    => $mail['created'],
					'seen'    => false,
					'message' => L10n::t('{0} sent you a message'),
				];
				$notifs[] = $notif;
			}
		}

		if (DBA::isResult($regs)) {
			foreach ($regs as $reg) {
				$notif = [
					'id'      => 0,
					'href'    => System::baseUrl() . '/admin/users/',
					'name'    => $reg['name'],
					'url'     => $reg['url'],
					'photo'   => $reg['micro'],
					'date'    => $reg['created'],
					'seen'    => false,
					'message' => L10n::t('{0} requested registration'),
				];
				$notifs[] = $notif;
			}
		}

		// sort notifications by $[]['date']
		$sort_function = function ($a, $b) {
			$adate = strtotime($a['date']);
			$bdate = strtotime($b['date']);

			// Unseen messages are kept at the top
			// The value 31536000 means one year. This should be enough :-)
			if (!$a['seen']) {
				$adate += 31536000;
			}
			if (!$b['seen']) {
				$bdate += 31536000;
			}

			if ($adate == $bdate) {
				return 0;
			}
			return ($adate < $bdate) ? 1 : -1;
		};
		usort($notifs, $sort_function);

		if (DBA::isResult($notifs)) {
			// Are the nofications called from the regular process or via the friendica app?
			$regularnotifications = (!empty($_GET['uid']) && !empty($_GET['_']));

			foreach ($notifs as $notif) {
				if ($a->isFriendicaApp() || !$regularnotifications) {
					$notif['message'] = str_replace("{0}", $notif['name'], $notif['message']);
				}

				$contact = Contact::getDetailsByURL($notif['url']);
				if (isset($contact['micro'])) {
					$notif['photo'] = ProxyUtils::proxifyUrl($contact['micro'], false, ProxyUtils::SIZE_MICRO);
				} else {
					$notif['photo'] = ProxyUtils::proxifyUrl($notif['photo'], false, ProxyUtils::SIZE_MICRO);
				}

				$local_time = DateTimeFormat::local($notif['date']);

				$notifications[] = [
					'id'        => $notif['id'],
					'href'      => $notif['href'],
					'name'      => $notif['name'],
					'url'       => $notif['url'],
					'photo'     => $notif['photo'],
					'date'      => Temporal::getRelativeDate($notif['date']),
					'message'   => $notif['message'],
					'seen'      => $notif['seen'],
					'timestamp' => strtotime($local_time)
				];
			}
		}
	}

	$sysmsgs = [];
	$sysmsgs_info = [];

	if (x($_SESSION, 'sysmsg')) {
		$sysmsgs = $_SESSION['sysmsg'];
		unset($_SESSION['sysmsg']);
	}

	if (x($_SESSION, 'sysmsg_info')) {
		$sysmsgs_info = $_SESSION['sysmsg_info'];
		unset($_SESSION['sysmsg_info']);
	}

	if ($format == 'json') {
		$data['groups'] = $groups_unseen;
		$data['forums'] = $forums_unseen;
		$data['notify'] = $sysnotify_count + $intro_count + $mail_count + $register_count;
		$data['notifications'] = $notifications;
		$data['sysmsgs'] = [
			'notice' => $sysmsgs,
			'info' => $sysmsgs_info
		];

		$json_payload = json_encode(["result" => $data]);

		if (isset($_GET['callback'])) {
			// JSONP support
			header("Content-type: application/javascript");
			echo $_GET['callback'] . '(' . $json_payload . ')';
		} else {
			header("Content-type: application/json");
			echo $json_payload;
		}
	} else {
		// Legacy slower XML format output
		$data = ping_format_xml_data($data, $sysnotify_count, $notifications, $sysmsgs, $sysmsgs_info, $groups_unseen, $forums_unseen);

		header("Content-type: text/xml");
		echo XML::fromArray(["result" => $data], $xml);
	}

	killme();
}

/**
 * @brief Retrieves the notifications array for the given user ID
 *
 * @param int $uid User id
 * @return array Associative array of notifications
 */
function ping_get_notifications($uid)
{
	$result  = [];
	$offset  = 0;
	$seen    = false;
	$seensql = "NOT";
	$order   = "DESC";
	$quit    = false;

	$a = get_app();

	do {
		$r = q(
			"SELECT `notify`.*, `item`.`visible`, `item`.`deleted`
			FROM `notify` LEFT JOIN `item` ON `item`.`id` = `notify`.`iid`
			WHERE `notify`.`uid` = %d AND `notify`.`msg` != ''
			AND NOT (`notify`.`type` IN (%d, %d))
			AND $seensql `notify`.`seen` ORDER BY `notify`.`date` $order LIMIT %d, 50",
			intval($uid),
			intval(NOTIFY_INTRO),
			intval(NOTIFY_MAIL),
			intval($offset)
		);

		if (!$r && !$seen) {
			$seen = true;
			$seensql = "";
			$order = "DESC";
			$offset = 0;
		} elseif (!$r) {
			$quit = true;
		} else {
			$offset += 50;
		}

		foreach ($r as $notification) {
			if (is_null($notification["visible"])) {
				$notification["visible"] = true;
			}

			if (is_null($notification["deleted"])) {
				$notification["deleted"] = 0;
			}

			if ($notification["msg_cache"]) {
				$notification["name"] = $notification["name_cache"];
				$notification["message"] = $notification["msg_cache"];
			} else {
				$notification["name"] = strip_tags(BBCode::convert($notification["name"]));
				$notification["message"] = format_notification_message($notification["name"], strip_tags(BBCode::convert($notification["msg"])));

				q(
					"UPDATE `notify` SET `name_cache` = '%s', `msg_cache` = '%s' WHERE `id` = %d",
					DBA::escape($notification["name"]),
					DBA::escape($notification["message"]),
					intval($notification["id"])
				);
			}

			$notification["href"] = System::baseUrl() . "/notify/view/" . $notification["id"];

			if ($notification["visible"]
				&& !$notification["deleted"]
				&& !(x($result, $notification["parent"]) && !empty($result[$notification["parent"]]))
			) {
				// Should we condense the notifications or show them all?
				if (PConfig::get(local_user(), 'system', 'detailed_notif')) {
					$result[$notification["id"]] = $notification;
				} else {
					$result[$notification["parent"]] = $notification;
				}
			}
		}
	} while ((count($result) < 50) && !$quit);

	return($result);
}

/**
 * @brief Backward-compatible XML formatting for ping.php output
 * @deprecated
 *
 * @param array $data            The initial ping data array
 * @param int   $sysnotify_count Number of unseen system notifications
 * @param array $notifs          Complete list of notification
 * @param array $sysmsgs         List of system notice messages
 * @param array $sysmsgs_info    List of system info messages
 * @param int   $groups_unseen   Number of unseen group items
 * @param int   $forums_unseen   Number of unseen forum items
 *
 * @return array XML-transform ready data array
 */
function ping_format_xml_data($data, $sysnotify_count, $notifs, $sysmsgs, $sysmsgs_info, $groups_unseen, $forums_unseen)
{
	$notifications = [];
	foreach ($notifs as $key => $notif) {
		$notifications[$key . ':note'] = $notif['message'];

		$notifications[$key . ':@attributes'] = [
			'id'        => $notif['id'],
			'href'      => $notif['href'],
			'name'      => $notif['name'],
			'url'       => $notif['url'],
			'photo'     => $notif['photo'],
			'date'      => $notif['date'],
			'seen'      => $notif['seen'],
			'timestamp' => $notif['timestamp']
		];
	}

	$sysmsg = [];
	foreach ($sysmsgs as $key => $m) {
		$sysmsg[$key . ':notice'] = $m;
	}
	foreach ($sysmsgs_info as $key => $m) {
		$sysmsg[$key . ':info'] = $m;
	}

	$data['notif'] = $notifications;
	$data['@attributes'] = ['count' => $sysnotify_count + $data['intro'] + $data['mail'] + $data['register']];
	$data['sysmsgs'] = $sysmsg;

	if ($data['register'] == 0) {
		unset($data['register']);
	}

	$groups = [];
	if (count($groups_unseen)) {
		foreach ($groups_unseen as $key => $item) {
			$groups[$key . ':group'] = $item['count'];
			$groups[$key . ':@attributes'] = ['id' => $item['id']];
		}
		$data['groups'] = $groups;
	}

	$forums = [];
	if (count($forums_unseen)) {
		foreach ($forums_unseen as $key => $item) {
			$forums[$key . ':forum'] = $item['count'];
			$forums[$key . ':@attributes'] = ['id' => $item['id']];
		}
		$data['forums'] = $forums;
	}

	return $data;
}
