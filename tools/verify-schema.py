from pathlib import Path
import re
import sqlite3

root = Path(__file__).resolve().parent.parent
template = (root / "tools" / "installer.template.php").read_text(encoding="utf-8")
match = re.search(r"\$database->exec\(<<<'SQL'\r?\n(.*?)\r?\nSQL\);", template, re.S)
if not match:
    raise SystemExit("SQLite schema was not found in installer template")

with sqlite3.connect(":memory:") as connection:
    connection.execute("PRAGMA foreign_keys = ON")
    connection.executescript(match.group(1))
    admin = connection.execute(
        "INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)",
        ("admin", "test-hash", "admin"),
    ).lastrowid
    storekeeper = connection.execute(
        "INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)",
        ("lager", "test-hash", "storekeeper"),
    ).lastrowid
    connection.execute(
        "INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)",
        ("mitglied", "test-hash", "member"),
    )
    cursor = connection.execute(
        "INSERT INTO parts (name, manufacturer, category, value, drawer, quantity, minimum) VALUES (?, ?, ?, ?, ?, ?, ?)",
        ("NE555P", "Texas Instruments", "IC / Halbleiter", "Timer", "B-14", 24, 5),
    )
    part_id = cursor.lastrowid
    connection.execute(
        "INSERT INTO movements (part_id, part_name, type, delta, stock, actor_user_id, actor_name) VALUES (?, ?, ?, ?, ?, ?, ?)",
        (part_id, "NE555P", "Einlagerung", 24, 24, storekeeper, "lager"),
    )
    connection.execute("UPDATE parts SET quantity = quantity - 3 WHERE id = ?", (part_id,))
    connection.execute(
        "INSERT INTO movements (part_id, part_name, type, delta, stock, actor_user_id, actor_name) VALUES (?, ?, ?, ?, ?, ?, ?)",
        (part_id, "NE555P", "Entnahme", -3, 21, admin, "admin"),
    )
    connection.execute("DELETE FROM parts WHERE id = ?", (part_id,))
    connection.commit()

    tables = {row[0] for row in connection.execute("SELECT name FROM sqlite_master WHERE type = 'table'")}
    expected = {"users", "parts", "movements", "settings"}
    if not expected.issubset(tables):
        raise SystemExit(f"Missing tables: {sorted(expected - tables)}")
    movement_count, detached_count = connection.execute(
        "SELECT COUNT(*), SUM(part_id IS NULL) FROM movements"
    ).fetchone()
    if (movement_count, detached_count) != (2, 2):
        raise SystemExit("Movement history did not survive part deletion")
    roles = {row[0] for row in connection.execute("SELECT role FROM users")}
    if roles != {"admin", "storekeeper", "member"}:
        raise SystemExit("Role schema is incomplete")
    settings = dict(connection.execute("SELECT key, value FROM settings"))
    if settings.get("schema_version") != "3":
        raise SystemExit("Schema version 3 is missing")
    if settings.get("github_repository") != "DL1DRK/a12-teilchenbeschleuniger":
        raise SystemExit("GitHub update channel is missing")

print("OK: SQLite schema, stock update and retained movement history verified")
