CREATE DATABASE IF NOT EXISTS chat_app
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE chat_app;

-- Users
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50) UNIQUE NOT NULL,
    email           VARCHAR(100) UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) NOT NULL,
    avatar_color    VARCHAR(7) DEFAULT '#6366f1',
    avatar_path     VARCHAR(255) DEFAULT NULL,
    bio             VARCHAR(160) DEFAULT NULL,
    status          ENUM('online','away','busy','offline') DEFAULT 'offline',
    status_message  VARCHAR(120) DEFAULT NULL,
    show_last_seen  TINYINT(1) DEFAULT 1,
    last_seen       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    theme           VARCHAR(30) DEFAULT 'default',
    font_family     VARCHAR(30) DEFAULT 'inter',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB;

-- Rooms
CREATE TABLE IF NOT EXISTS rooms (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    description   TEXT,
    image_path    VARCHAR(255) DEFAULT NULL,
    wallpaper     VARCHAR(50) DEFAULT 'default',
    theme         VARCHAR(30) DEFAULT 'default',
    font_family   VARCHAR(30) DEFAULT 'inter',
    created_by    INT NOT NULL,
    is_private    TINYINT(1) DEFAULT 0,
    disappearing_seconds INT DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_name (name)
) ENGINE=InnoDB;

-- Room members
CREATE TABLE IF NOT EXISTS room_members (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    room_id    INT NOT NULL,
    user_id    INT NOT NULL,
    role       ENUM('owner','admin','member') DEFAULT 'member',
    joined_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_membership (room_id, user_id)
) ENGINE=InnoDB;

-- Messages
CREATE TABLE IF NOT EXISTS messages (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    room_id        INT NOT NULL,
    user_id        INT NOT NULL,
    content        TEXT NOT NULL,
    message_type   ENUM('text','system','file','image','audio','video','call','sticker') DEFAULT 'text',
    attachment     VARCHAR(255) DEFAULT NULL,
    attachment_meta JSON DEFAULT NULL,
    is_pinned      TINYINT(1) DEFAULT 0,
    is_deleted     TINYINT(1) DEFAULT 0,
    expires_at     TIMESTAMP NULL DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_room_created (room_id, created_at),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    type       VARCHAR(50) NOT NULL,
    title      VARCHAR(180) NOT NULL,
    body       VARCHAR(255) DEFAULT NULL,
    link       VARCHAR(255) DEFAULT NULL,
    is_read    TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read)
) ENGINE=InnoDB;