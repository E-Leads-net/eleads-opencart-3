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
			$this->response->setOutput('Method Not Allowed');
			return;
		}

		$api_key = (string)$this->config->get('module_eleads_api_key');
		$auth = $this->getBearerToken();
		if ($api_key === '' || $auth === '' || !hash_equals($api_key, $auth)) {
			$this->response->addHeader('HTTP/1.1 401 Unauthorized');
			$this->response->setOutput('Unauthorized');
			return;
		}

		$payload = json_decode((string)file_get_contents('php://input'), true);
		if (!is_array($payload)) {
			$this->response->addHeader('HTTP/1.1 400 Bad Request');
			$this->response->setOutput('Invalid payload');
			return;
		}

		$action = isset($payload['action']) ? (string)$payload['action'] : '';
		$slug = isset($payload['slug']) ? trim((string)$payload['slug']) : '';
		$new_slug = isset($payload['new_slug']) ? trim((string)$payload['new_slug']) : '';

		if (!in_array($action, array('create', 'update', 'delete'), true) || $slug === '' || ($action === 'update' && $new_slug === '')) {
			$this->response->addHeader('HTTP/1.1 422 Unprocessable Entity');
			$this->response->setOutput('Invalid action');
			return;
		}

		$slugs = $this->readSeoSitemapSlugs();
		$slugs = array_values(array_filter(array_unique($slugs), 'strlen'));

		if ($action === 'create') {
			if (!in_array($slug, $slugs, true)) {
				$slugs[] = $slug;
			}
		} elseif ($action === 'delete') {
			$slugs = array_values(array_filter($slugs, function ($value) use ($slug) {
				return $value !== $slug;
			}));
		} else {
			$slugs = array_values(array_filter($slugs, function ($value) use ($slug) {
				return $value !== $slug;
			}));
			if (!in_array($new_slug, $slugs, true)) {
				$slugs[] = $new_slug;
			}
		}

		$this->writeSeoSitemapSlugs($slugs);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(array('ok' => true)));
	}

	public function seoPage() {
		$slug = isset($this->request->get['slug']) ? trim((string)$this->request->get['slug']) : '';
		if ($slug === '') {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			return;
		}

		$slugs = $this->readSeoSitemapSlugs();
		if (!in_array($slug, $slugs, true)) {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			return;
		}

		$page = $this->fetchSeoPage($slug);
		if (!$page) {
			$this->response->addHeader('HTTP/1.1 404 Not Found');
			return;
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
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/home'))
		);
		$data['breadcrumbs'][] = array(
			'text' => $title,
			'href' => $this->url->link('extension/module/eleads/seoPage', 'slug=' . urlencode($slug))
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
		$data['compare'] = $this->url->link('product/compare');
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
		$data['products'] = $this->buildProducts($product_ids);
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

	private function fetchSeoPage($slug) {
		$api_key = trim((string)$this->config->get('module_eleads_api_key'));
		if ($api_key === '') {
			return null;
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
		curl_setopt($ch, CURLOPT_URL, EleadsApiRoutes::SEO_PAGE . rawurlencode($slug));
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
		return array(
			'query' => isset($page['query']) ? (string)$page['query'] : '',
			'seo_slug' => isset($page['seo_slug']) ? (string)$page['seo_slug'] : '',
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

	private function buildProducts($product_ids) {
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
				'href'        => $this->url->link('product/product', 'product_id=' . $result['product_id'])
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
		$path = $this->getSeoSitemapPath();
		if (!is_file($path)) {
			return array();
		}
		$content = (string)file_get_contents($path);
		if ($content === '') {
			return array();
		}
		$matches = array();
		preg_match_all('#<loc>[^<]*/e-search/([^<]+)</loc>#i', $content, $matches);
		if (empty($matches[1])) {
			return array();
		}
		$slugs = array();
		foreach ($matches[1] as $value) {
			$slug = urldecode(htmlspecialchars_decode($value, ENT_QUOTES));
			$slug = trim($slug);
			if ($slug !== '') {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	private function writeSeoSitemapSlugs($slugs) {
		$path = $this->getSeoSitemapPath();
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$base_url = $this->getSeoBaseUrl();
		$rows = array();
		foreach ((array)$slugs as $slug) {
			$slug = trim((string)$slug);
			if ($slug === '') {
				continue;
			}
			$loc = $base_url . '/e-search/' . rawurlencode($slug);
			$rows[] = '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc></url>';
		}
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
		if ($rows) {
			$xml .= implode("\n", $rows) . "\n";
		}
		$xml .= "</urlset>\n";
		@file_put_contents($path, $xml);
	}
}
