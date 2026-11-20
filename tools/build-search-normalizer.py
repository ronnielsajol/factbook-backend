"""Export SQLite unicode61 character mappings for query normalization."""
import json
import sqlite3
import unicodedata
from pathlib import Path

db = sqlite3.connect(":memory:")
db.execute("CREATE VIRTUAL TABLE words USING fts5(text, tokenize='unicode61 remove_diacritics 2')")
db.execute("CREATE VIRTUAL TABLE vocabulary USING fts5vocab(words, instance)")
characters = [(code, chr(code)) for code in range(0x110000)
              if unicodedata.category(chr(code))[0] in ("L", "N")]
db.executemany("INSERT INTO words(rowid,text) VALUES (?,?)", characters)
normalized = dict(db.execute("SELECT doc,term FROM vocabulary"))
mapping = {char: normalized.get(code, " ") for code, char in characters
           if normalized.get(code, " ") != char}
target = Path(__file__).resolve().parents[1] / "storage/app/factbook/metadata/unicode61.json"
target.write_text(json.dumps(mapping, ensure_ascii=False), encoding="utf-8")
print(f"Exported {len(mapping)} mappings using SQLite {sqlite3.sqlite_version}")

