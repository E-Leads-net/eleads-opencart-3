<?php
class ControllerExtensionModuleEleads extends Controller {
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

	private function normalizeFeedLang($lang) {
		$lang = strtolower((string)$lang);
		if (strpos($lang, 'en') === 0) {
			return 'en';
		}
		if (strpos($lang, 'ru') === 0) {
			return 'ru';
		}
		if (strpos($lang, 'uk') === 0 || strpos($lang, 'ua') === 0) {
			return 'uk';
		}
		return $lang;
	}

	private function getBearerToken() {
		$headers = array();
		if (function_exists('getallheaders')) {
			$headers = getallheaders();
		}
		$auth = '';
		if (isset($headers['Authorization'])) {
			$auth = $headers['Authorization'];
		} elseif (isset($headers['authorization'])) {
			$auth = $headers['authorization'];
		} elseif (isset($this->request->server['HTTP_AUTHORIZATION'])) {
			$auth = $this->request->server['HTTP_AUTHORIZATION'];
		} elseif (isset($this->request->server['REDIRECT_HTTP_AUTHORIZATION'])) {
			$auth = $this->request->server['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if (stripos($auth, 'Bearer ') === 0) {
			return trim(substr($auth, 7));
		}
		return '';
	}

	private function getSeoSitemapPath() {
		$root = rtrim(dirname(DIR_APPLICATION), '/\\');
		return $root . '/e-search/sitemap.xml';
	}

	private function getSeoBaseUrl() {
		if (defined('HTTPS_SERVER') && HTTPS_SERVER) {
			return rtrim(HTTPS_SERVER, '/');
		}
		if (defined('HTTP_SERVER') && HTTP_SERVER) {
			return rtrim(HTTP_SERVER, '/');
		}
		$ssl = (string)$this->config->get('config_ssl');
		return $ssl !== '' ? rtrim($ssl, '/') : rtrim((string)$this->config->get('config_url'), '/');
	}

	private function fetchSeoPage($slug, $lang) {
		$api_key = trim((string)$this->config->get('module_eleads_api_key'));
		if ($api_key === '') {
			return null;
		}
		$lang = $this->normalizeFeedLang((string)$lang);
		if ($lang === '') {
			$lang = $this->normalizeFeedLang((string)$this->config->get('config_language'));
		}
		require_once DIR_SYSTEM . 'library/eleads/api_routes.php';
		$ch = curl_init();
		if ($ch === false) {
			return null;
		}
		$headers = array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json',
		);
		$url = EleadsApiRoutes::SEO_PAGE . rawurlencode($slug) . '?lang=' . rawurlencode($lang);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
		curl_setopt($ch, CURLOPT_TIMEOUT, 6);
		$response = curl_exec($ch);
		if ($response === false) {
			curl_close($ch);
			return null;
		}
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($httpCode < 200 || $httpCode >= 300) {
			return null;
		}
		$data = json_decode($response, true);
		if (!is_array($data) || empty($data['page']) || !is_array($data['page'])) {
			return null;
		}
		$page = $data['page'];
		$alternate = array();
		if (isset($page['alternate']) && is_array($page['alternate'])) {
			foreach ($page['alternate'] as $item) {
				if (!is_array($item)) {
					continue;
				}
				$alternate[] = array(
					'url' => isset($item['url']) ? trim((string)$item['url']) : '',
					'lang' => isset($item['lang']) ? trim((string)$item['lang']) : '',
				);
			}
		}
		return array(
			'query' => isset($page['query']) ? (string)$page['query'] : '',
			'seo_slug' => isset($page['seo_slug']) ? (string)$page['seo_slug'] : '',
			'url' => isset($page['url']) ? (string)$page['url'] : '',
			'language' => isset($page['language']) ? (string)$page['language'] : '',
			'alternate' => $alternate,
			'h1' => isset($page['h1']) ? (string)$page['h1'] : '',
			'meta_title' => isset($page['meta_title']) ? (string)$page['meta_title'] : '',
			'meta_description' => isset($page['meta_description']) ? (string)$page['meta_description'] : '',
			'meta_keywords' => isset($page['meta_keywords']) ? (string)$page['meta_keywords'] : '',
			'short_description' => isset($page['short_description']) ? (string)$page['short_description'] : '',
			'description' => isset($page['description']) ? (string)$page['description'] : '',
			'product_ids' => isset($page['product_ids']) && is_array($page['product_ids']) ? $page['product_ids'] : array(),
		);
	}

