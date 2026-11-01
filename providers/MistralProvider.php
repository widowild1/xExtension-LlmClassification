<?php

declare(strict_types=1);

final class MistralProvider extends AbstractLlmProvider {
	public function getId(): string { return 'mistral'; }
	public function getName(): string { return 'Mistral AI (Cloud)'; }
	public function getBaseUrl(string $configuredUrl = ''): string { return 'https://api.mistral.ai/v1'; }
	public function getDefaultModel(): string { return 'mistral-small-latest'; }
	public function getPlaceholderModel(): string { return 'mistral-small-latest'; }
	public function getPlaceholderUrl(): string { return 'https://api.mistral.ai/v1'; }
	public function requiresCustomUrl(): bool { return false; }
}
