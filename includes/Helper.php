<?php
namespace FlowThread;

use Wikimedia\Rdbms\DBConnRef;

class Helper {

	public static function buildSQLInExpr(DBConnRef $db, array $arr) {
		$range = '';
		foreach ($arr as $item) {
			if ($range) {
				$range .= ',';
			}
			$range .= $db->addQuotes($item);
		}
		return ' IN(' . $range . ')';
	}

	public static function buildPostInExpr(DBConnRef $db, array $arr) {
		$range = '';
		foreach ($arr as $post) {
			if ($range) {
				$range .= ',';
			}
			$range .= $db->addQuotes($post->id->getBin());
		}
		return ' IN(' . $range . ')';
	}

	public static function batchFetchParent(array $posts) {
		$needed = [];
		$ids = [];
		foreach ($posts as $post) {
			$p = $post;
			while ($p->parent !== null) $p = $p->parent;
			if ($p->parentid !== null) {
				$needed[] = $p;
				$ids[] = $p->parentid->getBin();
			}
		}

		if ( !count($needed) ) return 0;

		$dbr = wfGetDB(DB_REPLICA);
		$inExpr = self::buildSQLInExpr($dbr, $ids);
		$res = $dbr->select('FlowThread', Post::getRequiredColumns(), [
			'flowthread_id' . $inExpr
		]);

		$ret = [];
		foreach ($res as $row) {
			$ret[UID::fromBin($row->flowthread_id)->getHex()] = $row;
		}
		foreach ($needed as $post) {
			if ($post->parent !== null || $post->parentid === null) continue;
			$hex = $post->parentid->getHex();
			if (isset($ret[$hex])) {
				$post->parent = Post::newFromDatabaseRow($ret[$hex]);
			} else {
				// Inconsistent database state, probably caused by a removed
				// parent but the child not being removed.
				// Treat as deleted
				$post->parentid = null;
				$post->status = Post::STATUS_DELETED;
			}
		}

		return count($needed);
	}

	public static function batchGetUserAttitude(\User $user, array $posts) {
		if (!count($posts)) {
			return array();
		}

		$ret = [];

		// In this case we don't even need db query
		if ($user->isAnon()) {
			foreach ($posts as $post) {
				$ret[$post->id->getHex()] = Post::ATTITUDE_NORMAL;
			}
			return $ret;
		}

		$dbr = wfGetDB(DB_REPLICA);

		$inExpr = self::buildPostInExpr($dbr, $posts);
		$res = $dbr->select('FlowThreadAttitude', array(
			'flowthread_att_id',
			'flowthread_att_type',
		), array(
			'flowthread_att_id' . $inExpr,
			'flowthread_att_userid' => $user->getId(),
		));

		foreach ($res as $row) {
			$ret[UID::fromBin($row->flowthread_att_id)->getHex()] = intval($row->flowthread_att_type);
		}
		foreach ($posts as $post) {
			if (!isset($ret[$post->id->getHex()])) {
				$ret[$post->id->getHex()] = Post::ATTITUDE_NORMAL;
			}
		}

		return $ret;
	}

	public static function generateMentionedList(\ParserOutput $output, Post $post) {
		$pageTitle = \Title::newFromId($post->pageid);
		$mentioned = array();
		$links = $output->getLinks();
		if (isset($links[NS_USER]) && is_array($links[NS_USER])) {
			foreach ($links[NS_USER] as $titleName => $pageId) {
				$user = \User::newFromName($titleName);
				if (!$user) {
					continue; // Invalid user
				}
				if ($user->isAnon()) {
					continue;
				}
				if ($user->getId() == $post->userid) {
					continue; // Mention oneself
				}
				if ($pageTitle->getNamespace() === NS_USER && $pageTitle->getDBkey() === $titleName) {
					continue; // Do mentioning in one's own page.
				}
				$mentioned[$user->getId()] = $user->getId();
			}
		}

		// Exclude all users that will be notified on Post hook
		$parent = $post->getParent();
		for (; $parent; $parent = $parent->getParent()) {
			if (isset($mentioned[$parent->userid])) {
				unset($mentioned[$parent->userid]);
			}
		}
		return $mentioned;
	}

	public static function getAllowedNamespace() {
		global $wgFlowThreadAllowedNamespace;
		$ret = $wgFlowThreadAllowedNamespace;

		return is_array($ret) ? $ret : array(
			NS_MAIN,
			NS_USER,
			NS_PROJECT,
			NS_HELP
		);
	}

	public static function isAllowedTitle(\Title $title) {
		if ($title->isSpecialPage()) {
			return false;
		}

		// These could be explicitly allowed in later version
		if (!$title->canHaveTalkPage()) {
			return false;
		}

		if ($title->isTalkPage()) {
			return false;
		}

		// No commenting on main page
		if ($title->isMainPage()) {
			return false;
		}

		// Namespace whitelist
		if (!$title->inNamespaces(self::getAllowedNamespace())) {
			return false;
		}

		return true;
	}

	public static function canEverPostOnTitle(\Title $title) {
		// Disallow commenting on pages without article id
		if ($title->getArticleID() == 0) {
			return false;
		}

		return self::isAllowedTitle($title);
	}

	public static function convertPosts(array $posts, \User $user, $needTitle = false, $priviledged = false) {
		$attTable = self::batchGetUserAttitude($user, $posts);
		$ret = [];
		foreach ($posts as $post) {
			$json = [
				'id' => $post->id->getHex(),
				'userid' => $post->userid,
				'username' => $post->username,
				'text' => $post->text,
				'timestamp' => $post->id->getTimestamp(),
				'parentid' => $post->parentid ? $post->parentid->getHex() : '',
				'like' => $post->getFavorCount(),
				'myatt' => $attTable[$post->id->getHex()],
			];
			if ($needTitle) {
				$title = \Title::newFromId($post->pageid);
				$json['pageid'] = $post->pageid;
				$json['title'] = $title ? $title->getPrefixedText() : null;
			}
			if ($priviledged) {
				$json['report'] = $post->getReportCount();
				$json['status'] = $post->status;
			}
			$ret[] = $json;
		}
		return $ret;
	}

	/**
	 * Check if the a page is one's user page or user subpage
	 *
	 * @param User $user
	 *   User who is acting the action
	 * @param Title $title
	 *   Page on which the action is acting
	 * @return
	 *   True if the page belongs to the user
	 */
	public static function userOwnsPage(\User $user, \Title $title) {
		if ($title->inNamespace(NS_USER) && $title->getRootText() === $user->getName()) {
			return true;
		}
		return false;
	}
}
