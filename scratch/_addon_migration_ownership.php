<?php

declare(strict_types=1);

/**
 * Phase 2 — migration file → peer addon owner.
 * Used by AddonMigrationRegistrar + relocate tooling.
 * Connection remains omi_seo_ai (or mysql for credential tables) unless noted.
 */
return [

    // Optional only — ignored when empty or when directory has no *.php files.
    // Peer discovery uses owners[*].path; SeoContentAi is NOT required.
    'default_legacy_path' => '',

    'owners' => [
        'core' => [
            'connection' => 'mysql',
            'path' => 'database/migrations',
        ],
        'search-foundation' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/search-foundation/database/migrations',
        ],
        'seo' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/seo/database/migrations',
        ],
        'search-intelligence' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/search-intelligence/database/migrations',
        ],
        'ai-prompt' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/ai-prompt/database/migrations',
        ],
        'content' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/content/database/migrations',
        ],
        'content-projects' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/content-projects/database/migrations',
        ],
        'publishing' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/publishing/database/migrations',
        ],
        'media' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/media/database/migrations',
        ],
        'wordpress' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/wordpress/database/migrations',
        ],
        'site-sync' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/site-sync/database/migrations',
        ],
        'agent' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/agent/database/migrations',
        ],
        'agent-runtime' => [
            // Core mysql plane - Agent Runtime tables (agent_apps/threads/runs/messages).
            'connection' => 'mysql',
            'path' => 'addons/agent-runtime/database/migrations',
        ],
        'commerce' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/commerce/database/migrations',
        ],
        'social' => [
            'connection' => 'omi_seo_ai',
            'path' => 'addons/social/database/migrations',
        ],
        'seeding' => [
            // Infrastructure plane only — no business schema yet (localStorage SoT).
            'connection' => 'omi_seeding',
            'path' => 'addons/seeding/database/migrations',
        ],
        'social-automation' => [
            // Dedicated plane — business schema (sa_*) starts Phase B Package Registry.
            'connection' => 'omi_social_automation',
            'path' => 'addons/social-automation/database/migrations',
        ],
        // _legacy-obsolete / seeding legacy-experimental intentionally omitted from active discovery.
    ],

    /*
    | Filename substring rules — first match wins (order matters).
    */
    'classify_rules' => [
        ['owner' => 'legacy-obsolete', 'any' => [
            'create_entities_table',
            'create_entity_results',
            'create_article_keyword',
            'create_business_hook',
            'automation_v2',
            'automation_v3',
            'automation_manual',
            'automation_classification',
            'drop_seo_links_table',
            'drop_prompt_parts',
            'drop_seo_tasks',
            'drop_seo_prompt_resultables',
            'drop_keyword_site_meta',
            'drop_keyword_tag_table',
            'create_seo_generated_images', // merged into seo_media
        ]],
        ['owner' => 'publishing', 'any' => [
            'publishing_queue',
            'publishing_lease',
            'publish_attempts',
        ]],
        ['owner' => 'agent-runtime', 'any' => [
            'agent_apps',
            'agent_threads',
            'agent_runs',
            'agent_messages',
            'seed_seo_ops_agent_app',
        ]],
        ['owner' => 'agent', 'any' => [
            'seo_agent',
            'agent_workspace',
            'content_project_agent',
        ]],
        ['owner' => 'site-sync', 'any' => [
            'site_sync',
            'site_capabilities',
        ]],
        ['owner' => 'wordpress', 'any' => [
            'wordpress_side_effect',
            'wp_sync_jobs',
            'remote_snapshots',
            'article_wp_sync',
        ]],
        ['owner' => 'commerce', 'any' => [
            'product_gallery',
            'product_review',
            'article_product_review',
        ]],
        ['owner' => 'social', 'any' => [
            'seo_social_profile',
            'social_profile',
        ]],
        ['owner' => 'media', 'any' => [
            'seo_media',
            'watermark',
            'image_optimization',
            'seo_wp_media',
            'seo_generated_image',
            'media_processing',
        ]],
        ['owner' => 'search-intelligence', 'any' => [
            'keyword_rank',
            'keyword_group',
            'keyword_intelligence',
            'seo_keyword',
            'seo_rank',
            'seo_gsc',
            'seo_serp',
            'gsc_',
            'serp_',
            'keyword_review',
        ]],
        ['owner' => 'search-foundation', 'any' => [
            'create_keywords_table',
            'keyword_meta',
            'keyword_tag',
            'keyword_site',
            'tags_and_keyword',
            'normalize_keywords',
            'seo_link_map',
            'seo_link_audit',
            'seo_pending_internal',
            'seo_site_link',
            'seo_site_manual_link',
            'seo_site_link_exclusion',
        ]],
        ['owner' => 'seo', 'any' => [
            'seo_extension',
            'seo_article_score',
            'analyze_article_seo',
            'audit_link',
        ]],
        ['owner' => 'ai-prompt', 'any' => [
            'prompt',
            'hook_binding',
            'comment_prompt',
            'ai_provider_template',
            'ai_routing',
            'ai_model_capabilit',
        ]],
        ['owner' => 'content-projects', 'any' => [
            'seo_project',
            'content_project',
            'content_archive',
            'seo_content_archive',
            'generation_blocked',
            'generation_read_states',
        ]],
        ['owner' => 'content', 'any' => [
            'articles_table',
            'article_meta',
            'article_editor',
            'editor_document',
            'document_version',
            'seo_article_revision',
            'seo_article_heading',
            'seo_faq',
            'seo_article_link',
            'seo_article_review',
            'seo_article_ai',
            'review_fields_to_articles',
            'translation_group',
            'add_status_to_articles',
            'add_slug_to_articles',
            'add_focus_keyword',
        ]],
        // Core-mysql credential alters that currently live under SeoContentAi
        ['owner' => 'core', 'any' => [
            'add_connection_type_to_api_connections',
            'ensure_connection_type_on_api_connections',
            'add_paid_locked_to_api_connections',
            'add_paid_lock_reasons_to_api_connections',
            'ai_runtime_health',
            'seo_gsc_master',
            'seo_gsc_property',
            'seo_dataforseo',
            'seo_serp_provider',
            'seo_extended_provider',
        ]],
    ],

    'fallback_owner' => 'legacy-obsolete',
];
