-- Extensions DevRadar depends on.
--
-- pg_trgm powers trigram similarity for the deduplication stage. It is the
-- reason PostgreSQL was chosen over MySQL: fuzzy matching stays in the
-- database instead of becoming a second service.
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Used for generated identifiers.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
