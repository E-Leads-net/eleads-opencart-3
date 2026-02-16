<?php
trait EleadsAdminControllerTrait {
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
			'module_eleads_filter_render_mode' => 'theme',
			'module_eleads_filter_max_index_depth' => 2,
			'module_eleads_filter_min_products_noindex' => 5,
			'module_eleads_filter_min_products_recommended' => 10,
				'module_eleads_filter_whitelist_attributes' => array(),
				'module_eleads_filter_templates' => array(),
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

		private function normalizeFilterTemplates($rows) {
			$result = array();
			foreach ((array)$rows as $row) {
				if (!is_array($row)) {
					continue;
				}
				$normalized = array(
					'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : 0,
					'depth' => isset($row['depth']) ? (int)$row['depth'] : 1,
					'translations' => array(),
				);
				$max_depth = (int)$this->config->get('module_eleads_filter_max_index_depth');
				if ($max_depth < 0) {
					$max_depth = 0;
				}
				if ($normalized['depth'] < 0) {
					$normalized['depth'] = 0;
				}
				if ($normalized['depth'] > $max_depth) {
					$normalized['depth'] = $max_depth;
				}
				if (isset($row['translations']) && is_array($row['translations'])) {
					foreach ($row['translations'] as $lang_code => $lang_data) {
						$lang_code = trim((string)$lang_code);
						if ($lang_code === '' || !is_array($lang_data)) {
							continue;
						}
						$fields = $this->normalizeFilterTemplateFields($lang_data);
						if (!$this->isFilterTemplateFieldsEmpty($fields)) {
							$normalized['translations'][$lang_code] = $fields;
						}
					}
				}
				$legacy = $this->normalizeFilterTemplateFields($row);
				if (!$this->isFilterTemplateFieldsEmpty($legacy)) {
					$default_lang = (string)$this->config->get('config_language');
					if ($default_lang === '') {
						$default_lang = 'default';
					}
					if (!isset($normalized['translations'][$default_lang])) {
						$normalized['translations'][$default_lang] = $legacy;
					}
				}
				if (empty($normalized['translations'])) {
					continue;
				}
				$result[] = $normalized;
			}
			return array_values($result);
		}

		private function normalizeFilterTemplateFields($row) {
			return array(
				'h1' => isset($row['h1']) ? trim((string)$row['h1']) : '',
				'meta_title' => isset($row['meta_title']) ? trim((string)$row['meta_title']) : '',
				'meta_description' => isset($row['meta_description']) ? trim((string)$row['meta_description']) : '',
				'meta_keywords' => isset($row['meta_keywords']) ? trim((string)$row['meta_keywords']) : '',
				'short_description' => isset($row['short_description']) ? trim((string)$row['short_description']) : '',
				'description' => isset($row['description']) ? trim((string)$row['description']) : '',
			);
		}

		private function isFilterTemplateFieldsEmpty($fields) {
			return
				$fields['h1'] === '' &&
				$fields['meta_title'] === '' &&
				$fields['meta_description'] === '' &&
				$fields['meta_keywords'] === '' &&
				$fields['short_description'] === '' &&
				$fields['description'] === '';
		}

		private function buildFilterTemplateCategoryOptions($nodes, $prefix = '') {
			$options = array();
			foreach ((array)$nodes as $node) {
				$options[] = array(
					'category_id' => (int)$node['category_id'],
					'name' => $prefix . (string)$node['name'],
				);
				if (!empty($node['children'])) {
					$options = array_merge($options, $this->buildFilterTemplateCategoryOptions($node['children'], $prefix . ' - '));
				}
			}
			return $options;
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
