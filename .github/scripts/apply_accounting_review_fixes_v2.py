from pathlib import Path

original = Path(__file__).with_name("apply_accounting_review_fixes.py")
source = original.read_text(encoding="utf-8")
old_function = '''def replace_once(path, old, new):
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{path}: expected exactly one match, got {count}: {old[:120]!r}")
    file_path.write_text(text.replace(old, new, 1), encoding="utf-8")
'''
new_function = '''def replace_once(path, old, new):
    file_path = ROOT / path
    text = file_path.read_text(encoding="utf-8")
    count = text.count(old)
    if count == 1:
        file_path.write_text(text.replace(old, new, 1), encoding="utf-8")
        return

    # The PHP error-email gettext string intentionally contains literal line
    # breaks. The generated fixer represents them as escaped \\n sequences, so
    # normalize only this one well-delimited block before replacing it.
    email_start = "        if (get_option('patsacbr_email_on_error', 'yes') === 'yes') {"
    if path == "includes/class-lexware-api-client.php" and old.startswith(email_start) and count == 0:
        start = text.find(email_start)
        end_marker = "\\n        }\\n    }\\n}"
        end = text.find(end_marker, start)
        if start >= 0 and end >= 0:
            end += len("\\n        }")
            replacement = new.replace("\\\\n", "\\n")
            file_path.write_text(text[:start] + replacement + text[end:], encoding="utf-8")
            return

    raise RuntimeError(f"{path}: expected exactly one match, got {count}: {old[:120]!r}")
'''
if old_function not in source:
    raise RuntimeError("Could not locate replace_once() in the original fixer")
source = source.replace(old_function, new_function, 1)
namespace = {"__file__": str(original), "__name__": "__main__"}
exec(compile(source, str(original), "exec"), namespace)
