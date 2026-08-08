CREATE DATABASE IF NOT EXISTS db_user CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_user;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id     BIGINT UNSIGNED NOT NULL UNIQUE,
    username        VARCHAR(255) DEFAULT NULL,
    country_name    VARCHAR(64) DEFAULT NULL,

    gold            BIGINT UNSIGNED NOT NULL DEFAULT 5000,
    oil             BIGINT UNSIGNED NOT NULL DEFAULT 1000,

    military        INT UNSIGNED NOT NULL DEFAULT 100,
    economy         INT UNSIGNED NOT NULL DEFAULT 100,
    tech            INT UNSIGNED NOT NULL DEFAULT 100,
    population      BIGINT UNSIGNED NOT NULL DEFAULT 100000,

    reputation      INT NOT NULL DEFAULT 0,
    has_nuke        TINYINT(1) NOT NULL DEFAULT 0,
    has_bunker      TINYINT(1) NOT NULL DEFAULT 0,

    channel_checked_at DATETIME DEFAULT NULL,
    channel_member      TINYINT(1) DEFAULT NULL,

    alliance_id     INT UNSIGNED DEFAULT NULL,
    referred_by     INT UNSIGNED DEFAULT NULL,

    state           VARCHAR(64) DEFAULT NULL,
    state_data      TEXT DEFAULT NULL,

    last_hourly_mission DATETIME DEFAULT NULL,
    last_free_money     DATETIME DEFAULT NULL,
    last_income_collect DATETIME DEFAULT NULL,
    last_oil_collect    DATETIME DEFAULT NULL,

    spy_level           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    counter_spy_level   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_banned           TINYINT(1) NOT NULL DEFAULT 0,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_military (military),
    INDEX idx_gold (gold),
    UNIQUE INDEX idx_country_name (country_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS companies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    type            VARCHAR(32) NOT NULL,   -- factory, farm, techlab, oil_rig, bank
    level           INT UNSIGNED NOT NULL DEFAULT 1,
    income_per_hour INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    resource        VARCHAR(16) NOT NULL,   -- gold, oil, military, tech
    amount          BIGINT UNSIGNED NOT NULL,
    price_gold      BIGINT UNSIGNED NOT NULL,
    order_type      ENUM('sell','buy') NOT NULL DEFAULT 'sell',
    status          ENUM('open','filled','cancelled') NOT NULL DEFAULT 'open',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS black_market_deals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(128) NOT NULL,
    resource        VARCHAR(16) NOT NULL,
    amount          BIGINT UNSIGNED NOT NULL,
    price_gold      BIGINT UNSIGNED NOT NULL,
    risk_percent    TINYINT UNSIGNED NOT NULL DEFAULT 20, -- chance of being caught & losing gold
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alliances (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64) NOT NULL UNIQUE,
    leader_id       INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (leader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE users
    ADD CONSTRAINT fk_users_alliance
    FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS battles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attacker_id     INT UNSIGNED NOT NULL,
    defender_id     INT UNSIGNED NOT NULL,
    battle_type     ENUM('military','nuclear') NOT NULL DEFAULT 'military',
    attacker_won    TINYINT(1) NOT NULL,
    gold_stolen     BIGINT NOT NULL DEFAULT 0,
    oil_stolen      BIGINT NOT NULL DEFAULT 0,
    attacker_losses INT NOT NULL DEFAULT 0,
    defender_losses INT NOT NULL DEFAULT 0,
    reported        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attacker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (defender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_attacker (attacker_id),
    INDEX idx_defender (defender_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS espionage_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spy_id          INT UNSIGNED NOT NULL,
    target_id       INT UNSIGNED NOT NULL,
    success         TINYINT(1) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (spy_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS opinions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    target_id       INT UNSIGNED NOT NULL,
    score           INT NOT NULL DEFAULT 0, -- -100..100
    UNIQUE KEY uniq_pair (user_id, target_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS diplomacy_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_id         INT UNSIGNED NOT NULL,
    to_id           INT UNSIGNED NOT NULL,
    request_type    ENUM('peace','trade','alliance') NOT NULL,
    message         VARCHAR(255) DEFAULT NULL,
    status          ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (from_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (to_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS private_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_id         INT UNSIGNED NOT NULL,
    to_id           INT UNSIGNED NOT NULL,
    message         VARCHAR(1000) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (from_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (to_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS national_projects (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    project_type    VARCHAR(32) NOT NULL, -- highway, university, defense_shield, nuclear_program
    level           INT UNSIGNED NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS global_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(128) NOT NULL,
    description     VARCHAR(500) NOT NULL,
    effect_type     VARCHAR(32) DEFAULT NULL,
    effect_value    DECIMAL(6,2) DEFAULT NULL,
    expires_at      DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS official_statements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    message         VARCHAR(500) NOT NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS weapons (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    weapon_key   VARCHAR(32) NOT NULL UNIQUE,
    name         VARCHAR(64) NOT NULL,
    category     ENUM('shop','blackmarket','nuke') NOT NULL DEFAULT 'shop',
    unit_type    ENUM('ground','air','naval','nuclear') NOT NULL DEFAULT 'ground',
    attack       INT UNSIGNED NOT NULL DEFAULT 0,
    defense      INT UNSIGNED NOT NULL DEFAULT 0,
    cost         BIGINT UNSIGNED NOT NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_category_active (category, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_weapons (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    weapon_key   VARCHAR(32) NOT NULL,
    quantity     INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_user_weapon (user_id, weapon_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS peace_treaties (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_a_id INT UNSIGNED NOT NULL,
    country_b_id INT UNSIGNED NOT NULL,
    status       ENUM('active','broken','expired') NOT NULL DEFAULT 'active',
    expires_at   DATETIME NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (country_a_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (country_b_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_pair_status (country_a_id, country_b_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alliance_join_requests (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alliance_id  INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    status       ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alliance_messages (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alliance_id  INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    message      VARCHAR(1000) NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_settings (
    setting_key   VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO admin_settings (setting_key, setting_value) VALUES
    ('daily_gift_gold', '0'),
    ('daily_gift_oil', '0'),
    ('channel_username', ''),
    ('channel_id', ''),
    ('economy_multiplier', '1.0'),
    ('economy_multiplier_expires', '');

INSERT IGNORE INTO weapons (weapon_key, name, category, unit_type, attack, defense, cost) VALUES
    ('soldier',    '🪖 سرباز',              'shop', 'ground', 10,  10,  300),
    ('rifleman',   '🔫 تفنگدار',            'shop', 'ground', 25,  15,  700),
    ('apc',        '🚙 نفربر زرهی',          'shop', 'ground', 40,  35,  1500),
    ('tank',       '🚛 تانک',                'shop', 'ground', 70,  90,  4000),
    ('dushka_technical', '🚙 ماشین دوشکا',    'shop', 'ground', 35,  20,  1200),
    ('heli',       '🚁 بالگرد جنگی',         'shop', 'air',    90,  60,  5000),
    ('kamikaze_drone', '🛸 پهباد انتحاری',   'shop', 'air',    55,  5,   2200),
    ('air_def',    '🛡️ پدافند هوایی',        'shop', 'ground', 20,  300, 6500),
    ('jet',        '🛩️ جنگنده',              'shop', 'air',    130, 80,  9000),
    ('missile',    '🚀 موشک بالستیک',        'shop', 'ground', 150, 20,  12000),
    ('attack_boat','🚤 قایق تندرو جنگی',     'shop', 'naval',  55,  40,  3200),
    ('warship',       '🚢 ناو جنگی',              'blackmarket', 'naval', 250, 200, 30000),
    ('laser_def',     '🛰️ سامانه دفاع لیزری',     'blackmarket', 'ground', 50,  500, 35000),
    ('tactical_nuke', '☢️ کلاهک اتمی تاکتیکی',    'blackmarket', 'ground', 400, 0,   50000),
    ('stealth_bomber','🕶️ بمب‌افکن رادارگریز',    'blackmarket', 'air',    320, 150, 45000),
    ('nuke_bomb',     '☢️ بمب اتمی استراتژیک',    'nuke',        'nuclear', 0,   0,   150000);
