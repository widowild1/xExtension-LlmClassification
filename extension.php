<?php

declare(strict_types=1);

require_once __DIR__ . '/providers/LlmProviderInterface.php';
require_once __DIR__ . '/providers/AbstractLlmProvider.php';
require_once __DIR__ . '/providers/OpenAiProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/MistralProvider.php';
require_once __DIR__ . '/providers/OllamaProvider.php';
require_once __DIR__ . '/providers/LlamaCppProvider.php';
require_once __DIR__ . '/providers/CustomProvider.php';
require_once __DIR__ . '/providers/LlmProviderFactory.php';

final class LlmClassificationExtension extends Minz_Extension {
	public const DEFAULT_TIMEOUT = 30;
	public const DEFAULT_MAX_CONTENT_LENGTH = 4000;
	public const DEFAULT_MAX_TOKENS = 512;
	public const DEFAULT_MAX_RETRIES = 2;
	private const RETRYABLE_HTTP_STATUSES = [429, 500, 502, 503, 504];
	private const PROMPT_FILENAME = 'prompt.md';
	private const ATTRIBUTE_PROMPT_HASH = 'prompt_hash';

	public string $user_prompt = '';

	public function init(): void {
		$this->registerTranslates();

		$hook = (class_exists('Minz_HookType') && defined('Minz_HookType::EntryBeforeInsert'))
			? Minz_HookType::EntryBeforeInsert
			: 'entry_before_insert';
		$this->registerHook($hook, [$this, 'classifyEntry']);

		$this->initDefaultConfig('provider', 'gemini');
		$this->initDefaultConfig('api_url', '');
		$this->initDefaultConfig('api_key', '');
		$this->initDefaultConfig('model', 'gemini-3.5-flash');
		$this->initDefaultConfig('max_content_length', self::DEFAULT_MAX_CONTENT_LENGTH);
		$this->initDefaultConfig('timeout', self::DEFAULT_TIMEOUT);
		$this->initDefaultConfig('enable_tags', false);
		$this->initDefaultConfig('tag_prefix', '');
		$this->initDefaultConfig('allowed_tags', '');
		$this->initDefaultConfig('max_tokens', self::DEFAULT_MAX_TOKENS);
		$this->initDefaultConfig('max_retries', self::DEFAULT_MAX_RETRIES);
		$this->initDefaultConfig('search_filter', '');
		$this->initDefaultConfig('allow_thinking', true);
	}

	private function initDefaultConfig(string $key, mixed $defaultValue): void {
		if ($this->getConfigValue($key) === null) {
			$this->setConfigValue($key, $defaultValue);
		}
	}

	public function getConfigValue(string $key): mixed {
		if (method_exists($this, 'getUserConfigurationValue')) {
			return $this->getUserConfigurationValue($key);
		}
		if (class_exists('FreshRSS_Context') && FreshRSS_Context::hasUserConf()) {
			return FreshRSS_Context::userConf()->attributeArray($this->getEntrypoint())[$key] ?? null;
		}
		return null;
	}

	public function setConfigValue(string $key, mixed $value): void {
		if (method_exists($this, 'setUserConfigurationValue')) {
			$this->setUserConfigurationValue($key, $value);
			return;
		}
		if (class_exists('FreshRSS_Context') && FreshRSS_Context::hasUserConf()) {
			$conf = FreshRSS_Context::userConf()->attributeArray($this->getEntrypoint());
			$conf[$key] = $value;
			FreshRSS_Context::userConf()->_attribute($this->getEntrypoint(), $conf);
			FreshRSS_Context::userConf()->save();
		}
	}

	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			if (Minz_Request::paramString('action') === 'test_connection') {
				FreshRSS_Auth::checkCsrf();
				$providerId = trim(Minz_Request::paramString('provider', plaintext: true));
				$apiUrl     = trim(Minz_Request::paramString('api_url', plaintext: true));
				$apiKey     = trim(Minz_Request::paramString('api_key', plaintext: true));
				$model      = trim(Minz_Request::paramString('model', plaintext: true));
				$timeout    = max(3, min(30, Minz_Request::paramInt('timeout') ?: 10));

				$testResult = $this->testConnection($providerId, $apiUrl, $apiKey, $model, $timeout);

				header('Content-Type: application/json; charset=utf-8');
				echo json_encode($testResult);
				exit;
			}

