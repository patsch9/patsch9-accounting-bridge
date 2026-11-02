"""Offline regressions for the destructive boundary of artifact cleanup."""

from copy import deepcopy
import hashlib
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch
import zipfile

import cleanup_release_artifacts as cleanup


class CleanupTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.archive = self.root / "source.zip"
        with zipfile.ZipFile(self.archive, "w") as archive:
            archive.writestr("app.exe", b"verified build")
            archive.writestr("SHA256SUMS.txt", b"checksums")
        self.assets = [dict(id=index, name=name, size=len(data), state="uploaded",
                            digest="sha256:" + hashlib.sha256(data).hexdigest())
                       for index, (name, data) in enumerate(
                           [("app.exe", b"verified build"), ("SHA256SUMS.txt", b"checksums")], 1)]
        self.run = dict(id=42, path=".github/workflows/release.yml", head_sha="abc",
                        head_branch="2026.11.1", status="completed", conclusion="success", event="push")
        self.artifact = dict(id=8, name="release-assets", size_in_bytes=100, expired=False,
                             workflow_run=dict(id=42, head_sha="abc"))
        self.release = dict(id=7, tag_name="2026.11.1", draft=False)
        self.deletions = []
        self.changed_assets = False
        self.fail_download = False

    def matches(self, assets=None):
        return cleanup.release_copy_matches(self.archive, self.assets if assets is None else assets,
                                            "owner/repo", self.root)

    def api(self, endpoint, *, destination=None, method="GET", binary=False):
        if method == "DELETE":
            self.deletions.append(endpoint)
            return None
        if destination is not None:
            if self.fail_download:
                raise OSError("download interrupted")
            shutil.copyfile(self.archive, destination)
            return None
        if "/actions/artifacts?" in endpoint or "/actions/runs/42/artifacts?" in endpoint:
            return {"artifacts": [self.artifact]}
        if endpoint.endswith("/actions/runs/42"):
            return self.run
        if "/releases/tags/" in endpoint or endpoint.endswith("/releases/7"):
            return self.release
        if "/releases/7/assets?" in endpoint:
            self.asset_calls += 1
            assets = deepcopy(self.assets)
            if self.changed_assets and self.asset_calls > 1:
                assets[0]["id"] += 10
            return assets
        raise AssertionError(endpoint)

    def scan(self, **kwargs):
        self.asset_calls = 0
        with patch.object(cleanup, "gh_api", side_effect=self.api):
            cleanup.cleanup("owner/repo", **kwargs)

    def test_all_files_must_match(self):
        self.assertTrue(self.matches())

    def test_missing_release_file_keeps_artifact(self):
        self.assertFalse(self.matches(self.assets[:1]))

    def test_wrong_size_keeps_artifact(self):
        self.assets[0]["size"] += 1
        self.assertFalse(self.matches())

    def test_same_size_different_digest_keeps_artifact(self):
        self.assets[0]["digest"] = "sha256:" + "0" * 64
        self.assertFalse(self.matches())

    def test_unfinished_asset_keeps_artifact(self):
        self.assets[0]["state"] = "starter"
        self.assertFalse(self.matches())

    def test_nested_path_keeps_artifact(self):
        with zipfile.ZipFile(self.archive, "w") as archive:
            archive.writestr("../app.exe", b"verified build")
        self.assertFalse(self.matches())

    def test_empty_archive_keeps_artifact(self):
        with zipfile.ZipFile(self.archive, "w"):
            pass
        self.assertFalse(self.matches())

    def test_missing_digest_uses_downloaded_release_content(self):
        self.assets[0]["digest"] = None
        def download(endpoint, *, destination, binary):
            self.assertTrue(binary)
            Path(destination).write_bytes(b"verified build")
        with patch.object(cleanup, "gh_api", side_effect=download):
            self.assertTrue(self.matches())

    def test_only_verified_artifact_is_deleted(self):
        self.scan()
        self.assertEqual(self.deletions, ["repos/owner/repo/actions/artifacts/8"])

    def test_failed_run_is_never_deleted(self):
        self.run["conclusion"] = "failure"
        self.scan()
        self.assertEqual(self.deletions, [])

    def test_other_workflow_is_never_deleted(self):
        self.run["path"] = ".github/workflows/android.yml"
        self.scan()
        self.assertEqual(self.deletions, [])

    def test_in_progress_historical_run_is_never_deleted(self):
        self.run["status"] = "in_progress"
        self.scan()
        self.assertEqual(self.deletions, [])

    def test_current_run_requires_matching_environment(self):
        with patch.dict(os.environ, {"GITHUB_RUN_ID": "99"}):
            with self.assertRaises(ValueError):
                self.scan(run_id="42", release_tag="2026.11.1")
        self.assertEqual(self.deletions, [])

    def test_published_current_run_can_be_deleted(self):
        self.run["status"] = "in_progress"
        with patch.dict(os.environ, {"GITHUB_RUN_ID": "42"}):
            self.scan(run_id="42", release_tag="2026.11.1")
        self.assertEqual(len(self.deletions), 1)

    def test_draft_release_is_never_deleted(self):
        self.release["draft"] = True
        self.scan()
        self.assertEqual(self.deletions, [])

    def test_release_change_during_verification_keeps_artifact(self):
        self.changed_assets = True
        self.scan()
        self.assertEqual(self.deletions, [])

    def test_mismatch_does_not_reach_delete_endpoint(self):
        self.assets[0]["digest"] = "sha256:" + "0" * 64
        self.scan()
        self.assertEqual(self.deletions, [])

    def test_interrupted_download_never_deletes(self):
        self.fail_download = True
        with self.assertRaises(SystemExit):
            self.scan()
        self.assertEqual(self.deletions, [])

    def test_pagination_snapshots_all_pages(self):
        with patch.object(cleanup, "gh_api", side_effect=[{"artifacts": list(range(100))},
                                                        {"artifacts": [100]}]) as api:
            self.assertEqual(len(cleanup.pages("endpoint", "artifacts")), 101)
            self.assertEqual(api.call_count, 2)


if __name__ == "__main__":
    unittest.main()
