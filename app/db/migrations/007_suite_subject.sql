-- 007_suite_subject.sql — map suite users by their immutable 8 West ID
-- subject rather than by email. Fresh installs get this from db/schema.sql.
--
-- Why: CLAIMS_CONTRACT_V1 rev 2 requires the suite to identify a person by
-- `sub`, which never changes, instead of by email, which does. Keying on
-- email hands anyone who changes their address a brand-new empty account
-- while orphaning the real one — and it silently splits a single human into
-- two records across the four suite apps.
--
-- Nullable on purpose: accounts that predate suite entry keep NULL until
-- their owner next signs in through 8 West ID, at which point the subject is
-- claimed once by email and backfilled.

SET NAMES utf8mb4;

ALTER TABLE users
  ADD COLUMN suite_subject VARCHAR(64) NULL DEFAULT NULL AFTER email;

-- Unique across the install, not per tenant: one 8 West ID subject is one
-- person, and the same subject must never appear twice.
ALTER TABLE users
  ADD UNIQUE KEY uq_users_suite_subject (suite_subject);
