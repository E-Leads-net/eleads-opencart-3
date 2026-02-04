<?php
class EleadsFeedEngine {
	public function build($adapter, $lang_code, $feed_lang, $request_key) {
		$settings = $adapter->getSettings();

		if (!EleadsAccessGuard::allowFeed(isset($settings['access_key']) ? $settings['access_key'] : '', $request_key)) {
			return array('ok' => false, 'error' => 'access_denied');
		}

		$adapter->setLanguageByCode($lang_code);

		$shop_name = $settings['shop_name'] !== '' ? $settings['shop_name'] : $adapter->getDefaultShopName();
		$email = $settings['email'] !== '' ? $settings['email'] : $adapter->getDefaultEmail();
		$shop_url = $settings['shop_url'] !== '' ? $settings['shop_url'] : $adapter->getDefaultShopUrl();
		$feed_currency = $settings['currency'] !== '' ? $settings['currency'] : $adapter->getDefaultCurrency();
		$picture_limit = $settings['picture_limit'] > 0 ? (int)$settings['picture_limit'] : 5;
		$image_size = $settings['image_size'] !== '' ? $settings['image_size'] : 'original';
		$grouped_products = (bool)$settings['grouped_products'];
		$short_description_source = $settings['short_description_source'];

		$selected_category_ids = $settings['categories'];
		$selected_attribute_ids = $settings['filter_attributes'];
		$selected_option_value_ids = $settings['filter_option_values'];

		$categories = $adapter->getAllCategories();
		$categories_by_id = array();
		foreach ($categories as $category) {
			$categories_by_id[(int)$category['category_id']] = $category;
		}

		if (empty($selected_category_ids)) {
			$export_categories = array();
			$selected_category_set = array();
		} else {
			$selected_category_ids = array_values(array_unique(array_map('intval', $selected_category_ids)));
			$tree_set = EleadsFeedFormatter::collectCategoryTree($selected_category_ids, $categories_by_id);
			$export_categories = array();
			foreach ($categories as $category) {
				if (isset($tree_set[(int)$category['category_id']])) {
					$export_categories[] = $category;
				}
			}
			$selected_category_set = array_flip($selected_category_ids);
		}

		$products = $adapter->getProducts();
		$selected_attribute_set = array_flip(array_values(array_unique(array_map('intval', $selected_attribute_ids))));
		$selected_option_value_set = array_flip(array_values(array_unique(array_map('intval', $selected_option_value_ids))));

		$offers = EleadsOfferBuilder::buildOffers(
			$adapter,
			$products,
			$selected_category_set,
			$selected_attribute_set,
			$selected_option_value_set,
			$feed_currency,
			$picture_limit,
			$image_size,
			$feed_lang,
			$short_description_source,
			$grouped_products
		);

		$xml = $this->buildXml($shop_name, $email, $shop_url, $feed_lang, $export_categories, $offers, $adapter);
		return array('ok' => true, 'xml' => $xml);
	}

	private function buildXml($shop_name, $email, $shop_url, $lang_code, $categories, $offers, $adapter) {
		$feed_date = date('Y-m-d H:i');

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<yml_catalog date="' . EleadsFeedFormatter::xmlEscape($feed_date) . '">' . "\n";
		$xml .= "<shop>\n";
		$xml .= '<shopName>' . EleadsFeedFormatter::xmlEscape($shop_name) . "</shopName>\n";
		$xml .= '<email>' . EleadsFeedFormatter::xmlEscape($email) . "</email>\n";
		$xml .= '<url>' . EleadsFeedFormatter::xmlEscape($shop_url) . "</url>\n";
		$xml .= '<language>' . EleadsFeedFormatter::xmlEscape($lang_code) . "</language>\n";
		$xml .= "<categories>\n";
		foreach ($categories as $category) {
			$attrs = ' id="' . (int)$category['category_id'] . '"';
			if (!empty($category['parent_id'])) {
				$attrs .= ' parentId="' . (int)$category['parent_id'] . '"';
			}
			$attrs .= ' position="' . (int)$category['sort_order'] . '"';
			$category_url = $adapter->getCategoryUrl((int)$category['category_id']);
			$attrs .= ' url="' . EleadsFeedFormatter::xmlEscape($category_url) . '"';
			$xml .= '<category' . $attrs . '>' . EleadsFeedFormatter::xmlEscape($category['name']) . "</category>\n";
		}
		$xml .= "</categories>\n";
		$xml .= "<offers>\n";
		foreach ($offers as $offer) {
			$attrs = ' id="' . EleadsFeedFormatter::xmlEscape($offer['id']) . '"';
			if (!empty($offer['group_id'])) {
				$attrs .= ' group_id="' . EleadsFeedFormatter::xmlEscape($offer['group_id']) . '"';
			}
			$attrs .= ' available="' . ($offer['available'] ? 'true' : 'false') . '"';
			$xml .= '<offer' . $attrs . ">\n";
			$xml .= '<url>' . EleadsFeedFormatter::xmlEscape($offer['url']) . "</url>\n";
			$xml .= '<name>' . EleadsFeedFormatter::xmlEscape($offer['name']) . "</name>\n";
			$xml .= '<price>' . EleadsFeedFormatter::xmlEscape($offer['price']) . "</price>\n";
			$xml .= '<old_price>' . ($offer['old_price'] !== null ? EleadsFeedFormatter::xmlEscape($offer['old_price']) : '') . "</old_price>\n";
			$xml .= '<currency>' . EleadsFeedFormatter::xmlEscape($offer['currency']) . "</currency>\n";
			$xml .= '<categoryId>' . EleadsFeedFormatter::xmlEscape($offer['category_id']) . "</categoryId>\n";
			$xml .= '<quantity>' . EleadsFeedFormatter::xmlEscape($offer['quantity']) . "</quantity>\n";
			$xml .= '<stock_status>' . EleadsFeedFormatter::xmlEscape($offer['stock_status']) . "</stock_status>\n";
			foreach ($offer['pictures'] as $picture) {
				$xml .= '<picture>' . EleadsFeedFormatter::xmlEscape($picture) . "</picture>\n";
			}
			$xml .= '<vendor>' . EleadsFeedFormatter::xmlEscape($offer['vendor']) . "</vendor>\n";
			$xml .= '<sku>' . EleadsFeedFormatter::xmlEscape($offer['sku']) . "</sku>\n";
			$xml .= "<label/>\n";
			$xml .= '<order>' . EleadsFeedFormatter::xmlEscape($offer['order']) . "</order>\n";
			$xml .= '<description>' . EleadsFeedFormatter::xmlEscape($offer['description']) . "</description>\n";
			$xml .= '<short_description>' . EleadsFeedFormatter::xmlEscape($offer['short_description']) . "</short_description>\n";
			foreach ($offer['params'] as $param) {
				$param_attrs = ' name="' . EleadsFeedFormatter::xmlEscape($param['name']) . '"';
				if (!empty($param['filter'])) {
					$param_attrs = ' filter="true"' . $param_attrs;
				}
				$xml .= '<param' . $param_attrs . '>' . EleadsFeedFormatter::xmlEscape($param['value']) . "</param>\n";
			}
			$xml .= "</offer>\n";
		}
		$xml .= "</offers>\n";
		$xml .= "</shop>\n";
		$xml .= "</yml_catalog>\n";
		return $xml;
	}
}
