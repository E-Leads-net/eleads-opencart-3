<?php
class EleadsApiRoutes {
	const API_BASE = 'https://dashboard.e-leads.net/api/';
	const WIDGETS_LOADER_TAG = 'https://api.e-leads.net/v1/widgets-loader-tag';
	const TOKEN_STATUS = 'https://dashboard.e-leads.net/api/ecommerce/token/status';
	const SEO_SLUGS = 'https://dashboard.e-leads.net/api/seo/slugs';
	const SEO_PAGE = 'https://dashboard.e-leads.net/api/seo/pages/';
	const SEARCH_FILTERS = 'https://processing.e-leads.net/api/ecommerce/search/filters';

	const GITHUB_REPO = 'https://github.com/E-Leads-net/eleads-opencart-3';
	const GITHUB_LATEST_RELEASE = 'https://api.github.com/repos/E-Leads-net/eleads-opencart-3/releases/latest';
	const GITHUB_TAGS = 'https://api.github.com/repos/E-Leads-net/eleads-opencart-3/tags';

	public static function ecommerceItemsUpdateUrl($external_id) {
		return rtrim(self::API_BASE, '/') . '/ecommerce/items/' . rawurlencode((string)$external_id);
	}

	public static function githubZipballUrl($tag) {
		$tag = ltrim((string)$tag, 'vV');
		return self::GITHUB_REPO . '/archive/refs/tags/v' . $tag . '.zip';
	}
}
