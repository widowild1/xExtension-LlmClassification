<?php

declare(strict_types=1);

final class LlmClassificationExtension extends Minz_Extension {
	public const DEFAULT_MODEL = 'gpt-4o-mini';
	public const DEFAULT_TIMEOUT = 30;
	public const DEFAULT_MAX_CONTENT_LENGTH = 4000;
	public const DEFAULT_MAX_TOKENS = 512;
	public const DEFAULT_MAX_RETRIES = 2;
	private const RETRYABLE_HTTP_STATUSES = [429, 500, 502, 503, 504];
	private const PROMPT_FILENAME = 'prompt.md';
	private const ATTRIBUTE_PROMPT_HASH = 'prompt_hash';

	public string $user_prompt = '';

	/**
	 * @throws FreshRSS_Context_Exception
	 */
	#[\Override]
	public function init(): void {
		if (!FreshRSS_Context::hasSystemConf()) {
			throw new FreshRSS_Context_Exception('System configuration not initialised!');
		}
		$this->registerTranslates();
		$this->registerHook(Minz_HookType::EntryBeforeInsert, $this->classifyEntry(...));

		if ($this->getUserConfigurationString('api_url') === null) {
			$this->setUserConfigurationValue('api_url', '');
		}
		if ($this->getUserConfigurationString('api_key') === null) {
			$this->setUserConfigurationValue('api_key', '');
		}
		if ($this->getUserConfigurationString('model') === null) {
			$this->setUserConfigurationValue('model', self::DEFAULT_MODEL);
		}
		if ($this->getUserConfigurationInt('max_content_length') === null) {
			$this->setUserConfigurationValue('max_content_length', self::DEFAULT_MAX_CONTENT_LENGTH);
		}
		if ($this->getUserConfigurationInt('timeout') === null) {
			$this->setUserConfigurationValue('timeout', self::DEFAULT_TIMEOUT);
		}
		if ($this->getUserConfigurationBool('enable_tags') === null) {
			$this->setUserConfigurationValue('enable_tags', false);
		}
		if ($this->getUserConfigurationString('tag_prefix') === null) {
			$this->setUserConfigurationValue('tag_prefix', '');
		}
		if ($this->getUserConfigurationString('allowed_tags') === null) {
			$this->setUserConfigurationValue('allowed_tags', '');
		}
		if ($this->getUserConfigurationInt('max_tokens') === null) {
			$this->setUserConfigurationValue('max_tokens', self::DEFAULT_MAX_TOKENS);
		}
		if ($this->getUserConfigurationInt('max_retries') === null) {
			$this->setUserConfigurationValue('max_retries', self::DEFAULT_MAX_RETRIES);
		}
		if ($this->getUserConfigurationString('search_filter') === null) {
			$this->setUserConfigurationValue('search_filter', '');
		}
		if ($this->getUserConfigurationBool('allow_thinking') === null) {
			$this->setUserConfigurationValue('allow_thinking', true);
		}
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			FreshRSS_Auth::checkCsrf();

			$apiUrl = trim(Minz_Request::paramString('api_url', plaintext: true));
			$apiUrl = preg_replace('#/chat/completions/?$#i', '', $apiUrl) ?? $apiUrl;
			$this->setUserConfigurationValue('api_url', $apiUrl);
			$this->setUserConfigurationValue('api_key', trim(Minz_Request::paramString('api_key', plaintext: true)));
			$this->setUserConfigurationValue('model', trim(Minz_Request::paramString('model', plaintext: true)));

			$userPrompt = trim(Minz_Request::paramString('user_prompt', plaintext: true))
				?: _t('ext.llm_classification.default_prompt');
			$this->saveFile(self::PROMPT_FILENAME, $userPrompt);

			$this->setUserConfigurationValue('max_content_length', Minz_Request::paramInt('max_content_length'));
			$this->setUserConfigurationValue('timeout', Minz_Request::paramInt('timeout') ?: self::DEFAULT_TIMEOUT);
			$this->setUserConfigurationValue('max_tokens', Minz_Request::paramInt('max_tokens'));
			$this->setUserConfigurationValue('max_retries', max(0, min(5, Minz_Request::paramInt('max_retries'))));
			$this->setUserConfigurationValue('enable_tags', Minz_Request::paramBoolean('enable_tags'));
			$this->setUserConfigurationValue('tag_prefix', trim(Minz_Request::paramString('tag_prefix', plaintext: true)));
			$this->setUserConfigurationValue('allowed_tags', trim(Minz_Request::paramString('allowed_tags', plaintext: true)));
			$this->setUserConfigurationValue('search_filter', trim(Minz_Request::paramString('search_filter', plaintext: true)));
			$this->setUserConfigurationValue('allow_thinking', Minz_Request::paramBoolean('allow_thinking'));
		}

