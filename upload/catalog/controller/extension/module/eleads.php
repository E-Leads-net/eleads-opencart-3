<?php
class ControllerExtensionModuleEleads extends Controller {
	public function index() {
		require_once DIR_SYSTEM . 'library/eleads/bootstrap.php';
		require_once DIR_SYSTEM . 'library/eleads/oc_adapter.php';

		$feed_lang = isset($this->request->get['lang']) ? (string)$this->request->get['lang'] : '';
		if ($feed_lang === '') {
			$feed_lang = $this->config->get('config_language');
		}
		$feed_lang = $this->normalizeFeedLang($feed_lang);

		$request_key = isset($this->request->get['key']) ? (string)$this->request->get['key'] : '';

		$adapter = new EleadsOcAdapter($this->registry);
		$lang_code = $adapter->resolveLanguageCode($feed_lang);
		$engine = new EleadsFeedEngine();
		$result = $engine->build($adapter, $lang_code, $feed_lang, $request_key);

		if (!$result['ok']) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			return;
		}

		$this->response->addHeader('Content-Type: application/xml; charset=utf-8');
		$this->response->setOutput($result['xml']);
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
