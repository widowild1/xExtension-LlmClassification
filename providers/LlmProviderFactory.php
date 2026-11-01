<?php

declare(strict_types=1);

final class LlmProviderFactory {
	/**
	 * @return array<string, LlmProviderInterface>
	 */
	public static function getAll(): array {
		return [
			'gemini'   => new GeminiProvider(),
			'openai'   => new OpenAiProvider(),
			'mistral'  => new MistralProvider(),
			'ollama'   => new OllamaProvider(),
			'llamacpp' => new LlamaCppProvider(),
			'custom'   => new CustomProvider(),
		];
	}

	public static function get(string $providerId): LlmProviderInterface {
		$all = self::getAll();
		return $all[$providerId] ?? $all['gemini'];
	}
}
