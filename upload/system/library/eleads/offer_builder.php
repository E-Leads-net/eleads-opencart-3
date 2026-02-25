<?php
class EleadsOfferBuilder {
	public static function buildOffers($adapter, $products, $selected_category_set, $selected_attribute_set, $selected_option_value_set, $feed_currency, $picture_limit, $image_size, $lang, $short_description_source, $grouped_products) {
		$offers = array();

		foreach ($products as $product) {
			$product_id = (int)$product['product_id'];

			$product_category_ids = $adapter->getProductCategoryIds($product_id);
			if (empty($product_category_ids) && !empty($product['main_category_id'])) {
				$product_category_ids = array((int)$product['main_category_id']);
			}

			$has_selected_category = false;
			foreach ($product_category_ids as $category_id) {
				if (isset($selected_category_set[(int)$category_id])) {
					$has_selected_category = true;
					break;
				}
			}
			if (!$has_selected_category) {
				continue;
			}

			$category_id = isset($product['main_category_id']) ? (int)$product['main_category_id'] : 0;
			if (!$category_id || !isset($selected_category_set[$category_id])) {
				$category_id = (int)reset($product_category_ids);
			}

			$variants = $adapter->getProductVariants($product_id);
			if (empty($variants)) {
				continue;
			}

			$images = $adapter->getProductImages($product_id, $picture_limit, $image_size);
			$brand_name = $adapter->getManufacturerName((int)$product['manufacturer_id']);

			$attributes = $adapter->getProductAttributes($product_id);
			$options = $adapter->getProductOptions($product_id);

			if ($grouped_products) {
				$first_variant = reset($variants);
				if (!$first_variant) {
					continue;
				}

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

				$price = $adapter->convertPrice($first_variant['price'], $feed_currency);
				$old_price = null;
				if (!empty($first_variant['old_price']) && $first_variant['old_price'] > 0) {
					$old_price = $adapter->convertPrice($first_variant['old_price'], $feed_currency);
				}

				$params = EleadsFeedFormatter::prepareParams($attributes, $options, $selected_attribute_set, $selected_option_value_set);

				$offers[] = array(
					'id' => $product_id,
					'group_id' => null,
					'available' => $available,
					'url' => $adapter->getProductUrl($product_id),
					'name' => $product['name'],
					'price' => $price,
					'old_price' => $old_price,
					'currency' => $feed_currency,
					'category_id' => $category_id,
					'quantity' => $quantity,
					'stock_status' => EleadsFeedFormatter::formatStockStatus($available, $lang),
					'pictures' => $images,
					'vendor' => $brand_name,
					'sku' => self::resolveOfferSku($first_variant, $product),
					'label' => '',
					'order' => isset($product['sort_order']) ? (int)$product['sort_order'] : 0,
					'description' => $product['description'],
					'short_description' => EleadsFeedFormatter::resolveShortDescription($product, $short_description_source),
					'params' => $params,
				);
				continue;
			}

			foreach ($variants as $variant) {
				$available = $variant['stock'] === null || $variant['stock'] > 0;
				$quantity = $variant['stock'] === null ? 1 : max(0, (int)$variant['stock']);

				$price = $adapter->convertPrice($variant['price'], $feed_currency);
				$old_price = null;
				if (!empty($variant['old_price']) && $variant['old_price'] > 0) {
					$old_price = $adapter->convertPrice($variant['old_price'], $feed_currency);
				}

				$offer_name = $product['name'];
				if (!empty($variant['name'])) {
					$offer_name .= ' ' . $variant['name'];
				}

				$params = EleadsFeedFormatter::prepareParams($attributes, $options, $selected_attribute_set, $selected_option_value_set);

				$offers[] = array(
					'id' => isset($variant['id']) ? $variant['id'] : $product_id,
					'group_id' => $product_id,
					'available' => $available,
					'url' => $adapter->getProductUrl($product_id),
					'name' => $offer_name,
					'price' => $price,
					'old_price' => $old_price,
					'currency' => $feed_currency,
					'category_id' => $category_id,
					'quantity' => $quantity,
					'stock_status' => EleadsFeedFormatter::formatStockStatus($available, $lang),
					'pictures' => $images,
					'vendor' => $brand_name,
					'sku' => self::resolveOfferSku($variant, $product),
					'label' => '',
					'order' => isset($product['sort_order']) ? (int)$product['sort_order'] : 0,
					'description' => $product['description'],
					'short_description' => EleadsFeedFormatter::resolveShortDescription($product, $short_description_source),
					'params' => $params,
				);
			}
		}

		return $offers;
	}

	private static function resolveOfferSku($variant, $product) {
		$sku = isset($variant['sku']) ? trim((string)$variant['sku']) : '';
		if ($sku !== '') {
			return $sku;
		}
		return isset($product['model']) ? trim((string)$product['model']) : '';
	}
}
