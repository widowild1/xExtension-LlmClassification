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
}
