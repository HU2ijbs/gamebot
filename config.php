<?php

define('BOT_TOKEN', '8735468401:AAHCTktkWFyQsIojCDGTtWKRU7WFGMA2iLk');
define('TELEGRAM_API', 'https://api.telegram.org/bot' . 8735468401:AAHCTktkWFyQsIojCDGTtWKRU7WFGMA2iLk . '/');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'dbName');
define('DB_USER', 'User');
define('DB_PASS', 'Pass');
define('DB_CHARSET', 'utf8mb4');

define('ADMIN_IDS', [7727625618]);

define('@HusteRIX', '7727625618'); 

define('START_GOLD', 5000);
define('START_OIL', 1000);
define('START_MILITARY', 100);
define('START_ECONOMY', 100);
define('START_TECH', 100);
define('START_POPULATION', 100000);

define('FREE_MONEY_PER_PIP', 50); 
define('FREE_MONEY_COOLDOWN_MIN', 1440); // 24 saat

define('NUKE_TECH_REQUIREMENT', 500); 
define('NUKE_GOLD_COST', 20000); 
define('NUKE_BOMB_GOLD_COST', 150000);
define('NUKE_MIN_BOMBS_PER_ATTACK', 5);

define('BUNKER_GOLD_COST', 40000);
define('NO_BUNKER_DESTRUCTION_PERCENT', 90);
define('BUNKER_DESTRUCTION_PERCENT', 50);

define('ATTACK_PERCENT_MIN', 10);
define('ATTACK_PERCENT_MAX', 90);
define('ATTACK_PERCENT_STEP', 10);

define('NEW_PLAYER_PROTECTION_MIN', 30);

define('CHANNEL_CHECK_CACHE_MIN', 10);
define('CHANNEL_CHECK_NEGATIVE_CACHE_SEC', 20);
define('DB_PERSISTENT', true);

define('ALLIANCE_CREATE_COST', 20000); 

define('PEACE_TREATY_DAYS', 3);
define('PEACE_BREAK_GOLD_COST', 5000);
define('PEACE_BREAK_REPUTATION_PENALTY', 15);

define('SPY_EQUIPMENT_COSTS', [1 => 1000, 2 => 3000, 3 => 6000]);
define('COUNTER_SPY_EQUIPMENT_COSTS', [1 => 1200, 2 => 3500, 3 => 7000]);
define('SPY_EQUIPMENT_BONUS_PERCENT', 15); 
define('COUNTER_SPY_EQUIPMENT_BONUS_PERCENT', 15); 
define('SPY_BASE_SUCCESS_PERCENT', 55);
define('SPY_COST_GOLD', 500);

define('OIL_COLLECT_COOLDOWN_MIN', 120);
define('OIL_PER_RIG_LEVEL', 40);

// --- Timezone ---
date_default_timezone_set('Asia/Tehran');

error_reporting(E_ALL);
ini_set('display_errors', '1');
