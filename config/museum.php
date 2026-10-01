<?php

return [

    /*
    | Publication policy. Facts below `min_fact_status` are never shown publicly.
    | `source_verified` means the claim was checked to exist in the cited source.
    */
    'publication' => [
        'min_fact_status' => env('MUSEUM_MIN_FACT_STATUS', 'source_verified'),
        // An entity is auto-publishable only when it has at least this many public facts.
        'min_public_facts' => (int) env('MUSEUM_MIN_PUBLIC_FACTS', 1),
    ],

    'storage' => [
        'raw_disk' => env('MUSEUM_RAW_DISK', 'museum_raw'),
        'media_disk' => env('MUSEUM_MEDIA_DISK', 'museum_media'),
        'backup_disk' => env('MUSEUM_BACKUP_DISK', 'museum_backup'),
    ],

    'media' => [
        'cdn_url' => env('MUSEUM_CDN_URL'),
        'max_upload_mb' => (int) env('MUSEUM_MAX_UPLOAD_MB', 200),
        'ffmpeg' => env('MUSEUM_FFMPEG', 'ffmpeg'),
        'ffprobe' => env('MUSEUM_FFPROBE', 'ffprobe'),
        'thumbnail_width' => 640,
    ],

    'queues' => [
        'crawl' => env('MUSEUM_QUEUE_CRAWL', 'museum-crawl'),
        'extract' => env('MUSEUM_QUEUE_EXTRACT', 'museum-extract'),
        'ai' => env('MUSEUM_QUEUE_AI', 'museum-ai'),
        'index' => env('MUSEUM_QUEUE_INDEX', 'museum-index'),
        'media' => env('MUSEUM_QUEUE_MEDIA', 'museum-media'),
    ],

    'crawler' => [
        // Honest, identifiable user agent. Robots.txt rules for this agent (and "*") are obeyed.
        'user_agent' => env('MUSEUM_CRAWLER_UA', 'HormozganDigitalMuseumBot/1.0 (+'.env('APP_URL', 'http://localhost').'/museum/about/crawler)'),
        'robots_agent' => 'HormozganDigitalMuseumBot',
        'timeout' => 60,
        'max_bytes' => 300 * 1024 * 1024,
        'min_delay_ms' => 2000,
        'robots_cache_hours' => 24,
    ],

    'ocr' => [
        'driver' => env('MUSEUM_OCR_DRIVER', 'tesseract'),   // tesseract, none
        'tesseract_binary' => env('MUSEUM_TESSERACT', 'tesseract'),
        'languages' => env('MUSEUM_OCR_LANGS', 'fas+ara+eng'),
        'pdftoppm_binary' => env('MUSEUM_PDFTOPPM', 'pdftoppm'),
        'pdftotext_binary' => env('MUSEUM_PDFTOTEXT', 'pdftotext'),
        // Pages with fewer extracted characters than this are treated as scanned images and OCR'd.
        'min_text_chars_per_page' => 40,
    ],

    'extraction' => [
        'chunk_chars' => 3500,
        'chunk_overlap' => 300,
        'low_confidence_threshold' => 0.6,
        // Discovery: a candidate entity is promoted to the review queue after this many independent sources.
        'discovery_min_sources' => (int) env('MUSEUM_DISCOVERY_MIN_SOURCES', 2),
        'prompt_version' => 'x1',
    ],

    'resolution' => [
        'auto_match_threshold' => 0.92,
        'review_threshold' => 0.75,
        'geo_duplicate_km' => 2.0,
    ],

    'llm' => [
        'driver' => env('MUSEUM_LLM_DRIVER', 'anthropic'),   // anthropic, null (null = AI features disabled)
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com'),
        'model' => env('MUSEUM_LLM_MODEL', 'claude-opus-5-5'),
        'extraction_model' => env('MUSEUM_LLM_EXTRACTION_MODEL', 'claude-opus-5-5'),
        'effort' => env('MUSEUM_LLM_EFFORT', 'medium'),
        'max_tokens' => (int) env('MUSEUM_LLM_MAX_TOKENS', 4096),
        'timeout' => 120,
    ],

    // Speech-to-text for oral history: any OpenAI-compatible /audio/transcriptions server
    // (e.g. self-hosted Whisper) so recordings can stay on project infrastructure.
    'transcription' => [
        'url' => env('MUSEUM_TRANSCRIBE_URL'),
        'api_key' => env('MUSEUM_TRANSCRIBE_API_KEY'),
        'model' => env('MUSEUM_TRANSCRIBE_MODEL', 'whisper-1'),
        'language' => env('MUSEUM_TRANSCRIBE_LANGUAGE', 'fa'),
    ],

    'embeddings' => [
        // voyage (API), openai_compatible (any /v1/embeddings server), hashing (local, lexical, offline)
        'driver' => env('MUSEUM_EMBEDDINGS_DRIVER', 'hashing'),
        'model' => env('MUSEUM_EMBEDDINGS_MODEL', 'voyage-3'),
        'api_key' => env('MUSEUM_EMBEDDINGS_API_KEY'),
        'base_url' => env('MUSEUM_EMBEDDINGS_URL', 'https://api.voyageai.com/v1'),
        'dimensions' => (int) env('MUSEUM_EMBEDDINGS_DIMENSIONS', 512),
        'batch_size' => 64,
    ],

    'vector' => [
        'driver' => env('MUSEUM_VECTOR_DRIVER', 'database'), // database, qdrant
        'qdrant_url' => env('MUSEUM_QDRANT_URL', 'http://127.0.0.1:6333'),
        'qdrant_collection' => env('MUSEUM_QDRANT_COLLECTION', 'museum_chunks'),
        'qdrant_api_key' => env('MUSEUM_QDRANT_API_KEY'),
        'database_scan_limit' => 200000,
    ],

    'search' => [
        // database (portable LIKE), mysql_fulltext, opensearch
        'driver' => env('MUSEUM_SEARCH_DRIVER', 'database'),
        'opensearch_url' => env('MUSEUM_OPENSEARCH_URL', 'http://127.0.0.1:9200'),
        'opensearch_index' => env('MUSEUM_OPENSEARCH_INDEX', 'museum'),
        'opensearch_user' => env('MUSEUM_OPENSEARCH_USER'),
        'opensearch_password' => env('MUSEUM_OPENSEARCH_PASSWORD'),
    ],

    'rag' => [
        'keyword_k' => 20,
        'vector_k' => 20,
        'context_chunks' => 8,
        'max_context_chars' => 24000,
        // Only text from published records is used to answer public questions.
        'public_only' => true,
    ],

    'api' => [
        'per_page' => 24,
        'max_per_page' => 100,
        'cache_ttl' => (int) env('MUSEUM_API_CACHE_TTL', 600),
    ],

    'backup' => [
        'retention_days' => (int) env('MUSEUM_BACKUP_RETENTION_DAYS', 90),
        'mysqldump' => env('MUSEUM_MYSQLDUMP', 'mysqldump'),
    ],

    'wikidata' => [
        'endpoint' => 'https://query.wikidata.org/sparql',
        'api' => 'https://www.wikidata.org/w/api.php',
        'hormozgan_qid' => 'Q633659',
    ],
];
