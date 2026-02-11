#!/usr/bin/env python3
import sqlite3
import json

db_path = 'f:/GIT/newtickex/str/save_the_rave.sqlite'
conn = sqlite3.connect(db_path)
conn.row_factory = sqlite3.Row
cur = conn.cursor()

print('=== TABLAS Y VISTAS ===')
cur.execute("SELECT type, name FROM sqlite_master WHERE type IN ('table','view') ORDER BY type, name")
for row in cur.fetchall():
    print(f"{row['type']}: {row['name']}")

print('\n=== COLUMNAS ENTRADAS ===')
cur.execute('PRAGMA table_info(entradas)')
for row in cur.fetchall():
    print(f"  {row[1]} ({row[2]})")

print('\n=== PRUEBA v_senforms_bridge_status ===')
try:
    cur.execute('SELECT * FROM v_senforms_bridge_status LIMIT 1')
    row = cur.fetchone()
    if row:
        cols = [desc[0] for desc in cur.description]
        print(f'Columnas encontradas: {", ".join(cols)}')
        print('Sample:', dict(row))
except Exception as e:
    print(f'Vista no existe: {e}')

print('\n=== PRUEBA senforms_bridge_tickets ===')
try:
    cur.execute('SELECT * FROM senforms_bridge_tickets LIMIT 1')
    row = cur.fetchone()
    if row:
        cols = [desc[0] for desc in cur.description]
        print(f'Columnas encontradas: {", ".join(cols)}')
        print('Sample:', dict(row))
    else:
        print('Tabla vacía')
except Exception as e:
    print(f'Tabla no existe: {e}')

conn.close()
