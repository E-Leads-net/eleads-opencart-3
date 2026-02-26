<?php
class EleadsUpdateManager {
	private $registry;
	private $loader;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->loader = $registry->get('load');
	}

	public function getUpdateInfo() {
		require_once DIR_SYSTEM . 'library/eleads/bootstrap.php';
		return EleadsUpdateHelper::getUpdateInfo();
	}

	public function updateToLatest() {
		require_once DIR_SYSTEM . 'library/eleads/bootstrap.php';

		$root_path = rtrim(dirname(DIR_APPLICATION), '/\\') . '/';
		$result = EleadsUpdateHelper::updateToLatest($root_path);
		if (!empty($result['ok'])) {
			$this->refreshAfterUpdate();
		}

		return $result;
	}

	private function refreshAfterUpdate() {
		$this->syncLocalModification();
		$this->loader->controller('marketplace/modification/refresh', array('redirect' => 'extension/module/eleads'));
		$this->clearDirectoryFiles(DIR_CACHE);
	}

	private function syncLocalModification() {
		$xml_file = rtrim(dirname(DIR_APPLICATION), '/\\') . '/install.xml';

		if (!is_file($xml_file)) {
			return;
		}

		$xml = @file_get_contents($xml_file);

		if ($xml === false || trim($xml) === '') {
			return;
		}

		$meta = $this->parseModificationMeta($xml);

		$this->loader->model('setting/modification');

		$exists = $this->model_setting_modification->getModificationByCode($meta['code']);
		if (!empty($exists['modification_id'])) {
			$this->model_setting_modification->deleteModification((int)$exists['modification_id']);
		}

		$this->model_setting_modification->addModification(array(
			'extension_install_id' => 0,
			'code' => $meta['code'],
			'name' => $meta['name'],
			'author' => $meta['author'],
			'version' => $meta['version'],
			'link' => '',
			'xml' => $xml,
			'status' => 1,
		));
	}

	private function parseModificationMeta($xml) {
		$meta = array(
			'code' => 'eleads_oc3',
			'name' => 'E-Leads OpenCart 3',
			'author' => 'E-Leads',
			'version' => '0.0.0',
		);

		$doc = @simplexml_load_string($xml);
		if ($doc instanceof SimpleXMLElement) {
			if (isset($doc->code) && trim((string)$doc->code) !== '') {
				$meta['code'] = trim((string)$doc->code);
			}
			if (isset($doc->name) && trim((string)$doc->name) !== '') {
				$meta['name'] = trim((string)$doc->name);
			}
			if (isset($doc->author) && trim((string)$doc->author) !== '') {
				$meta['author'] = trim((string)$doc->author);
			}
			if (isset($doc->version) && trim((string)$doc->version) !== '') {
				$meta['version'] = trim((string)$doc->version);
			}
		}

		return $meta;
	}

	private function clearDirectoryFiles($dir) {
		if (!is_dir($dir)) {
			return;
		}
		$items = glob(rtrim($dir, '/\\') . '/*');
		if (!$items) {
			return;
		}
		foreach ($items as $item) {
			$name = basename($item);
			if ($name === 'index.html' || $name === '.htaccess') {
				continue;
			}
			if (is_dir($item)) {
				$this->removeDirectory($item);
			} else {
				@unlink($item);
			}
		}
	}

	private function removeDirectory($dir) {
		$items = glob(rtrim($dir, '/\\') . '/*');
		if ($items) {
			foreach ($items as $item) {
				if (is_dir($item)) {
					$this->removeDirectory($item);
				} else {
					@unlink($item);
				}
			}
		}
		@rmdir($dir);
	}
}
