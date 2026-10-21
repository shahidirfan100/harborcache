"""Summarize Xdebug self time; profiled results are not acceptance timings."""
import gzip
import json
from pathlib import Path
import re
import sys

for file in sorted(Path(sys.argv[1]).glob("cachegrind.*.gz")):
    names = {}
    costs = {}
    function = None
    edge = False
    events = None
    with gzip.open(file, "rt", errors="replace") as stream:
        for line in stream:
            if line.startswith("events:"):
                events = line.strip()
            if line.startswith("fn="):
                match = re.match(r"fn=\((\d+)\)(?: (.*))?", line.strip())
                if match:
                    identity, name = match.groups()
                    if name is not None:
                        names[identity] = name
                    function = names.get(identity, identity)
                else:
                    function = line[3:].strip()
            elif line.startswith("calls="):
                edge = True
            elif re.match(r"^\d+ \d+", line):
                if edge:
                    edge = False
                elif function:
                    costs[function] = costs.get(function, 0) + int(line.split()[1])
    if events != "events: Time_(10ns) Memory_(bytes)":
        raise RuntimeError(f"Unsupported profiler events: {events}")
    selected = [(name, value / 100000) for name, value in costs.items()
                if any(token in name for token in ("HarborCache", "WP_Object_Cache", "serialize", "key_part", "ch_context"))]
    print(json.dumps({"profile": file.name, "unit": "self_ms", "top": sorted(selected, key=lambda x: x[1], reverse=True)[:25]}))
