<?php
namespace FlowThread;

use MediaWiki\MediaWikiServices;

class Hooks {

	public static function onBeforePageDisplay(\OutputPage &$output, \Skin &$skin) {
		$title = $output->getTitle();

		// If the comments are never allowed on the title, do not load
		// FlowThread at all.
		if (!Helper::canEverPostOnTitle($title)) {
			return true;
		}

		// Do not display when printing
		if ($output->isPrintable()) {
			return true;
		}

		// Disable if not viewing
		if ($skin->getRequest()->getVal('action', 'view') != 'view') {
			return true;
		}

		if ($output->getUser()->isAllowed('commentadmin-restricted')) {
			$output->addJsConfigVars(array('commentadmin' => ''));
		}

		global $wgFlowThreadConfig;
		$config = array(
			'Avatar' => $wgFlowThreadConfig['Avatar'],
			'AnonymousAvatar' => $wgFlowThreadConfig['AnonymousAvatar'],
		);

		// First check if user can post at all
		if (!\FlowThread\Post::canPost($output->getUser())) {
			$config['CantPostNotice'] = wfMessage('flowthread-ui-cantpost')->parse();
		} else {
			$status = SpecialControl::getControlStatus($title);
			if ($status === SpecialControl::STATUS_OPTEDOUT) {
				$config['CantPostNotice'] = wfMessage('flowthread-ui-useroptout')->parse();
			} else if ($status === SpecialControl::STATUS_DISABLED) {
				$config['CantPostNotice'] = wfMessage('flowthread-ui-disabled')->parse();
			} else {
				$output->addJsConfigVars(array('canpost' => ''));
			}
		}

		global $wgFlowThreadConfig;
		$output->addJsConfigVars(array('wgFlowThreadConfig' => $config));
		$output->addModules('ext.flowthread');
		return true;
	}

	public static function onLoadExtensionSchemaUpdates($updater) {
		$dir = __DIR__ . '/../sql';

		$dbType = $updater->getDB()->getType();
		// For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
		if (!in_array($dbType, array('mysql', 'sqlite'))) {
			throw new \Exception('Database type not currently supported');
		} else {
			$filename = 'mysql.sql';
		}

		$updater->addExtensionTable('FlowThread', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadAttitude', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadControl', "{$dir}/control.sql");

		return true;
	}

	private static function archiveInpage($pageid, $archive = true) {
		$status_archived = Post::STATUS_ARCHIVED;
		$status_deleted = Post::STATUS_DELETED;
		$dbw = wfGetDB(DB_MASTER);

		if ($archive) {
			$dbw->update('FlowThread', [
					"flowthread_status=flowthread_status|{$status_archived}"
				], [
					'flowthread_pageid' => $id,
					"NOT flowthread_status&{$status_archived}" // The archived status of deleted comments' children should not be changed
				]
			);
		} else {
			$res = $dbw->select('FlowThread', Post::getRequiredColumns(), [
					'flowthread_pageid' => $pageid,
					'flowthread_parentid' => null,
					"NOT flowthread_status&{$status_deleted}" // The archived status of deleted comments' children should not be changed
				]
			);

			$dbw->update('FlowThread', [
					"flowthread_status=flowthread_status^{$status_archived}"
				], [
					'flowthread_pageid' => $pageid,
					'flowthread_parentid' => null
				]
			);

			foreach ($res as $row) {
				$post = Post::newFromDatabaseRow($row);
				if (!!($post->status & $status_archived) !== $archive) {
					$post->archiveChildren($dbw, $archive);
				}
			}
		}
	}

	public static function onArticleDeleteComplete(\WikiPage $wikiPage, \User $user, $reason, $id, \Content $content, \LogEntry $logEntry, $archivedRevisionCount) {
		self::archiveInpage($id);
		SpecialControl::setControlStatus($wikiPage->getTitle(), SpecialControl::STATUS_ENABLED);

		return true;
	}

	public static function onArticleUndelete(\Title $title, $create, $comment, $oldPageId, $restoredPages) {
		if ($create) {
			self::archiveInpage($oldPageId, false);
			return true;
		}
		$dbw = wfGetDB(DB_MASTER);
		$hit = false;
		foreach ($restoredPages as $pageid => $true) {
			$res = $dbw->selectRow('archive', 'ar_title', [ 'ar_page_id' => $pageid ]);
			if ($res === false) {
				$dbw->update('FlowThread', [
						'flowthread_pageid' => $oldPageId
					], [
						'flowthread_pageid' => $pageid
					]
				);
				$hit = true;
			}
		}
		if ($hit) self::archiveInpage($oldPageId, false);
		return true;
	}

	private static function checkHavePost($pageid) {
		$dbr = wfGetDB(DB_REPLICA);
		$res = $dbr->selectRow('FlowThread', 'flowthread_id', [
			'flowthread_pageid' => $pageid
		]);
		return $res !== false;
	}

