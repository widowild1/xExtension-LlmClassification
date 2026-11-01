<?php

declare(strict_types=1);

final class LlmProviderFactory {
	/**
	 * @return array<string, LlmProviderInterface>
	 */
	public static function getAll(): array {
		return [
			'openai'   => new OpenAiProvider(),
			'gemini'   => new GeminiProvider(),
			'mistral'  => new MistralProvider(),
			'ollama'   => new OllamaProvider(),
			'llamacpp' => new LlamaCppProvider(),
			'custom'   => new CustomProvider(),
		];
	}

	public static function get(string $providerId): LlmProviderInterface {
		$all = self::getAll();
		return $all[$providerId] ?? $all['custom'];
	}
}
