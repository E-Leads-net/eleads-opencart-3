<?php
class EleadsFeedJobManager {
	const BATCH_SIZE = 300;
	const JOB_STALE_SECONDS = 600;

	private $registry;

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function getReadyFile($feed_lang) {
		$paths = $this->getPaths($feed_lang);

		return is_file($paths['final']) ? $paths['final'] : '';
	}

	public function start($adapter, $lang_code, $feed_lang) {
		$paths = $this->getPaths($feed_lang);
		$meta = $this->readMeta($feed_lang);

		if (!empty($meta) && ($meta['status'] ?? '') === 'running' && !$this->isStale($meta) && is_file($paths['temp'])) {
			return $this->finalizeMeta($meta, $paths);
		}

		$context = $this->buildContext($adapter, $lang_code, $feed_lang);
		if (!$context['ok']) {
			return $context;
		}

		$this->clearTransientFiles($paths);

		$engine = new EleadsFeedEngine();
		$handle = @fopen($paths['temp'], 'wb');
		if (!$handle) {
			return array('ok' => false, 'status' => 'failed', 'error' => 'write_failed');
		}

		$engine->writeHeader(
			$handle,
			$context['shop_name'],
			$context['email'],
			$context['shop_url'],
			$feed_lang,
			$context['export_categories'],
			$adapter
		);
		fclose($handle);

		$now = date('Y-m-d H:i:s');
		$meta = array(
			'lang' => $feed_lang,
			'status' => 'running',
			'offset' => 0,
			'processed' => 0,
			'batch_size' => self::BATCH_SIZE,
			'started_at' => $now,
			'updated_at' => $now,
			'finished_at' => '',
			'error' => '',
		);
		$this->writeMeta($feed_lang, $meta);

		return $this->finalizeMeta($meta, $paths);
	}

	public function processStep($adapter, $lang_code, $feed_lang) {
		$paths = $this->getPaths($feed_lang);
		$meta = $this->readMeta($feed_lang);

		if (empty($meta)) {
			if (is_file($paths['final'])) {
				return $this->finalizeMeta(array(
					'lang' => $feed_lang,
					'status' => 'ready',
					'offset' => 0,
					'processed' => 0,
					'batch_size' => self::BATCH_SIZE,
					'started_at' => '',
					'updated_at' => date('Y-m-d H:i:s', filemtime($paths['final'])),
					'finished_at' => date('Y-m-d H:i:s', filemtime($paths['final'])),
					'error' => '',
				), $paths);
			}

			return array(
				'ok' => true,
				'lang' => $feed_lang,
				'status' => 'idle',
				'processed' => 0,
				'batch_size' => self::BATCH_SIZE,
				'updated_at' => '',
				'finished_at' => '',
				'size' => 0,
				'error' => '',
			);
		}

		if (($meta['status'] ?? '') === 'ready' || ($meta['status'] ?? '') === 'failed') {
			return $this->finalizeMeta($meta, $paths);
		}

		if ($this->isStale($meta)) {
			$this->clearTransientFiles($paths);
			$meta['status'] = 'failed';
			$meta['updated_at'] = date('Y-m-d H:i:s');
			$meta['finished_at'] = $meta['updated_at'];
			$meta['error'] = 'job_stale';
			$this->writeMeta($feed_lang, $meta);

			return $this->finalizeMeta($meta, $paths);
		}

		$lock_handle = @fopen($paths['lock'], 'c');
		if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
			if ($lock_handle) {
				fclose($lock_handle);
			}

			return $this->finalizeMeta($meta, $paths);
		}

		try {
			$meta = $this->readMeta($feed_lang);
			if (empty($meta)) {
				$meta = $this->start($adapter, $lang_code, $feed_lang);
				if (empty($meta['ok'])) {
					return $meta;
				}
			}

			$context = $this->buildContext($adapter, $lang_code, $feed_lang);
			if (!$context['ok']) {
				return $context;
			}

			if (!is_file($paths['temp'])) {
				$started = $this->start($adapter, $lang_code, $feed_lang);
				if (empty($started['ok'])) {
					return $started;
				}
				$meta = $this->readMeta($feed_lang);
			}

			$offset = isset($meta['offset']) ? (int)$meta['offset'] : 0;
			$products = $adapter->getProductsBatch($offset, self::BATCH_SIZE);
			$product_count = count($products);

			if ($product_count > 0) {
				$offers = EleadsOfferBuilder::buildOffers(
					$adapter,
					$products,
					$context['selected_category_set'],
					$context['selected_attribute_set'],
					$context['selected_option_value_set'],
					$context['feed_currency'],
					$context['picture_limit'],
					$context['image_size'],
					$feed_lang,
					$context['short_description_source'],
					$context['grouped_products']
				);

				$engine = new EleadsFeedEngine();
				$handle = @fopen($paths['temp'], 'ab');
				if (!$handle) {
					throw new RuntimeException('write_failed');
				}

				foreach ($offers as $offer) {
					$engine->writeOffer($handle, $offer);
				}
				fclose($handle);

				$meta['offset'] = $offset + $product_count;
				$meta['processed'] = (int)$meta['offset'];
				$meta['updated_at'] = date('Y-m-d H:i:s');
			}

			if ($product_count < self::BATCH_SIZE) {
				$engine = new EleadsFeedEngine();
				$handle = @fopen($paths['temp'], 'ab');
				if (!$handle) {
					throw new RuntimeException('write_failed');
				}

				$engine->writeFooter($handle);
				fclose($handle);

				if (!@rename($paths['temp'], $paths['final'])) {
					@unlink($paths['final']);
					if (!@rename($paths['temp'], $paths['final'])) {
						throw new RuntimeException('write_failed');
					}
				}

				$meta['status'] = 'ready';
				$meta['finished_at'] = date('Y-m-d H:i:s');
				$meta['updated_at'] = $meta['finished_at'];
				$meta['error'] = '';
			} else {
				$meta['status'] = 'running';
			}

			$this->writeMeta($feed_lang, $meta);
		} catch (Throwable $e) {
			$meta = array(
				'lang' => $feed_lang,
				'status' => 'failed',
				'offset' => isset($meta['offset']) ? (int)$meta['offset'] : 0,
				'processed' => isset($meta['processed']) ? (int)$meta['processed'] : 0,
				'batch_size' => self::BATCH_SIZE,
				'started_at' => isset($meta['started_at']) ? $meta['started_at'] : '',
				'updated_at' => date('Y-m-d H:i:s'),
				'finished_at' => date('Y-m-d H:i:s'),
				'error' => $e->getMessage(),
			);
			$this->writeMeta($feed_lang, $meta);
		}

