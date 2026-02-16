<?php

trait EleadsCatalogSeoSitemapTrait {
	private function getSeoSitemapPath() {
		$root = rtrim(dirname(DIR_APPLICATION), '/\\');
		return $root . '/e-search/sitemap.xml';
	}
	private function getSeoBaseUrl() {
		if (defined('HTTPS_SERVER') && HTTPS_SERVER) {
			return rtrim(HTTPS_SERVER, '/');
		}
		if (defined('HTTP_SERVER') && HTTP_SERVER) {
			return rtrim(HTTP_SERVER, '/');
		}
		$ssl = (string)$this->config->get('config_ssl');
		return $ssl !== '' ? rtrim($ssl, '/') : rtrim((string)$this->config->get('config_url'), '/');
	}
	private function readSeoSitemapSlugs() {
		$entries = $this->readSeoSitemapEntries();
		$slugs = array();
		foreach ($entries as $entry) {
			$slugs[] = $entry['slug'];
		}
		return array_values(array_unique($slugs));
	}
	private function writeSeoSitemapSlugs($slugs) {
		$lang = $this->resolveSeoSitemapLanguage((string)$this->config->get('config_language'));
		$entries = array();
		foreach ((array)$slugs as $slug) {
			$slug = trim((string)$slug);
			if ($slug === '') {
				continue;
			}
			$entries[] = array('lang' => $lang, 'slug' => $slug);
		}
		return $this->writeSeoSitemapEntries($entries);
	}
	private function readSeoSitemapEntries() {
		$path = $this->getSeoSitemapPath();
		if (!is_file($path)) {
			return array();
		}
		$content = (string)file_get_contents($path);
		if ($content === '') {
			return array();
		}
		$matches = array();
		preg_match_all('#<loc>([^<]+)</loc>#i', $content, $matches);
		if (empty($matches[1])) {
			return array();
		}
		$entries = array();
		foreach ($matches[1] as $value) {
			$loc = html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
			$path = parse_url($loc, PHP_URL_PATH);
			if (!is_string($path)) {
				continue;
			}
			$path = trim($path, '/');
			$lang = '';
			$slug = '';
			if (preg_match('#^([^/]+)/e-search/(.+)$#', $path, $m)) {
				$lang = $this->resolveSeoSitemapLanguage($m[1]);
				$slug = $m[2];
			} elseif (strpos($path, 'e-search/') === 0) {
				$tail = substr($path, strlen('e-search/'));
				if ($tail === false || $tail === '') {
					continue;
				}
				$parts = explode('/', $tail, 2);
				if (count($parts) === 2) {
					$lang = $this->resolveSeoSitemapLanguage($parts[0]);
					$slug = $parts[1];
				} else {
					$slug = $parts[0];
				}
			} else {
				continue;
			}
			$slug = trim((string)urldecode($slug));
			if ($slug === '') {
				continue;
			}
			if ($lang === '') {
				$lang = $this->resolveSeoSitemapLanguage((string)$this->config->get('config_language'));
			}
			$key = $lang . '|' . $slug;
			$entries[$key] = array('lang' => $lang, 'slug' => $slug);
		}
		return array_values($entries);
	}
	private function writeSeoSitemapEntries($entries) {
		$path = $this->getSeoSitemapPath();
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$base_url = $this->getSeoBaseUrl();
		$rows = array();
		$unique = array();
		foreach ((array)$entries as $entry) {
			$lang = $this->resolveSeoSitemapLanguage(isset($entry['lang']) ? (string)$entry['lang'] : '');
			$slug = trim(isset($entry['slug']) ? (string)$entry['slug'] : '');
			if ($slug === '') {
				continue;
			}
			if ($lang === '') {
				$lang = $this->resolveSeoSitemapLanguage((string)$this->config->get('config_language'));
			}
			$key = $lang . '|' . $slug;
			if (isset($unique[$key])) {
				continue;
			}
			$unique[$key] = true;
			$loc = $base_url . '/' . rawurlencode($lang) . '/e-search/' . rawurlencode($slug);
			$rows[] = '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc></url>';
		}
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
		$xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
		if ($rows) {
			$xml .= implode("\n", $rows) . "\n";
		}
		$xml .= "</urlset>\n";
		return @file_put_contents($path, $xml) !== false;
	}
	private function resolveSeoSitemapLanguage($lang) {
		$lang = strtolower(trim((string)$lang));
		$normalized = $this->normalizeFeedLang($lang);
		if ($normalized === 'en') {
			return 'en';
		}
		if ($normalized === 'ru') {
			return 'ru';
		}
		if ($normalized === 'uk') {
			return 'ua';
		}

		if (preg_match('/^[a-z]{2}/', $lang, $m)) {
			return $m[1];
		}

		return 'en';
	}
	private function resolveStoreLanguageCode($lang) {
		$this->load->model('localisation/language');
		$languages = (array)$this->model_localisation_language->getLanguages();
		$lang = strtolower(trim((string)$lang));
		$normalized = $this->normalizeFeedLang($lang);

		foreach ($languages as $language) {
			if (isset($language['status']) && !$language['status']) {
				continue;
			}
			$code = strtolower(isset($language['code']) ? (string)$language['code'] : '');
			if ($code !== '' && $code === $lang) {
				return $code;
			}
			if ($code !== '' && $this->normalizeFeedLang($code) === $normalized) {
				return $code;
			}
		}

		return (string)$this->config->get('config_language');
	}
	private function buildSeoPageUrl($lang, $slug) {
		$base = $this->getSeoBaseUrl();
		$lang = $this->resolveSeoSitemapLanguage($lang);
		return $base . '/' . rawurlencode((string)$lang) . '/e-search/' . rawurlencode((string)$slug);
	}
}