	private function fetchFilteredSearchData($state, $lang) {
		$api_key = trim((string)$this->config->get('module_eleads_api_key'));
		$project_id = (int)$this->config->get('module_eleads_project_id');
		if ($api_key === '') {
			return null;
		}

		require_once DIR_SYSTEM . 'library/eleads/api_routes.php';
		$params = array(
			'language' => $this->normalizeFeedLang($lang),
			'page' => max(1, (int)$state['page']),
			'per_page' => max(1, (int)$state['limit']),
		);
		if ($project_id > 0) {
			$params['project_id'] = (string)$project_id;
		}
		if ((string)$state['query'] !== '') {
			$params['query'] = (string)$state['query'];
		}
		$category_fallback = '';
		if ((string)$state['category'] !== '') {
			$category_value = (string)$state['category'];
			$category_id = $this->resolveCategoryIdByName($category_value);
			$params['category'] = $category_id !== '' ? $category_id : $category_value;
			if ($category_id !== '' && $category_id !== $category_value) {
				$category_fallback = $category_value;
			}
		}
		if ((string)$state['sort'] !== '') {
			$params['sort'] = (string)$state['sort'];
		}
		if (!empty($state['filters'])) {
			$params['filters'] = json_encode($state['filters']);
		}

		$data = $this->requestSearchFilters($params, $api_key);
		if (is_array($data) && $category_fallback !== '' && (int)$data['total'] <= 0) {
			$params['category'] = $category_fallback;
			$fallback_data = $this->requestSearchFilters($params, $api_key);
			if (is_array($fallback_data)) {
				$data = $fallback_data;
			}
		}

		return $data;
	}

	private function requestSearchFilters($params, $api_key) {
		$url = EleadsApiRoutes::SEARCH_FILTERS . '?' . http_build_query((array)$params);
		$ch = curl_init();
		if ($ch === false) {
			return null;
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json',
		));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
		curl_setopt($ch, CURLOPT_TIMEOUT, 8);
		$response = curl_exec($ch);
		if ($response === false) {
			curl_close($ch);
			return null;
		}
		$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($http_code < 200 || $http_code >= 300) {
			return null;
		}

