-- MR-POS: the two databases and the two accounts (see docs/CLIENT_LAPTOP_SETUP.md).
-- Work on a COPY of this file. Replace CHANGE_APP_PASSWORD and CHANGE_BACKUP_PASSWORD (four places in all)
-- with two passwords of your own (letters and digits only), run it, then delete the copy.

CREATE DATABASE IF NOT EXISTS mrpos        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS mrpos_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- scratch area where "Test restore" proves a backup works

CREATE USER IF NOT EXISTS 'mrpos_app'@'localhost'    IDENTIFIED BY 'CHANGE_APP_PASSWORD';
CREATE USER IF NOT EXISTS 'mrpos_app'@'127.0.0.1'    IDENTIFIED BY 'CHANGE_APP_PASSWORD';
CREATE USER IF NOT EXISTS 'mrpos_backup'@'localhost' IDENTIFIED BY 'CHANGE_BACKUP_PASSWORD';
CREATE USER IF NOT EXISTS 'mrpos_backup'@'127.0.0.1' IDENTIFIED BY 'CHANGE_BACKUP_PASSWORD';

GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES,TRIGGER,CREATE TEMPORARY TABLES,LOCK TABLES
      ON mrpos.* TO 'mrpos_app'@'localhost','mrpos_app'@'127.0.0.1';
GRANT ALL PRIVILEGES ON mrpos_verify.* TO 'mrpos_app'@'localhost','mrpos_app'@'127.0.0.1';
-- read-only: used only to make the backup
GRANT SELECT,SHOW VIEW,TRIGGER,LOCK TABLES,EVENT
      ON mrpos.* TO 'mrpos_backup'@'localhost','mrpos_backup'@'127.0.0.1';

DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
