<?php
/**
 * Product Manager Simulator settings.
 *
 * 1. Copy this file to "config.php" in the same folder.
 * 2. Paste your Anthropic API key between the quotes below.
 * 3. Never put this folder inside public_html, and never share the key.
 *
 * While the key is empty, the game runs with a built-in "mock" game master
 * so you can test everything without spending anything.
 */
return [
    'anthropic_api_key' => '',

    // The AI model that narrates the game
    'model' => 'claude-haiku-5-5',

    // Daily spending cap in US dollars. When reached, the game shows
    // "The office is closed for the night" until midnight UTC.
    'daily_budget_usd' => 2.00,

    // Set to true to force the mock game master even when a key is present
    'mock' => false,

    // Model that turns video transcripts into knowledge cards (admin page only).
    // Opus writes the best cards (about 5-10 cents per video).
    // Use 'claude-haiku-5-5' if card building ever times out on your hosting.
    'builder_model' => 'claude-opus-5-5',

    // Password for the private admin page at /api/pmsim-admin.php.
    // Use a long, unique password. Leave empty to keep the admin page switched off.
    'admin_password' => '',

    // Only these sites may use the game's API
    'allowed_origins' => ['https://benlindsay.net', 'https://www.benlindsay.net'],
];