		$data = json_decode($response, true);
		if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
			return null;
		}

		$items = array();
		if (isset($data['results']['items']) && is_array($data['results']['items'])) {
			$items = $data['results']['items'];
		}

		return array(
			'query' => isset($data['query']) ? (string)$data['query'] : '',
			'total' => isset($data['total']) ? (int)$data['total'] : 0,
			'page' => isset($data['page']) ? (int)$data['page'] : 1,
			'pages' => isset($data['pages']) ? (int)$data['pages'] : 1,
			'items' => $items,
			'categories' => isset($data['results']['categories']) && is_array($data['results']['categories']) ? $data['results']['categories'] : array(),
			'facets' => isset($data['facets']) && is_array($data['facets']) ? $data['facets'] : array(),
		);
	}

	private function parseFilterStateFromPath($path) {
		$state = array(
			'query' => '',
			'category' => '',
			'sort' => '',
			'page' => 1,
			'limit' => 20,
			'filters' => array(),
		);
		$path = trim((string)$path, '/');
		if ($path === '') {
			return $state;
		}

		$parts = explode('/', $path);
		foreach ($parts as $idx => $part) {
			if (strpos($part, 'c-') === 0) {
				$state['category'] = rawurldecode(substr($part, 2));
				continue;
			}
			if (strpos($part, 's-') === 0) {
				$sort = rawurldecode(substr($part, 2));
				if (in_array($sort, array('price_asc', 'price_desc', 'popularity'), true)) {
					$state['sort'] = $sort;
				}
				continue;
			}
			if (strpos($part, 'p-') === 0) {
				$page = (int)substr($part, 2);
				$state['page'] = $page > 0 ? $page : 1;
				continue;
			}
			if (strpos($part, 'l-') === 0) {
				$limit = (int)substr($part, 2);
				$state['limit'] = $limit > 0 ? $limit : 20;
				continue;
			}
			if (strpos($part, 'f-') === 0 && strpos($part, '~') !== false) {
				$raw = substr($part, 2);
				$pair = explode('~', $raw, 2);
				if (count($pair) === 2) {
					$enc_name = strtr($pair[0], '-_', '+/');
					$enc_name .= str_repeat('=', (4 - strlen($enc_name) % 4) % 4);
					$enc_value = strtr($pair[1], '-_', '+/');
					$enc_value .= str_repeat('=', (4 - strlen($enc_value) % 4) % 4);
					$name = base64_decode($enc_name, true);
					$value = base64_decode($enc_value, true);
					$name = $name === false ? '' : trim((string)$name);
					$value = $value === false ? '' : trim((string)$value);
					if ($name !== '' && $value !== '') {
						if (!isset($state['filters'][$name]) || !is_array($state['filters'][$name])) {
							$state['filters'][$name] = array();
						}
						$state['filters'][$name][] = $value;
					}
				}
				continue;
			}
			if (strpos($part, 'f-') === 0) {
				$encoded = substr($part, 2);
				$json = base64_decode(strtr($encoded, '-_', '+/'), true);
				if ($json === false) {
					continue;
				}
				$filters = json_decode($json, true);
				if (!is_array($filters)) {
					continue;
				}
				$normalized = array();
				foreach ($filters as $key => $values) {
					$key = trim((string)$key);
					if ($key === '' || !is_array($values)) {
						continue;
					}
					$row = array();
					foreach ($values as $value) {
						$value = trim((string)$value);
						if ($value !== '') {
							$row[] = $value;
						}
					}
					if ($row) {
						$normalized[$key] = array_values(array_unique($row));
					}
				}
				$state['filters'] = $normalized;
				continue;
			}

			if ($part !== '' && strpos($part, '-') !== false) {
				if ($idx === 0 && $state['category'] === '') {
					$candidate = trim(rawurldecode(str_replace('_', ' ', $part)));
					if ($candidate !== '' && $this->resolveCategoryIdByName($candidate) !== '') {
						$state['category'] = $candidate;
						continue;
					}
				}
				$dash_pos = strpos($part, '-');
				$attr = $dash_pos === false ? '' : trim(rawurldecode(str_replace('_', ' ', substr($part, 0, $dash_pos))));
				$value = $dash_pos === false ? '' : trim(rawurldecode(str_replace('_', ' ', substr($part, $dash_pos + 1))));
				if ($attr !== '' && $value !== '') {
					if (!isset($state['filters'][$attr]) || !is_array($state['filters'][$attr])) {
						$state['filters'][$attr] = array();
					}
					$state['filters'][$attr][] = $value;
					continue;
				}
			}

			if ($state['category'] === '' && $part !== '') {
				$state['category'] = trim(rawurldecode(str_replace('_', ' ', $part)));
			}
		}

		if (!empty($state['filters'])) {
			foreach ($state['filters'] as $name => $values) {
				$state['filters'][$name] = array_values(array_unique(array_map('strval', (array)$values)));
			}
		}

		return $state;
	}

	private function buildFilterPageUrl($state, $lang) {
		$base = $this->getSeoBaseUrl();
		$lang = $this->resolveSeoSitemapLanguage($lang);
		$parts = array();
		if (!empty($state['category'])) {
			$parts[] = str_replace('%20', '_', rawurlencode((string)$state['category']));
		}
		if (!empty($state['filters']) && is_array($state['filters'])) {
			$filters = $state['filters'];
			ksort($filters);
			foreach ($filters as $name => $values) {
				$name = trim((string)$name);
				if ($name === '' || !is_array($values)) {
					continue;
				}
				$vals = array_values(array_unique(array_map('strval', $values)));
				sort($vals);
				foreach ($vals as $value) {
					$value = trim((string)$value);
					if ($value === '') {
						continue;
					}
					$name_part = str_replace('%20', '_', rawurlencode($name));
					$value_part = str_replace('%20', '_', rawurlencode($value));
					$parts[] = $name_part . '-' . $value_part;
				}
			}
		}
		$tail = $parts ? '/' . implode('/', $parts) : '';
		$url = $base . '/' . rawurlencode($lang) . '/e-filter' . $tail;
		$query = array();
		if (!empty($state['query'])) {
			$query['q'] = (string)$state['query'];
		}
		if (!empty($state['sort'])) {
			$query['sort'] = (string)$state['sort'];
		}
		$page = isset($state['page']) ? (int)$state['page'] : 1;
		if ($page > 1) {
			$query['page'] = $page;
		}
		$limit = isset($state['limit']) ? (int)$state['limit'] : 20;
		if ($limit > 0 && $limit !== 20) {
			$query['limit'] = $limit;
		}
		if ($query) {
			$url .= '?' . http_build_query($query);
		}
		return $url;
	}

	private function isFilterPageNoindex($state, $total) {
		$filters = isset($state['filters']) && is_array($state['filters']) ? $state['filters'] : array();
		$filter_depth = count($filters);
		$path_depth = $filter_depth + (!empty($state['category']) ? 1 : 0);

		$max_depth = (int)$this->config->get('module_eleads_filter_max_index_depth');
		if ($max_depth <= 0) {
			$max_depth = 2;
		}
		if ($path_depth > $max_depth) {
			return true;
		}
		if ($filter_depth <= 1) {
			return false;
		}

		$whitelist = $this->resolveWhitelistAttributeNames((array)$this->config->get('module_eleads_filter_whitelist_attributes'));
		if (!$whitelist) {
			return true;
		}
		foreach (array_keys($filters) as $key) {
			$key_lc = function_exists('mb_strtolower') ? mb_strtolower((string)$key) : strtolower((string)$key);
			if (!isset($whitelist[$key_lc])) {
				return true;
			}
		}

		return false;
	}

	private function resolveWhitelistAttributeNames($attribute_ids) {
		$names = array();
		$language_id = (int)$this->config->get('config_language_id');
		foreach ((array)$attribute_ids as $attribute_id) {
			$aid = (int)$attribute_id;
			if ($aid <= 0) {
				continue;
			}
			$query = $this->db->query("SELECT name FROM " . DB_PREFIX . "attribute_description WHERE attribute_id = '" . $aid . "' AND language_id = '" . $language_id . "' LIMIT 1");
			if (!empty($query->row['name'])) {
				$name = function_exists('mb_strtolower') ? mb_strtolower((string)$query->row['name']) : strtolower((string)$query->row['name']);
				$names[$name] = true;
			}
		}
		return $names;
	}

	private function resolveCategoryNameById($category_id) {
		$cid = (int)$category_id;
		if ($cid <= 0) {
			return '';
		}
		$language_id = (int)$this->config->get('config_language_id');
		if ($language_id > 0) {
			$query = $this->db->query("SELECT name FROM " . DB_PREFIX . "category_description WHERE category_id = '" . $cid . "' AND language_id = '" . $language_id . "' LIMIT 1");
			if (!empty($query->row['name'])) {
				return trim(html_entity_decode((string)$query->row['name'], ENT_QUOTES, 'UTF-8'));
			}
		}
		$query = $this->db->query("SELECT name FROM " . DB_PREFIX . "category_description WHERE category_id = '" . $cid . "' ORDER BY language_id ASC LIMIT 1");
		return !empty($query->row['name']) ? trim(html_entity_decode((string)$query->row['name'], ENT_QUOTES, 'UTF-8')) : '';
	}

	private function resolveCategoryIdByName($category_name) {
		$name = trim(rawurldecode(str_replace('_', ' ', (string)$category_name)));
		if ($name === '') {
			return '';
		}
		if (ctype_digit($name)) {
			return $name;
		}
		$language_id = (int)$this->config->get('config_language_id');
		$norm_target = $this->normalizeCategoryText($name);
		$queries = array();
		if ($language_id > 0) {
			$queries[] = "SELECT category_id, name FROM " . DB_PREFIX . "category_description WHERE language_id = '" . $language_id . "'";
		}
		$queries[] = "SELECT category_id, name FROM " . DB_PREFIX . "category_description";

		foreach ($queries as $sql) {
			$query = $this->db->query($sql);
			foreach ((array)$query->rows as $row) {
				if ($this->normalizeCategoryText((string)$row['name']) === $norm_target) {
					return (string)(int)$row['category_id'];
				}
			}
		}
		return '';
	}

	private function normalizeCategoryText($text) {
		$text = html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8');
		$text = trim(str_replace('_', ' ', $text));
		return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
	}

	private function buildFilterSeoContent($state, $store_language_code = '') {
		$templates = (array)$this->config->get('module_eleads_filter_templates');
		$selected = null;
		$category_id = '';
		$category_name = '';
		$current_depth = (int)count((array)$state['filters']);

		if (!empty($state['category'])) {
			$category_name = (string)$state['category'];
			$category_id = $this->resolveCategoryIdByName($category_name);
			if ($category_id !== '') {
				$resolved = $this->resolveCategoryNameById((string)$category_id);
				if ($resolved !== '') {
					$category_name = $resolved;
				}
			}
		}

		foreach ((array)$templates as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row_depth = isset($row['depth']) ? (int)$row['depth'] : 1;
			if ($row_depth < 0) {
				$row_depth = 0;
			}
			if ($row_depth !== $current_depth) {
				continue;
			}
			$row_category_id = isset($row['category_id']) ? (int)$row['category_id'] : 0;
			if ($row_category_id > 0 && (int)$category_id !== $row_category_id) {
				continue;
			}
			if ($row_category_id > 0) {
				$selected = $row;
				break;
			}
			if ($selected === null) {
				$selected = $row;
			}
		}

		if (!$selected) {
			return array(
				'h1' => '',
				'meta_title' => '',
				'meta_description' => '',
				'meta_keywords' => '',
				'short_description' => '',
				'description' => '',
			);
		}

		$translation = $this->resolveFilterTemplateTranslation($selected, $store_language_code);
		$tokens = $this->buildFilterTemplateTokens($state, $category_name, $category_id);
		$result = array();
		foreach (array('h1', 'meta_title', 'meta_description', 'meta_keywords', 'short_description', 'description') as $field) {
			$template = isset($translation[$field]) ? (string)$translation[$field] : '';
			$result[$field] = $this->replaceFilterTemplateTokens($template, $tokens);
		}
		return $result;
	}

	private function resolveFilterTemplateTranslation($template, $store_language_code) {
		$translations = isset($template['translations']) && is_array($template['translations']) ? $template['translations'] : array();
		$store_language_code = (string)$store_language_code;
		if ($store_language_code !== '' && isset($translations[$store_language_code]) && is_array($translations[$store_language_code])) {
			return $translations[$store_language_code];
		}
		$target_short = $this->normalizeFeedLang($store_language_code);
		if ($target_short !== '') {
			foreach ($translations as $lang_code => $fields) {
				if (!is_array($fields)) {
					continue;
				}
				if ($this->normalizeFeedLang((string)$lang_code) === $target_short) {
					return $fields;
				}
			}
		}
		if ($translations) {
			$first = reset($translations);
			if (is_array($first)) {
				return $first;
			}
		}
		return array(
			'h1' => isset($template['h1']) ? (string)$template['h1'] : '',
			'meta_title' => isset($template['meta_title']) ? (string)$template['meta_title'] : '',
			'meta_description' => isset($template['meta_description']) ? (string)$template['meta_description'] : '',
			'meta_keywords' => isset($template['meta_keywords']) ? (string)$template['meta_keywords'] : '',
			'short_description' => isset($template['short_description']) ? (string)$template['short_description'] : '',
			'description' => isset($template['description']) ? (string)$template['description'] : '',
		);
	}

	private function buildFilterTemplateTokens($state, $category_name, $category_id) {
		$attributes = array();
		foreach ((array)$state['filters'] as $name => $values) {
			$attr_name = trim((string)$name);
			if ($attr_name === '') {
				continue;
			}
			$vals = array();
			foreach ((array)$values as $val) {
				$val = trim((string)$val);
				if ($val !== '') {
					$vals[] = $val;
				}
			}
			$vals = array_values(array_unique($vals));
			if ($vals) {
				$attributes[] = array(
					'name' => $attr_name,
					'value' => implode('; ', $vals),
				);
			}
		}

		$brand = '';
		foreach ($attributes as $attr) {
			$key = function_exists('mb_strtolower') ? mb_strtolower($attr['name']) : strtolower($attr['name']);
			if (in_array($key, array('brand', 'manufacturer', 'vendor', 'бренд', 'производитель'), true)) {
				$brand = $attr['value'];
				break;
			}
		}

		$tokens = array(
			'{$category}' => (string)$category_name,
			'{$category_h1}' => (string)$this->resolveCategoryH1($category_id, $category_name),
			'{$brand}' => (string)$brand,
			'{$sitename}' => (string)$this->config->get('config_name'),
		);

		$max_depth = (int)$this->config->get('module_eleads_filter_max_index_depth');
		if ($max_depth < 1) {
			$max_depth = 1;
		}
		for ($i = 1; $i <= 20; $i++) {
			$index = $i - 1;
			$name_key = $i === 1 ? '{$attribute_name}' : '{$attribute_name_' . $i . '}';
			$val_key = $i === 1 ? '{$attributes_val}' : '{$attributes_val_' . $i . '}';
			if ($i <= $max_depth) {
				$tokens[$name_key] = isset($attributes[$index]) ? $attributes[$index]['name'] : '';
				$tokens[$val_key] = isset($attributes[$index]) ? $attributes[$index]['value'] : '';
			} else {
				$tokens[$name_key] = '';
				$tokens[$val_key] = '';
			}
		}

		return $tokens;
	}

	private function replaceFilterTemplateTokens($template, $tokens) {
		$template = (string)$template;
		if ($template === '') {
			return '';
		}
		return str_replace(array_keys($tokens), array_values($tokens), $template);
	}

	private function resolveCategoryH1($category_id, $fallback) {
		$cid = (int)$category_id;
		if ($cid <= 0) {
			return (string)$fallback;
		}
		$columns = $this->db->query("SHOW COLUMNS FROM " . DB_PREFIX . "category_description LIKE 'meta_h1'")->rows;
		if (!$columns) {
			return (string)$fallback;
		}
		$language_id = (int)$this->config->get('config_language_id');
		$sql = "SELECT meta_h1 FROM " . DB_PREFIX . "category_description WHERE category_id = '" . $cid . "'";
		if ($language_id > 0) {
			$sql .= " AND language_id = '" . $language_id . "'";
		}
		$sql .= " LIMIT 1";
		$query = $this->db->query($sql);
		if (!empty($query->row['meta_h1'])) {
			return trim((string)$query->row['meta_h1']);
		}
		return (string)$fallback;
	}

	private function normalizeProductIds($product_ids) {
		$ids = array();
		foreach ((array)$product_ids as $value) {
			$id = (int)$value;
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	private function extractProductIdsFromSearchItems($items) {
		$ids = array();
		foreach ((array)$items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$id = 0;
			if (isset($item['id'])) {
				$id = (int)$item['id'];
			} elseif (isset($item['offer_id'])) {
				$id = (int)$item['offer_id'];
			}
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	private function buildProducts($product_ids, $store_language_code) {
		$this->load->model('catalog/product');
		$this->load->model('tool/image');
		$products = array();
		$size = $this->getProductImageSize();
		$description_limit = $this->getProductDescriptionLength();
		foreach ($product_ids as $product_id) {
			$result = $this->model_catalog_product->getProduct($product_id);
			if (!$result) {
				continue;
			}

			if ($result['image']) {
				$image = $this->model_tool_image->resize($result['image'], $size['width'], $size['height']);
			} else {
				$image = $this->model_tool_image->resize('placeholder.png', $size['width'], $size['height']);
			}

			if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
				$price = $this->currency->format($this->tax->calculate($result['price'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
			} else {
				$price = false;
			}

			if (!is_null($result['special']) && (float)$result['special'] >= 0) {
				$special = $this->currency->format($this->tax->calculate($result['special'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				$tax_price = (float)$result['special'];
			} else {
				$special = false;
				$tax_price = (float)$result['price'];
			}

			if ($this->config->get('config_tax')) {
				$tax = $this->currency->format($tax_price, $this->session->data['currency']);
			} else {
				$tax = false;
			}

			if ($this->config->get('config_review_status')) {
				$rating = (int)$result['rating'];
			} else {
				$rating = false;
			}

			$products[] = array(
				'product_id'  => $result['product_id'],
				'thumb'       => $image,
				'name'        => $result['name'],
				'description' => utf8_substr(strip_tags(html_entity_decode($result['description'], ENT_QUOTES, 'UTF-8')), 0, $description_limit) . '..',
				'price'       => $price,
				'special'     => $special,
				'tax'         => $tax,
				'rating'      => $rating,
				'href'        => $this->url->link('product/product', 'language=' . $store_language_code . '&product_id=' . $result['product_id'])
			);
		}
		return $products;
	}

	private function getProductImageSize() {
		$theme = (string)$this->config->get('config_theme');
		$width = (int)$this->config->get($theme . '_image_product_width');
		$height = (int)$this->config->get($theme . '_image_product_height');
		if ($width <= 0) {
			$width = 200;
		}
		if ($height <= 0) {
			$height = 200;
		}
		return array('width' => $width, 'height' => $height);
	}

	private function getProductDescriptionLength() {
		$theme = (string)$this->config->get('config_theme');
		$length = (int)$this->config->get($theme . '_product_description_length');
		if ($length <= 0) {
			$length = (int)$this->config->get('config_product_description_length');
		}
		if ($length <= 0) {
			$length = 100;
		}
		return $length;
	}

	private function readSeoSitemapSlugs() {
		$entries = $this->readSeoSitemapEntries();
		$slugs = array();
		foreach ($entries as $entry) {
			$slugs[] = $entry['slug'];
		}
		return array_values(array_unique($slugs));
	}

	private function writeSeoSitemapSlugs($slugs) {
		$lang = $this->resolveSeoSitemapLanguage((string)$this->config->get('config_language'));
		$entries = array();
		foreach ((array)$slugs as $slug) {
			$slug = trim((string)$slug);
			if ($slug === '') {
				continue;
			}
			$entries[] = array('lang' => $lang, 'slug' => $slug);
		}
		return $this->writeSeoSitemapEntries($entries);
	}

	private function readSeoSitemapEntries() {
		$path = $this->getSeoSitemapPath();
		if (!is_file($path)) {
			return array();
		}
		$content = (string)file_get_contents($path);
		if ($content === '') {
			return array();
		}
		$matches = array();
		preg_match_all('#<loc>([^<]+)</loc>#i', $content, $matches);
		if (empty($matches[1])) {
			return array();
		}
		$entries = array();
		foreach ($matches[1] as $value) {
			$loc = html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
			$path = parse_url($loc, PHP_URL_PATH);
			if (!is_string($path)) {
				continue;
			}
			$path = trim($path, '/');
			$lang = '';
			$slug = '';
			if (preg_match('#^([^/]+)/e-search/(.+)$#', $path, $m)) {
				$lang = $this->resolveSeoSitemapLanguage($m[1]);
				$slug = $m[2];
			} elseif (strpos($path, 'e-search/') === 0) {
				$tail = substr($path, strlen('e-search/'));
				if ($tail === false || $tail === '') {
					continue;
				}
				$parts = explode('/', $tail, 2);
				if (count($parts) === 2) {
					$lang = $this->resolveSeoSitemapLanguage($parts[0]);
					$slug = $parts[1];
				} else {
					$slug = $parts[0];
				}
			} else {
				continue;
			}
			$slug = trim((string)urldecode($slug));
			if ($slug === '') {
				continue;
			}
			if ($lang === '') {
				$lang = $this->resolveSeoSitemapLanguage((string)$this->config->get('config_language'));
			}
			$key = $lang . '|' . $slug;
			$entries[$key] = array('lang' => $lang, 'slug' => $slug);
		}
		return array_values($entries);
	}

	private function writeSeoSitemapEntries($entries) {
		$path = $this->getSeoSitemapPath();
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$base_url = $this->getSeoBaseUrl();
		$rows = array();
		$unique = array();
		foreach ((array)$entries as $entry) {
			$lang = $this->resolveSeoSitemapLanguage(isset($entry['lang']) ? (string)$entry['lang'] : '');
			$slug = trim(isset($entry['slug']) ? (string)$entry['slug'] : '');
			if ($slug === '') {
				continue;
			}
			if ($lang === '') {
				$lang = $this->resolveSeoSitemapLanguage((string)$this->config->get('config_language'));
			}
			$key = $lang . '|' . $slug;
			if (isset($unique[$key])) {
				continue;
			}
			$unique[$key] = true;
			$loc = $base_url . '/' . rawurlencode($lang) . '/e-search/' . rawurlencode($slug);
			$rows[] = '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc></url>';
		}
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
		if ($rows) {
			$xml .= implode("\n", $rows) . "\n";
		}
		$xml .= "</urlset>\n";
		return @file_put_contents($path, $xml) !== false;
	}

	private function resolveSeoSitemapLanguage($lang) {
		$lang = strtolower(trim((string)$lang));
		$normalized = $this->normalizeFeedLang($lang);
		if ($normalized === 'en') {
			return 'en';
		}
		if ($normalized === 'ru') {
			return 'ru';
		}
		if ($normalized === 'uk') {
			return 'ua';
		}

		if (preg_match('/^[a-z]{2}/', $lang, $m)) {
			return $m[1];
		}

		return 'en';
	}

	private function resolveStoreLanguageCode($lang) {
		$this->load->model('localisation/language');
		$languages = (array)$this->model_localisation_language->getLanguages();
		$lang = strtolower(trim((string)$lang));
		$normalized = $this->normalizeFeedLang($lang);

		foreach ($languages as $language) {
			if (isset($language['status']) && !$language['status']) {
				continue;
			}
			$code = strtolower(isset($language['code']) ? (string)$language['code'] : '');
			if ($code !== '' && $code === $lang) {
				return $code;
			}
			if ($code !== '' && $this->normalizeFeedLang($code) === $normalized) {
				return $code;
			}
		}

		return (string)$this->config->get('config_language');
	}

	private function applyStoreLanguage($code) {
		$code = (string)$code;
		if ($code === '') {
			return;
		}

		$this->load->model('localisation/language');
		$languages = (array)$this->model_localisation_language->getLanguages();
		if (!isset($languages[$code])) {
			return;
		}

		$this->session->data['language'] = $code;
		$this->config->set('config_language', $code);
		$this->config->set('config_language_id', (int)$languages[$code]['language_id']);

		$language = new Language($code);
		$language->load($code);
		$this->registry->set('language', $language);
		$this->language = $language;
	}

	private function findAlternateUrl($alternates, $target_short) {
		foreach ((array)$alternates as $alternate) {
			if (!is_array($alternate)) {
				continue;
			}
			$alt_short = $this->resolveSeoSitemapLanguage(isset($alternate['lang']) ? (string)$alternate['lang'] : '');
			$url = isset($alternate['url']) ? trim((string)$alternate['url']) : '';
			if ($url !== '' && $alt_short === $target_short) {
				return $url;
			}
		}
		return '';
	}

	private function buildSeoPageUrl($lang, $slug) {
		$base = $this->getSeoBaseUrl();
		$lang = $this->resolveSeoSitemapLanguage($lang);
		return $base . '/' . rawurlencode((string)$lang) . '/e-search/' . rawurlencode((string)$slug);
	}
}