			$this->setConfigValue('provider', trim(Minz_Request::paramString('provider', plaintext: true)));
			$apiUrl = trim(Minz_Request::paramString('api_url', plaintext: true));
			$apiUrl = preg_replace('#/chat/completions/?$#i', '', $apiUrl) ?? $apiUrl;
			$this->setConfigValue('api_url', $apiUrl);
			$this->setConfigValue('api_key', trim(Minz_Request::paramString('api_key', plaintext: true)));
			$this->setConfigValue('model', trim(Minz_Request::paramString('model', plaintext: true)));

			$userPrompt = trim(Minz_Request::paramString('user_prompt', plaintext: true))
				?: _t('ext.llm_classification.default_prompt');
			$this->saveFile(self::PROMPT_FILENAME, $userPrompt);

			$this->setConfigValue('max_content_length', Minz_Request::paramInt('max_content_length'));
			$this->setConfigValue('timeout', Minz_Request::paramInt('timeout') ?: self::DEFAULT_TIMEOUT);
			$this->setConfigValue('max_tokens', Minz_Request::paramInt('max_tokens'));
			$this->setConfigValue('max_retries', max(0, min(5, Minz_Request::paramInt('max_retries'))));
			$this->setConfigValue('enable_tags', Minz_Request::paramBoolean('enable_tags'));
			$this->setConfigValue('tag_prefix', trim(Minz_Request::paramString('tag_prefix', plaintext: true)));
			$this->setConfigValue('allowed_tags', trim(Minz_Request::paramString('allowed_tags', plaintext: true)));
			$this->setConfigValue('search_filter', trim(Minz_Request::paramString('search_filter', plaintext: true)));
			$this->setConfigValue('allow_thinking', Minz_Request::paramBoolean('allow_thinking'));
		}

		$this->user_prompt = $this->hasFile(self::PROMPT_FILENAME)
			? ($this->getFile(self::PROMPT_FILENAME) ?? '')
			: _t('ext.llm_classification.default_prompt');
	}

	private function testConnection(string $providerId, string $apiUrl, string $apiKey, string $model, int $timeout): array {
		$provider = LlmProviderFactory::get($providerId);
		$url = $provider->getChatCompletionsUrl($apiUrl);
		$headers = $provider->buildHeaders($apiKey);

		$body = [
			'model' => $model !== '' ? $model : $provider->getDefaultModel(),
			'messages' => [
				['role' => 'user', 'content' => 'Réponds uniquement par le mot OK'],
			],
			'max_completion_tokens' => 100,
		];

		$requestBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $requestBody,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
		]);

		$start = microtime(true);
		$responseBody = curl_exec($ch);
		$latency = round(microtime(true) - $start, 2);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($curlError === '' && $status >= 200 && $status < 300 && is_string($responseBody)) {
			$data = json_decode($responseBody, true);
			$reply = $data['choices'][0]['message']['content'] ?? 'OK';
			$successMsg = sprintf('Succès (%s) en %.2fs. Réponse du modèle : « %s »', $provider->getName(), $latency, trim((string)$reply));

			Minz_Log::notice('LlmClassification [Test Connexion] ' . $successMsg);

			return [
				'success' => true,
				'code' => $status,
				'message' => 'Connexion réussie !',
				'detail' => $successMsg,
				'latency' => $latency,
			];
		}

		$explanation = AbstractLlmProvider::explainHttpError($status, (string)$responseBody, $curlError);
		Minz_Log::warning(sprintf('LlmClassification [Test Connexion] Échec avec %s (HTTP %d) sur %s : %s', $provider->getName(), $status, $url, $explanation));

		return [
			'success' => false,
			'code' => $status,
			'message' => 'Échec du test de connexion',
			'detail' => $explanation,
		];
	}

	public function getSystemPrompt(): string {
		$prompt = <<<'PROMPT'
			You are a classification assistant.
			Your response MUST be valid JSON with exactly this structure: `{"tags": ["tag1", "tag2"]}`
			Rules:
			- "tags" is an array of short classification labels (UTF-8). It can be empty. Each label can only be used once.
			- Do NOT include any markdown code blocks or text outside the JSON object.

			PROMPT;

		$allowedTags = $this->getAllowedTagsList();
		if ($allowedTags !== []) {
			$prompt .= '- You MUST only use tags from this list: ' . implode(', ', $allowedTags) . "\n";
		}

		return $prompt;
	}

	private function getAllowedTagsList(): array {
		$allowedTagsStr = (string)($this->getConfigValue('allowed_tags') ?? '');
		if ($allowedTagsStr === '') {
			return [];
		}

		return array_values(array_filter(
			array_map('trim', explode("\n", $allowedTagsStr)),
			static fn(string $tag) => $tag !== ''
		));
	}

	private function sanitizeArticleContent(string $rawHtml): string {
		if ($rawHtml === '') {
			return '';
		}

		$text = preg_replace('/<(?:br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "$0\n", $rawHtml) ?? $rawHtml;
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
		$text = preg_replace("/\n\s*\n+/u", "\n\n", $text) ?? $text;

		return trim($text);
	}

	private function buildUserPrompt(FreshRSS_Entry $entry): string {
		$template = $this->hasFile(self::PROMPT_FILENAME)
			? ($this->getFile(self::PROMPT_FILENAME) ?? '')
			: _t('ext.llm_classification.default_prompt');

		if ($template === '') {
			return '';
		}

		$content = $this->sanitizeArticleContent($entry->content());
		$maxLength = (int)($this->getConfigValue('max_content_length') ?? self::DEFAULT_MAX_CONTENT_LENGTH);
		if ($maxLength > 0 && mb_strlen($content) > $maxLength) {
			$content = mb_substr($content, 0, $maxLength) . '…';
		}

		$feed = $entry->feed();

		$replacements = [
			'{title}' => (string)$entry->title(),
			'{content}' => $content,
			'{author}' => (string)$entry->authors(true),
			'{url}' => (string)$entry->link(),
			'{feed_url}' => $feed !== null ? (string)$feed->url() : '',
			'{feed_name}' => $feed !== null ? (string)$feed->name() : '',
			'{date}' => (string)$entry->date(),
			'{tags}' => (string)$entry->tags(true),
		];

		return strtr($template, $replacements);
	}

	private function entryMatchesSearchFilter(FreshRSS_Entry $entry): bool {
		$filterStr = (string)($this->getConfigValue('search_filter') ?? '');
		if ($filterStr === '') {
			return true;
		}

		$lines = array_filter(array_map('trim', explode("\n", $filterStr)), static fn(string $line) => $line !== '');
		foreach ($lines as $line) {
			try {
				$booleanSearch = new FreshRSS_BooleanSearch($line);
				if ($entry->matches($booleanSearch)) {
					return true;
				}
			} catch (\Throwable $e) {
				Minz_Log::warning('LlmClassification: Filtre de recherche invalide « ' . $line . ' » : ' . $e->getMessage());
			}
		}

		return false;
	}

	private function buildResponseFormat(): array {
		$allowedTags = $this->getAllowedTagsList();

		if ($allowedTags === []) {
			return ['type' => 'json_object'];
		}

		return [
			'type' => 'json_schema',
			'json_schema' => [
				'name' => 'classification',
				'strict' => true,
				'schema' => [
					'type' => 'object',
					'properties' => [
						'tags' => [
							'type' => 'array',
							'items' => [
								'type' => 'string',
								'enum' => $allowedTags,
							],
						],
					],
					'required' => ['tags'],
					'additionalProperties' => false,
				],
			],
		];
	}

	private static function parseClassificationJson(string $rawContent): ?array {
		$clean = trim($rawContent);

		if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $clean, $matches)) {
			$clean = trim($matches[1]);
		}

		if (!str_starts_with($clean, '{') && preg_match('/\{[\s\S]*"tags"[\s\S]*\}/', $clean, $matches)) {
			$clean = $matches[0];
		}

		$data = json_decode($clean, true);
		if (is_array($data) && is_array($data['tags'] ?? null)) {
			return [
				'tags' => array_values(array_filter(
					$data['tags'],
					static fn($tag) => is_string($tag) && trim($tag) !== ''
				)),
			];
		}

		return null;
	}

	private function callLlm(string $systemPrompt, string $userPrompt): ?array {
		$providerId = (string)($this->getConfigValue('provider') ?? 'gemini');
		$configuredUrl = (string)($this->getConfigValue('api_url') ?? '');
		$apiKey = trim((string)($this->getConfigValue('api_key') ?? ''));
		$configuredModel = trim((string)($this->getConfigValue('model') ?? ''));
		$timeout = (int)($this->getConfigValue('timeout') ?? self::DEFAULT_TIMEOUT);

		$provider = LlmProviderFactory::get($providerId);
		$url = $provider->getChatCompletionsUrl($configuredUrl);
		$model = $configuredModel !== '' ? $configuredModel : $provider->getDefaultModel();

		if ($providerId === 'gemini' && (str_contains($model, 'gemini-1.5') || $model === '')) {
			$model = 'gemini-3.5-flash';
		}

		$maxTokens = (int)($this->getConfigValue('max_tokens') ?? self::DEFAULT_MAX_TOKENS);
		$allowThinking = (bool)($this->getConfigValue('allow_thinking') ?? true);

		$body = $provider->buildRequestBody(
			$model,
			$systemPrompt,
			$userPrompt,
			$this->buildResponseFormat(),
			$maxTokens,
			$allowThinking
		);

		$requestBody = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($requestBody === false) {
			Minz_Log::warning('LlmClassification: Échec d’encodage JSON');
			return null;
		}

		$headers = $provider->buildHeaders($apiKey);
		$cachePath = CACHE_PATH . '/llm_classification_' . sha1($url . $apiKey . $model . $requestBody) . '.json';
		if (file_exists($cachePath) && (time() - (int)@filemtime($cachePath) < 86400 * 30)) {
			$cached = @file_get_contents($cachePath);
			if (is_string($cached) && $cached !== '') {
				$parsed = self::parseClassificationJson($cached);
				if ($parsed !== null) {
					return $parsed;
				}
			}
		}

		$maxRetries = (int)($this->getConfigValue('max_retries') ?? self::DEFAULT_MAX_RETRIES);
		$responseBody = null;
		$httpStatus = 0;

		for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
			$ch = curl_init();
			curl_setopt_array($ch, [
				CURLOPT_URL => $url,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $requestBody,
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => $timeout,
				CURLOPT_SSL_VERIFYPEER => true,
			]);

			$responseBody = curl_exec($ch);
			$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curlError = curl_error($ch);
			curl_close($ch);

			if ($curlError === '' && $httpStatus >= 200 && $httpStatus < 300 && is_string($responseBody) && $responseBody !== '') {
				break;
			}

			if ($attempt < $maxRetries && in_array($httpStatus, self::RETRYABLE_HTTP_STATUSES, true)) {
				$delay = (int)pow(2, $attempt);
				Minz_Log::warning(sprintf('LlmClassification: Erreur transitoire %s (HTTP %d). Réessai %d/%d dans %ds', $provider->getName(), $httpStatus, $attempt + 1, $maxRetries, $delay));
				sleep($delay);
				continue;
			}

			$explanation = AbstractLlmProvider::explainHttpError($httpStatus, (string)$responseBody, $curlError);
			Minz_Log::warning(sprintf('LlmClassification: Échec appel API sur %s (%s, HTTP %d) : %s', $url, $provider->getName(), $httpStatus, $explanation));
			return null;
		}

		if (!is_string($responseBody) || $responseBody === '') {
			return null;
		}

		$responseData = json_decode($responseBody, true);
		if (!is_array($responseData)) {
			Minz_Log::warning('LlmClassification: Réponse JSON invalide reçue de l’API (HTTP ' . $httpStatus . ')');
			return null;
		}

		$choices = $responseData['choices'] ?? null;
		$choice = is_array($choices) && is_array($choices[0] ?? null) ? $choices[0] : null;
		$content = is_array($choice) && is_array($choice['message'] ?? null) ? ($choice['message']['content'] ?? null) : null;

		if (!is_string($content)) {
			Minz_Log::warning('LlmClassification: Propriété `choices[0].message.content` introuvable dans la réponse API');
			return null;
		}

		$parsed = self::parseClassificationJson($content);
		if ($parsed === null) {
			Minz_Log::warning('LlmClassification: Impossible d’extraire la structure JSON retournée : ' . mb_substr($content, 0, 300));
			return null;
		}

		@file_put_contents($cachePath, $content);
		return $parsed;
	}

	private function applyClassification(FreshRSS_Entry $entry, array $classification, bool $removeOldTags): FreshRSS_Entry {
		if (!is_array($classification['tags'] ?? null)) {
			return $entry;
		}

		$prefix = (string)($this->getConfigValue('tag_prefix') ?? '');
		$allowedTags = $this->getAllowedTagsList();
		$existingTags = $entry->tags();

		if ($removeOldTags && $prefix !== '') {
			$existingTags = array_values(array_filter(
				$existingTags,
				static fn(string $tag) => !str_starts_with($tag, $prefix)
			));
		}

		$newTags = [];
		foreach ($classification['tags'] as $tag) {
			if (!is_string($tag)) {
				continue;
			}
			$tag = trim($tag);
			if ($tag === '') {
				continue;
			}
			if ($allowedTags !== [] && !in_array($tag, $allowedTags, true)) {
				continue;
			}

			$newTags[] = $prefix . $tag;
		}

		$entry->_tags(array_values(array_unique(array_merge($existingTags, $newTags))));
		return $entry;
	}

	private function loadExistingEntry(FreshRSS_Entry $incomingEntry): ?FreshRSS_Entry {
		$feedId = $incomingEntry->feedId();
		$guid = $incomingEntry->guid();
		if ($feedId === 0 || $guid === '') {
			return null;
		}

		try {
			$entryDAO = FreshRSS_Factory::createEntryDao();
			return $entryDAO->searchByGuid($feedId, $guid);
		} catch (\Throwable $e) {
			Minz_Log::warning('LlmClassification: Erreur recherche entrée : ' . $e->getMessage());
			return null;
		}
	}

	public function classifyEntry(FreshRSS_Entry $entry): FreshRSS_Entry {
		$enableTags = (bool)($this->getConfigValue('enable_tags') ?? false);
		if (!$enableTags) {
			return $entry;
		}

		if (!$this->entryMatchesSearchFilter($entry)) {
			return $entry;
		}

		$systemPrompt = $this->getSystemPrompt();
		$userPrompt = $this->buildUserPrompt($entry);
		if ($userPrompt === '') {
			return $entry;
		}

		$incomingPromptHash = sha1($systemPrompt . $userPrompt);
		$classification = null;

		if ($entry->isUpdated()) {
			$existingEntry = $this->loadExistingEntry($entry);
			$existingPromptHash = $existingEntry?->attributeArray($this->getEntrypoint())[self::ATTRIBUTE_PROMPT_HASH] ?? null;

			if ($existingPromptHash === $incomingPromptHash) {
				$prefix = (string)($this->getConfigValue('tag_prefix') ?? '');
				$existingTags = $existingEntry->tags();

				if ($prefix !== '') {
					$existingTags = array_values(array_filter(
						$existingTags,
						static fn(string $tag) => str_starts_with($tag, $prefix)
					));
					$prefixLength = strlen($prefix);
					$existingTags = array_map(static fn(string $tag) => substr($tag, $prefixLength), $existingTags);
				}

				$classification = ['tags' => $existingTags];
			}
		}

		if ($classification === null) {
			$classification = $this->callLlm($systemPrompt, $userPrompt);
			if ($classification === null) {
				return $entry;
			}
		}

		$entry->_attribute($this->getEntrypoint(), [
			self::ATTRIBUTE_PROMPT_HASH => $incomingPromptHash,
		]);

		$entry = $this->applyClassification($entry, $classification, removeOldTags: true);

		$appliedTags = $classification['tags'] ?? [];
		if (!empty($appliedTags)) {
			echo '[LLM Classification] ✓ Tags ajoutés à "' . mb_substr($entry->title(), 0, 35) . '..." : ' . implode(', ', $appliedTags) . PHP_EOL;
		} else {
			echo '[LLM Classification] - Aucun tag pour "' . mb_substr($entry->title(), 0, 35) . '..."' . PHP_EOL;
		}

		return $entry;
	}
}
