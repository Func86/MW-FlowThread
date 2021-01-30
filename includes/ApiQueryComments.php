<?php
namespace FlowThread;

class ApiQueryComments extends \ApiQueryBase {

	public function __construct( \ApiQuery $queryModule, $moduleName ) {
		parent::__construct( $queryModule, $moduleName, 'fc' );
	}

	private function fetchPosts($pageid, $user, $limit = 10, $offset = 0) {
		$page = new Query();
		$page->pageid = $pageid;
		$page->setFilter(Query::FILTER_NORMAL);
		$page->offset = $offset;
		$page->limit = $limit;
		$page->fetch();

		$comments = Helper::convertPosts($page->posts, $user);

		$popular = PopularPosts::getFromPageId($pageid);
		$popularRet = Helper::convertPosts($popular, $user);

		$obj = array(
			"posts" => $comments,
			"popular" => $popularRet,
			"count" => $page->totalCount,
		);

		return $obj;
	}

	public function execute() {
		if ($this->getPageSet()->getGoodTitleCount() == 0) return;

		$titles = $this->getPageSet()->getGoodTitles();
		$params = $this->extractRequestParams();
		$limit = isset($params['limit']) ? $params['limit'] : 10;
		$offset = isset($params['offset']) ? $params['offset'] : 0;

		foreach ($titles as $title) {
			$pageid = $title->getArticleID();
			$this->addPageSubItems($pageid, $this->fetchPosts($pageid, $this->getUser(), $limit, $offset));
		}
	}

	public function getAllowedParams() {
		return [
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
