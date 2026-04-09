-- AI Player System Migration
-- Run this SQL to add AI player support to Antaris
-- NOTE: Table names use 'uni1_' prefix. Adjust prefix if your installation uses a different one.

-- 1. Extend uni1_users table with AI fields
ALTER TABLE `uni1_users` 
  ADD COLUMN `is_ai` TINYINT(1) NOT NULL DEFAULT 0 AFTER `authlevel`,
  ADD COLUMN `ai_difficulty` TINYINT NOT NULL DEFAULT 1 AFTER `is_ai`,
  ADD COLUMN `ai_personality` VARCHAR(50) NOT NULL DEFAULT 'balanced' AFTER `ai_difficulty`;

-- 2. Create AI configuration table
CREATE TABLE IF NOT EXISTS `uni1_ai_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `config_key` VARCHAR(100) NOT NULL,
  `config_value` TEXT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Insert default AI configuration
INSERT INTO `uni1_ai_config` (`config_key`, `config_value`) VALUES
  ('ai_enabled', '0'),
  ('ai_max_players', '10'),
  ('ai_tick_interval', '60'),
  ('ai_max_actions_per_tick', '3'),
  ('ai_allow_attacks', '1'),
  ('ai_attack_ai', '0'),
  ('ai_log_actions', '1'),
  ('ai_daemon_pid', ''),
  ('ai_daemon_last_tick', '0');

-- 3. Create AI action log table
CREATE TABLE IF NOT EXISTS `uni1_ai_action_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ai_user_id` INT(11) NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `action_data` TEXT,
  `executed_at` INT(11) NOT NULL,
  `result` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_user_id` (`ai_user_id`),
  KEY `executed_at` (`executed_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- 4. Create AI state table
CREATE TABLE IF NOT EXISTS `uni1_ai_state` (
  `ai_user_id` INT(11) NOT NULL,
  `current_strategy` VARCHAR(50) NOT NULL DEFAULT 'balanced',
  `state_data` TEXT,
  `last_tick` INT(11) NOT NULL DEFAULT 0,
  `next_action_time` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ai_user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- 5. Register AI Cronjob (fallback when daemon is not running)
INSERT INTO `uni1_cronjobs` (`name`, `isActive`, `min`, `hours`, `dom`, `month`, `dow`, `class`, `nextTime`, `lock`) VALUES
  ('AI Players', 0, '*/5', '*', '*', '*', '*', 'AICronjob', 0, NULL);
