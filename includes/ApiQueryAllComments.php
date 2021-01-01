<?php
namespace FlowThread;

class ApiQueryAllComments extends \ApiQueryBase {

	public function __construct( \ApiQuery $queryModule, $moduleName ) {
		parent::__construct( $queryModule, $moduleName, 'cl' );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireMaxOneParameter($params, 'pageid', 'title');

		$query = new Query();
		$query->threadMode = false;

		$priviledged = true;
		$filter = $params['filter'];
		if ($filter === 'all') {
			$query->filter = Query::FILTER_ALL;
		} else if ($filter === 'deleted') {
			$query->filter = Query::FILTER_DELETED;
		} else if ($filter === 'spam') {
			$query->filter = Query::FILTER_SPAM;
		} else if ($filter === 'reported') {
			$query->filter = Query::FILTER_REPORTED;
		} else {
			$priviledged = false;
			$query->filter = Query::FILTER_NORMAL;
		}

		// Try pageid first, if it is absent/invalid, also try title.
		// Ranges of pageid was restricted via PARAM_MIN, so we don't have to check it.
		if (isset($params['pageid'])) {
			$query->pageid = $params['pageid'];
		} else {
			$titleObj = \Title::newFromText($params['title']);
			if ($titleObj !== null && $titleObj->exists()) {
				$query->pageid = $titleObj->getArticleID();
			}
		}
		if (isset($params['user'])) $query->user = $params['user'];

		if (isset($params['keyword'])) {
			// Even though this is public information, this operation is quite
			// expensive, so we restrict its usage.
			$priviledged = true;
			$query->keyword = $params['keyword'];
		}
		$query->dir = $params['dir'] === 'newer' ? 'newer' : 'older';
		$limit = isset($params['limit']) ? $params['limit'] : 10;
		$query->limit = $limit + 1;
		$query->offset = isset($params['offset']) ? $params['offset'] : 0;

		// Check if the user is allowed to do priviledged queries.
		if ($priviledged) {
			$this->checkUserRightsAny('commentadmin-restricted');
		} else {
			if ($this->getUser()->isAllowed('commentadmin-restricted')) $priviledged = true;
		}

		$query->fetch();
		/** @var Post[] $posts */
		$posts = $query->posts;

		// We fetched one extra row. If it exists in response, then we know we have more to fetch.
		$more = false;
		if (count($posts) > $limit) {
			$more = true;
			array_pop($posts);
		}

		// For un-priviledged users, do sanitisation
		if (!$priviledged) {
			$visible = [];
			foreach ($posts as $post) {
				if ($post->isVisible()) $visible[] = $post;
			}
			$posts = $visible;
		}

		$comments = Helper::convertPosts($posts, $this->getUser(), true, $priviledged);
		$obj = [
			"more" => $more,
			"posts" => $comments,
		];
		$this->getResult()->addValue('query', $this->getModuleName(), $obj);
	}

	public function getAllowedParams() {
		return [
			'filter' => [
				\ApiBase::PARAM_TYPE => [
					'all',
					'normal',
					'deleted',
					'spam',
					'reported'
				],
				\ApiBase::PARAM_DFLT => 'normal'
			],
			'pageid' => [
				\ApiBase::PARAM_TYPE => 'integer',
				\ApiBase::PARAM_MIN => 1
			],
			'title' => [
				\ApiBase::PARAM_TYPE => 'string'
			],
			'user' => [
				\ApiBase::PARAM_TYPE => 'user'
			],
			'keyword' => [
				\ApiBase::PARAM_TYPE => 'string'
			],
			'dir' => [
				\ApiBase::PARAM_TYPE => [
					'newer',
					'older'
				],
				\ApiBase::PARAM_DFLT => 'newer'
			],
			'limit' => [
				\ApiBase::PARAM_TYPE => 'limit',
				\ApiBase::PARAM_MIN => 1,
				\ApiBase::PARAM_MAX => 200, // Max limit value in the Spacial:FlowThreadControl is 200
				\ApiBase::PARAM_MAX2 => 500,
				\ApiBase::PARAM_DFLT => 10
			],
			'offset' => [
				\ApiBase::PARAM_TYPE => 'integer',
				\ApiBase::PARAM_MIN => 0,
				\ApiBase::PARAM_DFLT => 0
			]
		];
	}
}
