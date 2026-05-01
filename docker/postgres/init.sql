-- ================================================================
--  ENGAGEMENT SYSTEM — PostgreSQL Initialization
-- ================================================================

-- Расширения
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";

-- Отдельная схема для аналитики (timeseries данные)
CREATE SCHEMA IF NOT EXISTS analytics;
