<?php

declare(strict_types=1);

final class OllamaProvider extends AbstractLlmProvider {
	public function getId(): string { return 'ollama'; }
	public function getName(): string { return 'Ollama (Auto-hébergé)'; }
	public function getBaseUrl(string $configuredUrl = ''): string {
		return $configuredUrl !== '' ? $configuredUrl : 'http://localhost:11434/v1';
	}
	public function getDefaultModel(): string { return 'llama3.1'; }
	public function getPlaceholderModel(): string { return 'llama3.1'; }
	public function getPlaceholderUrl(): string { return 'http://localhost:11434/v1'; }
	public function requiresCustomUrl(): bool { return true; }
}
