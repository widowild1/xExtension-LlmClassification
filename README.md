# FreshRSS - LLM Classification extension

This FreshRSS extension automatically tags incoming RSS articles by calling an OpenAI-compatible LLM API.
It classifies articles on insertion using a customizable prompt, and applies tags based on the LLM response.

## Features

- Compatible with any OpenAI-compatible API (OpenAI, Ollama, LocalAI, vLLM, DeepSeek, etc.)
- Strict JSON output handling with automatic extraction from Markdown code fences
- Customizable user prompt with template placeholders for article data
- Tag prefixing (e.g. `llm/`)
- Entry filtering via FreshRSS Boolean search syntax with safe error handling
- Response caching to avoid redundant API calls
- Content truncation and intelligent block formatting to optimize token usage
- Skips re-classifying updated articles without any prompt-relevant change

## Requirements

- FreshRSS 1.28.2+
- PHP 8.1+
- An OpenAI-compatible LLM API endpoint

## Installation

Ensure that the `./xExtension-LlmClassification/` directory is placed inside FreshRSS `./extensions/` directory, and enable it on the extension panel in FreshRSS.

## Configuration

### API Configuration

| Setting | Default       | Description                                                       |
| :------ | :------------ | :---------------------------------------------------------------- |
| API URL | *(empty)*     | OpenAI-compatible API base URL (e.g. `https://api.openai.com/v1`) |
| API Key | *(empty)*     | Bearer token for API authentication (optional for local APIs)     |
| Model   | `gpt-4o-mini` | Model name                                                        |
| Timeout | `30`          | HTTP request timeout in seconds (1–300)                           |

### Prompts

The **system prompt** is read-only and constrains the LLM to return a JSON object with the structure `{"tags": ["tag1", "tag2"]}`.

The **user prompt** is an editable template. The following placeholders are replaced with article data before each API call:

| Placeholder  | Value                                                |
| :----------- | :--------------------------------------------------- |
| `{title}`    | Article title                                        |
| `{content}`  | Article content (HTML stripped, formatted, truncated)|
| `{author}`   | Article author(s)                                    |
| `{url}`      | Article link                                         |
| `{feed_url}` | Feed URL                                             |
| `{feed_name}`| Feed name                                            |
| `{date}`     | Article date                                         |
| `{tags}`     | Existing article tags                                |

**Max content length** (default: `4000` characters): Maximum number of characters for the `{content}` placeholder. Set to `0` for unlimited.

### Tag Classification

| Setting                   | Default   | Description                                                                                                                 |
| :------------------------ | :-------- | :-------------------------------------------------------------------------------------------------------------------------- |
| Enable tag classification | Off       | Master toggle for the extension                                                                                             |
| Tag prefix                | *(empty)* | Prefix prepended to each LLM-generated tag (e.g. `llm/`)                                                                    |
| Allowed tags              | *(empty)* | Whitelist of accepted tags (one per line). If set, only these tags are kept from the LLM response. Empty = all tags allowed |

### Conditions for tagging

**Search filters** (one per line): Only entries matching at least one filter are classified. Uses [FreshRSS Boolean search syntax](https://freshrss.github.io/FreshRSS/en/users/10_filter.html). Leave empty to classify all entries.
