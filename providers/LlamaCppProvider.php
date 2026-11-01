<?php

declare(strict_types=1);

final class LlamaCppProvider extends AbstractLlmProvider {
	public function getId(): string { return 'llamacpp'; }
	public function getName(): string { return 'Llama.cpp (Auto-hébergé)'; }
	public function getBaseUrl(string $configuredUrl = ''): string {
		return $configuredUrl !== '' ? $configuredUrl : 'http://localhost:8080/v1';
	}
	public function getDefaultModel(): string { return 'llama-3.2-1b'; }
	public function getPlaceholderModel(): string { return 'llama-3.2-1b'; }
	public function getPlaceholderUrl(): string { return 'http://localhost:8080/v1'; }
	public function requiresCustomUrl(): bool { return true; }
}
