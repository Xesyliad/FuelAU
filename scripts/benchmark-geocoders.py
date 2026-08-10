#!/usr/bin/env python3
"""Compare local Photon and Nominatim search against FuelAU's fixed AU corpus."""

from __future__ import annotations

import argparse
import json
import math
import statistics
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict
from pathlib import Path
from typing import Any

PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CORPUS = PROJECT_ROOT / "tests" / "fixtures" / "geocoder-benchmark.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--photon-url", default="http://127.0.0.1:12322")
    parser.add_argument("--nominatim-url", default="http://127.0.0.1:18081")
    parser.add_argument("--corpus", type=Path, default=DEFAULT_CORPUS)
    parser.add_argument("--runs", type=int, default=3)
    parser.add_argument("--timeout", type=float, default=10.0)
    return parser.parse_args()


def normalize(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value)).encode("ascii", "ignore").decode()
    return " ".join("".join(character if character.isalnum() else " " for character in text.lower()).split())


def percentile(values: list[float], proportion: float) -> float:
    ordered = sorted(values)
    return ordered[math.ceil((len(ordered) - 1) * proportion)]


def request_json(url: str, timeout: float) -> tuple[Any, float]:
    request = urllib.request.Request(url, headers={"User-Agent": "FuelAU geocoder evaluation"})
    started = time.perf_counter()
    with urllib.request.urlopen(request, timeout=timeout) as response:
        payload = json.load(response)
    return payload, (time.perf_counter() - started) * 1000


def photon_url(base_url: str, query: str) -> str:
    return f"{base_url.rstrip('/')}/api?{urllib.parse.urlencode({'q': query, 'limit': 5})}"


def nominatim_url(base_url: str, query: str) -> str:
    parameters = {
        "q": query,
        "format": "jsonv2",
        "addressdetails": 1,
        "countrycodes": "au",
        "limit": 5,
    }
    return f"{base_url.rstrip('/')}/search?{urllib.parse.urlencode(parameters)}"


def photon_results(payload: Any) -> list[dict[str, Any]]:
    if not isinstance(payload, dict) or not isinstance(payload.get("features"), list):
        raise ValueError("Photon did not return a GeoJSON FeatureCollection")
    return [feature for feature in payload["features"] if isinstance(feature, dict)]


def nominatim_results(payload: Any) -> list[dict[str, Any]]:
    if not isinstance(payload, list):
        raise ValueError("Nominatim did not return a result list")
    return [result for result in payload if isinstance(result, dict)]


def searchable_text(provider: str, result: dict[str, Any]) -> str:
    if provider == "photon":
        properties = result.get("properties")
        values = properties.values() if isinstance(properties, dict) else []
    else:
        address = result.get("address")
        values = [result.get("display_name", "")]
        if isinstance(address, dict):
            values.extend(address.values())
    return normalize(" ".join(str(value) for value in values if value is not None))


def expected_rank(provider: str, results: list[dict[str, Any]], expected: list[str]) -> int | None:
    expected_tokens = [normalize(value) for value in expected]
    for index, result in enumerate(results, start=1):
        candidate = searchable_text(provider, result)
        if all(token in candidate for token in expected_tokens):
            return index
    return None


def main() -> int:
    arguments = parse_args()
    if arguments.runs < 1:
        raise SystemExit("--runs must be at least 1")

    corpus = json.loads(arguments.corpus.read_text(encoding="utf-8"))
    providers = {
        "photon": lambda query: photon_url(arguments.photon_url, query),
        "nominatim": lambda query: nominatim_url(arguments.nominatim_url, query),
    }
    latencies: dict[str, list[float]] = defaultdict(list)
    accuracy: dict[str, list[int | None]] = defaultdict(list)
    errors: dict[str, int] = defaultdict(int)
    empty: dict[str, int] = defaultdict(int)

    print("provider\tkind\tquery\trank\tfirst_ms")
    for case in corpus:
        query = str(case["query"])
        expected = [str(value) for value in case["expected"]]
        for provider, build_url in providers.items():
            first_latency = float("nan")
            rank: int | None = None
            for run in range(arguments.runs):
                try:
                    payload, latency = request_json(build_url(query), arguments.timeout)
                    results = photon_results(payload) if provider == "photon" else nominatim_results(payload)
                    latencies[provider].append(latency)
                    if run == 0:
                        first_latency = latency
                        rank = expected_rank(provider, results, expected)
                        empty[provider] += int(not results)
                except (OSError, ValueError, json.JSONDecodeError, urllib.error.HTTPError):
                    errors[provider] += 1
                    if run == 0:
                        rank = None
            accuracy[provider].append(rank)
            rank_label = str(rank) if rank is not None else "-"
            print(f"{provider}\t{case['kind']}\t{query}\t{rank_label}\t{first_latency:.1f}")

    print("\nprovider\ttop1\ttop5\tempty\terrors\tmedian_ms\tp95_ms\tmax_ms")
    for provider in providers:
        ranks = accuracy[provider]
        timings = latencies[provider]
        top_one = sum(rank == 1 for rank in ranks)
        top_five = sum(rank is not None and rank <= 5 for rank in ranks)
        if timings:
            latency_fields = (
                f"{statistics.median(timings):.1f}",
                f"{percentile(timings, 0.95):.1f}",
                f"{max(timings):.1f}",
            )
        else:
            latency_fields = ("-", "-", "-")
        print(
            f"{provider}\t{top_one}/{len(ranks)}\t{top_five}/{len(ranks)}\t"
            f"{empty[provider]}\t{errors[provider]}\t" + "\t".join(latency_fields)
        )

    return 0 if all(errors[provider] == 0 for provider in providers) else 1


if __name__ == "__main__":
    raise SystemExit(main())
