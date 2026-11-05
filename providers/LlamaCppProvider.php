<?php

declare(strict_types=1);

final class LlamaCppProvider extends AbstractLlmProvider {
	public function getId(): string { return 'llamacpp'; }
	public function getName(): string { return 'Llama.cpp (Auto-hébergé)'; }
	public function getBaseUrl(string $configuredUrl = ''): string {
		return $configuredUrl !== '' ? $configuredUrl : 'http://localhost:8080/v1';
	}
	public function getDefaultModel(): string { return 'qwen2.5-7b-instruct'; }
	public function getPlaceholderModel(): string { return 'qwen2.5-7b-instruct'; }
	public function getPlaceholderUrl(): string { return 'http://localhost:8080/v1'; }
	public function requiresCustomUrl(): bool { return true; }

	#[\Override]
	public function buildRequestBody(
		string $model,
		string $systemPrompt,
		string $userPrompt,
		array $responseFormat,
		int $maxTokens,
		bool $allowThinking
	): array {
		// Consigne directe pour couper le monologue interne sur les modèles Qwen / DeepSeek
		$conciseSystemPrompt = $systemPrompt . "\nIMPORTANT: Do NOT output any thinking process, reasoning, or <think> tags. Output the raw JSON directly.";

		$body = [
			'model' => $model !== '' ? $model : $this->getDefaultModel(),
			'messages' => [
				['role' => 'system', 'content' => $conciseSystemPrompt],
				['role' => 'user', 'content' => $userPrompt],
			],
			'response_format' => ['type' => 'json_object'],
			'chat_template_kwargs' => [
				'enable_thinking' => false,
			],
		];

		if ($maxTokens > 0) {
			$body['max_tokens'] = $maxTokens;
		}

		return $body;
	}
}
