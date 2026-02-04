<?php
class EleadsFeedFormatter {
	public static function collectCategoryTree($selected_category_ids, $categories_by_id) {
		$result = array();
		foreach ($selected_category_ids as $category_id) {
			$category_id = (int)$category_id;
			if (!isset($categories_by_id[$category_id])) {
				continue;
			}
			$current_id = $category_id;
			while (!empty($current_id) && isset($categories_by_id[$current_id])) {
				$result[$current_id] = true;
				$current_id = (int)$categories_by_id[$current_id]['parent_id'];
			}
		}
		return $result;
	}

	public static function prepareParams($attributes, $options, $selected_attribute_set, $selected_option_value_set) {
		$params = array();
		$grouped_attributes = array();
		$grouped_attribute_filters = array();
		foreach ($attributes as $attr) {
			$name = isset($attr['name']) ? trim((string)$attr['name']) : '';
			$value = isset($attr['value']) ? trim((string)$attr['value']) : '';
			if ($name === '' || $value === '') {
				continue;
			}
			if (!isset($grouped_attributes[$name])) {
				$grouped_attributes[$name] = array();
				$grouped_attribute_filters[$name] = false;
			}
			if (!in_array($value, $grouped_attributes[$name], true)) {
				$grouped_attributes[$name][] = $value;
			}
			$attribute_id = isset($attr['attribute_id']) ? (int)$attr['attribute_id'] : 0;
			if ($attribute_id > 0 && isset($selected_attribute_set[$attribute_id])) {
				$grouped_attribute_filters[$name] = true;
			}
		}

		foreach ($grouped_attributes as $name => $values) {
			$params[] = array(
				'name' => $name,
				'value' => implode('; ', $values),
				'filter' => !empty($grouped_attribute_filters[$name])
			);
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
			$params[] = array(
				'name' => 'Опции',
				'value' => implode(' | ', $option_values),
				'filter' => false
			);
		}
		return $params;
	}

	public static function resolveShortDescription($product, $source) {
		if ($source === 'meta_description' && !empty($product['meta_description'])) {
			return (string)$product['meta_description'];
		}
		if ($source === 'description' && !empty($product['description'])) {
			return (string)$product['description'];
		}
		if (!empty($product['short_description'])) {
			return (string)$product['short_description'];
		}
		return (string)(isset($product['meta_description']) ? $product['meta_description'] : '');
	}

	public static function formatStockStatus($available, $lang) {
		if ($lang === 'uk' || $lang === 'ua') {
			return $available ? 'В наявності' : 'Немає в наявності';
		}
		if ($lang === 'en') {
			return $available ? 'In stock' : 'Out of stock';
		}
		return $available ? 'На складе' : 'Нет в наличии';
	}

	public static function xmlEscape($value) {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
}
