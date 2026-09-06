<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class Migration
{
    public static function run(PDO $db): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value LONGTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS admins (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                email VARCHAR(255) NULL,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip_hash CHAR(64) NOT NULL,
                username VARCHAR(100) NOT NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_rate (ip_hash,attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS sites (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(32) NOT NULL UNIQUE,
                site_key VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(2048) NOT NULL,
                rss_url VARCHAR(2048) NULL,
                search_console_property VARCHAR(2048) NULL,
                login_url VARCHAR(2048) NULL,
                github_url VARCHAR(2048) NULL,
                normalized_url VARCHAR(2048) NOT NULL,
                category VARCHAR(100) NULL,
                description TEXT NULL,
                admin_email VARCHAR(255) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                ranking_enabled TINYINT(1) NOT NULL DEFAULT 1,
                links_enabled TINYINT(1) NOT NULL DEFAULT 1,
                rss_enabled TINYINT(1) NOT NULL DEFAULT 1,
                rotation_enabled TINYINT(1) NOT NULL DEFAULT 0,
                is_priority TINYINT(1) NOT NULL DEFAULT 0,
                priority_multiplier DECIMAL(6,2) NOT NULL DEFAULT 1.50,
                is_special TINYINT(1) NOT NULL DEFAULT 0,
                special_points DECIMAL(10,2) NOT NULL DEFAULT 100.00,
                is_rescue TINYINT(1) NOT NULL DEFAULT 0,
                rescue_min_points DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                is_excluded TINYINT(1) NOT NULL DEFAULT 0,
                contact_ads_notice TINYINT(1) NOT NULL DEFAULT 0,
                contact_links_notice TINYINT(1) NOT NULL DEFAULT 0,
                contact_custom_enabled TINYINT(1) NOT NULL DEFAULT 0,
                contact_custom_text TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_sites_active (active),
                INDEX idx_sites_normalized (normalized_url(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS site_aliases (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                alias_url VARCHAR(2048) NOT NULL,
                normalized_url VARCHAR(2048) NOT NULL,
                match_type ENUM('host','prefix','contains','exact') NOT NULL DEFAULT 'host',
                allow_tracking_origin TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_alias_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_alias_site (site_id),
                INDEX idx_alias_normalized (normalized_url(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS excluded_referrers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(255) NOT NULL,
                pattern VARCHAR(500) NOT NULL,
                match_type ENUM('host','contains','exact') NOT NULL DEFAULT 'host',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_excluded_pattern (pattern, match_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS raw_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id CHAR(36) NULL,
                pageview_id CHAR(36) NULL,
                site_id BIGINT UNSIGNED NOT NULL,
                event_type ENUM('pageview','internal_click','outbound','widget_click') NOT NULL DEFAULT 'pageview',
                visitor_hash CHAR(64) NOT NULL,
                session_hash CHAR(64) NULL,
                page_url VARCHAR(2048) NOT NULL,
                normalized_page_url VARCHAR(2048) NOT NULL,
                referrer_url VARCHAR(2048) NULL,
                referrer_host VARCHAR(255) NULL,
                channel VARCHAR(30) NULL,
                target_url VARCHAR(2048) NULL,
                widget_id BIGINT UNSIGNED NULL,
                page_title VARCHAR(500) NULL,
                user_agent VARCHAR(500) NULL,
                device VARCHAR(30) NULL,
                browser VARCHAR(50) NULL,
                os VARCHAR(50) NULL,
                is_bot TINYINT(1) NOT NULL DEFAULT 0,
                is_suspicious TINYINT(1) NOT NULL DEFAULT 0,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_event_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                UNIQUE KEY uq_event_dedup (site_id,event_id),
                INDEX idx_event_site_time (site_id, occurred_at),
                INDEX idx_event_pageview (site_id,pageview_id),
                INDEX idx_event_suspicious (site_id,is_suspicious,occurred_at),
                INDEX idx_event_visitor (site_id, visitor_hash, occurred_at),
                INDEX idx_event_referrer (site_id, referrer_host, occurred_at),
                INDEX idx_event_type (site_id, event_type, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS daily_stats (
                site_id BIGINT UNSIGNED NOT NULL,
                stat_date DATE NOT NULL,
                pv BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uu BIGINT UNSIGNED NOT NULL DEFAULT 0,
                outbound BIGINT UNSIGNED NOT NULL DEFAULT 0,
                internal_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                widget_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id, stat_date),
                CONSTRAINT fk_daily_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS daily_visitors (
                site_id BIGINT UNSIGNED NOT NULL,
                visit_date DATE NOT NULL,
                visitor_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (site_id,visit_date,visitor_hash),
                CONSTRAINT fk_visitor_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_visitor_cleanup (visit_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS daily_referrer_visitors (
                site_id BIGINT UNSIGNED NOT NULL,
                visit_date DATE NOT NULL,
                referrer_host VARCHAR(255) NOT NULL,
                visitor_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (site_id,visit_date,referrer_host,visitor_hash),
                CONSTRAINT fk_ref_visitor_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_ref_visitor_cleanup (visit_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS daily_link_stats (
                site_id BIGINT UNSIGNED NOT NULL,
                stat_date DATE NOT NULL,
                target_hash CHAR(64) NOT NULL,
                target_url VARCHAR(2048) NOT NULL,
                target_host VARCHAR(255) NOT NULL,
                internal_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                outbound_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                widget_clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id,stat_date,target_hash),
                CONSTRAINT fk_link_stats_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_link_stats_host (site_id,stat_date,target_host)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS referrer_stats (
                site_id BIGINT UNSIGNED NOT NULL,
                stat_date DATE NOT NULL,
                referrer_host VARCHAR(255) NOT NULL,
                inbound BIGINT UNSIGNED NOT NULL DEFAULT 0,
                unique_inbound BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id, stat_date, referrer_host),
                CONSTRAINT fk_referrer_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_referrer_rank (site_id, stat_date, inbound)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS widgets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                public_id VARCHAR(32) NOT NULL UNIQUE,
                type ENUM('ranking','links','rss','notices') NOT NULL,
                slot_code VARCHAR(10) NOT NULL,
                name VARCHAR(255) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                item_limit INT UNSIGNED NOT NULL DEFAULT 10,
                width VARCHAR(30) NOT NULL DEFAULT '100%',
                height VARCHAR(30) NOT NULL DEFAULT 'auto',
                template_html LONGTEXT NULL,
                custom_css LONGTEXT NULL,
                config_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_widget_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                UNIQUE KEY uq_widget_slot (site_id, type, slot_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS reciprocal_links (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                partner_name VARCHAR(255) NOT NULL,
                partner_url VARCHAR(2048) NOT NULL,
                normalized_url VARCHAR(2048) NOT NULL,
                description TEXT NULL,
                category VARCHAR(100) NULL,
                slots VARCHAR(20) NOT NULL DEFAULT 'A',
                status ENUM('pending','approved','paused','rejected','removed') NOT NULL DEFAULT 'pending',
                rel_type ENUM('follow','nofollow','sponsored','ugc') NOT NULL DEFAULT 'follow',
                open_new_tab TINYINT(1) NOT NULL DEFAULT 1,
                is_priority TINYINT(1) NOT NULL DEFAULT 0,
                is_special TINYINT(1) NOT NULL DEFAULT 0,
                is_rescue TINYINT(1) NOT NULL DEFAULT 0,
                is_excluded TINYINT(1) NOT NULL DEFAULT 0,
                reciprocal_link_enabled TINYINT(1) NOT NULL DEFAULT 1,
                reciprocal_rss_enabled TINYINT(1) NOT NULL DEFAULT 0,
                rss_url VARCHAR(2048) NULL,
                allocation_type ENUM('normal','priority_120','priority_150','priority_200','special','rescue','excluded') NOT NULL DEFAULT 'normal',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_link_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_link_site_status (site_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rss_feeds (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                reciprocal_link_id BIGINT UNSIGNED NULL,
                name VARCHAR(255) NOT NULL,
                feed_url VARCHAR(2048) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                last_fetched_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                etag VARCHAR(255) NULL,
                last_modified VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_feed_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_feed_reciprocal_link FOREIGN KEY (reciprocal_link_id) REFERENCES reciprocal_links(id) ON DELETE CASCADE,
                UNIQUE KEY uq_feed_reciprocal_link (reciprocal_link_id),
                INDEX idx_feed_active (active, last_fetched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rss_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                feed_id BIGINT UNSIGNED NOT NULL,
                site_id BIGINT UNSIGNED NOT NULL,
                guid_hash CHAR(64) NOT NULL,
                title VARCHAR(1000) NOT NULL,
                url VARCHAR(2048) NOT NULL,
                normalized_url VARCHAR(2048) NOT NULL,
                description TEXT NULL,
                category VARCHAR(255) NULL,
                image_url VARCHAR(2048) NULL,
                published_at DATETIME NULL,
                fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_item_feed FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE CASCADE,
                CONSTRAINT fk_item_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                UNIQUE KEY uq_feed_guid (feed_id, guid_hash),
                INDEX idx_item_site_date (site_id, published_at),
                INDEX idx_item_fetched (fetched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS article_archive (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                feed_id BIGINT UNSIGNED NULL,
                url_hash CHAR(64) NOT NULL,
                title VARCHAR(1000) NOT NULL,
                url VARCHAR(2048) NOT NULL,
                description TEXT NULL,
                category VARCHAR(255) NULL,
                image_url VARCHAR(2048) NULL,
                original_published_at DATETIME NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_rotated_at DATETIME NULL,
                rotation_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT fk_archive_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_archive_feed FOREIGN KEY (feed_id) REFERENCES rss_feeds(id) ON DELETE SET NULL,
                UNIQUE KEY uq_archive_url (site_id, url_hash),
                INDEX idx_archive_rotation (site_id, active, last_rotated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rotation_feeds (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                slug VARCHAR(100) NOT NULL DEFAULT 'random-post',
                category VARCHAR(255) NOT NULL DEFAULT '',
                interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
                image_required TINYINT(1) NOT NULL DEFAULT 0,
                feed_ids_json LONGTEXT NULL,
                current_article_id BIGINT UNSIGNED NULL,
                current_since DATETIME NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_rotation_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_rotation_article FOREIGN KEY (current_article_id) REFERENCES article_archive(id) ON DELETE SET NULL,
                UNIQUE KEY uq_rotation_feed (site_id, slug, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rss_distribution_batches (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id CHAR(16) NOT NULL UNIQUE,
                target_site_id BIGINT UNSIGNED NOT NULL,
                calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_dist_batch_target FOREIGN KEY (target_site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_dist_batch_latest (target_site_id,calculated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rss_distribution_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                target_site_id BIGINT UNSIGNED NOT NULL,
                source_site_id BIGINT UNSIGNED NOT NULL,
                batch_id CHAR(16) NOT NULL,
                inbound BIGINT UNSIGNED NOT NULL DEFAULT 0,
                base_weight DECIMAL(14,4) NOT NULL DEFAULT 0,
                final_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
                reason VARCHAR(255) NULL,
                calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_dist_target FOREIGN KEY (target_site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_dist_source FOREIGN KEY (source_site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_dist_target_time (target_site_id, calculated_at),
                INDEX idx_dist_batch (target_site_id,batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS reciprocal_rss_distribution_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                target_site_id BIGINT UNSIGNED NOT NULL,
                reciprocal_link_id BIGINT UNSIGNED NOT NULL,
                batch_id CHAR(16) NOT NULL,
                inbound BIGINT UNSIGNED NOT NULL DEFAULT 0,
                multiplier DECIMAL(6,2) NOT NULL DEFAULT 1.00,
                base_weight DECIMAL(14,4) NOT NULL DEFAULT 0,
                final_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
                allocation_type VARCHAR(30) NOT NULL DEFAULT 'normal',
                calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_reciprocal_dist_target FOREIGN KEY (target_site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_reciprocal_dist_link FOREIGN KEY (reciprocal_link_id) REFERENCES reciprocal_links(id) ON DELETE CASCADE,
                INDEX idx_reciprocal_dist_batch (target_site_id,batch_id),
                INDEX idx_reciprocal_dist_time (target_site_id,calculated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS link_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                receipt_no VARCHAR(32) NOT NULL UNIQUE,
                status_token_hash CHAR(64) NOT NULL,
                form_nonce_hash CHAR(64) NOT NULL UNIQUE,
                status ENUM('new','reviewing','approved','rejected','registered','removed') NOT NULL DEFAULT 'new',
                site_name VARCHAR(255) NOT NULL,
                site_url VARCHAR(2048) NOT NULL,
                manager_name VARCHAR(255) NULL,
                email VARCHAR(255) NOT NULL,
                category VARCHAR(100) NULL,
                backlink_url VARCHAR(2048) NULL,
                requested_slots VARCHAR(20) NULL,
                message TEXT NULL,
                public_message TEXT NULL,
                removal_reason TEXT NULL,
                notify_status_page TINYINT(1) NOT NULL DEFAULT 1,
                notify_email TINYINT(1) NOT NULL DEFAULT 0,
                publish_notice TINYINT(1) NOT NULL DEFAULT 0,
                ip_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_request_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_request_site_status (site_id, status),
                INDEX idx_request_ip_time (ip_hash, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notices (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                notice_type ENUM('normal','important','maintenance','registered','removed') NOT NULL DEFAULT 'normal',
                is_public TINYINT(1) NOT NULL DEFAULT 1,
                is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_notice_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_notice_public (is_public, starts_at, ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS contact_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                receipt_no VARCHAR(32) NOT NULL UNIQUE,
                form_nonce_hash CHAR(64) NOT NULL UNIQUE,
                status ENUM('unread','reviewing','resolved') NOT NULL DEFAULT 'unread',
                sender_name VARCHAR(255) NOT NULL,
                sender_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(500) NULL,
                read_at DATETIME NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_contact_message_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_contact_message_site_status (site_id,status,created_at),
                INDEX idx_contact_message_ip_time (site_id,ip_hash,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS exports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                export_year SMALLINT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_export_year_file (export_year, file_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS cron_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_name VARCHAR(100) NOT NULL,
                status ENUM('running','success','failed') NOT NULL,
                message TEXT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL,
                INDEX idx_cron_task_time (task_name, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS analytics_sessions (
                site_id BIGINT UNSIGNED NOT NULL,
                session_hash CHAR(64) NOT NULL,
                visitor_hash CHAR(64) NOT NULL,
                started_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                pageviews INT UNSIGNED NOT NULL DEFAULT 0,
                engagement_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
                conversion_count INT UNSIGNED NOT NULL DEFAULT 0,
                channel VARCHAR(30) NOT NULL DEFAULT 'direct',
                referrer_host VARCHAR(255) NULL,
                landing_page VARCHAR(2048) NULL,
                exit_page VARCHAR(2048) NULL,
                device VARCHAR(30) NULL,
                browser VARCHAR(50) NULL,
                os VARCHAR(50) NULL,
                is_bot TINYINT(1) NOT NULL DEFAULT 0,
                is_suspicious TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id,session_hash),
                CONSTRAINT fk_analytics_session_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_analytics_session_time (site_id,started_at),
                INDEX idx_analytics_session_visitor (site_id,visitor_hash,started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS analytics_pageviews (
                site_id BIGINT UNSIGNED NOT NULL,
                pageview_id CHAR(36) NOT NULL,
                session_hash CHAR(64) NOT NULL,
                visitor_hash CHAR(64) NOT NULL,
                page_url VARCHAR(2048) NOT NULL,
                normalized_page_url VARCHAR(2048) NOT NULL,
                page_title VARCHAR(500) NULL,
                started_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                engagement_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
                scroll_depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
                is_bot TINYINT(1) NOT NULL DEFAULT 0,
                is_suspicious TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (site_id,pageview_id),
                CONSTRAINT fk_analytics_pageview_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_analytics_pageview_time (site_id,started_at),
                INDEX idx_analytics_pageview_session (site_id,session_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS conversion_rules (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                url_pattern VARCHAR(2048) NOT NULL,
                match_type ENUM('exact','prefix','contains') NOT NULL DEFAULT 'contains',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_conversion_rule_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_conversion_rule_site (site_id,active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS conversion_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                rule_id BIGINT UNSIGNED NOT NULL,
                pageview_id CHAR(36) NOT NULL,
                session_hash CHAR(64) NOT NULL,
                visitor_hash CHAR(64) NOT NULL,
                page_url VARCHAR(2048) NOT NULL,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_conversion_event_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_conversion_event_rule FOREIGN KEY (rule_id) REFERENCES conversion_rules(id) ON DELETE CASCADE,
                UNIQUE KEY uq_conversion_pageview (site_id,rule_id,pageview_id),
                INDEX idx_conversion_event_time (site_id,occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS tracking_security_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NULL,
                fingerprint CHAR(64) NOT NULL,
                event_code VARCHAR(50) NOT NULL,
                severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
                request_hash CHAR(64) NOT NULL,
                origin_host VARCHAR(255) NULL,
                user_agent VARCHAR(500) NULL,
                details VARCHAR(1000) NULL,
                hit_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                CONSTRAINT fk_tracking_security_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                UNIQUE KEY uq_tracking_security_fingerprint (fingerprint),
                INDEX idx_tracking_security_time (site_id,last_seen_at),
                INDEX idx_tracking_security_severity (site_id,severity,resolved_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS tracking_rate_limits (
                site_id BIGINT UNSIGNED NOT NULL,
                request_hash CHAR(64) NOT NULL,
                window_start DATETIME NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (site_id,request_hash,window_start),
                CONSTRAINT fk_tracking_rate_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_tracking_rate_cleanup (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($queries as $query) {
            $db->exec($query);
        }

        $defaults = [
            'schema_version' => '6',
            'ranking_period_days' => '3',
            'distribution_window_hours' => '24',
            'raw_retention_days' => '180',
            'distribution_retention_days' => '180',
            'aggregate_retention_days' => '1095',
            'rss_item_retention_days' => '3',
        ];
        $stmt = $db->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($defaults as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        $exclude=$db->prepare('INSERT IGNORE INTO excluded_referrers (label,pattern,match_type) VALUES (?,?,\'contains\')');
        foreach(['Google'=>'google.','Yahoo!'=>'yahoo.','Bing'=>'bing.com','DuckDuckGo'=>'duckduckgo.com','Baidu'=>'baidu.com','Yandex'=>'yandex.'] as $label=>$pattern)$exclude->execute([$label,$pattern]);
    }

    /**
     * Apply additive updates to an existing installation without replacing data.
     */
    public static function upgrade(PDO $db): void
    {
        $version = (int) ($db->query(
            "SELECT setting_value FROM settings WHERE setting_key='schema_version' LIMIT 1"
        )->fetchColumn() ?: 1);
        if ($version < 3) {
            self::run($db);
        }
        $exists = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        if ($version < 2) {
            $columns = [
                'login_url' => 'ALTER TABLE sites ADD COLUMN login_url VARCHAR(2048) NULL AFTER url',
                'github_url' => 'ALTER TABLE sites ADD COLUMN github_url VARCHAR(2048) NULL AFTER login_url',
            ];
            foreach ($columns as $column => $sql) {
                $exists->execute(['sites', $column]);
                if ((int) $exists->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            }
        }

        if ($version < 3) {
            $siteColumns = [
                'rss_url' => 'ALTER TABLE sites ADD COLUMN rss_url VARCHAR(2048) NULL AFTER url',
                'search_console_property' => 'ALTER TABLE sites ADD COLUMN search_console_property VARCHAR(2048) NULL AFTER rss_url',
            ];
            foreach ($siteColumns as $column => $sql) {
                $exists->execute(['sites', $column]);
                if ((int) $exists->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            }
            $columns = [
                'event_id' => 'ALTER TABLE raw_events ADD COLUMN event_id CHAR(36) NULL AFTER id',
                'pageview_id' => 'ALTER TABLE raw_events ADD COLUMN pageview_id CHAR(36) NULL AFTER event_id',
                'channel' => "ALTER TABLE raw_events ADD COLUMN channel VARCHAR(30) NULL AFTER referrer_host",
                'is_suspicious' => 'ALTER TABLE raw_events ADD COLUMN is_suspicious TINYINT(1) NOT NULL DEFAULT 0 AFTER is_bot',
            ];
            foreach ($columns as $column => $sql) {
                $exists->execute(['raw_events', $column]);
                if ((int) $exists->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            }
            $indexExists = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
            foreach ([
                'uq_event_dedup' => 'ALTER TABLE raw_events ADD UNIQUE KEY uq_event_dedup (site_id,event_id)',
                'idx_event_pageview' => 'ALTER TABLE raw_events ADD INDEX idx_event_pageview (site_id,pageview_id)',
                'idx_event_suspicious' => 'ALTER TABLE raw_events ADD INDEX idx_event_suspicious (site_id,is_suspicious,occurred_at)',
            ] as $index => $sql) {
                $indexExists->execute(['raw_events', $index]);
                if ((int) $indexExists->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            }
        }

        if ($version < 4) {
            $db->exec("CREATE TABLE IF NOT EXISTS contact_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT UNSIGNED NOT NULL,
                receipt_no VARCHAR(32) NOT NULL UNIQUE,
                form_nonce_hash CHAR(64) NOT NULL UNIQUE,
                status ENUM('unread','reviewing','resolved') NOT NULL DEFAULT 'unread',
                sender_name VARCHAR(255) NOT NULL,
                sender_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(500) NULL,
                read_at DATETIME NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_contact_message_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                INDEX idx_contact_message_site_status (site_id,status,created_at),
                INDEX idx_contact_message_ip_time (site_id,ip_hash,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if ($version < 5) {
            $linkColumns = [
                'reciprocal_link_enabled' => 'ALTER TABLE reciprocal_links ADD COLUMN reciprocal_link_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_excluded',
                'reciprocal_rss_enabled' => 'ALTER TABLE reciprocal_links ADD COLUMN reciprocal_rss_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER reciprocal_link_enabled',
                'rss_url' => 'ALTER TABLE reciprocal_links ADD COLUMN rss_url VARCHAR(2048) NULL AFTER reciprocal_rss_enabled',
                'allocation_type' => "ALTER TABLE reciprocal_links ADD COLUMN allocation_type ENUM('normal','priority_120','priority_150','priority_200','special','rescue','excluded') NOT NULL DEFAULT 'normal' AFTER rss_url",
            ];
            foreach ($linkColumns as $column => $sql) {
                $exists->execute(['reciprocal_links', $column]);
                if ((int) $exists->fetchColumn() === 0) {
                    $db->exec($sql);
                }
            }

            $exists->execute(['rss_feeds', 'reciprocal_link_id']);
            if ((int) $exists->fetchColumn() === 0) {
                $db->exec('ALTER TABLE rss_feeds ADD COLUMN reciprocal_link_id BIGINT UNSIGNED NULL AFTER site_id');
                $db->exec('ALTER TABLE rss_feeds ADD CONSTRAINT fk_feed_reciprocal_link FOREIGN KEY (reciprocal_link_id) REFERENCES reciprocal_links(id) ON DELETE CASCADE');
                $db->exec('ALTER TABLE rss_feeds ADD UNIQUE KEY uq_feed_reciprocal_link (reciprocal_link_id)');
            }

            $db->exec("CREATE TABLE IF NOT EXISTS reciprocal_rss_distribution_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                target_site_id BIGINT UNSIGNED NOT NULL,
                reciprocal_link_id BIGINT UNSIGNED NOT NULL,
                batch_id CHAR(16) NOT NULL,
                inbound BIGINT UNSIGNED NOT NULL DEFAULT 0,
                multiplier DECIMAL(6,2) NOT NULL DEFAULT 1.00,
                base_weight DECIMAL(14,4) NOT NULL DEFAULT 0,
                final_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
                allocation_type VARCHAR(30) NOT NULL DEFAULT 'normal',
                calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_reciprocal_dist_target FOREIGN KEY (target_site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_reciprocal_dist_link FOREIGN KEY (reciprocal_link_id) REFERENCES reciprocal_links(id) ON DELETE CASCADE,
                INDEX idx_reciprocal_dist_batch (target_site_id,batch_id),
                INDEX idx_reciprocal_dist_time (target_site_id,calculated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("UPDATE reciprocal_links SET allocation_type=CASE
                WHEN is_excluded=1 THEN 'excluded'
                WHEN is_rescue=1 THEN 'rescue'
                WHEN is_special=1 THEN 'special'
                WHEN is_priority=1 THEN 'priority_150'
                ELSE 'normal' END");

            $sites = $db->query('SELECT id FROM sites')->fetchAll(PDO::FETCH_COLUMN);
            $widget = $db->prepare('INSERT IGNORE INTO widgets (site_id,public_id,type,slot_code,name,item_limit,template_html,custom_css,config_json) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($sites as $siteId) {
                foreach (range('A', 'J') as $slot) {
                    $widget->execute([(int)$siteId, Security::randomToken(8), 'rss', 'IMAGE-'.$slot, '画像RSS '.$slot, 10, '<article><a href="{url}">{image_tag}<span>{title}</span></a></article>', '.asyura-rss article{display:flex;margin:0 0 10px}.asyura-rss img{width:96px;height:72px;object-fit:cover;margin-right:10px}', '{"image_required":true,"display_mode":"image"}']);
                    $widget->execute([(int)$siteId, Security::randomToken(8), 'rss', 'TEXT-'.$slot, 'テキストRSS '.$slot, 10, '<a href="{url}">{title}</a>', '.asyura-rss a{display:block;padding:6px 0;border-bottom:1px solid #eee}', '{"image_required":false,"display_mode":"text"}']);
                }
            }
        }

        if ($version < 6) {
            // Reclassify retained history with the same conservative rules used
            // for new events. Existing rows are kept; only their flags change.
            $botTokens = [
                'gptbot','oai-searchbot','oai-adsbot','chatgpt-user',
                'claudebot','claude-searchbot','claude-user','anthropic-ai',
                'perplexitybot','perplexity-user','googlebot','googleother',
                'google-inspectiontool','google-extended','storebot-google',
                'adsbot-google','apis-google','mediapartners-google','bingbot',
                'bingpreview','adidxbot','applebot','facebookexternalhit',
                'facebookbot','meta-externalagent','meta-externalfetcher',
                'bytespider','ccbot','cohere-ai','diffbot','ai2bot','youbot',
                'omgili','imagesiftbot','petalbot','amazonbot','ahrefsbot',
                'semrushbot','mj12bot','dotbot','blexbot','dataforseobot',
                'serpstatbot','headlesschrome','phantomjs','selenium',
                'playwright','puppeteer','scrapy','python-requests',
                'python-httpx','aiohttp','go-http-client','apache-httpclient',
                'okhttp','libwww-perl','crawler','spider','slurp','scraper',
            ];
            $conditions = [];
            foreach ($botTokens as $token) {
                $conditions[] = 'LOWER(COALESCE(user_agent,\'\')) LIKE ' . $db->quote('%'.$token.'%');
            }
            $db->exec('UPDATE raw_events SET is_bot=1,is_suspicious=1 WHERE '.implode(' OR ', $conditions));
            $db->exec("UPDATE raw_events SET is_suspicious=1 WHERE user_agent IS NULL OR CHAR_LENGTH(TRIM(user_agent))<12");

            $db->exec("UPDATE analytics_pageviews p JOIN raw_events r ON r.site_id=p.site_id AND r.pageview_id=p.pageview_id AND r.event_type='pageview' SET p.is_bot=GREATEST(p.is_bot,r.is_bot),p.is_suspicious=GREATEST(p.is_suspicious,r.is_suspicious)");
            $db->exec("INSERT IGNORE INTO analytics_sessions (site_id,session_hash,visitor_hash,started_at,last_seen_at,pageviews,channel,referrer_host,landing_page,exit_page,device,browser,os,is_bot,is_suspicious) SELECT grouped.site_id,grouped.session_hash,first_event.visitor_hash,grouped.started_at,grouped.last_seen_at,grouped.pageviews,COALESCE(NULLIF(first_event.channel,''),'direct'),first_event.referrer_host,first_event.normalized_page_url,last_event.normalized_page_url,first_event.device,first_event.browser,first_event.os,grouped.is_bot,grouped.is_suspicious FROM (SELECT site_id,session_hash,MIN(id) first_id,MAX(id) last_id,MIN(occurred_at) started_at,MAX(occurred_at) last_seen_at,COUNT(*) pageviews,MAX(is_bot) is_bot,MAX(is_suspicious) is_suspicious FROM raw_events WHERE event_type='pageview' AND session_hash IS NOT NULL GROUP BY site_id,session_hash) grouped JOIN raw_events first_event ON first_event.site_id=grouped.site_id AND first_event.id=grouped.first_id JOIN raw_events last_event ON last_event.site_id=grouped.site_id AND last_event.id=grouped.last_id");
            $db->exec("UPDATE analytics_sessions s JOIN (SELECT site_id,session_hash,MAX(is_bot) is_bot,MAX(is_suspicious) is_suspicious FROM raw_events WHERE event_type='pageview' GROUP BY site_id,session_hash) r ON r.site_id=s.site_id AND r.session_hash=s.session_hash SET s.is_bot=GREATEST(s.is_bot,r.is_bot),s.is_suspicious=GREATEST(s.is_suspicious,r.is_suspicious)");

            // Correct only dates still represented by raw_events. Older
            // aggregate-only history is intentionally left untouched.
            $db->exec('CREATE TEMPORARY TABLE asyura_raw_dates AS SELECT DISTINCT site_id,DATE(occurred_at) stat_date FROM raw_events');
            $db->exec("CREATE TEMPORARY TABLE asyura_human_daily AS SELECT site_id,DATE(occurred_at) stat_date,SUM(event_type='pageview') pv,COUNT(DISTINCT CASE WHEN event_type='pageview' THEN visitor_hash END) uu,SUM(event_type='outbound') outbound,SUM(event_type='internal_click') internal_clicks,SUM(event_type='widget_click') widget_clicks FROM raw_events WHERE is_bot=0 AND is_suspicious=0 GROUP BY site_id,DATE(occurred_at)");
            $db->exec('UPDATE daily_stats d JOIN asyura_raw_dates rd ON rd.site_id=d.site_id AND rd.stat_date=d.stat_date LEFT JOIN asyura_human_daily h ON h.site_id=d.site_id AND h.stat_date=d.stat_date SET d.pv=COALESCE(h.pv,0),d.uu=COALESCE(h.uu,0),d.outbound=COALESCE(h.outbound,0),d.internal_clicks=COALESCE(h.internal_clicks,0),d.widget_clicks=COALESCE(h.widget_clicks,0)');
            $db->exec('INSERT INTO daily_stats (site_id,stat_date,pv,uu,outbound,internal_clicks,widget_clicks) SELECT site_id,stat_date,pv,uu,outbound,internal_clicks,widget_clicks FROM asyura_human_daily ON DUPLICATE KEY UPDATE pv=VALUES(pv),uu=VALUES(uu),outbound=VALUES(outbound),internal_clicks=VALUES(internal_clicks),widget_clicks=VALUES(widget_clicks)');
            $db->exec('DROP TEMPORARY TABLE asyura_human_daily');
            $db->exec('DROP TEMPORARY TABLE asyura_raw_dates');
        }

        $stmt = $db->prepare(
            "INSERT INTO settings (setting_key,setting_value) VALUES ('schema_version','6')
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute();
    }
}
