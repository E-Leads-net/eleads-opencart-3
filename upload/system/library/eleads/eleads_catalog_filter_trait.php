<?php

trait EleadsCatalogFilterTrait {
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
}
