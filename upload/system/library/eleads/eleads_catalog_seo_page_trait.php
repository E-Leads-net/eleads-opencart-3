<?php

trait EleadsCatalogSeoPageTrait {
	private function fetchSeoPage($slug, $lang) {
		$api_key = trim((string)$this->config->get('module_eleads_api_key'));
		if ($api_key === '') {
			return null;
		}
		$lang = $this->normalizeFeedLang((string)$lang);
		if ($lang === '') {
			$lang = $this->normalizeFeedLang((string)$this->config->get('config_language'));
		}
		require_once DIR_SYSTEM . 'library/eleads/api_routes.php';
		$ch = curl_init();
		if ($ch === false) {
			return null;
		}
		$headers = array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json',
		);
		$url = EleadsApiRoutes::SEO_PAGE . rawurlencode($slug) . '?lang=' . rawurlencode($lang);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
		curl_setopt($ch, CURLOPT_TIMEOUT, 6);
		$response = curl_exec($ch);
		if ($response === false) {
			curl_close($ch);
			return null;
		}
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($httpCode < 200 || $httpCode >= 300) {
			return null;
		}
		$data = json_decode($response, true);
		if (!is_array($data) || empty($data['page']) || !is_array($data['page'])) {
			return null;
		}
		$page = $data['page'];
		$alternate = array();
		if (isset($page['alternate']) && is_array($page['alternate'])) {
			foreach ($page['alternate'] as $item) {
				if (!is_array($item)) {
					continue;
				}
				$alternate[] = array(
					'url' => isset($item['url']) ? trim((string)$item['url']) : '',
					'lang' => isset($item['lang']) ? trim((string)$item['lang']) : '',
				);
			}
		}
		return array(
			'query' => isset($page['query']) ? (string)$page['query'] : '',
			'seo_slug' => isset($page['seo_slug']) ? (string)$page['seo_slug'] : '',
			'url' => isset($page['url']) ? (string)$page['url'] : '',
			'language' => isset($page['language']) ? (string)$page['language'] : '',
			'alternate' => $alternate,
			'h1' => isset($page['h1']) ? (string)$page['h1'] : '',
			'meta_title' => isset($page['meta_title']) ? (string)$page['meta_title'] : '',
			'meta_description' => isset($page['meta_description']) ? (string)$page['meta_description'] : '',
			'meta_keywords' => isset($page['meta_keywords']) ? (string)$page['meta_keywords'] : '',
			'short_description' => isset($page['short_description']) ? (string)$page['short_description'] : '',
			'description' => isset($page['description']) ? (string)$page['description'] : '',
			'product_ids' => isset($page['product_ids']) && is_array($page['product_ids']) ? $page['product_ids'] : array(),
		);
	}
	private function applyStoreLanguage($code) {
		$code = (string)$code;
		if ($code === '') {
			return;
		}

		$this->load->model('localisation/language');
		$languages = (array)$this->model_localisation_language->getLanguages();
		if (!isset($languages[$code])) {
			return;
		}

		$this->session->data['language'] = $code;
		$this->config->set('config_language', $code);
		$this->config->set('config_language_id', (int)$languages[$code]['language_id']);

		$language = new Language($code);
		$language->load($code);
		$this->registry->set('language', $language);
		$this->language = $language;
	}
	private function findAlternateUrl($alternates, $target_short) {
		foreach ((array)$alternates as $alternate) {
			if (!is_array($alternate)) {
				continue;
			}
			$alt_short = $this->resolveSeoSitemapLanguage(isset($alternate['lang']) ? (string)$alternate['lang'] : '');
			$url = isset($alternate['url']) ? trim((string)$alternate['url']) : '';
			if ($url !== '' && $alt_short === $target_short) {
				return $url;
			}
		}
		return '';
	}
}
