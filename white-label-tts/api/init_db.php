<?php
// Auto-create SQLite database on first run
if (!file_exists(DB_PATH)) {
    // Create data directory
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT DEFAULT '',
            quota_allocated INTEGER DEFAULT 0,
            quota_used INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            auth_token TEXT,
            plan_name TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS tts_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER,
            job_id TEXT,
            text_preview TEXT,
            characters_used INTEGER DEFAULT 0,
            voice_id TEXT,
            model_id TEXT,
            status TEXT DEFAULT 'pending',
            result_file TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id)
        );

        CREATE TABLE IF NOT EXISTS plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            quota INTEGER NOT NULL,
            price TEXT DEFAULT '',
            description TEXT DEFAULT '',
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS plan_activations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER,
            plan_id INTEGER,
            plan_name TEXT,
            quota_granted INTEGER,
            activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id),
            FOREIGN KEY (plan_id) REFERENCES plans(id)
        );
    ");
}

// Migration: Add tables for existing databases
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        quota INTEGER NOT NULL,
        price TEXT DEFAULT '',
        description TEXT DEFAULT '',
        is_active INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS plan_activations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER,
        plan_id INTEGER,
        plan_name TEXT,
        quota_granted INTEGER,
        activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id),
        FOREIGN KEY (plan_id) REFERENCES plans(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER,
        plan_id INTEGER,
        plan_name TEXT,
        plan_price TEXT,
        quota INTEGER,
        status TEXT DEFAULT 'pending',
        note TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id),
        FOREIGN KEY (plan_id) REFERENCES plans(id)
    )");

    // Add plan_name column to customers if missing
    $cols = $db->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('plan_name', $colNames)) {
        $db->exec("ALTER TABLE customers ADD COLUMN plan_name TEXT DEFAULT ''");
    }
} catch (Exception $e) {
    // Ignore migration errors (tables already exist)
}

// Migration: Add affiliate/referral columns
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cols = $db->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('referral_code', $colNames)) {
        $db->exec("ALTER TABLE customers ADD COLUMN referral_code TEXT DEFAULT ''");
    }
    if (!in_array('referred_by', $colNames)) {
        $db->exec("ALTER TABLE customers ADD COLUMN referred_by INTEGER DEFAULT NULL");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_bonus_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        referrer_id INTEGER NOT NULL,
        referred_id INTEGER NOT NULL,
        bonus_amount INTEGER NOT NULL DEFAULT 0,
        plan_quota INTEGER NOT NULL DEFAULT 0,
        plan_name TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // Ignore
}

