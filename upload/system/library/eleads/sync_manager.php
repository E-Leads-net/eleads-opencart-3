<?php
class EleadsSyncManager {
	private $registry;
	private $db;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->db = $registry->get('db');
	}

	public function syncProduct($product_id, $mode) {
		$product_id = (int)$product_id;
		$mode = (string)$mode;
		if ($product_id <= 0) {
			return;
		}

		require_once DIR_SYSTEM . 'library/eleads/bootstrap.php';
		require_once DIR_SYSTEM . 'library/eleads/oc_adapter.php';

		$adapter = new EleadsOcAdapter($this->registry);
		$settings = $adapter->getSettings();
		$payload_builder = new EleadsSyncPayloadBuilder();
		$api_client = new EleadsApiClient();
		$service = new EleadsSyncService($settings, $adapter, $payload_builder, $api_client);

		$languages = ($mode === 'delete') ? $this->getEnabledSyncLanguages() : $this->getProductSyncLanguages($product_id);
		if (empty($languages)) {
			return;
		}

		foreach ($languages as $lang_code) {
			if ($mode === 'create') {
				$service->syncProductCreated($product_id, $lang_code);
			} elseif ($mode === 'delete') {
				$service->syncProductDeleted($product_id, $lang_code);
			} else {
				$service->syncProductUpdated($product_id, $lang_code);
			}
		}
	}

	private function getProductSyncLanguages($product_id) {
		$languages = array();
		$query = $this->db->query(
			"SELECT DISTINCT l.code FROM " . DB_PREFIX . "product_description pd LEFT JOIN " . DB_PREFIX . "language l ON (pd.language_id = l.language_id) WHERE pd.product_id = '" . (int)$product_id . "' AND l.status = '1'"
		);
		foreach ((array)$query->rows as $row) {
			$normalized = $this->normalizeFeedLang(isset($row['code']) ? (string)$row['code'] : '');
			if ($normalized !== '') {
				$languages[$normalized] = $normalized;
			}
		}
		if (!empty($languages)) {
			return array_values($languages);
		}
		return $this->getEnabledSyncLanguages();
	}

	private function getEnabledSyncLanguages() {
		$languages = array();
		$query = $this->db->query("SELECT code FROM " . DB_PREFIX . "language WHERE status = '1'");
		foreach ((array)$query->rows as $row) {
			$normalized = $this->normalizeFeedLang(isset($row['code']) ? (string)$row['code'] : '');
			if ($normalized !== '') {
				$languages[$normalized] = $normalized;
			}
		}
		return array_values($languages);
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
}
