<?php
class EleadsApiClient {
	public function send($url, $method, $payload, $api_key) {
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
		if ($body === false) {
			return;
		}

		$ch = curl_init();
		if ($ch === false) {
			return;
		}

		$headers = array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json',
			'Content-Type: application/json',
		);

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		if (!empty($payload)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($ch, CURLOPT_TIMEOUT, 4);

		curl_exec($ch);
		curl_close($ch);
	}
}
