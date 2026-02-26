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

	public function feeds() {
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

		$access_key = trim((string)$this->config->get('module_eleads_access_key'));
		$this->load->model('localisation/language');
		$items = array();
		foreach ((array)$this->model_localisation_language->getLanguages() as $language) {
			if (isset($language['status']) && !$language['status']) {
				continue;
			}
			$code = isset($language['code']) ? (string)$language['code'] : '';
			$label = $this->resolveSeoSitemapLanguage($code);
			$feed_lang = $this->normalizeFeedLang($code);
			if ($label === '' || $feed_lang === '') {
				continue;
			}
			if (!isset($items[$label])) {
				$items[$label] = $this->buildFeedUrl($feed_lang, $access_key);
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array(
			'status' => 'ok',
			'count' => count($items),
			'items' => (object)$items,
		)));
	}

	public function filterPage() {
		require_once DIR_SYSTEM . 'library/eleads/api_routes.php';

		$this->load->language('product/category');

		$requested_store_code = isset($this->request->get['language'])
			? (string)$this->request->get['language']
			: (isset($this->session->data['language']) ? (string)$this->session->data['language'] : (string)$this->config->get('config_language'));
		if (isset($this->request->get['lang']) && (string)$this->request->get['lang'] !== '') {
			$requested_store_code = (string)$this->request->get['lang'];
		}
		$store_language_code = $this->resolveStoreLanguageCode($requested_store_code);

		$this->applyStoreLanguage($store_language_code);
		$api_language = $this->normalizeFeedLang($store_language_code);
		$project_id = $this->resolveFilterProjectId();

		$this->document->setTitle('E-Filter');

		$data = array();
		$data['breadcrumbs'] = array(
			array(
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/home', 'language=' . $store_language_code),
			),
			array(
				'text' => 'E-Filter',
				'href' => $this->buildFilterBaseUrl(),
			),
		);

		$data['heading_title'] = 'E-Filter';
		$data['text_empty'] = $this->language->get('text_empty');
		$data['text_sort'] = $this->language->get('text_sort');
		$data['text_limit'] = $this->language->get('text_limit');
		$data['button_list'] = $this->language->get('button_list');
		$data['button_grid'] = $this->language->get('button_grid');
		$data['button_prev'] = 'Prev';
		$data['button_next'] = 'Next';
		$data['text_category'] = 'Categories';
		$data['text_all_categories'] = 'All categories';
		$data['text_loading'] = 'Loading...';
		$data['text_no_project'] = 'project_id is not set. Save a valid API key in module settings first.';

		$data['filter_api_url'] = EleadsApiRoutes::SEARCH_FILTERS;
		$data['filter_project_id'] = $project_id;
		$data['filter_language'] = $api_language;
		$data['filter_base_url'] = $this->buildFilterBaseUrl();
		$data['filter_state_json'] = json_encode($this->parseFilterStateFromQuery());
		$data['filter_custom_css'] = $this->sanitizeFilterCustomCss((string)$this->config->get('module_eleads_filter_custom_css'));

		$data['sort_options'] = array(
			array('value' => '', 'name' => 'Default'),
			array('value' => 'price_asc', 'name' => 'Price (Low > High)'),
			array('value' => 'price_desc', 'name' => 'Price (High > Low)'),
			array('value' => 'popularity', 'name' => 'Popularity'),
		);
		$data['limit_options'] = array(20, 25, 50, 75, 100);

		$data['column_left'] = $this->load->controller('extension/module/eleads/filterSidebar');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/eleads/filter', $data));
	}

	public function filterSidebar() {
		$data = array(
			'filter_custom_css' => $this->sanitizeFilterCustomCss((string)$this->config->get('module_eleads_filter_custom_css')),
		);
		return $this->load->view('extension/eleads/filter_sidebar', $data);
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

	private function buildProducts($product_ids, $store_language_code) {
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

	private function buildFeedUrl($feed_lang, $access_key) {
		$url = $this->getSeoBaseUrl() . '/eleads-yml/' . rawurlencode((string)$feed_lang) . '.xml';
		if ($access_key !== '') {
			$url .= '?key=' . rawurlencode((string)$access_key);
		}
		return $url;
	}

	private function buildFilterBaseUrl() {
		$base = $this->getSeoBaseUrl();
		if (isset($this->request->get['lang']) && (string)$this->request->get['lang'] !== '') {
			$lang = $this->resolveSeoSitemapLanguage((string)$this->request->get['lang']);
			return $base . '/' . rawurlencode($lang) . '/e-filter';
		}

		return $base . '/e-filter';
	}

	private function parseFilterStateFromQuery() {
		$state = array(
			'query' => isset($this->request->get['query']) ? trim((string)$this->request->get['query']) : '',
			'category' => isset($this->request->get['category']) ? trim((string)$this->request->get['category']) : '',
			'sort' => isset($this->request->get['sort']) ? trim((string)$this->request->get['sort']) : '',
			'page' => max(1, (int)(isset($this->request->get['page']) ? $this->request->get['page'] : 1)),
			'per_page' => max(1, (int)(isset($this->request->get['per_page']) ? $this->request->get['per_page'] : 20)),
			'filters' => array(),
		);

		if (isset($this->request->get['filters'])) {
			$decoded = json_decode((string)$this->request->get['filters'], true);
			if (is_array($decoded)) {
				foreach ($decoded as $name => $values) {
					$name = trim((string)$name);
					if ($name === '') {
						continue;
					}
					$row = array();
					foreach ((array)$values as $value) {
						$value = trim((string)$value);
						if ($value !== '') {
							$row[] = $value;
						}
					}
					if (!empty($row)) {
						$state['filters'][$name] = array_values(array_unique($row));
					}
				}
			}
		}

		if (!in_array($state['sort'], array('', 'price_asc', 'price_desc', 'popularity'), true)) {
			$state['sort'] = '';
		}

		return $state;
	}

	private function sanitizeFilterCustomCss($css) {
		$css = str_replace(array('</style>', '</STYLE>'), '', (string)$css);
		return trim($css);
	}

	private function resolveFilterProjectId() {
		$project_id = (int)$this->config->get('module_eleads_project_id');
		if ($project_id > 0) {
			return $project_id;
		}

		$api_key = trim((string)$this->config->get('module_eleads_api_key'));
		if ($api_key === '') {
			return 0;
		}

		require_once DIR_SYSTEM . 'library/eleads/api_routes.php';
		$ch = curl_init();
		if ($ch === false) {
			return 0;
		}

		curl_setopt($ch, CURLOPT_URL, EleadsApiRoutes::TOKEN_STATUS);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json',
		));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
		curl_setopt($ch, CURLOPT_TIMEOUT, 6);
		$response = curl_exec($ch);
		if ($response === false) {
			curl_close($ch);
			return 0;
		}
		$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($http_code < 200 || $http_code >= 300) {
			return 0;
		}

		$data = json_decode($response, true);
		if (!is_array($data) || empty($data['ok']) || !isset($data['project_id'])) {
			return 0;
		}

		$project_id = (int)$data['project_id'];
		if ($project_id <= 0) {
			return 0;
		}

		return $project_id;
	}
}
