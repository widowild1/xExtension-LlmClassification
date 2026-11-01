<?php

declare(strict_types=1);

interface LlmProviderInterface {
	public function getId(): string;
	public function getName(): string;
	public function getBaseUrl(string $configuredUrl = ''): string;
	public function getChatCompletionsUrl(string $configuredUrl = ''): string;
	public function getDefaultModel(): string;
	public function getPlaceholderModel(): string;
	public function getPlaceholderUrl(): string;
	public function requiresCustomUrl(): bool;

	/**
	 * @return list<string>
	 */
	public function buildHeaders(string $apiKey): array;

	/**
	 * @param array<string, mixed> $responseFormat
	 * @return array<string, mixed>
	 */
	public function buildRequestBody(
		string $model,
		string $systemPrompt,
		string $userPrompt,
		array $responseFormat,
		int $maxTokens,
		bool $allowThinking
	): array;
}
