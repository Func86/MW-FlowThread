<?php
namespace FlowThread;

class ApiQueryAllComments extends \ApiQueryBase {

	public function __construct( \ApiQuery $queryModule, $moduleName ) {
		parent::__construct( $queryModule, $moduleName, 'cl' );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireMaxOneParameter($params, 'pageid', 'title');

		$priviledged = true;
		$query = new Query();
		$query->threadMode = false;

		if ( $params['continue'] !== null ) {
			$cont = explode('|', $params['continue']);
			$this->dieContinueUsageIf(count($cont) != 1);
			$query->continue = $cont[0];
		}

		$filterMap = [
			'all' => Query::FILTER_ALL,
			'normal' => Query::FILTER_NORMAL,
			'deleted' => Query::FILTER_DELETED,
			'spam' => Query::FILTER_SPAM,
			'reported' => Query::FILTER_REPORTED
		];
		$filter = $params['filter'];
		if (isset($filter) && isset($filterMap[$filter])) {
			$query->setFilter($filterMap[$filter]);
		} else {
			$priviledged = false;
			$query->setFilter(Query::FILTER_NORMAL);
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
		$query->limit = $limit;
		$query->offset = isset($params['offset']) ? $params['offset'] : 0;
		$query->internal = isset($params['pager']);

		// Check if the user is allowed to do priviledged queries.
		if ($priviledged) {
			$this->checkUserRightsAny('commentadmin-restricted');
		} else {
			if ($this->getUser()->isAllowed('commentadmin-restricted')) $priviledged = true;
		}

		$query->fetch();
		/** @var Post[] $posts */
		$posts = $query->posts;

		// If there are more can be fetch.
		if (isset($query->pager['next'])) {
			$this->setContinueEnumParameter('continue', $query->pager['nextid']);
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
			"pager" => $query->pager,
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
			'pager' => [ // Internal use
				\ApiBase::PARAM_TYPE => 'boolean'
			],
			'continue' => [
				\ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
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
