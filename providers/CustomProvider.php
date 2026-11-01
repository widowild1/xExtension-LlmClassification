<?php

declare(strict_types=1);

final class CustomProvider extends AbstractLlmProvider {
	public function getId(): string { return 'custom'; }
	public function getName(): string { return 'Autre (URL personnalisée)'; }
	public function getBaseUrl(string $configuredUrl = ''): string {
		return $configuredUrl !== '' ? $configuredUrl : 'https://api.openai.com/v1';
	}
	public function getDefaultModel(): string { return 'gpt-4o-mini'; }
	public function getPlaceholderModel(): string { return 'nom-du-modele'; }
	public function getPlaceholderUrl(): string { return 'https://api.mon-fournisseur.com/v1'; }
	public function requiresCustomUrl(): bool { return true; }
}
