"""Delete only release artifacts whose every file has a verified release copy.

No extraction, release deletion, cache deletion, or workflow-run deletion occurs.
The default mode scans successful tag releases; current-run mode is called only
after the publishing job succeeds. Failed/manual builds remain until retention.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from urllib.parse import quote
import zipfile


def gh_api(endpoint, *, destination=None, method="GET", binary=False):
    command = ["gh", "api", "--method", method, endpoint]
    if binary:
        command += ["-H", "Accept: application/octet-stream"]
    if destination is not None:
        with Path(destination).open("wb") as output:
            subprocess.run(command, stdout=output, check=True, timeout=300)
        return None
    result = subprocess.run(command, capture_output=True, check=True, timeout=60)
    return json.loads(result.stdout) if result.stdout.strip() else None


def pages(endpoint, key=None):
    result = []
    for page in range(1, 1001):
        data = gh_api(f"{endpoint}?per_page=100&page={page}")
        batch = data[key] if key else data
        result.extend(batch)
        if len(batch) < 100:
            return result
    raise RuntimeError("Pagination limit reached; refusing an incomplete scan")


def sha256(stream):
    digest = hashlib.sha256()
    for chunk in iter(lambda: stream.read(1024 * 1024), b""):
        digest.update(chunk)
    return "sha256:" + digest.hexdigest()


def release_copy_matches(archive_path, assets, repo, workdir):
    """Check all members before the caller is allowed to delete the archive."""
    available = {}
    for asset in assets:
        name = asset["name"]
        if name in available:
            return False
        available[name] = asset
    with zipfile.ZipFile(archive_path) as archive:
        members = [member for member in archive.infolist() if not member.is_dir()]
        if not members or len({member.filename for member in members}) != len(members):
            return False
        for member in members:
            # Release artifacts contain flat files. Never extract untrusted paths.
            if "/" in member.filename or "\\" in member.filename:
                return False
            asset = available.get(member.filename)
            if not asset or asset.get("state") != "uploaded" or asset["size"] != member.file_size:
                return False
            expected = asset.get("digest") or ""
            if not re.fullmatch(r"sha256:[0-9a-f]{64}", expected):
                copy_path = Path(workdir) / "release-asset.bin"
                gh_api(f"repos/{repo}/releases/assets/{int(asset['id'])}",
                       destination=copy_path, binary=True)
                if copy_path.stat().st_size != member.file_size:
                    return False
                with copy_path.open("rb") as stream:
                    expected = sha256(stream)
            with archive.open(member) as stream:
                if sha256(stream) != expected:
                    return False
    return True


def eligible_run(run, artifact, current_run_id=None):
    if run.get("path", "").split("@", 1)[0] != ".github/workflows/release.yml":
        return False
    if run.get("head_sha") != artifact["workflow_run"].get("head_sha"):
        return False
    if current_run_id is not None:
        return str(run["id"]) == str(current_run_id) and run.get("status") == "in_progress"
    return (run.get("status") == "completed" and run.get("conclusion") == "success"
            and run.get("event") == "push")


def asset_signatures(assets):
    return sorted((asset["id"], asset["name"], asset["size"], asset.get("digest"),
                   asset.get("state")) for asset in assets)


def cleanup(repo, *, run_id=None, release_tag=None):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
        raise ValueError("Invalid repository")
    if bool(run_id) != bool(release_tag):
        raise ValueError("run-id and release-tag must be provided together")
    if run_id and str(run_id) != os.environ.get("GITHUB_RUN_ID"):
        raise ValueError("Current-run cleanup must match GITHUB_RUN_ID")
    base = f"repos/{repo}"
    endpoint = (f"{base}/actions/runs/{int(run_id)}/artifacts" if run_id
                else f"{base}/actions/artifacts")
    # Snapshot all pages before deleting, so offsets cannot skip artifacts.
    artifacts = pages(endpoint, "artifacts")
    runs, releases = {}, {}
    deleted, freed, errors = 0, 0, 0
    for artifact in artifacts:
        name = artifact["name"]
        if artifact.get("expired") or not (name == "release-assets" or name.startswith("p9-rezeptwerk-")):
            continue
        artifact_run_id = artifact["workflow_run"]["id"]
        try:
            if artifact_run_id not in runs:
                runs[artifact_run_id] = gh_api(f"{base}/actions/runs/{int(artifact_run_id)}")
            run = runs[artifact_run_id]
            if not eligible_run(run, artifact, run_id):
                continue
            tag = release_tag or run["head_branch"]
            if tag not in releases:
                release = gh_api(f"{base}/releases/tags/{quote(tag, safe='')}")
                releases[tag] = (release, pages(f"{base}/releases/{int(release['id'])}/assets"))
            release, assets = releases[tag]
            if release.get("draft") or release["tag_name"] != tag:
                continue
            with tempfile.TemporaryDirectory() as workdir:
                archive_path = Path(workdir) / "artifact.zip"
                gh_api(f"{base}/actions/artifacts/{int(artifact['id'])}/zip", destination=archive_path)
                if not release_copy_matches(archive_path, assets, repo, workdir):
                    print(f"KEEP {artifact['id']} {name}: release copy incomplete or different", flush=True)
                    continue
                # Recheck publication after verification and before removal.
                current = gh_api(f"{base}/releases/{int(release['id'])}")
                current_assets = pages(f"{base}/releases/{int(release['id'])}/assets")
                if (current.get("draft") or current["tag_name"] != tag
                        or asset_signatures(current_assets) != asset_signatures(assets)):
                    print(f"KEEP {artifact['id']} {name}: release changed during verification", flush=True)
                    continue
                gh_api(f"{base}/actions/artifacts/{int(artifact['id'])}", method="DELETE")
                deleted += 1
                freed += artifact["size_in_bytes"]
                print(f"DELETE {artifact['id']} {name}: all files verified in release {tag}", flush=True)
        except (subprocess.SubprocessError, OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
            errors += 1
            print(f"KEEP {artifact['id']} {name}: verification failed ({type(error).__name__})", flush=True)
    summary = f"Deleted {deleted} verified artifacts; freed {freed} bytes ({freed / 2**30:.3f} GiB); errors: {errors}."
    print(summary, flush=True)
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as output:
            output.write(summary + "\n")
    if errors:
        raise SystemExit(1)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-id")
    parser.add_argument("--release-tag")
    arguments = parser.parse_args()
    cleanup(os.environ["GITHUB_REPOSITORY"], run_id=arguments.run_id,
            release_tag=arguments.release_tag)
