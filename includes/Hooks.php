<?php
namespace FlowThread;

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

	public static function onArticleDeleteComplete(&$article, \User &$user, $reason, $id, $content, \LogEntry $logEntry, $archivedRevisionCount) {
		$status_archived = Post::STATUS_ARCHIVED;
		$dbw = wfGetDB(DB_MASTER);
		$dbw->update('FlowThread', [
				"flowthread_status=flowthread_status|{$status_archived}"
			], [
				'flowthread_pageid' => $id,
				"NOT flowthread_status&{$status_archived}" // As deleted comments' children have this status
			]
		);
		return true;
	}

	public static function onArticleUndelete(\Title $title, $create, $comment, $oldPageId, $restoredPages) {
		if ($create) {
			$status_archived = Post::STATUS_ARCHIVED;
			$status_deleted = Post::STATUS_DELETED;
	
			$dbw = wfGetDB(DB_MASTER);

			$dbw->update('FlowThread', [
					"flowthread_status=flowthread_status^{$status_archived}"
				], [
					'flowthread_pageid' => $oldPageId,
					'flowthread_parentid' => null
				]
			);

			$res = $dbw->select('FlowThread', Post::getRequiredColumns(), [
					'flowthread_pageid' => $oldPageId,
					'flowthread_parentid' => null,
					"NOT flowthread_status&{$status_deleted}" // As deleted comments' children have this status
				]
			);

			foreach ($res as $row) {
				$post = Post::newFromDatabaseRow($row);
				$count += $post->archiveChildren($dbw, false);
			}
		}
		return true;
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
		$updateFields[] = [ 'FlowThread', 'flowthread_userid', 'flowthread_username' ];
		return true;
	}


	public static function onBaseTemplateToolbox(\BaseTemplate &$baseTemplate, array &$toolbox) {
		if (isset($baseTemplate->data['nav_urls']['usercomments'])
			&& $baseTemplate->data['nav_urls']['usercomments']) {
			$toolbox['usercomments'] = $baseTemplate->data['nav_urls']['usercomments'];
			$toolbox['usercomments']['id'] = 't-usercomments';
		}
	}

	public static function onSkinTemplateOutputPageBeforeExec(&$skinTemplate, &$tpl) {
		$commentAdmin = $skinTemplate->getUser()->isAllowed('commentadmin-restricted');
		$user = $skinTemplate->getRelevantUser();

		if ($user && $commentAdmin) {
			$nav_urls = $tpl->get('nav_urls');
			$nav_urls['usercomments'] = [
				'text' => wfMessage('sidebar-usercomments')->text(),
				'href' => \SpecialPage::getTitleFor('FlowThreadManage')->getLocalURL(array(
					'user' => $user->getName(),
				)),
			];
			$tpl->set('nav_urls', $nav_urls);
		}

		$title = $skinTemplate->getRelevantTitle();
		if (Helper::canEverPostOnTitle($title) && ($commentAdmin || Post::userOwnsPage($skinTemplate->getUser(), $title))) {
			$contentNav = $tpl->get('content_navigation');
			$contentNav['actions']['flowthreadcontrol'] = [
				'id' => 'ca-flowthreadcontrol',
				'text' => wfMessage('action-flowthreadcontrol')->text(),
				'href' => \SpecialPage::getTitleFor('FlowThreadControl', $title->getPrefixedDBKey())->getLocalURL()
			];
			$tpl->set('content_navigation', $contentNav);
		}

		return true;
	}
}
