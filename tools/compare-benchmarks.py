"""Compare rotating, same-run benchmark blocks without pooling runner speeds."""

import json
import statistics
import sys
from pathlib import Path


def median(values):
    return round(statistics.median(values), 4)


def summarize(directory):
    rows = [json.loads(line) for line in (directory / "benchmark.jsonl").read_text().splitlines() if line.strip()]
    if not rows:
        raise ValueError("No benchmark rows in " + str(directory))
    result = {"directory": directory.name, "rows": len(rows), "lanes": {}}
    for lane in sorted({row["lane"] for row in rows}):
        selected = [row for row in rows if row["lane"] == lane]
        engines = sorted({row["engine"] for row in selected})
        if engines != ["baseline", "free", "harbor"]:
            raise ValueError("Incomplete engine set for " + lane)
        blocks = [{row["round"] for row in selected if row["engine"] == engine} for engine in engines]
        if not all(block == blocks[0] for block in blocks) or len(selected) != 3 * len(blocks[0]):
            raise ValueError("Missing or duplicate paired rounds for " + lane)
        metrics = {
            "php_cpu_ms": lambda row: row["php_cpu_ms"],
            "bootstrap_php_cpu_ms": lambda row: row["bootstrap_php_cpu_ms"],
            "bootstrap_wall_ms": lambda row: row["bootstrap_wall_ms"],
            "peak_mib": lambda row: row["php_peak_bytes"] / 1048576,
        }
        for operation in selected[0]["timings"]:
            for percentile in ("p50_ms", "p95_ms", "p99_ms"):
                metrics[operation + "." + percentile] = lambda row, o=operation, p=percentile: row["timings"][o][p]
        lane_result = {}
        for name, getter in metrics.items():
            values = {engine: {row["round"]: getter(row) for row in selected if row["engine"] == engine} for engine in engines}
            item = {engine: median(list(blocks.values())) for engine, blocks in values.items()}
            for reference in ("baseline", "free"):
                if reference in values and "harbor" in values:
                    common = values[reference].keys() & values["harbor"].keys()
                    ratios = [100 * (values["harbor"][block] / values[reference][block] - 1) for block in common if values[reference][block] > 0]
                    item["paired_change_vs_" + reference + "_pct"] = median(ratios)
            lane_result[name] = item
        result["lanes"][lane] = lane_result
    return result


if __name__ == "__main__":
    reports = [summarize(Path(argument)) for argument in sys.argv[1:]]
    print(json.dumps(reports, indent=2))
