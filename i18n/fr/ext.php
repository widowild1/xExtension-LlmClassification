<?php

return array(
	'llm_classification' => array(
		'api' => array(
			'title' => 'Configuration du Moteur Principal',
			'advanced_title' => 'Paramètres Techniques & Débit',
			'provider' => 'Moteur d’IA',
			'url' => 'URL de l’API',
			'url_help' => 'Laisser vide pour l’URL par défaut du service.',
			'key' => 'Clé API (Jeton)',
			'key_help' => 'Jeton d’authentification (Bearer token). Requis pour Gemini, OpenAI, Mistral.',
			'model' => 'Nom du modèle',
			'model_help' => 'Modèle d’IA à interroger (ex. gemini-3.5-flash, gpt-4o-mini, llama3.1)',
			'timeout' => 'Délai d’attente (secondes)',
			'test_btn' => 'Tester la connexion principale',
			'test_in_progress' => 'Test en cours…',
			'max_tokens' => 'Limite de tokens',
			'max_tokens_help' => 'Nombre maximal de tokens générés (0 = valeur par défaut)',
			'max_retries' => 'Tentatives en cas d’échec',
			'max_retries_help' => 'Nombre de réessais automatiques en cas d’erreur réseau temporaire.',
			'allow_thinking' => 'Autoriser le mode raisonnement (thinking)',
			'allow_thinking_help' => 'Activer le mode réflexion pour les modèles compatibles.',
		),
		'fallback' => array(
			'title' => 'Moteur de Secours (Bascule automatique en cas de quota dépassé)',
			'enable' => 'Activer la bascule vers un moteur alternatif',
			'enable_help' => 'Si le moteur principal renvoie une erreur 429 (quota épuisé) ou est en panne, les articles basculent instantanément sur ce moteur.',
			'provider' => 'Moteur d’IA de secours',
			'test_btn' => 'Tester la connexion de secours',
		),
		'prompts' => array(
			'title' => 'Invites (Prompts)',
			'system' => 'Invite système (lecture seule)',
			'user' => 'Invite utilisateur',
			'user_help' => 'Variables disponibles : <code>{title}</code>, <code>{content}</code>, <code>{author}</code>, <code>{url}</code>, <code>{feed_url}</code>, <code>{feed_name}</code>, <code>{date}</code>, <code>{tags}</code>',
			'max_content_length' => 'Longueur max. du contenu (caractères)',
			'max_content_length_help' => 'Plafonnement des caractères pour {content} (0 = illimité)',
		),
		'tags' => array(
			'title' => 'Classification par tags',
			'enable' => 'Activer la classification par tags',
			'prefix' => 'Préfixe des tags',
			'prefix_help' => 'Préfixe ajouté à chaque tag issu du LLM (ex. « llm/ »)',
			'allowed' => 'Tags autorisés (un par ligne)',
			'allowed_help' => 'Si renseigné, seuls ces tags seront acceptés dans la réponse. Vide = tout autorisé.',
		),
		'filter' => array(
			'title' => 'Conditions pour l’étiquetage',
			'search' => 'Filtres de recherche',
			'search_help' => 'Classifier uniquement les articles correspondant à au moins un de ces filtres. Laisser vide pour classifier tous les articles.',
		),
		'default_prompt' => 'Classifie l’article suivant.

Titre : {title}
Auteur : {author}
Date : {date}
URL : {url}
Flux : {feed_name} ({feed_url})
Tags existants : {tags}

Contenu :
{content}',
	),
);
