<?php

declare(strict_types=1);

final class GeminiProvider extends AbstractLlmProvider {
	public function getId(): string { return 'gemini'; }
	public function getName(): string { return 'Google Gemini (Cloud)'; }
	public function getBaseUrl(string $configuredUrl = ''): string { return 'https://generativelanguage.googleapis.com/v1beta/openai'; }
	public function getDefaultModel(): string { return 'gemini-3.5-flash'; }
	public function getPlaceholderModel(): string { return 'gemini-3.5-flash'; }
	public function getPlaceholderUrl(): string { return 'https://generativelanguage.googleapis.com/v1beta/openai'; }
	public function requiresCustomUrl(): bool { return false; }

	#[\Override]
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

		if ($maxTokens > 0) {
			$body['max_completion_tokens'] = $maxTokens;
		}

		return $body;
	}
}
