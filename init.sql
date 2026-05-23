-- FTM IT Property Management Database Initialization
-- This file will be executed when the PostgreSQL container starts for the first time

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create activity_log table
CREATE TABLE IF NOT EXISTS activity_log (
    id SERIAL PRIMARY KEY,
    actor_user_id INTEGER,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INTEGER NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create categories table
CREATE TABLE IF NOT EXISTS categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
);

-- Create departments table
CREATE TABLE IF NOT EXISTS departments (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    code VARCHAR(20)
);

-- Create password_reset_tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    token VARCHAR(128) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create items table with all your actual columns
CREATE TABLE IF NOT EXISTS items (
    id SERIAL PRIMARY KEY,
    item_name VARCHAR(190) NOT NULL,
    serial_number VARCHAR(190),
    category VARCHAR(100),
    description VARCHAR(255),
    status VARCHAR(50) DEFAULT 'available',
    returned BOOLEAN DEFAULT FALSE,
    taken_by VARCHAR(190),
    ftm_pin VARCHAR(100),
    taken_by_user_id INTEGER,
    department VARCHAR(100),
    date_taken TIMESTAMP,
    expected_return_date TIMESTAMP,
    date_returned TIMESTAMP,
    condition_returned VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP,
    updated_by INTEGER
);
-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_items_status ON items(status);
CREATE INDEX IF NOT EXISTS idx_items_returned ON items(returned);
CREATE INDEX IF NOT EXISTS idx_items_taken_by_user_id ON items(taken_by_user_id);
CREATE INDEX IF NOT EXISTS idx_items_date_taken ON items(date_taken);
CREATE INDEX IF NOT EXISTS idx_items_expected_return_date ON items(expected_return_date);
CREATE INDEX IF NOT EXISTS idx_items_description ON items(description);
CREATE INDEX IF NOT EXISTS idx_items_item_name ON items(item_name);
CREATE INDEX IF NOT EXISTS idx_items_serial_number ON items(serial_number);
CREATE INDEX IF NOT EXISTS idx_items_category ON items(category);
CREATE INDEX IF NOT EXISTS idx_items_department ON items(department);
CREATE INDEX IF NOT EXISTS idx_items_date_returned ON items(date_returned);
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_activity_actor ON activity_log(actor_user_id);
CREATE INDEX IF NOT EXISTS idx_activity_entity ON activity_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_token ON password_reset_tokens(token);
CREATE INDEX IF NOT EXISTS idx_user ON password_reset_tokens(user_id);

-- Default users; admin password is set from ADMIN_PASSWORD in .env on app startup
INSERT INTO users (id, username, name, email, password_hash, role, created_at) VALUES
(1, 'admin', 'ADMIN', 'admin@it.com', '$2y$10$i1rm9GNXiMT8oMEK679A.uyFFqGNV3I5chlKBtwYorvXzC1eAOJH.', 'admin', '2025-09-25 13:10:47'),
(2, 'admin1', 'admin', 'admin1@it.com', '$2y$10$CjF0yk6XkcGCqnYq1uWyf.bHa/MA1LeIqKbLRUcVwhNCjWvB8IrA6', 'admin', '2025-09-25 14:03:49'),
(3, 'thabo', 'Thabo', 'thabouries@gmail.com', '$2y$10$weoYafDo8F0lAQGyLGlEceKjMt5iGck8l0FT32xiFjE3b66a7cF1S', 'user', '2025-09-30 11:15:18'),
(4, 'kona', 'Kona', 'nkosikhonad44@ftmswaziland.co', '$2y$10$W717cybKi9Rbt2h99iCjYefIlDG8lRVTwc9suBu6tQqUxJr1Qeop2', 'user', '2025-09-30 11:37:00'),
(5, 'nhlakanipho', 'NHLAKANIPHO MAMBA', 'nhlakaniphomambalendze@gmail.com', '$2y$10$fDgkU13xM/fGlucB2AQvQeEmFdopAK/dpb72d3f4a3hW0HPKZXCfG', 'user', '2025-09-30 13:03:06'),
(6, 'mbongeni', 'Mbongeni', '11430mbongisenin@gmail.com', '$2y$10$2JqGw/1BUrJDHTrsDz0gnOuysgFjb4U5aSy34t/YlSJuIFEKX8rr2', 'user', '2025-09-30 13:08:09');

SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users));