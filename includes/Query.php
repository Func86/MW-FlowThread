<?php
namespace FlowThread;

class Query {
	const FILTER_ALL = 0;
	const FILTER_NORMAL = 1;
	const FILTER_REPORTED = 2;
	const FILTER_DELETED = 3;
	const FILTER_SPAM = 4;

	// Query options
	public $pageid = 0;
	public $user = '';
	public $keyword = '';
	public $dir = 'older';
	public $offset = 0;
	public $limit = -1;
	public $threadMode = true;
	public $filter = self::FILTER_ALL;
	public $continue = null;
	public $internal = false;

	// Query results
	public $totalCount = 0;
	public $posts = null;
	public $pager = [];

	public function fetch() {
		$dbr = wfGetDB(DB_REPLICA);
		$older = $this->dir === 'older';

		$comments = [];
		$parentLookup = [];
		$options = [
			'ORDER BY' => 'flowthread_id ' . ($older ? 'DESC' : 'ASC')
		];

		if (!$this->continue && $this->offset > 0) { // Disable offset when continue is used
			$options['OFFSET'] = $this->internal ? $this->offset - 1 : $this->limit;
		}
		if ($this->limit !== -1) {
			$options['LIMIT'] = $this->internal ? $this->limit + 1 : $this->limit;
		}
		if ($this->threadMode) {
			$options[] = 'SQL_CALC_FOUND_ROWS';
		}

		$cond = [];
		$expCond = []; // Expensive condiction should be put in the end
		if ($this->pageid) {
			$cond['flowthread_pageid'] = $this->pageid;
		}
		if ($this->threadMode) {
			$cond[] = 'flowthread_parentid IS NULL';
		}
		if ($this->user) {
			$cond['flowthread_username'] = $this->user;
		}
		if ($this->keyword) {
			$expCond[] = 'flowthread_text' . $dbr->buildLike($dbr->anyString(), $this->keyword, $dbr->anyString());
		}

		switch ($this->filter) {
		case static::FILTER_ALL:
			$status_archived = Post::STATUS_ARCHIVED;
			$cond[] = "NOT flowthread_status&{$status_archived}";
			break;
		case static::FILTER_NORMAL:
			$cond['flowthread_status'] = Post::STATUS_NORMAL;
			break;
		case static::FILTER_REPORTED:
			$cond['flowthread_status'] = Post::STATUS_NORMAL;
			$cond[] = 'flowthread_report > 0';
			break;
		case self::FILTER_DELETED:
			$cond['flowthread_status'] = Post::STATUS_DELETED;
			break;
		case self::FILTER_SPAM:
			$cond['flowthread_status'] = Post::STATUS_SPAM;
			break;
		}

		// Query backward for pager prev-links
		if ($this->internal && $this->continue) {
			$row = $dbr->selectRow('FlowThread', [ 'flowthread_id' ],
				$cond + [
					'flowthread_id' . ($older ? '>' : '<') . $dbr->addQuotes(UID::fromHex($this->continue)->getBin())
				] + $expCond);
			if ($row !== false) {
				$this->pager['prev'] = true;
				$this->pager['previd'] = UID::fromBin($row->flowthread_id)->getHex();
			}
		}

		if ($this->continue) {
			$cond[] = 'flowthread_id' . ($older ? '<=' : '>=') . $dbr->addQuotes(UID::fromHex($this->continue)->getBin());
		}

		// Get all root posts
		$res = $dbr->select('FlowThread', Post::getRequiredColumns(),
			$cond + $expCond, __METHOD__, $options);

		$count = 0;
		$sqlPart = '';
		foreach ($res as $row) {
			if (!$count++ && $this->internal && $this->offset > 0) {
				$this->pager['prev'] = true;
				$this->pager['previd'] = UID::fromBin($row->flowthread_id)->getHex();
				continue;
			} elseif ($count > $this->limit) {
				$this->pager['next'] = true;
				$this->pager['nextid'] = UID::fromBin($row->flowthread_id)->getHex();
				break;
			}
			$post = Post::newFromDatabaseRow($row);
			$comments[] = $post;
			$parentLookup[$post->id->getBin()] = $post;

			// Build SQL Statement for children query
			if ($sqlPart) {
				$sqlPart .= ',';
			}
			$sqlPart .= $dbr->addQuotes($post->id->getBin());
		}

		if ($this->threadMode) {
			$this->totalCount = intval($dbr->query('select FOUND_ROWS() as row')->fetchObject()->row);

			// Recursively get all children post list
			// This is not really resource consuming as you might think, as we use IN to boost it up
			while ($sqlPart) {
				$cond = array(
					'flowthread_pageid' => $this->pageid,
					'flowthread_parentid IN(' . $sqlPart . ')',
				);
				switch ($this->filter) {
				case static::FILTER_ALL:
					break;
				// Other cases shouldn't match
				default:
					$cond['flowthread_status'] = Post::STATUS_NORMAL;
					break;
				}

				$res = $dbr->select('FlowThread', Post::getRequiredColumns(), $cond);

				$sqlPart = '';

				foreach ($res as $row) {
					$post = Post::newFromDatabaseRow($row);
					if ($post->parentid) {
						$post->parent = $parentLookup[$post->parentid->getBin()];
					}

					$comments[] = $post;
					$parentLookup[$post->id->getBin()] = $post;

					// Build SQL Statement for children query
					if ($sqlPart) {
						$sqlPart .= ',';
					}
					$sqlPart .= $dbr->addQuotes($post->id->getBin());
				}
			}
		}

		$this->posts = $comments;
	}

}
