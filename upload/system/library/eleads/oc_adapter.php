<?php
class EleadsOcAdapter {
	private $registry;
	private $config;
	private $db;
	private $url;
	private $currency;
	private $request;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->config = $registry->get('config');
		$this->db = $registry->get('db');
		$this->url = $registry->get('url');
		$this->currency = $registry->get('currency');
		$this->request = $registry->get('request');
	}

	public function getSettings() {
		return array(
			'access_key' => (string)$this->config->get('module_eleads_access_key'),
			'categories' => (array)$this->config->get('module_eleads_categories'),
			'filter_attributes' => (array)$this->config->get('module_eleads_filter_attributes'),
			'filter_option_values' => (array)$this->config->get('module_eleads_filter_option_values'),
			'grouped_products' => $this->toBool($this->config->get('module_eleads_grouped')),
			'shop_name' => (string)$this->config->get('module_eleads_shop_name'),
			'email' => (string)$this->config->get('module_eleads_email'),
			'shop_url' => (string)$this->config->get('module_eleads_shop_url'),
			'currency' => (string)$this->config->get('module_eleads_currency'),
			'picture_limit' => (int)$this->config->get('module_eleads_picture_limit'),
			'image_size' => (string)$this->config->get('module_eleads_image_size'),
			'short_description_source' => (string)$this->config->get('module_eleads_short_description_source'),
			'sync_enabled' => $this->toBool($this->config->get('module_eleads_sync_enabled')),
			'api_key' => (string)$this->config->get('module_eleads_api_key'),
		);
	}

	public function getDefaultShopName() {
		return (string)$this->config->get('config_name');
	}

	public function getDefaultEmail() {
		return (string)$this->config->get('config_email');
	}

	public function getDefaultShopUrl() {
		if (defined('HTTPS_CATALOG') && HTTPS_CATALOG) {
			return rtrim(HTTPS_CATALOG, '/');
		}
		if (defined('HTTP_CATALOG') && HTTP_CATALOG) {
			return rtrim(HTTP_CATALOG, '/');
		}
		$ssl = (string)$this->config->get('config_ssl');
		if ($ssl !== '') {
			return rtrim($ssl, '/');
		}
		return rtrim((string)$this->config->get('config_url'), '/');
	}

	public function getDefaultCurrency() {
		return (string)$this->config->get('config_currency');
	}

	public function getFeedCurrency() {
		$currency = (string)$this->config->get('module_eleads_currency');
		return $currency !== '' ? $currency : $this->getDefaultCurrency();
	}

	public function getImageSize() {
		$size = (string)$this->config->get('module_eleads_image_size');
		return $size !== '' ? $size : 'original';
	}

	public function getShortDescriptionSource() {
		return (string)$this->config->get('module_eleads_short_description_source');
	}

	public function setLanguageByCode($code) {
		$code = $code === 'uk' ? 'ua' : $code;
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "language WHERE code = '" . $this->db->escape($code) . "' AND status = '1'");
		if ($query->num_rows) {
			$this->config->set('config_language_id', (int)$query->row['language_id']);
			$this->config->set('config_language', $query->row['code']);
		}
	}

	public function resolveLanguageCode($feed_lang) {
		$feed_lang = strtolower((string)$feed_lang);
		if ($feed_lang === 'uk' || $feed_lang === 'ua') {
			$feed_lang = 'uk';
		} elseif ($feed_lang === 'ru') {
			$feed_lang = 'ru';
		} elseif ($feed_lang === 'en') {
			$feed_lang = 'en';
		}
		$query = $this->db->query("SELECT code FROM " . DB_PREFIX . "language WHERE status = '1' AND (code = '" . $this->db->escape($feed_lang) . "' OR code LIKE '" . $this->db->escape($feed_lang) . "-%' OR code LIKE '" . $this->db->escape($feed_lang) . "_%') ORDER BY code ASC LIMIT 1");
		if ($query->num_rows && !empty($query->row['code'])) {
			return $query->row['code'];
		}
		return $feed_lang;
	}

	public function getAllCategories() {
		$this->registry->get('load')->model('catalog/category');
		$all = array();
		$this->collectCategories(0, $all);
		return $all;
	}

	private function collectCategories($parent_id, &$all) {
		$categories = $this->registry->get('model_catalog_category')->getCategories($parent_id);
		foreach ($categories as $category) {
			$all[] = array(
				'category_id' => (int)$category['category_id'],
				'parent_id' => (int)$category['parent_id'],
				'name' => $category['name'],
				'sort_order' => (int)$category['sort_order'],
			);
			$this->collectCategories((int)$category['category_id'], $all);
		}
	}

	public function getCategory($category_id) {
		$this->registry->get('load')->model('catalog/category');
		$category = $this->registry->get('model_catalog_category')->getCategory((int)$category_id);
		if (!$category) {
			$language_id = (int)$this->config->get('config_language_id');
			$query = $this->db->query("SELECT c.category_id, c.parent_id, c.sort_order, cd.name FROM " . DB_PREFIX . "category c LEFT JOIN " . DB_PREFIX . "category_description cd ON (c.category_id = cd.category_id) WHERE c.category_id = '" . (int)$category_id . "' AND cd.language_id = '" . $language_id . "'");
			$category = $query->row;
		}
		return $category ? array(
			'category_id' => (int)$category['category_id'],
			'parent_id' => (int)$category['parent_id'],
			'name' => $category['name'],
			'sort_order' => (int)$category['sort_order'],
		) : null;
	}

	public function getCategoryUrl($category_id) {
		$path = $this->getCategoryPath((int)$category_id);
		if ($path === '') {
			return '';
		}
		$base = $this->getDefaultShopUrl();
		if ($base === '') {
			return '';
		}
		return rtrim($base, '/') . '/index.php?route=product/category&path=' . $path;
	}

	public function getCategoryPathNames($category_id) {
		$path_ids = $this->getCategoryPathIds((int)$category_id);
		if (empty($path_ids)) {
			return array();
		}
		$names = array();
		$language_id = (int)$this->config->get('config_language_id');
		foreach ($path_ids as $id) {
			$query = $this->db->query("SELECT name FROM " . DB_PREFIX . "category_description WHERE category_id = '" . (int)$id . "' AND language_id = '" . $language_id . "'");
			if ($query->num_rows) {
				$names[] = $query->row['name'];
			}
		}
		return $names;
	}

	private function getCategoryPath($category_id) {
		$ids = $this->getCategoryPathIds($category_id);
		if (empty($ids)) {
			return '';
		}
		return implode('_', $ids);
	}

	private function getCategoryPathIds($category_id) {
		$query = $this->db->query("SELECT path_id FROM " . DB_PREFIX . "category_path WHERE category_id = '" . (int)$category_id . "' ORDER BY level");
		if (!$query->num_rows) {
			return array();
		}
		$ids = array();
		foreach ($query->rows as $row) {
			$ids[] = (int)$row['path_id'];
		}
		return $ids;
	}

	public function getProducts() {
		$this->registry->get('load')->model('catalog/product');
		$filter = array(
			'filter_status' => 1,
			'start' => 0,
			'limit' => 1000000,
		);
		$products = $this->registry->get('model_catalog_product')->getProducts($filter);
		$result = array();
		foreach ($products as $product) {
			$result[] = array(
				'product_id' => (int)$product['product_id'],
				'name' => $product['name'],
				'description' => $product['description'],
				'meta_description' => $product['meta_description'],
				'manufacturer_id' => (int)$product['manufacturer_id'],
				'sort_order' => (int)$product['sort_order'],
				'main_category_id' => 0,
			);
		}
		return $result;
	}

	public function getProduct($product_id) {
		$this->registry->get('load')->model('catalog/product');
		$product = $this->registry->get('model_catalog_product')->getProduct((int)$product_id);
		if (!$product) {
			return null;
		}
		return array(
			'product_id' => (int)$product['product_id'],
			'name' => $product['name'],
			'description' => $product['description'],
			'meta_description' => $product['meta_description'],
			'manufacturer_id' => (int)$product['manufacturer_id'],
			'stock_status_id' => isset($product['stock_status_id']) ? (int)$product['stock_status_id'] : 0,
			'sort_order' => (int)$product['sort_order'],
			'price' => (float)$product['price'],
			'special' => isset($product['special']) ? (float)$product['special'] : 0,
			'sku' => isset($product['sku']) ? $product['sku'] : '',
			'quantity' => isset($product['quantity']) ? (int)$product['quantity'] : 0,
		);
	}

	public function getProductCategoryIds($product_id) {
		$this->registry->get('load')->model('catalog/product');
		$model = $this->registry->get('model_catalog_product');
		if (isset($model->getProductCategories)) {
			$categories = $model->getProductCategories((int)$product_id);
		} else {
			$categories = $model->getCategories((int)$product_id);
		}
		$ids = array();
		foreach ($categories as $row) {
			if (is_array($row)) {
				if (isset($row['category_id'])) {
					$ids[] = (int)$row['category_id'];
				}
			} else {
				$ids[] = (int)$row;
			}
		}
		return $ids;
	}

	public function getProductPrimaryCategory($product_id) {
		$ids = $this->getProductCategoryIds($product_id);
		if (empty($ids)) {
			return null;
		}
		return $this->getCategory((int)$ids[0]);
	}

	public function getProductVariants($product_id) {
		$product = $this->getProduct($product_id);
		if (!$product) {
			return array();
		}

		$variants = array();
		$options = $this->getRawProductOptions($product_id);
		if (count($options) === 1) {
			$option = $options[0];
			if (!empty($option['product_option_value'])) {
				foreach ($option['product_option_value'] as $value) {
					$price = $product['special'] > 0 ? $product['special'] : $product['price'];
					$old_price = $product['special'] > 0 ? $product['price'] : 0;
					$price = $this->applyOptionPrice($price, $value);
					$old_price = $old_price > 0 ? $this->applyOptionPrice($old_price, $value) : 0;
					$variants[] = array(
						'id' => (int)$value['product_option_value_id'],
						'name' => $value['name'],
						'price' => $price,
						'old_price' => $old_price,
						'sku' => $product['sku'],
						'stock' => isset($value['quantity']) ? (int)$value['quantity'] : $product['quantity'],
					);
				}
			}
		}

		if (empty($variants)) {
			$price = $product['special'] > 0 ? $product['special'] : $product['price'];
			$old_price = $product['special'] > 0 ? $product['price'] : 0;
			$variants[] = array(
				'id' => (int)$product['product_id'],
				'name' => '',
				'price' => $price,
				'old_price' => $old_price,
				'sku' => $product['sku'],
				'stock' => $product['quantity'],
			);
		}

		return $variants;
	}

	private function applyOptionPrice($base_price, $option_value) {
		$price = (float)$base_price;
		if (!empty($option_value['price'])) {
			$delta = (float)$option_value['price'];
			if (isset($option_value['price_prefix']) && $option_value['price_prefix'] === '-') {
				$price -= $delta;
			} else {
				$price += $delta;
			}
		}
		return $price;
	}

	public function getProductAttributes($product_id) {
		$this->registry->get('load')->model('catalog/product');
		$groups = $this->registry->get('model_catalog_product')->getProductAttributes((int)$product_id);
		$result = array();
		foreach ($groups as $group) {
			if (empty($group['attribute'])) {
				continue;
			}
			foreach ($group['attribute'] as $attr) {
				$result[] = array(
					'attribute_id' => (int)$attr['attribute_id'],
					'name' => $attr['name'],
					'value' => $attr['text'],
				);
			}
		}
		return $result;
	}

	public function getProductOptions($product_id) {
		$options = $this->getRawProductOptions($product_id);
		$result = array();
		foreach ($options as $option) {
			if (empty($option['product_option_value'])) {
				continue;
			}
			foreach ($option['product_option_value'] as $value) {
				$result[] = array(
					'option_id' => (int)$option['option_id'],
					'option_value_id' => (int)$value['option_value_id'],
					'name' => $option['name'],
					'value' => $value['name'],
				);
			}
		}
		return $result;
	}

	private function getRawProductOptions($product_id) {
		$this->registry->get('load')->model('catalog/product');
		return $this->registry->get('model_catalog_product')->getProductOptions((int)$product_id);
	}

	public function getProductImages($product_id, $limit, $image_size) {
		$this->registry->get('load')->model('catalog/product');
		$this->registry->get('load')->model('tool/image');

		$images = $this->registry->get('model_catalog_product')->getProductImages((int)$product_id);
		$main = $this->registry->get('model_catalog_product')->getProduct((int)$product_id);
		$urls = array();

		if (!empty($main['image'])) {
			$urls[] = $this->resolveImageUrl($main['image'], $image_size);
		}
		foreach ($images as $image) {
			if ($limit > 0 && count($urls) >= $limit) {
				break;
			}
			if (!empty($image['image'])) {
				$urls[] = $this->resolveImageUrl($image['image'], $image_size);
			}
		}
		return $urls;
	}

	public function getStockStatusName($stock_status_id) {
		$stock_status_id = (int)$stock_status_id;
		if ($stock_status_id <= 0) {
			return '';
		}
		$language_id = (int)$this->config->get('config_language_id');
		$query = $this->db->query("SELECT name FROM " . DB_PREFIX . "stock_status WHERE stock_status_id = '" . $stock_status_id . "' AND language_id = '" . $language_id . "'");
		return !empty($query->row['name']) ? $query->row['name'] : '';
	}

	public function getProductLabel($product_id) {
		$labels = array();
		$product_id = (int)$product_id;
		$product = $this->getProduct($product_id);
		if ($product && !empty($product['special']) && $product['special'] > 0) {
			$labels[] = 'special';
		}
		if ($this->isFeaturedProduct($product_id)) {
			$labels[] = 'featured';
		}
		if ($this->isBestSellerProduct($product_id)) {
			$labels[] = 'bestseller';
		}
		return implode(',', $labels);
	}

	private function isFeaturedProduct($product_id) {
		static $featured = null;
		if ($featured === null) {
			$featured = array();
			$query = $this->db->query("SELECT setting FROM " . DB_PREFIX . "module WHERE code = 'featured'");
			foreach ($query->rows as $row) {
				$setting = json_decode($row['setting'], true);
				if (empty($setting) || empty($setting['status']) || empty($setting['product'])) {
					continue;
				}
				foreach ((array)$setting['product'] as $pid) {
					$featured[(int)$pid] = true;
				}
			}
		}
		return isset($featured[(int)$product_id]);
	}

	private function isBestSellerProduct($product_id) {
		static $bestsellers = null;
		if ($bestsellers === null) {
			$bestsellers = array();
			$limit = 0;
			$query = $this->db->query("SELECT setting FROM " . DB_PREFIX . "module WHERE code = 'bestseller'");
			foreach ($query->rows as $row) {
				$setting = json_decode($row['setting'], true);
				if (empty($setting) || empty($setting['status'])) {
					continue;
				}
				if (!empty($setting['limit']) && (int)$setting['limit'] > $limit) {
					$limit = (int)$setting['limit'];
				}
			}
			if ($limit <= 0) {
				return false;
			}
			$q = $this->db->query("SELECT op.product_id, SUM(op.quantity) AS total FROM " . DB_PREFIX . "order_product op LEFT JOIN `" . DB_PREFIX . "order` o ON (op.order_id = o.order_id) WHERE o.order_status_id > '0' GROUP BY op.product_id ORDER BY total DESC LIMIT " . (int)$limit);
			foreach ($q->rows as $row) {
				$bestsellers[(int)$row['product_id']] = true;
			}
		}
		return isset($bestsellers[(int)$product_id]);
	}

	private function resolveImageUrl($image, $image_size) {
		if ($image_size === '' || $image_size === 'original') {
			$base = $this->getDefaultShopUrl();
			return rtrim($base, '/') . '/image/' . ltrim($image, '/');
		}
		if (preg_match('/^(\\d+)x(\\d+)$/', $image_size, $m)) {
			return $this->registry->get('model_tool_image')->resize($image, (int)$m[1], (int)$m[2]);
		}
		return $this->registry->get('model_tool_image')->resize($image, 500, 500);
	}

	public function getManufacturerName($manufacturer_id) {
		if (!$manufacturer_id) {
			return '';
		}
		$this->registry->get('load')->model('catalog/manufacturer');
		$manufacturer = $this->registry->get('model_catalog_manufacturer')->getManufacturer((int)$manufacturer_id);
		return $manufacturer && isset($manufacturer['name']) ? $manufacturer['name'] : '';
	}

	public function getProductUrl($product_id) {
		$base = $this->getDefaultShopUrl();
		if ($base === '') {
			return '';
		}
		return rtrim($base, '/') . '/index.php?route=product/product&product_id=' . (int)$product_id;
	}

	public function convertPrice($amount, $currency_code) {
		$from = (string)$this->config->get('config_currency');
		if ($currency_code === '' || $currency_code === $from) {
			return (float)$amount;
		}
		return $this->currency->convert((float)$amount, $from, $currency_code);
	}

	public function getSelectedAttributeNames($attributes) {
		$selected_ids = (array)$this->config->get('module_eleads_filter_attributes');
		$selected_set = array_flip(array_map('intval', $selected_ids));
		$names = array();
		foreach ($attributes as $attr) {
			if (isset($selected_set[(int)$attr['attribute_id']])) {
				$names[] = $attr['name'];
			}
		}
		return array_values(array_unique($names));
	}

	private function toBool($value) {
		return !empty($value) && $value !== '0';
	}
}
