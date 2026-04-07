<?php
class EleadsFeedEngine {
	private $batchSize = 200;

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

		$selected_attribute_set = array_flip(array_values(array_unique(array_map('intval', $selected_attribute_ids))));
		$selected_option_value_set = array_flip(array_values(array_unique(array_map('intval', $selected_option_value_ids))));

		$target_file = $this->getFeedFilePath($feed_lang);
		$temp_file = $target_file . '.tmp';
		$handle = @fopen($temp_file, 'wb');

		if (!$handle) {
			return array('ok' => false, 'error' => 'write_failed');
		}

		$this->writeHeader($handle, $shop_name, $email, $shop_url, $feed_lang, $export_categories, $adapter);

		$start = 0;
		while (true) {
			$products = $adapter->getProductsBatch($start, $this->batchSize);
			if (empty($products)) {
				break;
			}

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

			foreach ($offers as $offer) {
				$this->writeOffer($handle, $offer);
			}

			$count = count($products);
			unset($offers, $products);

			if ($count < $this->batchSize) {
				break;
			}

			$start += $this->batchSize;
		}

		$this->writeFooter($handle);
		fclose($handle);

		if (!@rename($temp_file, $target_file)) {
			@unlink($target_file);
			if (!@rename($temp_file, $target_file)) {
				@unlink($temp_file);
				return array('ok' => false, 'error' => 'write_failed');
			}
		}

		return array('ok' => true, 'file' => $target_file);
	}

	private function getFeedFilePath($lang_code) {
		$directory = rtrim(DIR_CACHE, '/\\') . '/eleads';
		if (!is_dir($directory)) {
			@mkdir($directory, 0775, true);
		}

		return $directory . '/feed-' . preg_replace('/[^a-z0-9_-]/i', '-', (string)$lang_code) . '.xml';
	}

	private function writeHeader($handle, $shop_name, $email, $shop_url, $lang_code, $categories, $adapter) {
		$feed_date = date('Y-m-d H:i');

		$this->writeLine($handle, '<?xml version="1.0" encoding="UTF-8"?>');
		$this->writeLine($handle, '<yml_catalog date="' . EleadsFeedFormatter::xmlEscape($feed_date) . '">');
		$this->writeLine($handle, '<shop>');
		$this->writeLine($handle, '<shopName>' . EleadsFeedFormatter::xmlEscape($shop_name) . '</shopName>');
		$this->writeLine($handle, '<email>' . EleadsFeedFormatter::xmlEscape($email) . '</email>');
		$this->writeLine($handle, '<url>' . EleadsFeedFormatter::xmlEscape($shop_url) . '</url>');
		$this->writeLine($handle, '<language>' . EleadsFeedFormatter::xmlEscape($lang_code) . '</language>');
		$this->writeLine($handle, '<categories>');

		foreach ($categories as $category) {
			$attrs = ' id="' . (int)$category['category_id'] . '"';
			if (!empty($category['parent_id'])) {
				$attrs .= ' parentId="' . (int)$category['parent_id'] . '"';
			}
			$attrs .= ' position="' . (int)$category['sort_order'] . '"';
			$category_url = $adapter->getCategoryUrl((int)$category['category_id']);
			$attrs .= ' url="' . EleadsFeedFormatter::xmlEscape($category_url) . '"';
			$this->writeLine($handle, '<category' . $attrs . '>' . EleadsFeedFormatter::xmlEscape($category['name']) . '</category>');
		}
		$this->writeLine($handle, '</categories>');
		$this->writeLine($handle, '<offers>');
	}

	private function writeOffer($handle, $offer) {
		$attrs = ' id="' . EleadsFeedFormatter::xmlEscape($offer['id']) . '"';
		if (!empty($offer['group_id'])) {
			$attrs .= ' group_id="' . EleadsFeedFormatter::xmlEscape($offer['group_id']) . '"';
		}
		$attrs .= ' available="' . ($offer['available'] ? 'true' : 'false') . '"';

		$this->writeLine($handle, '<offer' . $attrs . '>');
		$this->writeLine($handle, '<url>' . EleadsFeedFormatter::xmlEscape($offer['url']) . '</url>');
		$this->writeLine($handle, '<name>' . EleadsFeedFormatter::xmlEscape($offer['name']) . '</name>');
		$this->writeLine($handle, '<price>' . EleadsFeedFormatter::xmlEscape($offer['price']) . '</price>');
		$this->writeLine($handle, '<old_price>' . ($offer['old_price'] !== null ? EleadsFeedFormatter::xmlEscape($offer['old_price']) : '') . '</old_price>');
		$this->writeLine($handle, '<currency>' . EleadsFeedFormatter::xmlEscape($offer['currency']) . '</currency>');
		$this->writeLine($handle, '<categoryId>' . EleadsFeedFormatter::xmlEscape($offer['category_id']) . '</categoryId>');
		$this->writeLine($handle, '<quantity>' . EleadsFeedFormatter::xmlEscape($offer['quantity']) . '</quantity>');
		$this->writeLine($handle, '<stock_status>' . EleadsFeedFormatter::xmlEscape($offer['stock_status']) . '</stock_status>');

		foreach ($offer['pictures'] as $picture) {
			$this->writeLine($handle, '<picture>' . EleadsFeedFormatter::xmlEscape($picture) . '</picture>');
		}

		$this->writeLine($handle, '<vendor>' . EleadsFeedFormatter::xmlEscape($offer['vendor']) . '</vendor>');
		$this->writeLine($handle, '<sku>' . EleadsFeedFormatter::xmlEscape($offer['sku']) . '</sku>');
		$this->writeLine($handle, '<label/>');
		$this->writeLine($handle, '<order>' . EleadsFeedFormatter::xmlEscape($offer['order']) . '</order>');
		$this->writeLine($handle, '<description>' . EleadsFeedFormatter::xmlEscape($offer['description']) . '</description>');
		$this->writeLine($handle, '<short_description>' . EleadsFeedFormatter::xmlEscape($offer['short_description']) . '</short_description>');

		foreach ($offer['params'] as $param) {
			$param_attrs = ' name="' . EleadsFeedFormatter::xmlEscape($param['name']) . '"';
			if (!empty($param['filter'])) {
				$param_attrs = ' filter="true"' . $param_attrs;
			}
			$this->writeLine($handle, '<param' . $param_attrs . '>' . EleadsFeedFormatter::xmlEscape($param['value']) . '</param>');
		}

		$this->writeLine($handle, '</offer>');
	}

	private function writeFooter($handle) {
		$this->writeLine($handle, '</offers>');
		$this->writeLine($handle, '</shop>');
		$this->writeLine($handle, '</yml_catalog>');
	}

	private function writeLine($handle, $line) {
		fwrite($handle, $line . "\n");
	}
}
