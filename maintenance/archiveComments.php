<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use FlowThread\UID;
use FlowThread\Post;
use MediaWiki\MediaWikiServices;

class ArchiveComments extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Archive comments on deleted pages and children comments of deleted comments' );
				$this->addOption(
			'begin',
			'Only archive comments whose ID are alphabetically after the provided one',
			false,
			true
		);
		$this->addOption(
			'throttle',
			'Wait this many milliseconds after each batch. Default: 0',
			false,
			true
		);

		$this->setBatchSize( 500 );

		$this->requireExtension( 'FlowThread' );
	}

	/**
	 * @see Maintenance:execute()
	 */
	public function execute() {
		$status_archived = Post::STATUS_ARCHIVED;
		$status_deleted = Post::STATUS_DELETED;

		$throttle = $this->getOption( 'throttle', 0 );
		$this->begin = $this->getOption( 'begin', '' );

		$dbw = wfGetDB( DB_MASTER );
		$factory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		$this->output( "Archive comments on deleted pages...\n" );
		$dbw->query("UPDATE `flowthread` LEFT JOIN `page` ON `flowthread_pageid`=`page_id` 
SET `flowthread_status`=`flowthread_status`|{$status_archived} 
WHERE `page_id` IS NULL AND NOT `flowthread_status`&{$status_archived}");

		$this->output( "Archive children comments of deleted comments...\n" );
		$where = [ "flowthread_status&{$status_deleted}" ];
		if (isset($this->begin)) {
			$this->begin = UID::fromHex($this->begin)->getBin();
			$where[] = "flowthread_id > " . $dbw->addQuotes($this->begin);
		}
		while (true) {
			$res = $dbw->select('FlowThread', Post::getRequiredColumns(), $where, [
				'ORDER BY' => 'flowthread_id',
				'LIMIT' => $this->getBatchSize()
			]);
			if (!$res || $res->numRows() <= 0) break;

			foreach ($res as $row) {
				$this->begin = $row->flowthread_id;
				$post = Post::newFromDatabaseRow($row);
				if (!($post->status & $status_archived)) {
					$post->archiveChildren($dbw, true);
				}
			}

			$where[2] = "flowthread_id > " . $dbw->addQuotes($this->begin);
			$hex = UID::fromBin($this->begin)->getHex();
			$factory->waitForReplication();
			if ($res->numRows() < $this->getBatchSize()) break;

			$this->output( "--begin={$hex}\n" );
			usleep( $throttle * 1000 );
		}
	}
}

$maintClass = ArchiveComments::class;
require_once RUN_MAINTENANCE_IF_MAIN;
