import sqlite3


SCHEMA = """
PRAGMA foreign_keys = ON;
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL COLLATE NOCASE UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('admin','storekeeper','member')),
  active INTEGER NOT NULL CHECK (active IN (0,1)),
  created_at TEXT NOT NULL,
  last_login_at TEXT
);
CREATE TABLE parts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  manufacturer TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL,
  value TEXT NOT NULL DEFAULT '',
  drawer TEXT NOT NULL,
  quantity INTEGER NOT NULL CHECK (quantity >= 0),
  minimum INTEGER NOT NULL CHECK (minimum >= 0),
  datasheet TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE TABLE movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  part_id INTEGER,
  part_name TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('Einlagerung','Entnahme','Korrektur')),
  delta INTEGER NOT NULL,
  stock INTEGER NOT NULL CHECK (stock >= 0),
  actor_user_id INTEGER,
  actor_name TEXT NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE SET NULL,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
INSERT INTO settings VALUES ('schema_version','3');
"""


def verify_numbering_reset(connection: sqlite3.Connection) -> None:
    users = connection.execute("SELECT id FROM users ORDER BY id").fetchall()
    parts = connection.execute("SELECT id FROM parts ORDER BY id").fetchall()
    movements = connection.execute("SELECT id, part_id, actor_user_id FROM movements ORDER BY id").fetchall()
    user_map = {row[0]: index + 1 for index, row in enumerate(users)}
    part_map = {row[0]: index + 1 for index, row in enumerate(parts)}
    movement_map = {row[0]: index + 1 for index, row in enumerate(movements)}

    connection.execute("BEGIN IMMEDIATE")
    connection.execute("UPDATE movements SET part_id=NULL, actor_user_id=NULL")
    connection.execute("UPDATE movements SET id=-id")
    connection.execute("UPDATE parts SET id=-id")
    connection.execute("UPDATE users SET id=-id")
    for old_id, new_id in user_map.items():
        connection.execute("UPDATE users SET id=? WHERE id=?", (new_id, -old_id))
    for old_id, new_id in part_map.items():
        connection.execute("UPDATE parts SET id=? WHERE id=?", (new_id, -old_id))
    for old_id, old_part_id, old_actor_id in movements:
        connection.execute(
            "UPDATE movements SET id=?,part_id=?,actor_user_id=? WHERE id=?",
            (
                movement_map[old_id],
                part_map.get(old_part_id),
                user_map.get(old_actor_id),
                -old_id,
            ),
        )
    connection.execute("DELETE FROM sqlite_sequence WHERE name IN ('users','parts','movements')")
    for name, sequence in (("users", len(users)), ("parts", len(parts)), ("movements", len(movements))):
        if sequence:
            connection.execute("INSERT INTO sqlite_sequence(name,seq) VALUES (?,?)", (name, sequence))
    assert not connection.execute("PRAGMA foreign_key_check").fetchall()
    connection.commit()


def verify_full_reset(connection: sqlite3.Connection) -> None:
    admin = connection.execute(
        "SELECT username,password_hash,created_at,last_login_at FROM users WHERE id=1"
    ).fetchone()
    connection.execute("BEGIN IMMEDIATE")
    connection.execute("DELETE FROM movements")
    connection.execute("DELETE FROM parts")
    connection.execute("DELETE FROM users")
    connection.execute("DELETE FROM sqlite_sequence WHERE name IN ('users','parts','movements')")
    connection.execute(
        "INSERT INTO users(id,username,password_hash,role,active,created_at,last_login_at) VALUES (1,?,?, 'admin',1,?,?)",
        admin,
    )
    assert not connection.execute("PRAGMA foreign_key_check").fetchall()
    connection.commit()


with sqlite3.connect(":memory:") as db:
    db.executescript(SCHEMA)
    db.executemany(
        "INSERT INTO users(id,username,password_hash,role,active,created_at) VALUES (?,?,?,?,?,?)",
        [(2, "admin", "hash-admin", "admin", 1, "2026-01-01"), (7, "member", "hash-member", "member", 1, "2026-01-02")],
    )
    db.executemany(
        "INSERT INTO parts(id,name,category,drawer,quantity,minimum,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)",
        [(4, "NE555", "IC", "A-1", 10, 2, "2026-01-01", "2026-01-01"), (9, "1N4148", "Diode", "B-2", 20, 3, "2026-01-02", "2026-01-02")],
    )
    db.executemany(
        "INSERT INTO movements(id,part_id,part_name,type,delta,stock,actor_user_id,actor_name,created_at) VALUES (?,?,?,?,?,?,?,?,?)",
        [(3, 4, "NE555", "Einlagerung", 10, 10, 2, "admin", "2026-01-01"), (8, 9, "1N4148", "Entnahme", -2, 18, 7, "member", "2026-01-02")],
    )
    db.commit()

    verify_numbering_reset(db)
    assert db.execute("SELECT id FROM users ORDER BY id").fetchall() == [(1,), (2,)]
    assert db.execute("SELECT id FROM parts ORDER BY id").fetchall() == [(1,), (2,)]
    assert db.execute("SELECT id,part_id,actor_user_id FROM movements ORDER BY id").fetchall() == [(1, 1, 1), (2, 2, 2)]

    verify_full_reset(db)
    assert db.execute("SELECT id,username,role,active FROM users").fetchall() == [(1, "admin", "admin", 1)]
    assert db.execute("SELECT COUNT(*) FROM parts").fetchone()[0] == 0
    assert db.execute("SELECT COUNT(*) FROM movements").fetchone()[0] == 0
    assert db.execute("SELECT value FROM settings WHERE key='schema_version'").fetchone()[0] == "3"

print("OK: Neunummerierung erhält Verknüpfungen; vollständiger Reset behält nur den Administrator")
