<?php

declare(strict_types=1);

abstract class AbstractLlmProvider implements LlmProviderInterface {
	public function getChatCompletionsUrl(string $configuredUrl = ''): string {
		$base = rtrim($this->getBaseUrl($configuredUrl), '/');
		return $base . '/chat/completions';
	}

	public function buildHeaders(string $apiKey): array {
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json',
		];
		if ($apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $apiKey;
		}
		return $headers;
	}

	public function buildRequestBody(
		string $model,
		string $systemPrompt,
		string $userPrompt,
		array $responseFormat,
		int $maxTokens,
		bool $allowThinking
	): array {
		$body = [
			'model' => $model !== '' ? $model : $this->getDefaultModel(),
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $userPrompt],
			],
		];

		if (!empty($responseFormat)) {
			$body['response_format'] = $responseFormat;
		}

		if ($maxTokens > 0) {
			$body['max_completion_tokens'] = $maxTokens;
		}

		if (!$allowThinking) {
			$body['chat_template_kwargs'] = [
				'enable_thinking' => false,
			];
		}

		return $body;
	}

	public static function explainHttpError(int $httpCode, string $rawBody = '', string $curlError = ''): string {
		$apiErrorMessage = '';
		if ($rawBody !== '') {
			$json = json_decode($rawBody, true);
			if (is_array($json)) {
				$err = $json['error'] ?? $json[0]['error'] ?? $json;
				$apiErrorMessage = $err['message'] ?? $err['error'] ?? (is_string($err) ? $err : '');
				if (is_array($apiErrorMessage)) {
					$apiErrorMessage = json_encode($apiErrorMessage, JSON_UNESCAPED_UNICODE);
				}
			}
			if ($apiErrorMessage === '') {
				$apiErrorMessage = mb_substr(trim(strip_tags($rawBody)), 0, 250);
			}
		}

		$detail = match ($httpCode) {
			0 => 'Échec de connexion réseau (hôte inaccessible, DNS invalide ou délai dépassé).' . ($curlError !== '' ? ' Détail : ' . $curlError : ''),
			400 => 'Requête rejetée (HTTP 400). Les paramètres envoyés sont incompatibles avec ce fournisseur.',
			401 => 'Erreur d’authentification (HTTP 401). La clé API (jeton) est manquante, invalide ou expirée.',
			403 => 'Accès refusé (HTTP 403). Droits insuffisants pour ce modèle avec votre clé.',
			404 => 'Point de terminaison introuvable (HTTP 404). Vérifiez l’URL ou l’existence du modèle spécifié.',
			429 => 'Quota dépassé / Rate-limit (HTTP 429). Trop de requêtes envoyées ou solde épuisé.',
			500 => 'Erreur interne du fournisseur d’IA (HTTP 500). Panne temporaire chez l’hébergeur.',
			502, 503, 504 => 'Service momentanément indisponible (HTTP ' . $httpCode . ').',
			default => 'Erreur HTTP (code ' . $httpCode . ').',
		};

		if ($apiErrorMessage !== '') {
			$detail .= ' — Détail fournisseur : « ' . $apiErrorMessage . ' »';
		}

		return $detail;
	}
}