	public static function onMovePageCheckPermissions( \Title $oldTitle, \Title $newTitle, \User $user, $reason, \Status $status ) {
		if (!Helper::canEverPostOnTitle($oldTitle)) return true;

		if ($user->isAllowed('commentadmin-restricted')) return true;

		if ($oldTitle->getNamespace() !== NS_USER && $newTitle->getNamespace() === NS_USER &&
			SpecialControl::getControlStatus($oldTitle) !== SpecialControl::STATUS_ENABLED
		) {
			$status->fatal('flowthread-error-movetouser');
			return false;
		}

		if (Helper::isAllowedTitle($newTitle)) return true;
		

		if (self::checkHavePost($oldTitle->getArticleID())) {
			$status->fatal('flowthread-error-movetoinvalid');
			return false;
		}
	}

	public static function onTitleMoveComplete( \Title &$oldTitle, \Title &$newTitle, \User &$user, $oldid, $newid, $reason, \Revision $revision )
		if (!Helper::canEverPostOnTitle($oldTitle)) {
			if (Helper::canEverPostOnTitle($newTitle)) {
				self::archiveInpage($oldid, false);
			}
			return true;
		}

		if (!Helper::canEverPostOnTitle($newTitle)) {
			self::archiveInpage($oldid);
		} elseif ($oldTitle->inNamespace(NS_USER) && !$newTitle->inNamespace(NS_USER)) {
			SpecialControl::setControlStatus($newTitle, SpecialControl::STATUS_ENABLED);
		}
	}

	public static function onEditFilterMergedContent(\IContextSource $context, \Content $content, \Status $status, $summary, \User $user, $minoredit) {
		if ($user->isAllowed('commentadmin-restricted')) return true;

		$alias = MediaWikiServices::getInstance()->getMagicWordFactory()->newArray([ 'redirect' ]);
		$text = $content->getText();
		$regexes = $alias->getRegexStart();
		$is_Redirect = false;
		foreach ($regexes as $regex) {
			if (preg_match($regex, $text)) {
				$is_Redirect = true;
				break;
			}
		}
		if ($is_Redirect && self::checkHavePost($context->getTitle()->getArticleID())) {
			$status->fatal('flowthread-error-pageredirect');
			$status->value = MediaWiki\EditPage\IEditObject::AS_HOOK_ERROR_EXPECTED;
			return false;
		}
	}

	public static function onRenameUserComplete( $uid, $oldName, $newName ) {
		$dbw = wfGetDB(DB_MASTER);
		$dbw->update('FlowThread', [
				'flowthread_userid' => $uid
			], [
				'flowthread_username' => $newName
			]
		);
	}

	public static function onMergeAccountFields( &$updateFields ) {
		$updateFields[] = [
			[ 'FlowThread', 'flowthread_userid', 'flowthread_username' ],
			[ 'FlowThreadAttitude', 'flowthread_att_userid' ],
		];
		return true;
	}

	public static function onBaseTemplateToolbox(\BaseTemplate &$baseTemplate, array &$toolbox) {
		if (isset($baseTemplate->data['nav_urls']['usercomments'])
			&& $baseTemplate->data['nav_urls']['usercomments']) {
			$toolbox['usercomments'] = $baseTemplate->data['nav_urls']['usercomments'];
			$toolbox['usercomments']['id'] = 't-usercomments';
		}
	}

	public static function onSidebarBeforeOutput(\Skin $skin, &$sidebar) {
		$commentAdmin = $skin->getUser()->isAllowed('commentadmin-restricted');
		$user = $skin->getRelevantUser();

		if ($user && $commentAdmin) {
			$sidebar['TOOLBOX'][] = [
				'text' => wfMessage('sidebar-usercomments')->text(),
				'href' => \SpecialPage::getTitleFor('FlowThreadManage')->getLocalURL(array(
					'user' => $user->getName(),
				)),
			];
		}
	}

	public static function onSkinTemplateNavigation_Universal(\SkinTemplate $skinTemplate, array &$links) {
		$commentAdmin = $skinTemplate->getUser()->isAllowed('commentadmin-restricted');
		$user = $skinTemplate->getRelevantUser();

		$title = $skinTemplate->getRelevantTitle();
		if (Helper::canEverPostOnTitle($title) && ($commentAdmin || Helper::userOwnsPage($skinTemplate->getUser(), $title))) {
			// add a new action
			$links['actions']['flowthreadcontrol'] = [
				'id' => 'ca-flowthreadcontrol',
				'text' => wfMessage('action-flowthreadcontrol')->text(),
				'href' => \SpecialPage::getTitleFor('FlowThreadControl', $title->getPrefixedDBKey())->getLocalURL()
			];
		}

		return true;
	}

}
