"""Fail on Plugin Check findings even when WP-CLI returns exit status zero."""
import html
import json
import sys
from pathlib import Path


def check_report(report):
    report = html.unescape(report)
    decoder = json.JSONDecoder()
    findings = []
    parsed = False
    position = 0
    while position < len(report):
        if report[position] not in "[{":
            position += 1
            continue
        try:
            value, end = decoder.raw_decode(report, position)
        except ValueError:
            position += 1
            continue
        parsed = True
        pending = [value]
        while pending:
            item = pending.pop()
            if isinstance(item, list):
                pending.extend(item)
            elif isinstance(item, dict):
                if str(item.get("type", "")).upper() in {"ERROR", "WARNING"}:
                    findings.append(item)
                pending.extend(v for v in item.values() if isinstance(v, (dict, list)))
        position = end
    if findings:
        for item in findings:
            print(f"{item.get('type')}: {item.get('code')}: {item.get('message')}")
        return 1
    if not parsed and "Success: Checks complete. No errors found." not in report:
        print("FAIL Plugin Check returned no recognizable report")
        return 1
    print("PASS Plugin Check report contains no errors or warnings")
    return 0


if __name__ == "__main__":
    text = sys.stdin.read() if sys.argv[1] == "-" else Path(sys.argv[1]).read_text(encoding="utf-8-sig")
    sys.exit(check_report(text))