		$this->user_prompt = $this->hasFile(self::PROMPT_FILENAME)
			? ($this->getFile(self::PROMPT_FILENAME) ?? '')
			: _t('ext.llm_classification.default_prompt');
	}

	/**
	 * Build the system prompt that constrains the LLM to return a specific JSON structure.
	 */
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

	/**
	 * @return list<string>
	 */
	private function getAllowedTagsList(): array {
		$allowedTagsStr = $this->getUserConfigurationString('allowed_tags') ?? '';
		if ($allowedTagsStr === '') {
			return [];
		}

		return array_values(array_filter(
			array_map('trim', explode("\n", $allowedTagsStr)),
			static fn(string $tag) => $tag !== ''
		));
	}

	/**
	 * Clean article HTML and separate blocks to prevent word gluing.
	 */
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

	/**
	 * Build the user prompt by replacing placeholders with entry values.
	 */
	private function buildUserPrompt(FreshRSS_Entry $entry): string {
		$template = $this->hasFile(self::PROMPT_FILENAME)
			? ($this->getFile(self::PROMPT_FILENAME) ?? '')
			: _t('ext.llm_classification.default_prompt');

		if ($template === '') {
			return '';
		}

		$content = $this->sanitizeArticleContent($entry->content());
		$maxLength = $this->getUserConfigurationInt('max_content_length') ?? self::DEFAULT_MAX_CONTENT_LENGTH;
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

	/**
	 * Check whether the entry matches the configured search filter.
	 * Catches malformed user query exceptions to avoid breaking feed refresh.
	 */
	private function entryMatchesSearchFilter(FreshRSS_Entry $entry): bool {
		$filterStr = $this->getUserConfigurationString('search_filter') ?? '';
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
				Minz_Log::warning('LlmClassification: Invalid search filter “' . $line . '”: ' . $e->getMessage());
			}
		}

		return false;
	}

	/**
	 * Build the response_format payload for the API request.
	 * OpenAI strict JSON schema disallows uniqueItems and maxItems.
	 * @return array<string, mixed>
	 */
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

	/**
	 * @param array{fail?:bool,status?:int,error?:string} $response
	 */
	private static function isRetryableFailure(array $response): bool {
		if (!($response['fail'] ?? false)) {
			return false;
		}
		if (($response['status'] ?? 0) === 0 && ($response['error'] ?? '') !== '') {
			return true;
		}

		return in_array($response['status'] ?? 0, self::RETRYABLE_HTTP_STATUSES, true);
	}

	/**
	 * Extract and parse JSON even if surrounded by markdown blocks or noise text.
	 * @return array{tags: list<string>}|null
	 */
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

	/**
	 * Call the LLM API and return the parsed classification result.
	 * @return array{tags: list<string>}|null
	 */
	private function callLlm(string $systemPrompt, string $userPrompt): ?array {
		$apiUrl = trim($this->getUserConfigurationString('api_url') ?? '');
		$apiKey = trim($this->getUserConfigurationString('api_key') ?? '');
		$model = trim($this->getUserConfigurationString('model') ?? '') ?: self::DEFAULT_MODEL;
		$timeout = $this->getUserConfigurationInt('timeout') ?? self::DEFAULT_TIMEOUT;

		if ($apiUrl === '') {
			return null;
		}

		$url = rtrim($apiUrl, '/') . '/chat/completions';
		$body = [
			'model' => $model,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $userPrompt],
			],
			'response_format' => $this->buildResponseFormat(),
		];

		$maxTokens = $this->getUserConfigurationInt('max_tokens') ?? self::DEFAULT_MAX_TOKENS;
		if ($maxTokens > 0) {
			$body['max_completion_tokens'] = $maxTokens;
		}

		$allowThinking = $this->getUserConfigurationBool('allow_thinking') ?? true;
		if (!$allowThinking) {
			$body['chat_template_kwargs'] = [
				'enable_thinking' => false,
			];
		}

		$requestBody = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($requestBody === false) {
			Minz_Log::warning('LlmClassification: Failed to encode request body');
			return null;
		}

		$headers = [
			'Content-Type: application/json',
			'Accept: application/json',
		];
		if ($apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $apiKey;
		}

		$cachePath = CACHE_PATH . '/llm_classification_' . sha1($apiUrl . $apiKey . $model . $requestBody) . '.json';
		$maxRetries = $this->getUserConfigurationInt('max_retries') ?? self::DEFAULT_MAX_RETRIES;
		$response = null;
		$httpStatus = 0;

		for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
			$response = FreshRSS_http_Util::httpGet($url, $cachePath, type: 'json', curl_options: [
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $requestBody,
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => $timeout,
			]);

			$httpStatus = (int)($response['status'] ?? 0);

			if (!($response['fail'] ?? false) && ($response['body'] ?? '') !== '') {
				break;
			}

			@unlink($cachePath);

			if ($attempt < $maxRetries && self::isRetryableFailure($response)) {
				$delay = (int)pow(2, $attempt);
				Minz_Log::warning('LlmClassification: API call failed (HTTP ' . $httpStatus
					. (($response['error'] ?? '') !== '' ? '; ' . $response['error'] : '')
					. '), retry ' . ($attempt + 1) . '/' . $maxRetries . ' after ' . $delay . 's');
				sleep($delay);
				continue;
			}

			Minz_Log::warning('LlmClassification: API call failed for ' . $url . ' (HTTP ' . $httpStatus . '), not retrying');
			return null;
		}

		if ($response === null || ($response['fail'] ?? false) || !is_string($response['body'] ?? null) || $response['body'] === '') {
			@unlink($cachePath);
			Minz_Log::warning('LlmClassification: API call failed after ' . (1 + $maxRetries) . ' attempt(s) for ' . $url . ' (HTTP ' . $httpStatus . ')');
			return null;
		}

		$responseData = json_decode($response['body'], true);
		if (!is_array($responseData)) {
			@unlink($cachePath);
			Minz_Log::warning('LlmClassification: Invalid JSON response from API! (HTTP ' . $httpStatus . ')');
			return null;
		}

		$choices = $responseData['choices'] ?? null;
		$choice = is_array($choices) && is_array($choices[0] ?? null) ? $choices[0] : null;
		$content = is_array($choice) && is_array($choice['message'] ?? null) ? ($choice['message']['content'] ?? null) : null;

		if (!is_string($content)) {
			@unlink($cachePath);
			Minz_Log::warning('LlmClassification: Missing `choices[0].message.content` in API response! (HTTP ' . $httpStatus . ')');
			return null;
		}

		$finishReason = is_array($choice) ? (string)($choice['finish_reason'] ?? 'stop') : '?';
		if ($finishReason === 'length') {
			Minz_Log::warning('LlmClassification: Response truncated due to `max_tokens`. Consider increasing it.');
		} elseif ($finishReason !== 'stop') {
			Minz_Log::warning('LlmClassification: API terminated prematurely with finish_reason “' . $finishReason . '”! (HTTP ' . $httpStatus . ')');
		}

		$parsed = self::parseClassificationJson($content);
		if ($parsed === null) {
			@unlink($cachePath);
			Minz_Log::warning('LlmClassification: LLM returned invalid JSON structure! `' . mb_substr($content, 0, 300) . '` (HTTP ' . $httpStatus . ')');
			return null;
		}

		return $parsed;
	}

	/**
	 * Apply classification results to an entry.
	 * Keeps tags as raw UTF-8 text (FreshRSS escapes HTML on output).
	 * @param array<string, mixed> $classification
	 */
	private function applyClassification(FreshRSS_Entry $entry, array $classification, bool $removeOldTags): FreshRSS_Entry {
		if (!is_array($classification['tags'] ?? null)) {
			return $entry;
		}

		$prefix = (string)($this->getUserConfigurationString('tag_prefix') ?? '');
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

	/**
	 * Look up the currently stored entry for an entry being updated.
	 */
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
			Minz_Log::warning('LlmClassification: Failed to load existing entry for re-classification: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Hook for EntryBeforeInsert: classify a new entry.
	 */
	public function classifyEntry(FreshRSS_Entry $entry): FreshRSS_Entry {
		$enableTags = $this->getUserConfigurationBool('enable_tags') ?? false;
		$apiUrl = $this->getUserConfigurationString('api_url') ?? '';
		if (!$enableTags || $apiUrl === '') {
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
				$prefix = (string)($this->getUserConfigurationString('tag_prefix') ?? '');
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

		$entry->_attribute($this->getEntrypoint(), [
			self::ATTRIBUTE_PROMPT_HASH => $incomingPromptHash,
		]);

		if ($classification === null) {
			$classification = $this->callLlm($systemPrompt, $userPrompt) ?? [];
		}

		return $this->applyClassification($entry, $classification, removeOldTags: true);
	}
}