		flock($lock_handle, LOCK_UN);
		fclose($lock_handle);
		if (is_file($paths['lock'])) {
			unlink($paths['lock']);
		}

		return $this->finalizeMeta($this->readMeta($feed_lang), $paths);
	}

	private function buildContext($adapter, $lang_code, $feed_lang) {
		$settings = $adapter->getSettings();
		$adapter->setLanguageByCode($lang_code);

		$shop_name = $settings['shop_name'] !== '' ? $settings['shop_name'] : $adapter->getDefaultShopName();
		$email = $settings['email'] !== '' ? $settings['email'] : $adapter->getDefaultEmail();
		$shop_url = $settings['shop_url'] !== '' ? $settings['shop_url'] : $adapter->getDefaultShopUrl();
		$feed_currency = $settings['currency'] !== '' ? $settings['currency'] : $adapter->getDefaultCurrency();
		$picture_limit = $settings['picture_limit'] > 0 ? (int)$settings['picture_limit'] : 5;
		$image_size = $settings['image_size'] !== '' ? $settings['image_size'] : 'original';
		$grouped_products = (bool)$settings['grouped_products'];
		$short_description_source = $settings['short_description_source'];

		$selected_category_ids = (array)$settings['categories'];
		$selected_attribute_ids = (array)$settings['filter_attributes'];
		$selected_option_value_ids = (array)$settings['filter_option_values'];

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

		return array(
			'ok' => true,
			'shop_name' => $shop_name,
			'email' => $email,
			'shop_url' => $shop_url,
			'feed_currency' => $feed_currency,
			'picture_limit' => $picture_limit,
			'image_size' => $image_size,
			'grouped_products' => $grouped_products,
			'short_description_source' => $short_description_source,
			'export_categories' => $export_categories,
			'selected_category_set' => $selected_category_set,
			'selected_attribute_set' => array_flip(array_values(array_unique(array_map('intval', $selected_attribute_ids)))),
			'selected_option_value_set' => array_flip(array_values(array_unique(array_map('intval', $selected_option_value_ids)))),
		);
	}

	private function finalizeMeta($meta, $paths) {
		if (empty($meta)) {
			return array('ok' => true, 'status' => 'idle');
		}

		$meta['ok'] = true;
		$meta['size'] = is_file($paths['final']) ? (int)filesize($paths['final']) : 0;

		return $meta;
	}

	private function readMeta($feed_lang) {
		$paths = $this->getPaths($feed_lang);
		if (!is_file($paths['meta'])) {
			return array();
		}

		$data = json_decode((string)file_get_contents($paths['meta']), true);

		return is_array($data) ? $data : array();
	}

	private function writeMeta($feed_lang, array $meta) {
		$paths = $this->getPaths($feed_lang);
		file_put_contents($paths['meta'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	}

	private function clearTransientFiles(array $paths) {
		@unlink($paths['temp']);
		if (is_file($paths['lock'])) {
			unlink($paths['lock']);
		}
	}

	private function isStale(array $meta) {
		if (empty($meta['updated_at'])) {
			return false;
		}

		$updated = strtotime($meta['updated_at']);
		if (!$updated) {
			return false;
		}

		return (time() - $updated) > self::JOB_STALE_SECONDS;
	}

	private function getPaths($feed_lang) {
		$directory = rtrim(DIR_CACHE, '/\\') . '/eleads';
		if (!is_dir($directory)) {
			@mkdir($directory, 0775, true);
		}

		$lang = preg_replace('/[^a-z0-9_-]/i', '-', (string)$feed_lang);

		return array(
			'dir' => $directory,
			'final' => $directory . '/feed-' . $lang . '.xml',
			'temp' => $directory . '/feed-' . $lang . '.xml.tmp',
			'meta' => $directory . '/feed-' . $lang . '.meta.json',
			'lock' => $directory . '/feed-' . $lang . '.lock',
		);
	}
}
