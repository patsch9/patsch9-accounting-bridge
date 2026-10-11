"""Validate catalogs against a fresh WP-CLI POT and compiled MO, without WordPress/DB."""

import argparse
import ast
import gettext
import re
from collections import Counter
from pathlib import Path


def read_catalog(path):
    entries = {}
    for block in path.read_text(encoding="utf-8").strip().split("\n\n"):
        values = {}
        field = None
        for line in block.splitlines():
            if line.startswith("#"):
                assert not line.startswith("#, fuzzy"), f"Fuzzy entry in {path}"
                continue
            match = re.match(r'(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) (".*")$', line)
            if match:
                field = match[1]
                values[field] = ast.literal_eval(match[2])
            elif line.startswith('"') and field:
                values[field] += ast.literal_eval(line)
        if values.get("msgid"):
            key = (values.get("msgctxt", ""), values["msgid"])
            assert key not in entries, f"Duplicate catalog entry: {key}"
            entries[key] = values
    return entries


def placeholders(text):
    return Counter(re.findall(r"%(?:\d+\$)?[-+#0 ']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]|\[[a-z_]+\]|\{[a-z_]+\}", text.replace("%%", "")))


def main():
    root = Path(__file__).resolve().parents[1]
    languages = root / "languages"
    parser = argparse.ArgumentParser()
    parser.add_argument("--pot", type=Path, help="Freshly extracted WP-CLI template")
    parser.add_argument("--mo", type=Path, help="Freshly compiled German catalog")
    args = parser.parse_args()
    pot = read_catalog(languages / "patsch9-accounting-bridge.pot")
    po = read_catalog(languages / "patsch9-accounting-bridge-de_DE.po")
    assert pot.keys() == po.keys(), "German PO and source template differ"
    if args.pot:
        fresh = read_catalog(args.pot)
        assert pot.keys() == fresh.keys(), "Regenerate POT: source strings have changed"
        for key in pot:
            assert pot[key].get("msgid_plural") == fresh[key].get("msgid_plural"), key
    with (languages / "patsch9-accounting-bridge-de_DE.mo").open("rb") as stream:
        german = gettext.GNUTranslations(stream)
    assert german.info()["language"] == "de_DE"
    if args.mo:
        with args.mo.open("rb") as stream:
            rebuilt = gettext.GNUTranslations(stream)
        assert german._catalog == rebuilt._catalog, "Recompile MO: binary catalog is stale"
    for (context, original), entry in po.items():
        if "msgid_plural" in entry:
            for index, source in enumerate((original, entry["msgid_plural"])):
                translated = entry.get(f"msgstr[{index}]", "")
                assert translated, f"Missing plural translation: {original}"
                assert placeholders(source) == placeholders(translated), original
            for count in (0, 1, 2, 101):
                actual = german.npgettext(context, original, entry["msgid_plural"], count) if context else german.ngettext(original, entry["msgid_plural"], count)
                assert actual == entry[f"msgstr[{0 if count == 1 else 1}]"], original
        else:
            translated = entry.get("msgstr", "")
            assert translated, f"Missing German translation: {original}"
            assert placeholders(original) == placeholders(translated), original
            actual = german.pgettext(context, original) if context else german.gettext(original)
            assert actual == translated, f"MO differs from PO: {original}"
    assert german.gettext("Invoice") == "Rechnung"
    assert german.gettext("Hello %s,") == "Hallo %s,"
    assert gettext.NullTranslations().gettext("Invoice") == "Invoice"
    assert "\n\n" in german.gettext("Really remove the link to the Lexware invoice?\n\nThe invoice will remain in Lexware, but you will be able to create a new invoice for this order.")
    print(f"OK: {len(pot)} fully translated entries; MO, plurals and placeholders verified.")


if __name__ == "__main__":
    main()
