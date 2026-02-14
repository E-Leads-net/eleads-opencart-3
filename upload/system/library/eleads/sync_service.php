<?php
class EleadsSyncService {
	private $settings;
	private $adapter;
	private $payload_builder;
	private $api_client;

	public function __construct($settings, $adapter, $payload_builder, $api_client) {
		$this->settings = $settings;
		$this->adapter = $adapter;
		$this->payload_builder = $payload_builder;
		$this->api_client = $api_client;
	}

	public function syncProductUpdated($product_id, $lang_code) {
		if (!$this->isSyncEnabled()) {
			return;
		}

		$api_key = trim((string)$this->settings['api_key']);
		if ($api_key === '') {
			return;
		}

		$this->applyLanguageContext($lang_code);

		$payload = $this->payload_builder->build($this->adapter, (int)$product_id, $lang_code);
		if ($payload === null) {
			return;
		}

		$this->api_client->send(
			EleadsApiRoutes::ecommerceItemsUpdateUrl((string)$product_id),
			'PUT',
			$payload,
			$api_key
		);
	}

	public function syncProductCreated($product_id, $lang_code) {
		if (!$this->isSyncEnabled()) {
			return;
		}

		$api_key = trim((string)$this->settings['api_key']);
		if ($api_key === '') {
			return;
		}

		$this->applyLanguageContext($lang_code);

		$payload = $this->payload_builder->build($this->adapter, (int)$product_id, $lang_code);
		if ($payload === null) {
			return;
		}

		$this->api_client->send(
			rtrim(EleadsApiRoutes::API_BASE, '/') . '/ecommerce/items',
			'POST',
			$payload,
			$api_key
		);
	}

	public function syncProductDeleted($product_id, $lang_code) {
		if (!$this->isSyncEnabled()) {
			return;
		}

		$api_key = trim((string)$this->settings['api_key']);
		if ($api_key === '') {
			return;
		}

		$this->api_client->send(
			EleadsApiRoutes::ecommerceItemsUpdateUrl((string)$product_id),
			'DELETE',
			array('language' => $lang_code),
			$api_key
		);
	}

	private function isSyncEnabled() {
		return !empty($this->settings['sync_enabled']);
	}

	private function applyLanguageContext($lang_code) {
		$resolved = (string)$lang_code;
		if (method_exists($this->adapter, 'resolveLanguageCode')) {
			$resolved = (string)$this->adapter->resolveLanguageCode((string)$lang_code);
		}
		if ($resolved !== '' && method_exists($this->adapter, 'setLanguageByCode')) {
			$this->adapter->setLanguageByCode($resolved);
		}
	}
}
