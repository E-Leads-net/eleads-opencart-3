<?php
require_once DIR_SYSTEM . 'library/eleads/eleads_catalog_controller_trait.php';

class ControllerExtensionModuleEleads extends Controller {
	use EleadsCatalogControllerTrait;

	public function index() {
		require_once DIR_SYSTEM . 'library/eleads/bootstrap.php';
		require_once DIR_SYSTEM . 'library/eleads/oc_adapter.php';

		$feed_lang = isset($this->request->get['lang']) ? (string)$this->request->get['lang'] : '';
		if ($feed_lang === '') {
			$feed_lang = $this->config->get('config_language');
		}
		$feed_lang = $this->normalizeFeedLang($feed_lang);

		$request_key = isset($this->request->get['key']) ? (string)$this->request->get['key'] : '';

		$adapter = new EleadsOcAdapter($this->registry);
		$lang_code = $adapter->resolveLanguageCode($feed_lang);
		$engine = new EleadsFeedEngine();
		$result = $engine->build($adapter, $lang_code, $feed_lang, $request_key);

		if (!$result['ok']) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			return;
		}

		$this->response->addHeader('Content-Type: application/xml; charset=utf-8');
		$this->response->setOutput($result['xml']);
	}

	public function sitemapSync() {
		if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->response->addHeader('HTTP/1.1 405 Method Not Allowed');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'method_not_allowed')));
			return;
		}

		$api_key = trim((string)$this->config->get('module_eleads_api_key'));
		if ($api_key === '') {
			$this->response->addHeader('HTTP/1.1 401 Unauthorized');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'api_key_missing')));
			return;
		}

		$auth = $this->getBearerToken();
		if ($auth === '' || !hash_equals($api_key, $auth)) {
			$this->response->addHeader('HTTP/1.1 401 Unauthorized');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'unauthorized')));
			return;
		}

		$payload = json_decode((string)file_get_contents('php://input'), true);
		if (!is_array($payload)) {
			$this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'invalid_payload')));
			return;
		}

		$action = isset($payload['action']) ? trim((string)$payload['action']) : '';
		$slug = isset($payload['slug']) ? trim((string)$payload['slug']) : '';
		$new_slug = isset($payload['new_slug']) ? trim((string)$payload['new_slug']) : '';

		if (!in_array($action, array('create', 'update', 'delete'), true)) {
			$this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'invalid_action')));
			return;
		}
		if ($slug === '' || ($action === 'update' && $new_slug === '')) {
			$this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'invalid_payload')));
			return;
		}

		$query_lang = isset($this->request->get['lang']) ? (string)$this->request->get['lang'] : '';
		$source_lang_input = $query_lang !== '' ? $query_lang : (isset($payload['lang']) ? (string)$payload['lang'] : (isset($payload['language']) ? (string)$payload['language'] : ''));
		$target_lang_input = isset($payload['new_lang']) ? (string)$payload['new_lang'] : (isset($payload['new_language']) ? (string)$payload['new_language'] : '');

		$source_lang_explicit = trim($source_lang_input) !== '';
		$target_lang_explicit = trim($target_lang_input) !== '';

		$source_lang = $this->resolveSeoSitemapLanguage($source_lang_input);
		if ($source_lang === '') {
			$source_lang = $this->resolveSeoSitemapLanguage((string)$this->config->get('config_language'));
		}
		$target_lang = $this->resolveSeoSitemapLanguage($target_lang_input);
		if ($target_lang === '') {
			$target_lang = $source_lang;
		}

		$entries = $this->readSeoSitemapEntries();
		$url = $this->buildSeoPageUrl($source_lang, $slug);

		if ($action === 'create') {
			$exists = false;
			foreach ($entries as $entry) {
				if ($entry['slug'] === $slug && $entry['lang'] === $source_lang) {
					$exists = true;
					break;
				}
			}
			if (!$exists) {
				$entries[] = array('lang' => $source_lang, 'slug' => $slug);
			}
		} elseif ($action === 'delete') {
			$entries = array_values(array_filter($entries, function ($entry) use ($slug, $source_lang, $source_lang_explicit) {
				if ($entry['slug'] !== $slug) {
					return true;
				}
				if (!$source_lang_explicit) {
					return false;
				}
				return $entry['lang'] !== $source_lang;
			}));
		} else {
			$matched = array();
			$kept = array();
			foreach ($entries as $entry) {
				$is_match = $entry['slug'] === $slug && (!$source_lang_explicit || $entry['lang'] === $source_lang);
				if ($is_match) {
					$matched[] = $entry;
				} else {
					$kept[] = $entry;
				}
			}
			$entries = $kept;

			if (empty($matched)) {
				$matched[] = array('lang' => $source_lang, 'slug' => $slug);
			}

			foreach ($matched as $entry) {
				$new_lang = $entry['lang'];
				if ($target_lang_explicit) {
					$new_lang = $target_lang;
				} elseif ($source_lang_explicit) {
					$new_lang = $source_lang;
				}
				$entries[] = array('lang' => $new_lang, 'slug' => $new_slug);
			}

			$url = $this->buildSeoPageUrl($target_lang_explicit ? $target_lang : $source_lang, $new_slug);
		}

		if (!$this->writeSeoSitemapEntries($entries)) {
			$this->response->addHeader('HTTP/1.1 500 Internal Server Error');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'sitemap_update_failed')));
			return;
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array(
			'status' => 'ok',
			'url' => $url,
		)));
	}

	public function languages() {
		if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'GET') {
			$this->response->addHeader('HTTP/1.1 405 Method Not Allowed');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'method_not_allowed')));
			return;
		}

		$api_key = (string)$this->config->get('module_eleads_api_key');
		if ($api_key === '') {
			$this->response->addHeader('HTTP/1.1 401 Unauthorized');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'api_key_missing')));
			return;
		}

		$auth = $this->getBearerToken();
		if ($auth === '' || !hash_equals($api_key, $auth)) {
			$this->response->addHeader('HTTP/1.1 401 Unauthorized');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode(array('error' => 'unauthorized')));
			return;
		}

		$this->load->model('localisation/language');
		$items = array();
		foreach ((array)$this->model_localisation_language->getLanguages() as $language) {
			if (isset($language['status']) && !$language['status']) {
				continue;
			}
			$code = isset($language['code']) ? (string)$language['code'] : '';
			$items[] = array(
				'id' => isset($language['language_id']) ? (int)$language['language_id'] : 0,
				'label' => $code,
				'code' => $code,
				'href_lang' => $this->normalizeFeedLang($code),
				'enabled' => true,
			);
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array(
			'status' => 'ok',
			'count' => count($items),
			'items' => $items,
		)));
	}

	public function filterPage() {
		$settings = $this->config->get('module_eleads_filter_pages_enabled');
		if (empty($settings)) {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			return;
		}

		$route_lang = isset($this->request->get['lang']) ? trim((string)$this->request->get['lang']) : '';
		$fpath = isset($this->request->get['fpath']) ? trim((string)$this->request->get['fpath']) : '';
		$state = $this->parseFilterStateFromPath($fpath);
		$route_query = isset($this->request->get['q']) ? trim((string)$this->request->get['q']) : (isset($this->request->get['query']) ? trim((string)$this->request->get['query']) : '');
		if ($route_query !== '') {
			$state['query'] = $route_query;
		}
		$route_sort = isset($this->request->get['sort']) ? trim((string)$this->request->get['sort']) : '';
		if ($route_sort !== '' && in_array($route_sort, array('price_asc', 'price_desc', 'popularity'), true)) {
			$state['sort'] = $route_sort;
		}
		$route_page = isset($this->request->get['page']) ? (int)$this->request->get['page'] : 0;
		if ($route_page > 1) {
			$state['page'] = $route_page;
		}
		$route_limit = isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 0;
		if ($route_limit > 0) {
			$state['limit'] = $route_limit;
		}

		$requested_store_code = isset($this->request->get['language']) ? (string)$this->request->get['language'] : (isset($this->session->data['language']) ? (string)$this->session->data['language'] : (string)$this->config->get('config_language'));
		$lang = $route_lang !== '' ? $route_lang : $requested_store_code;
		$lang = $this->normalizeFeedLang($lang);
		if ($lang === '') {
			$lang = $this->normalizeFeedLang((string)$this->config->get('config_language'));
		}

		$store_language_code = $this->resolveStoreLanguageCode($lang);
		$this->applyStoreLanguage($store_language_code);
		if ((string)$state['category'] !== '' && ctype_digit((string)$state['category'])) {
			$resolved_category_name = $this->resolveCategoryNameById((string)$state['category']);
			if ($resolved_category_name !== '') {
				$state['category'] = $resolved_category_name;
			}
		}

		$search = $this->fetchFilteredSearchData($state, $lang);
		if ($search === null) {
			$search = array(
				'query' => (string)$state['query'],
				'total' => 0,
				'page' => 1,
				'pages' => 1,
				'items' => array(),
				'categories' => array(),
				'facets' => array(),
			);
		}

		$canonical = $this->buildFilterPageUrl($state, $lang);
		$this->document->addLink($canonical, 'canonical');

		$selected_short = $this->resolveSeoSitemapLanguage($requested_store_code);
		$current_short = $this->resolveSeoSitemapLanguage($lang);
		if ($selected_short !== '' && $selected_short !== $current_short) {
			$alt_url = $this->buildFilterPageUrl($state, $selected_short);
			$this->document->addLink($alt_url, 'alternate');
		}

		$is_noindex = $this->isFilterPageNoindex($state, (int)$search['total']);
		if ($is_noindex) {
			$this->response->addHeader('X-Robots-Tag: noindex, follow');
			if (method_exists($this->document, 'setRobots')) {
				$this->document->setRobots('noindex,follow');
			}
		}

		$this->load->language('product/search');

		$data = array();
		$data['breadcrumbs'] = array(
			array(
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/home', 'language=' . $store_language_code),
			),
			array(
				'text' => 'E-Filter',
				'href' => $canonical,
			),
		);
		$data['heading_title'] = 'E-Filter';
		$data['text_empty'] = $this->language->get('text_empty');
		$data['text_sort'] = $this->language->get('text_sort');
		$data['text_limit'] = $this->language->get('text_limit');
		$data['button_list'] = $this->language->get('button_list');
		$data['button_grid'] = $this->language->get('button_grid');
		$data['button_cart'] = $this->language->get('button_cart');
		$data['button_wishlist'] = $this->language->get('button_wishlist');
		$data['button_compare'] = $this->language->get('button_compare');
		$data['text_tax'] = $this->language->get('text_tax');

		$data['search_query'] = (string)$state['query'];
		$data['selected_category'] = (string)$state['category'];
		$data['selected_sort'] = (string)$state['sort'];
		$data['selected_page'] = (int)$state['page'];
		$data['selected_limit'] = (int)$state['limit'];
		$data['selected_filters'] = (array)$state['filters'];
		$data['total'] = (int)$search['total'];
		$data['pages'] = (int)$search['pages'];
		$data['is_noindex'] = $is_noindex;

		$data['sorts'] = array(
			array('value' => '', 'name' => 'Default', 'href' => $this->buildFilterPageUrl(array_merge($state, array('sort' => '')), $lang)),
			array('value' => 'price_asc', 'name' => 'Price ↑', 'href' => $this->buildFilterPageUrl(array_merge($state, array('sort' => 'price_asc')), $lang)),
			array('value' => 'price_desc', 'name' => 'Price ↓', 'href' => $this->buildFilterPageUrl(array_merge($state, array('sort' => 'price_desc')), $lang)),
			array('value' => 'popularity', 'name' => 'Popularity', 'href' => $this->buildFilterPageUrl(array_merge($state, array('sort' => 'popularity')), $lang)),
		);
		$data['limits'] = array();
		foreach (array(20, 25, 50, 75, 100) as $limit_value) {
			$data['limits'][] = array(
				'value' => $limit_value,
				'text' => $limit_value,
				'href' => $this->buildFilterPageUrl(array_merge($state, array('limit' => $limit_value, 'page' => 1)), $lang),
			);
		}

		$data['categories'] = array();
		$data['category_reset_href'] = $this->buildFilterPageUrl(array_merge($state, array('category' => '', 'page' => 1)), $lang);
		foreach ((array)$search['categories'] as $category) {
			$cid = isset($category['id']) ? (string)$category['id'] : '';
			if ($cid === '') {
				continue;
			}
			$cname = isset($category['name']) ? trim((string)$category['name']) : '';
			if ($cname !== '' && ctype_digit($cname)) {
				$cname = '';
			}
			if ($cname === '') {
				$cname = $this->resolveCategoryNameById($cid);
			}
			if ($cname === '') {
				$cname = $cid;
			}
			$data['categories'][] = array(
				'id' => $cid,
				'name' => $cname,
				'count' => isset($category['count']) ? (int)$category['count'] : 0,
				'active' => ((string)$state['category'] !== '' ? ((string)$state['category'] === $cname) : false) || ($cid === (string)$state['category']),
				'href' => $this->buildFilterPageUrl(array_merge($state, array('category' => $cname, 'page' => 1)), $lang),
			);
		}

		$data['facets'] = array();
		foreach ((array)$search['facets'] as $facet_name => $facet_values) {
			if (!is_array($facet_values) || !$facet_values) {
				continue;
			}
			$items = array();
			foreach ($facet_values as $facet_value) {
				if (!is_array($facet_value)) {
					continue;
				}
				$value = isset($facet_value['value']) ? (string)$facet_value['value'] : '';
				if ($value === '') {
					continue;
				}
				$next_state = $state;
				$current = isset($next_state['filters'][$facet_name]) && is_array($next_state['filters'][$facet_name]) ? $next_state['filters'][$facet_name] : array();
				$selected = in_array($value, $current, true);
				if ($selected) {
					$current = array_values(array_filter($current, function ($v) use ($value) { return (string)$v !== $value; }));
				} else {
					$current[] = $value;
				}
				if ($current) {
					$next_state['filters'][$facet_name] = array_values(array_unique($current));
				} else {
					unset($next_state['filters'][$facet_name]);
				}
				$next_state['page'] = 1;

				$items[] = array(
					'value' => $value,
					'count' => isset($facet_value['count']) ? (int)$facet_value['count'] : 0,
					'active' => $selected,
					'href' => $this->buildFilterPageUrl($next_state, $lang),
				);
			}
			if ($items) {
				$data['facets'][] = array(
					'name' => (string)$facet_name,
					'items' => $items,
				);
			}
		}

		$product_ids = $this->extractProductIdsFromSearchItems((array)$search['items']);
		$data['products'] = $this->buildProducts($product_ids, $store_language_code);

		$data['prev_href'] = '';
		$data['next_href'] = '';
		if ((int)$state['page'] > 1) {
			$data['prev_href'] = $this->buildFilterPageUrl(array_merge($state, array('page' => (int)$state['page'] - 1)), $lang);
		}
		if ((int)$state['page'] < (int)$search['pages']) {
			$data['next_href'] = $this->buildFilterPageUrl(array_merge($state, array('page' => (int)$state['page'] + 1)), $lang);
		}

		$title_parts = array('E-Filter');
		if ($state['query'] !== '') {
			$title_parts[] = $state['query'];
		}
		if ($state['category'] !== '') {
			$title_parts[] = 'Category ' . $state['category'];
		}
		$this->document->setTitle(implode(' — ', $title_parts));

		$seo_content = $this->buildFilterSeoContent($state, $store_language_code);
		if (!empty($seo_content['h1'])) {
			$data['heading_title'] = $seo_content['h1'];
		}
		if (!empty($seo_content['meta_title'])) {
			$this->document->setTitle($seo_content['meta_title']);
		}
		if (!empty($seo_content['meta_description'])) {
			$this->document->setDescription($seo_content['meta_description']);
		}
		if (!empty($seo_content['meta_keywords'])) {
			$this->document->setKeywords($seo_content['meta_keywords']);
		}
		$data['filter_short_description'] = isset($seo_content['short_description']) ? $seo_content['short_description'] : '';
		$data['filter_description'] = isset($seo_content['description']) ? $seo_content['description'] : '';

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['header'] = $this->load->controller('common/header');
		if ($is_noindex && strpos($data['header'], 'name="robots"') === false) {
			$data['header'] = str_replace('</head>', "\n<meta name=\"robots\" content=\"noindex,follow\" />\n</head>", $data['header']);
		}
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/eleads/filter', $data));
	}

	public function seoPage() {
		$slug = isset($this->request->get['slug']) ? trim((string)$this->request->get['slug']) : '';
		$lang = isset($this->request->get['lang']) ? trim((string)$this->request->get['lang']) : '';
		$requested_store_code = isset($this->request->get['language']) ? (string)$this->request->get['language'] : (isset($this->session->data['language']) ? (string)$this->session->data['language'] : (string)$this->config->get('config_language'));
		if ($slug === '') {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			return;
		}

		$slugs = $this->readSeoSitemapSlugs();
		if (!in_array($slug, $slugs, true)) {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			return;
		}

		if ($lang === '') {
			$lang = $requested_store_code;
		}
		$lang = $this->normalizeFeedLang($lang);
		if ($lang === '') {
			$lang = $this->normalizeFeedLang((string)$this->config->get('config_language'));
		}

		$page = $this->fetchSeoPage($slug, $lang);
		if (!$page) {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			return;
		}

		$current_short = $this->resolveSeoSitemapLanguage($lang);
		$selected_short = $this->resolveSeoSitemapLanguage($requested_store_code);
		if ($selected_short !== '' && $current_short !== '' && $selected_short !== $current_short) {
			$alternate_url = $this->findAlternateUrl($page['alternate'], $selected_short);
			if ($alternate_url !== '') {
				$this->response->redirect($alternate_url);
				return;
			}
		}

		$store_language_code = $this->resolveStoreLanguageCode($lang);
		$this->applyStoreLanguage($store_language_code);

		$self_lang = $this->resolveSeoSitemapLanguage($lang);
		$self_url = $this->buildSeoPageUrl($self_lang, $slug);
		$this->document->addLink($self_url, 'canonical');
		$alternate_links = array($self_url => true);
		foreach ((array)$page['alternate'] as $alt) {
			$alt_url = isset($alt['url']) ? trim((string)$alt['url']) : '';
			$alt_lang = isset($alt['lang']) ? (string)$alt['lang'] : '';
			if ($alt_url === '' && $alt_lang !== '') {
				$alt_url = $this->buildSeoPageUrl($this->resolveSeoSitemapLanguage($alt_lang), $slug);
			}
			if ($alt_url === '' || isset($alternate_links[$alt_url])) {
				continue;
			}
			$this->document->addLink($alt_url, 'alternate');
			$alternate_links[$alt_url] = true;
		}

		$this->load->language('product/search');
		$this->load->model('catalog/product');
		$this->load->model('catalog/category');
		$this->load->model('tool/image');

		$title = $page['meta_title'] !== '' ? $page['meta_title'] : ($page['h1'] !== '' ? $page['h1'] : $page['query']);
		$this->document->setTitle($title);
		if ($page['meta_description'] !== '') {
			$this->document->setDescription($page['meta_description']);
		}
		if ($page['meta_keywords'] !== '') {
			$this->document->setKeywords($page['meta_keywords']);
		}

		$data = array();
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home', 'language=' . $store_language_code))
		);
		$data['breadcrumbs'][] = array(
			'text' => $title,
			'href' => $this->url->link('extension/module/eleads/seoPage', 'language=' . $store_language_code . '&lang=' . urlencode($current_short) . '&slug=' . urlencode($slug))
		);

		$data['heading_title'] = $page['h1'] !== '' ? $page['h1'] : $page['query'];
		$data['entry_search'] = $this->language->get('entry_search');
		$data['text_keyword'] = $this->language->get('text_keyword');
		$data['text_category'] = $this->language->get('text_category');
		$data['text_sub_category'] = $this->language->get('text_sub_category');
		$data['entry_description'] = $this->language->get('entry_description');
		$data['button_search'] = $this->language->get('button_search');
		$data['text_search'] = $this->language->get('text_search');
		$data['text_empty'] = $this->language->get('text_empty');
		$data['text_sort'] = $this->language->get('text_sort');
		$data['text_limit'] = $this->language->get('text_limit');
		$data['text_compare'] = sprintf($this->language->get('text_compare'), (isset($this->session->data['compare']) ? count($this->session->data['compare']) : 0));
		$data['compare'] = $this->url->link('product/compare', 'language=' . $store_language_code);
		$data['button_cart'] = $this->language->get('button_cart');
		$data['button_wishlist'] = $this->language->get('button_wishlist');
		$data['button_compare'] = $this->language->get('button_compare');
		$data['button_list'] = $this->language->get('button_list');
		$data['button_grid'] = $this->language->get('button_grid');
		$data['text_tax'] = $this->language->get('text_tax');

		$data['search'] = $page['query'];
		$data['tag'] = '';
		$data['description'] = false;
		$data['category_id'] = 0;
		$data['sub_category'] = false;
		$data['categories'] = array();
		$data['seo_category_module'] = $this->load->controller('extension/module/category');
		$data['sort'] = 'p.sort_order';
		$data['order'] = 'ASC';
		$data['limit'] = (int)$this->config->get('theme_' . $this->config->get('config_theme') . '_product_limit');
		$data['sorts'] = array();
		$data['limits'] = array();

		$product_ids = $this->normalizeProductIds($page['product_ids']);
		$data['products'] = $this->buildProducts($product_ids, $store_language_code);
		$data['pagination'] = '';
		$data['results'] = '';

		$data['seo_description'] = html_entity_decode($page['description'], ENT_QUOTES, 'UTF-8');
		$data['seo_short_description'] = html_entity_decode($page['short_description'], ENT_QUOTES, 'UTF-8');

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/eleads/seo', $data));
	}


}
