<?php
class ControllerExtensionModuleEleads extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/module/eleads');
		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');
		$this->load->model('localisation/language');
		$this->load->model('catalog/category');
		$this->load->model('catalog/attribute');
		$this->load->model('catalog/option');

		require_once DIR_SYSTEM . 'library/eleads/api_routes.php';

		$api_key = trim((string)$this->config->get('module_eleads_api_key'));
		$api_key_valid = false;
		$api_key_error = '';
		$api_key_submitted = null;
		$is_api_form = false;
		$seo_available = true;
		$seo_status = null;

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && isset($this->request->post['module_eleads_api_key_submit'])) {
			$is_api_form = true;
			$api_key_submitted = trim((string)$this->request->post['module_eleads_api_key']);
			$status = $this->getApiKeyStatusData($api_key_submitted);
			$this->storeProjectIdFromStatus($status);
			$api_key_status = $status !== null ? !empty($status['ok']) : false;
			$seo_status = $status !== null ? (!empty($status['seo_status'])) : null;
			if ($api_key_submitted !== '' && $api_key_status === true) {
				$settings_current = $this->model_setting_setting->getSetting('module_eleads');
				$settings_current['module_eleads_api_key'] = $api_key_submitted;
				$this->model_setting_setting->editSetting('module_eleads', $settings_current);
				$api_key = $api_key_submitted;
				$api_key_valid = true;
				$this->session->data['success'] = $this->language->get('text_api_key_saved');
			} else {
				$api_key_error = 'invalid';
			}
		}

		if (!$is_api_form && $api_key !== '') {
			$status = $this->getApiKeyStatusData($api_key);
			$this->storeProjectIdFromStatus($status);
			$api_key_valid = ($status !== null) ? !empty($status['ok']) : true;
			$seo_status = ($status !== null) ? !empty($status['seo_status']) : null;
			if (!$api_key_valid) {
				$api_key_error = 'invalid';
			}
		}

		if ($seo_status === false) {
			$seo_available = false;
			$settings_current = $this->model_setting_setting->getSetting('module_eleads');
			if (!empty($settings_current['module_eleads_seo_pages_enabled'])) {
				$settings_current['module_eleads_seo_pages_enabled'] = 0;
				$this->model_setting_setting->editSetting('module_eleads', $settings_current);
			}
			$this->syncSeoSitemap(false, (string)$api_key, $settings_current);
		}

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && !$is_api_form) {
			if (!$api_key_valid) {
				$this->session->data['error'] = $this->language->get('text_api_key_required');
			} elseif ($this->validate()) {
				$settings_current = $this->model_setting_setting->getSetting('module_eleads');
				$seo_prev = !empty($settings_current['module_eleads_seo_pages_enabled']);
				$settings_new = array_merge($settings_current, $this->request->post);
				if (empty($settings_new['module_eleads_api_key'])) {
					$settings_new['module_eleads_api_key'] = $api_key;
				}
				$this->model_setting_setting->editSetting('module_eleads', $settings_new);
				$this->syncWidgetLoaderTag(
					!empty($settings_new['module_eleads_status']),
					(string)$settings_new['module_eleads_api_key']
				);
				$seo_new = !empty($settings_new['module_eleads_seo_pages_enabled']);
				if ($seo_prev !== $seo_new || $seo_new) {
					$this->syncSeoSitemap($seo_new, (string)$settings_new['module_eleads_api_key'], $settings_new);
				}
				$this->session->data['success'] = $this->language->get('text_success');
				$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
			}
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['success'] = '';
		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		}
		$data['error'] = '';
		if (isset($this->session->data['error'])) {
			$data['error'] = $this->session->data['error'];
			unset($this->session->data['error']);
		}

		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/eleads', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/module/eleads', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		$data['text_enabled'] = $this->language->get('text_enabled');
		$data['text_disabled'] = $this->language->get('text_disabled');
		$data['button_save'] = $this->language->get('button_save');
		$data['button_cancel'] = $this->language->get('button_cancel');
		$data['tab_export'] = $this->language->get('tab_export');
		$data['tab_filter'] = $this->language->get('tab_filter');
		$data['tab_seo'] = $this->language->get('tab_seo');
		$data['tab_api'] = $this->language->get('tab_api');
		$data['tab_update'] = $this->language->get('tab_update');
		$data['entry_status'] = $this->language->get('entry_status');
		$data['entry_api_key'] = $this->language->get('entry_api_key');
		$data['entry_access_key'] = $this->language->get('entry_access_key');
		$data['entry_categories'] = $this->language->get('entry_categories');
		$data['entry_filter_attributes'] = $this->language->get('entry_filter_attributes');
		$data['entry_filter_option_values'] = $this->language->get('entry_filter_option_values');
		$data['entry_filter_attributes_toggle'] = $this->language->get('entry_filter_attributes_toggle');
		$data['entry_filter_option_values_toggle'] = $this->language->get('entry_filter_option_values_toggle');
		$data['entry_filter_pages_enabled'] = $this->language->get('entry_filter_pages_enabled');
		$data['entry_filter_max_index_depth'] = $this->language->get('entry_filter_max_index_depth');
		$data['entry_filter_min_products_noindex'] = $this->language->get('entry_filter_min_products_noindex');
		$data['entry_filter_min_products_recommended'] = $this->language->get('entry_filter_min_products_recommended');
		$data['entry_filter_whitelist_attributes'] = $this->language->get('entry_filter_whitelist_attributes');
		$data['help_filter_depth_rules'] = $this->language->get('help_filter_depth_rules');
		$data['help_filter_product_limits'] = $this->language->get('help_filter_product_limits');
		$data['entry_grouped'] = $this->language->get('entry_grouped');
		$data['entry_sync_enabled'] = $this->language->get('entry_sync_enabled');
		$data['entry_shop_name'] = $this->language->get('entry_shop_name');
		$data['entry_email'] = $this->language->get('entry_email');
		$data['entry_shop_url'] = $this->language->get('entry_shop_url');
		$data['entry_currency'] = $this->language->get('entry_currency');
		$data['entry_picture_limit'] = $this->language->get('entry_picture_limit');
		$data['entry_image_size'] = $this->language->get('entry_image_size');
		$data['entry_short_description_source'] = $this->language->get('entry_short_description_source');
		$data['entry_seo_pages'] = $this->language->get('entry_seo_pages');
		$data['entry_sitemap_url'] = $this->language->get('entry_sitemap_url');
		$data['text_seo_url_disabled'] = $this->language->get('text_seo_url_disabled');
		$data['seo_tab_available'] = $seo_available;
		$data['help_image_size'] = $this->language->get('help_image_size');
		$data['text_update'] = $this->language->get('text_update');
		$data['text_api_key_required'] = $this->language->get('text_api_key_required');
		$data['text_api_key_invalid'] = $this->language->get('text_api_key_invalid');
		$data['entry_api_key_title'] = $this->language->get('entry_api_key_title');
		$data['entry_api_key_hint'] = $this->language->get('entry_api_key_hint');

		$settings = $this->model_setting_setting->getSetting('module_eleads');
		$data = array_merge($data, $this->prepareSettingsData($settings));
		if (!$seo_available) {
			$data['module_eleads_seo_pages_enabled'] = 0;
		}
		$data['seo_url_enabled'] = (bool)$this->config->get('config_seo_url');
		$data['sitemap_url_full'] = $this->getCatalogBaseUrl() . '/e-search/sitemap.xml';
		$data['api_key_required'] = !$api_key_valid;
		$data['api_key_value'] = $api_key_submitted !== null ? $api_key_submitted : $api_key;
		$data['api_key_error'] = $api_key_error;

		if ($api_key_valid) {
			$data['languages'] = $this->model_localisation_language->getLanguages();
			$tree = $this->getCategoriesTreeNodes();
			$selected = array_flip(array_map('intval', (array)$data['module_eleads_categories']));
			$data['categories_tree_html'] = $this->renderCategoriesTreeHtml($tree, $selected);
			$data['attributes'] = $this->model_catalog_attribute->getAttributes();
			$options = $this->model_catalog_option->getOptions();
			foreach ($options as &$option) {
				$option['option_value'] = $this->model_catalog_option->getOptionValues($option['option_id']);
			}
			unset($option);
			$data['options'] = $options;

			$data['feed_urls'] = $this->buildFeedUrls($data['languages'], $data['module_eleads_access_key']);

			require_once DIR_SYSTEM . 'library/eleads/update_manager.php';
			$update_manager = new EleadsUpdateManager($this->registry);
			$data['update_info'] = $update_manager->getUpdateInfo();
			$data['update_url'] = $this->url->link('extension/module/eleads/update', 'user_token=' . $this->session->data['user_token'], true);
		} else {
			$data['languages'] = array();
			$data['categories_tree_html'] = '';
			$data['attributes'] = array();
			$data['options'] = array();
			$data['feed_urls'] = array();
			$data['update_info'] = array();
			$data['update_url'] = '';
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/eleads', $data));
	}

	private function checkApiKeyStatus($api_key) {
		$status = $this->getApiKeyStatusData($api_key);
		$this->storeProjectIdFromStatus($status);
		if ($status === null) {
			return null;
		}
		return !empty($status['ok']);
	}

	private function getApiKeyStatusData($api_key) {
		if ($api_key === '') {
			return false;
		}
		$ch = curl_init();
		if ($ch === false) {
			return null;
		}
		$headers = array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json',
		);
		curl_setopt($ch, CURLOPT_URL, EleadsApiRoutes::TOKEN_STATUS);
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
		if ($httpCode === 401 || $httpCode === 403) {
			return array('ok' => false, 'seo_status' => false);
		}
		if ($httpCode < 200 || $httpCode >= 300) {
			return null;
		}
		$data = json_decode($response, true);
		if (!is_array($data) || !isset($data['ok'])) {
			return null;
		}
		return array(
			'ok' => !empty($data['ok']),
			'seo_status' => isset($data['seo_status']) ? (bool)$data['seo_status'] : null,
			'project_id' => isset($data['project_id']) ? (int)$data['project_id'] : null
		);
	}

	private function storeProjectIdFromStatus($status) {
		if (!is_array($status) || !array_key_exists('project_id', $status)) {
			return;
		}
		$project_id = (int)$status['project_id'];
		if ($project_id <= 0) {
			return;
		}
		$this->load->model('setting/setting');
		$settings = $this->model_setting_setting->getSetting('module_eleads');
		if (isset($settings['module_eleads_project_id']) && (int)$settings['module_eleads_project_id'] === $project_id) {
			return;
		}
		$settings['module_eleads_project_id'] = $project_id;
		$this->model_setting_setting->editSetting('module_eleads', $settings);
	}

	public function update() {
		$this->load->language('extension/module/eleads');
		require_once DIR_SYSTEM . 'library/eleads/update_manager.php';
		$update_manager = new EleadsUpdateManager($this->registry);
		$result = $update_manager->updateToLatest();

		if (!empty($result['ok'])) {
			$this->session->data['success'] = $this->language->get('text_update_success');
		} else {
			$this->session->data['error'] = isset($result['message']) ? $result['message'] : $this->language->get('text_update_error');
		}

		$this->response->redirect($this->url->link('extension/module/eleads', 'user_token=' . $this->session->data['user_token'], true));
	}

	public function install() {
		$this->load->model('setting/event');
		$this->model_setting_event->addEvent('eleads_product_add', 'admin/model/catalog/product/addProduct/after', 'extension/module/eleads/eventProductAdd');
		$this->model_setting_event->addEvent('eleads_product_edit', 'admin/model/catalog/product/editProduct/after', 'extension/module/eleads/eventProductEdit');
		$this->model_setting_event->addEvent('eleads_product_delete', 'admin/model/catalog/product/deleteProduct/after', 'extension/module/eleads/eventProductDelete');
	}

	public function uninstall() {
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('eleads_product_add');
		$this->model_setting_event->deleteEventByCode('eleads_product_edit');
		$this->model_setting_event->deleteEventByCode('eleads_product_delete');
		$this->syncWidgetLoaderTag(false, '');
	}

	public function eventProductAdd($route, $args, $output) {
		$product_id = isset($output) ? (int)$output : (isset($args[0]) ? (int)$args[0] : 0);
		$this->syncProduct($product_id, 'create');
	}

	public function eventProductEdit($route, $args, $output) {
		$product_id = isset($args[0]) ? (int)$args[0] : 0;
		$this->syncProduct($product_id, 'update');
	}

	public function eventProductDelete($route, $args, $output) {
		$product_id = isset($args[0]) ? (int)$args[0] : 0;
		$this->syncProduct($product_id, 'delete');
	}

	private function syncProduct($product_id, $mode) {
		require_once DIR_SYSTEM . 'library/eleads/sync_manager.php';
		$manager = new EleadsSyncManager($this->registry);
		$manager->syncProduct((int)$product_id, (string)$mode);
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

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/eleads')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		return !$this->error;
	}

	private function prepareSettingsData($settings) {
		$default_shop_url = '';
		if (defined('HTTPS_CATALOG') && HTTPS_CATALOG) {
			$default_shop_url = rtrim(HTTPS_CATALOG, '/');
		} elseif (defined('HTTP_CATALOG') && HTTP_CATALOG) {
			$default_shop_url = rtrim(HTTP_CATALOG, '/');
		} else {
			$ssl = (string)$this->config->get('config_ssl');
			$default_shop_url = $ssl !== '' ? rtrim($ssl, '/') : rtrim((string)$this->config->get('config_url'), '/');
		}
		$defaults = array(
			'module_eleads_status' => 1,
			'module_eleads_access_key' => '',
			'module_eleads_categories' => array(),
			'module_eleads_filter_attributes' => array(),
			'module_eleads_filter_option_values' => array(),
			'module_eleads_filter_attributes_enabled' => 0,
			'module_eleads_filter_option_values_enabled' => 0,
			'module_eleads_filter_pages_enabled' => 0,
			'module_eleads_filter_max_index_depth' => 2,
			'module_eleads_filter_min_products_noindex' => 5,
			'module_eleads_filter_min_products_recommended' => 10,
			'module_eleads_filter_whitelist_attributes' => array(),
			'module_eleads_grouped' => 1,
			'module_eleads_sync_enabled' => 0,
			'module_eleads_shop_name' => (string)$this->config->get('config_name'),
			'module_eleads_email' => (string)$this->config->get('config_email'),
			'module_eleads_shop_url' => $default_shop_url,
			'module_eleads_currency' => (string)$this->config->get('config_currency'),
			'module_eleads_picture_limit' => 5,
			'module_eleads_image_size' => 'original',
			'module_eleads_short_description_source' => 'meta_description',
			'module_eleads_seo_pages_enabled' => 0,
			'module_eleads_api_key' => '',
		);

		$data = array();
		$fallback_on_empty = array(
			'module_eleads_shop_name',
			'module_eleads_email',
			'module_eleads_shop_url',
			'module_eleads_currency',
		);
		foreach ($defaults as $key => $value) {
			if (isset($this->request->post[$key])) {
				$data[$key] = $this->request->post[$key];
			} elseif (isset($settings[$key])) {
				$setting_value = $settings[$key];
				if (in_array($key, $fallback_on_empty, true) && trim((string)$setting_value) === '') {
					$data[$key] = $value;
				} else {
					$data[$key] = $setting_value;
				}
			} else {
				$data[$key] = $value;
			}
		}
		return $data;
	}

	private function syncSeoSitemap($enabled, $api_key, $settings) {
		require_once DIR_SYSTEM . 'library/eleads/seo_sitemap_manager.php';
		$manager = new EleadsSeoSitemapManager($this->registry);
		$manager->sync((bool)$enabled, (string)$api_key, (array)$settings);
	}


	private function getCategoriesTreeNodes($parent_id = 0, $level = 0, &$visited = array()) {
		$result = array();
		if ($level > 50) {
			return $result;
		}
		$rows = $this->db->query("SELECT c.category_id, c.parent_id, c.sort_order, cd.name FROM " . DB_PREFIX . "category c LEFT JOIN " . DB_PREFIX . "category_description cd ON (c.category_id = cd.category_id) WHERE c.parent_id = '" . (int)$parent_id . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' ORDER BY c.sort_order, LCASE(cd.name)")->rows;
		foreach ($rows as $row) {
			$category_id = (int)$row['category_id'];
			if (isset($visited[$category_id])) {
				continue;
			}
			$visited[$category_id] = true;
			$children = $this->getCategoriesTreeNodes($category_id, $level + 1, $visited);
			$result[] = array(
				'category_id' => $category_id,
				'name' => $row['name'],
				'level' => $level,
				'children' => $children,
			);
		}
		return $result;
	}

	private function renderCategoriesTreeHtml($nodes, $selected_set) {
		$html = '<ul class="eleads-tree-list">';
		foreach ($nodes as $node) {
			$id = (int)$node['category_id'];
			$name = htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8');
			$has_children = !empty($node['children']);
			$checked = isset($selected_set[$id]) ? ' checked' : '';
			$html .= '<li class="eleads-tree-item' . ($has_children ? ' has-children' : '') . '" data-id="' . $id . '">';
			if ($has_children) {
				$html .= '<button type="button" class="eleads-tree-toggle" aria-label="Toggle"></button>';
			} else {
				$html .= '<span class="eleads-tree-spacer"></span>';
			}
			$html .= '<label class="eleads-tree-label"><input type="checkbox" name="module_eleads_categories[]" value="' . $id . '"' . $checked . '><span class="eleads-tree-box"></span><span class="eleads-tree-text">' . $name . '</span></label>';
			if ($has_children) {
				$html .= '<div class="eleads-tree-children">' . $this->renderCategoriesTreeHtml($node['children'], $selected_set) . '</div>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	private function buildFeedUrls($languages, $access_key) {
		$root = $this->getCatalogBaseUrl();
		$seo_enabled = (bool)$this->config->get('config_seo_url');
		$urls = array();
		foreach ($languages as $language) {
			$label = $this->mapFeedLangCode($language['code'], $language['name']);
			if ($seo_enabled) {
				$url = $root . '/eleads-yml/' . $label . '.xml';
			} else {
				$url = $root . '/index.php?route=extension/module/eleads&lang=' . rawurlencode($label);
			}
			if ($access_key) {
				$url .= ($seo_enabled ? '?' : '&') . 'key=' . rawurlencode($access_key);
			}
			$urls[] = array(
				'name' => $language['name'],
				'code' => $label,
				'url' => $url,
			);
		}
		return $urls;
	}

	private function getCatalogBaseUrl() {
		$root = '';
		if (defined('HTTPS_CATALOG') && HTTPS_CATALOG) {
			$root = HTTPS_CATALOG;
		} elseif (defined('HTTP_CATALOG') && HTTP_CATALOG) {
			$root = HTTP_CATALOG;
		} else {
			$root = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		}
		if ($root === null) {
			$root = '';
		}
		return rtrim((string)$root, '/');
	}

	private function syncWidgetLoaderTag($enabled, $api_key) {
		require_once DIR_SYSTEM . 'library/eleads/widget_tag_manager.php';
		$manager = new EleadsWidgetTagManager($this->registry);
		$manager->sync((bool)$enabled, (string)$api_key);
	}

	private function mapFeedLangCode($code, $name) {
		$code = strtolower((string)$code);
		$name = strtolower((string)$name);
		if (strpos($code, 'en') === 0 || strpos($name, 'english') !== false) {
			return 'en';
		}
		if (strpos($code, 'ru') === 0 || strpos($name, 'рус') !== false) {
			return 'ru';
		}
		if (strpos($code, 'uk') === 0 || strpos($code, 'ua') === 0 || strpos($name, 'ukr') !== false || strpos($name, 'укр') !== false) {
			return 'uk';
		}
		return $code;
	}
}
