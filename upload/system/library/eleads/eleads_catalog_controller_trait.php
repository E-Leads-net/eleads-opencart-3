<?php
require_once DIR_SYSTEM . 'library/eleads/eleads_catalog_filter_trait.php';
require_once DIR_SYSTEM . 'library/eleads/eleads_catalog_seo_page_trait.php';
require_once DIR_SYSTEM . 'library/eleads/eleads_catalog_seo_sitemap_trait.php';

trait EleadsCatalogControllerTrait {
	use EleadsCatalogFilterTrait;
	use EleadsCatalogSeoPageTrait;
	use EleadsCatalogSeoSitemapTrait;

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
	private function getBearerToken() {
		$headers = array();
		if (function_exists('getallheaders')) {
			$headers = getallheaders();
		}
		$auth = '';
		if (isset($headers['Authorization'])) {
			$auth = $headers['Authorization'];
		} elseif (isset($headers['authorization'])) {
			$auth = $headers['authorization'];
		} elseif (isset($this->request->server['HTTP_AUTHORIZATION'])) {
			$auth = $this->request->server['HTTP_AUTHORIZATION'];
		} elseif (isset($this->request->server['REDIRECT_HTTP_AUTHORIZATION'])) {
			$auth = $this->request->server['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if (stripos($auth, 'Bearer ') === 0) {
			return trim(substr($auth, 7));
		}
		return '';
	}
}
