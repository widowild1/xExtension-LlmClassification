<?php

declare(strict_types=1);

final class GeminiProvider extends AbstractLlmProvider {
	public function getId(): string { return 'gemini'; }
	public function getName(): string { return 'Google Gemini (Cloud)'; }
	public function getBaseUrl(string $configuredUrl = ''): string { return 'https://generativelanguage.googleapis.com/v1beta/openai'; }
	public function getDefaultModel(): string { return 'gemini-1.5-flash'; }
	public function getPlaceholderModel(): string { return 'gemini-1.5-flash'; }
	public function getPlaceholderUrl(): string { return 'https://generativelanguage.googleapis.com/v1beta/openai'; }
	public function requiresCustomUrl(): bool { return false; }
}
