<?php
class EleadsSyncPayloadBuilder {
	public function build($adapter, $product_id, $lang_code) {
		$product = $adapter->getProduct($product_id);
		if (!$product) {
			return null;
		}

		$variants = $adapter->getProductVariants($product_id);
		if (empty($variants)) {
			return null;
		}

		$category = $adapter->getProductPrimaryCategory($product_id);
		$category_payload = array(
			'external_id' => $category ? (string)$category['category_id'] : '',
			'external_name' => $category ? (string)$category['name'] : '',
			'external_url' => $category ? $adapter->getCategoryUrl((int)$category['category_id']) : '',
			'external_parent_id' => $category && !empty($category['parent_id']) ? (string)$category['parent_id'] : '',
			'external_parent_name' => '',
			'external_parent_url' => '',
			'full_path' => '',
			'position' => $category ? (int)$category['sort_order'] : 0,
			'parent_position' => 0,
			'path' => array(),
		);

		if ($category) {
			if (!empty($category['parent_id'])) {
				$parent = $adapter->getCategory((int)$category['parent_id']);
				if ($parent) {
					$category_payload['external_parent_name'] = (string)$parent['name'];
					$category_payload['external_parent_url'] = $adapter->getCategoryUrl((int)$parent['category_id']);
					$category_payload['parent_position'] = (int)$parent['sort_order'];
				}
			}
			$path = $adapter->getCategoryPathNames((int)$category['category_id']);
			$category_payload['path'] = $path;
			$category_payload['full_path'] = implode(' / ', $path);
		}

		$first_variant = reset($variants);
		$quantity = 0;
		$has_unlimited = false;
		foreach ($variants as $variant) {
			if ($variant['stock'] === null) {
				$has_unlimited = true;
				break;
			}
			if ($variant['stock'] > 0) {
				$quantity += (int)$variant['stock'];
			}
		}
		$available = $has_unlimited || $quantity > 0;
		if ($has_unlimited) {
			$quantity = 1;
		}

		$currency = $adapter->getFeedCurrency();
		$price = $adapter->convertPrice($first_variant['price'], $currency);
		$old_price = 0;
		if (!empty($first_variant['old_price']) && $first_variant['old_price'] > 0) {
			$old_price = $adapter->convertPrice($first_variant['old_price'], $currency);
		}

		$brand_name = $adapter->getManufacturerName((int)$product['manufacturer_id']);
		$stock_status = $adapter->getStockStatusName(isset($product['stock_status_id']) ? (int)$product['stock_status_id'] : 0);
		$label = $adapter->getProductLabel($product_id);
		$images = $adapter->getProductImages($product_id, 0, $adapter->getImageSize());

		$short_description = isset($product['meta_description']) ? (string)$product['meta_description'] : '';
		$product_url = $adapter->getProductUrl($product_id);

		$attributes = $adapter->getProductAttributes($product_id);
		$attribute_filters = $adapter->getSelectedAttributeNames($attributes);
		$options = $adapter->getProductOptions($product_id);

		$grouped_attributes = array();
		foreach ($attributes as $attr) {
			$name = isset($attr['name']) ? trim((string)$attr['name']) : '';
			$value = isset($attr['value']) ? trim((string)$attr['value']) : '';
			if ($name === '' || $value === '') {
				continue;
			}
			if (!isset($grouped_attributes[$name])) {
				$grouped_attributes[$name] = array();
			}
			if (!in_array($value, $grouped_attributes[$name], true)) {
				$grouped_attributes[$name][] = $value;
			}
		}

		$attributes_map = array();
		foreach ($grouped_attributes as $name => $values) {
			$attributes_map[$name] = implode('; ', $values);
		}

		$option_values = array();
		foreach ($options as $opt) {
			$value = isset($opt['value']) ? trim((string)$opt['value']) : '';
			if ($value === '') {
				continue;
			}
			if (!in_array($value, $option_values, true)) {
				$option_values[] = $value;
			}
		}
		if ($option_values) {
			$attributes_map['Опции'] = implode(' | ', $option_values);
		}

		return array(
			'language' => (string)$lang_code,
			'payload' => array(
				'source' => array(
					'offer_id' => (string)$product_id,
					'language' => (string)$lang_code,
					'url' => $product_url,
					'group_id' => (string)$product_id,
				),
				'product' => array(
					'title' => (string)$product['name'],
					'description' => (string)(isset($product['description']) ? $product['description'] : ''),
					'short_description' => (string)$short_description,
					'price' => $price,
					'old_price' => $old_price,
					'currency' => $currency,
					'quantity' => (int)$quantity,
					'stock_status' => $stock_status,
					'vendor' => $brand_name,
					'sku' => (string)$product['sku'],
					'label' => $label,
					'sort_order' => isset($product['sort_order']) ? (int)$product['sort_order'] : 0,
					'attributes' => (object)$attributes_map,
					'attribute_filters' => $attribute_filters,
					'images' => $images,
				),
				'category' => $category_payload,
			),
		);
	}
}
