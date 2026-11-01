<?php

return array(
	'llm_classification' => array(
		'api' => array(
			'title' => 'API Configuration',
			'provider' => 'AI Provider',
			'url' => 'Custom API URL',
			'url_help' => 'Base URL (e.g. <code>http://localhost:11434/v1</code>). Leave empty for default.',
			'key' => 'API Key (Token)',
			'key_help' => 'Bearer authentication token. Required for Gemini, OpenAI and Mistral.',
			'model' => 'Model Name',
			'model_help' => 'Target model (e.g. gemini-3.5-flash, gpt-4o-mini, llama3.1)',
			'timeout' => 'Timeout (seconds)',
			'test_btn' => 'Test connection',
			'test_in_progress' => 'Testing…',
			'max_tokens' => 'Max completion tokens',
			'max_tokens_help' => 'Maximum generated tokens (0 = default)',
			'max_retries' => 'Max retries',
			'max_retries_help' => 'Retry attempts on transient failures (500, 429, timeout).',
			'allow_thinking' => 'Allow thinking mode',
			'allow_thinking_help' => 'Enable reasoning mode for compatible models.',
		),
		'prompts' => array(
			'title' => 'Prompts',
			'system' => 'System prompt (read-only)',
			'user' => 'User prompt',
			'user_help' => 'Available placeholders: <code>{title}</code>, <code>{content}</code>, <code>{author}</code>, <code>{url}</code>, <code>{feed_url}</code>, <code>{feed_name}</code>, <code>{date}</code>, <code>{tags}</code>',
			'max_content_length' => 'Max content length (characters)',
			'max_content_length_help' => 'Character truncation limit for {content} (0 = unlimited)',
		),
		'tags' => array(
			'title' => 'Tag Classification',
			'enable' => 'Enable tag classification',
			'prefix' => 'Tag prefix',
			'prefix_help' => 'Prefix prepended to LLM tags (e.g. "llm/")',
			'allowed' => 'Allowed tags (one per line)',
			'allowed_help' => 'Whitelist of tags. Empty = allow all.',
		),
		'filter' => array(
			'title' => 'Conditions for tagging',
			'search' => 'Search filters',
			'search_help' => 'Only classify articles matching these filters. Leave empty for all.',
		),
		'default_prompt' => 'Classify the following article.

Title: {title}
Author: {author}
Date: {date}
URL: {url}
Feed: {feed_name} ({feed_url})
Existing tags: {tags}

Content:
{content}',
	),
);
