<?php

declare(strict_types=1);

final class OpenAiProvider extends AbstractLlmProvider {
	public function getId(): string { return 'openai'; }
	public function getName(): string { return 'OpenAI (Cloud)'; }
	public function getBaseUrl(string $configuredUrl = ''): string { return 'https://api.openai.com/v1'; }
	public function getDefaultModel(): string { return 'gpt-4o-mini'; }
	public function getPlaceholderModel(): string { return 'gpt-4o-mini'; }
	public function getPlaceholderUrl(): string { return 'https://api.openai.com/v1'; }
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
		$body = parent::buildRequestBody($model, $systemPrompt, $userPrompt, $responseFormat, $maxTokens, $allowThinking);
		unset($body['chat_template_kwargs']); // OpenAI rejette les paramètres non standards
		return $body;
	}
}
