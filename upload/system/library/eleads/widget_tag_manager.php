<?php
class EleadsWidgetTagManager {
	private $config;

	public function __construct($registry) {
		$this->config = $registry->get('config');
	}

	public function sync($enabled, $api_key) {
		$files = $this->getFooterTemplateFiles();
		if (!$files) {
			return;
		}

		$block = '';
		if ($enabled) {
			$tag = $this->fetchWidgetLoaderTag((string)$api_key);
			if ($tag !== '') {
				$block = "<!-- ELeads Widgets Loader Tag Start -->\n" . $this->stripWidgetLoaderMarkers($tag) . "\n<!-- ELeads Widgets Loader Tag End -->";
			}
		}

		foreach ($files as $file) {
			$content = @file_get_contents($file);
			if ($content === false) {
				continue;
			}
			$updated = $this->removeWidgetLoaderBlock($content);
			if ($enabled && $block !== '') {
				if (preg_match('/<\/body>/i', $updated)) {
					$updated = preg_replace('/<\/body>/i', "\n" . $block . "\n</body>", $updated, 1);
				} else {
					$updated = rtrim($updated) . "\n\n" . $block . "\n";
				}
			}
			if ($updated !== $content) {
				@file_put_contents($file, $updated);
			}
		}
	}

	private function fetchWidgetLoaderTag($api_key) {
		$api_key = trim((string)$api_key);
		if ($api_key === '') {
			return '';
		}
		require_once DIR_SYSTEM . 'library/eleads/api_routes.php';

		$ch = curl_init();
		if ($ch === false) {
			return '';
		}
		curl_setopt($ch, CURLOPT_URL, EleadsApiRoutes::WIDGETS_LOADER_TAG);
		curl_setopt($ch, CURLOPT_HTTPGET, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json, text/plain',
		));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
		curl_setopt($ch, CURLOPT_TIMEOUT, 8);
		$response = curl_exec($ch);
		$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $http_code < 200 || $http_code >= 300) {
			return '';
		}

		$body = trim((string)$response);
		$json = json_decode($body, true);
		if (is_array($json)) {
			if (!empty($json['tag'])) {
				return (string)$json['tag'];
			}
			if (!empty($json['data']['tag'])) {
				return (string)$json['data']['tag'];
			}
			if (!empty($json['html'])) {
				return (string)$json['html'];
			}
		}

		return $body;
	}

	private function getFooterTemplateFiles() {
		$root = rtrim(dirname(DIR_APPLICATION), '/\\') . '/';
		$theme = (string)$this->config->get('config_theme');
		$candidates = array(
			$root . 'catalog/view/theme/' . $theme . '/template/common/footer.twig',
			$root . 'catalog/view/theme/' . $theme . '/template/common/footer.tpl',
			$root . 'catalog/view/theme/default/template/common/footer.twig',
			$root . 'catalog/view/theme/default/template/common/footer.tpl',
		);

		$files = array();
		foreach ($candidates as $file) {
			if (is_file($file)) {
				$files[] = $file;
			}
		}
		return array_values(array_unique($files));
	}

	private function removeWidgetLoaderBlock($content) {
		return preg_replace('/\\s*<!--\\s*ELeads Widgets Loader Tag Start\\s*-->.*?<!--\\s*ELeads Widgets Loader Tag End\\s*-->\\s*/is', "\n", (string)$content);
	}

	private function stripWidgetLoaderMarkers($content) {
		$content = preg_replace('/<!--\\s*ELeads Widgets Loader Tag Start\\s*-->/i', '', (string)$content);
		$content = preg_replace('/<!--\\s*ELeads Widgets Loader Tag End\\s*-->/i', '', (string)$content);
		return trim((string)$content);
	}
}
