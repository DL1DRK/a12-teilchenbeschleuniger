from pathlib import Path
import re
import sqlite3

root = Path(__file__).resolve().parent.parent
template = (root / "tools" / "updater.template.php").read_text(encoding="utf-8")
blocks = re.findall(r"\$database->exec\(<<<'SQL'\r?\n(.*?)\r?\nSQL\);", template, re.S)
if len(blocks) < 2:
    raise SystemExit("Migration SQL was not found")

old_schema = """
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL COLLATE NOCASE UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin','member')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TEXT
);
CREATE TABLE parts (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  part_id INTEGER,
  part_name TEXT NOT NULL,
  type TEXT NOT NULL,
  delta INTEGER NOT NULL,
  stock INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
INSERT INTO settings VALUES ('schema_version','1');
INSERT INTO users (username,password_hash,role) VALUES ('admin','hash','admin');
INSERT INTO movements (part_name,type,delta,stock) VALUES ('Altteil','Entnahme',-1,4);
"""

with sqlite3.connect(":memory:") as connection:
    connection.executescript(old_schema)
    for block in blocks:
        connection.executescript(block)
    roles_sql = connection.execute("SELECT sql FROM sqlite_master WHERE name='users'").fetchone()[0]
    if "storekeeper" not in roles_sql:
        raise SystemExit("Storekeeper role missing after migration")
    actor = connection.execute("SELECT actor_name FROM movements").fetchone()[0]
    settings = dict(connection.execute("SELECT key, value FROM settings"))
    if actor != "Altsystem" or settings.get("schema_version") != "3":
        raise SystemExit("Migration values are incomplete")
    if settings.get("github_repository") != "DL1DRK/a12-teilchenbeschleuniger":
        raise SystemExit("GitHub update channel is missing")
    if settings.get("update_cache") != "" or settings.get("update_checked_at") != "0":
        raise SystemExit("Update cache defaults are incomplete")

print("OK: Version 1 database migration to GitHub update schema version 3 verified")
