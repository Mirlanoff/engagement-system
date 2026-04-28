-- ================================================================
--  ENGAGEMENT SYSTEM — PostgreSQL Initialization
-- ================================================================

-- Расширения
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";    -- полнотекстовый поиск
CREATE EXTENSION IF NOT EXISTS "btree_gin";  -- составные индексы

-- Отдельная схема для аналитики (timeseries данные)
CREATE SCHEMA IF NOT EXISTS analytics;

-- Пользователь только для чтения (для Grafana)
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'engage_readonly') THEN
    CREATE ROLE engage_readonly WITH LOGIN PASSWORD 'readonly_pass_change_me';
  END IF;
END$$;

GRANT CONNECT ON DATABASE engagement_db TO engage_readonly;
GRANT USAGE ON SCHEMA public TO engage_readonly;
GRANT USAGE ON SCHEMA analytics TO engage_readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO engage_readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA analytics TO engage_readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO engage_readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA analytics GRANT SELECT ON TABLES TO engage_readonly;
