import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch
import urllib.error
import zipfile


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("council_release", ROOT / ".github" / "scripts" / "release.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        (ROOT / "scratch").mkdir(exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(prefix="release-test-", dir=ROOT / "scratch")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / "repo"
        self.root.mkdir()
        self.environment = patch.dict(os.environ, {"GH_TOKEN": ""}, clear=True)
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def fixture(self, version="2.0.0"):
        for name in release.REQUIRED:
            path = self.root / release.PLUGIN / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("<?php\n", encoding="utf-8")
        (self.root / release.PLUGIN / "config.json").write_text(json.dumps({"version": version}), encoding="utf-8")
        (self.root / "README.md").write_text(
            f"Current package version: {version}.\n[plugin](./plugins/council_sync)\n"
            "[config](./plugins/council_sync/config.json)\n[CI](./.github/workflows/ci.yml)\n", encoding="utf-8"
        )
        (self.root / "LICENSE").write_text("Synthetic test license.\n", encoding="utf-8")
        (self.root / "CHANGELOG.md").write_text(f"# Changelog\n\n## {version} - 2026-11-13\n\nChanges.\n", encoding="utf-8")

    def published(self):
        return {
            "draft": False, "prerelease": False,
            "assets": [{
                "name": "council-sync-2.0.0.zip", "state": "uploaded",
                "size": 100, "digest": "sha256:" + "b" * 64,
            }],
        }

    def test_docs_tests_and_ci_only_changes_do_not_require_another_release(self):
        lookup = Mock(side_effect=AssertionError("Unexpected metadata lookup"))
        ready, message = release.release_plan(
            "2.0.0", "2.0.0", ["README.md", "tests/run.php", ".github/workflows/ci.yml"],
            "pull_request", "a" * 40, lookup,
        )
        self.assertFalse(ready)
        self.assertIn("No plugin changes", message)
        lookup.assert_not_called()

    def test_plugin_changes_have_a_premerge_gate_without_automatically_bumping(self):
        for event, expected in (("pull_request", False), ("merge_group", False), ("push", True)):
            lookup = Mock(return_value=None)
            ready, _ = release.release_plan("2.0.0", "1.2.3", [release.PLUGIN + "/models/new.php"], event, "a" * 40, lookup)
            self.assertEqual(expected, ready)
            self.assertEqual(4, lookup.call_count)

    def test_workflow_outputs_distinguish_plugin_pushes_from_prs_and_docs_only_pushes(self):
        self.fixture()
        for index, (event, paths, publish) in enumerate((
            ("pull_request", [release.PLUGIN + "/config.json"], "false"),
            ("push", [release.PLUGIN + "/config.json"], "true"),
            ("push", ["README.md"], "false"),
        )):
            output = self.root / f"output-{index}.txt"
            environment = {
                "GITHUB_EVENT_NAME": event, "TARGET_BRANCH": "main", "GITHUB_REPOSITORY": release.REPOSITORY,
                "BASE_SHA": "a" * 40, "HEAD_SHA": "b" * 40, "GITHUB_OUTPUT": str(output),
            }
            with patch.dict(os.environ, environment, clear=True), patch.object(release, "changed_paths", return_value=paths):
                with patch.object(release, "base_version", return_value="1.2.3"), patch.object(release, "api_get", return_value=None):
                    release.check(self.root)
            self.assertEqual({"publish": publish, "version": "2.0.0"}, dict(
                line.split("=", 1) for line in output.read_text().splitlines()
            ))

    def test_existing_tag_release_or_draft_blocks_plugin_prs(self):
        reference = {"object": {"type": "commit", "sha": "a" * 40}}
        for tag, published in ((reference, None), (None, self.published()), (reference, {"draft": True})):
            lookup = Mock(side_effect=lambda path: tag if path.startswith("git/") else published)
            with self.assertRaises(release.ReleaseError):
                release.release_plan("2.0.0", "1.2.3", [release.PLUGIN + "/config.json"], "pull_request", "a" * 40, lookup)

    def test_only_complete_publication_at_the_same_commit_is_idempotent(self):
        reference = {"object": {"type": "commit", "sha": "a" * 40}}
        published = self.published()
        lookup = lambda path: reference if path.startswith("git/") else published
        ready, message = release.release_plan("2.0.0", "1.2.3", [release.PLUGIN + "/config.json"], "push", "a" * 40, lookup)
        self.assertFalse(ready)
        self.assertIn("already published", message)
        with self.assertRaises(release.ReleaseError):
            release.release_plan("2.0.0", "1.2.3", [release.PLUGIN + "/config.json"], "push", "c" * 40, lookup)
        published["assets"] = []
        with self.assertRaises(release.ReleaseError):
            release.release_plan("2.0.0", "1.2.3", [release.PLUGIN + "/config.json"], "push", "a" * 40, lookup)

    def test_beta_build_and_decreasing_versions_cannot_release(self):
        for version in ("2.0.0-beta.1", "2.0.0+build.1", "v2.0.0", "02.0.0", "2.0"):
            with self.assertRaises(release.ReleaseError):
                release.release_plan(version, "1.2.3", [release.PLUGIN + "/config.json"], "pull_request", "a" * 40, Mock())
        lookup = Mock()
        with self.assertRaises(release.ReleaseError):
            release.release_plan("1.2.2", "1.2.3", [release.PLUGIN + "/config.json"], "pull_request", "a" * 40, lookup)
        lookup.assert_not_called()

    def test_github_404_is_missing_but_rate_limits_and_redirects_fail_closed(self):
        for status in (404, 403, 429, 302):
            error = urllib.error.HTTPError("https://api.github.com", status, "Synthetic failure", {}, None)
            with patch.object(release.urllib.request, "build_opener") as opener:
                opener.return_value.open.side_effect = error
                if status == 404:
                    self.assertIsNone(release.api_get("releases/tags/v2.0.0"))
                else:
                    with self.assertRaises(release.ReleaseError):
                        release.api_get("releases/tags/v2.0.0")

    def test_successful_but_invalid_metadata_is_not_treated_as_a_missing_release(self):
        for body in (b"null", b"[]", b"invalid"):
            response = io.BytesIO(body)
            response.status = 200
            with patch.object(release.urllib.request, "build_opener") as opener:
                opener.return_value.open.return_value = response
                with self.assertRaises(release.ReleaseError):
                    release.api_get("releases/tags/v2.0.0")

    def test_zip_is_exactly_one_installable_folder_with_docs_but_no_tests(self):
        self.fixture()
        (self.root / "tests").mkdir()
        (self.root / "tests" / "test_private.php").write_text("Synthetic non-runtime data.", encoding="utf-8")
        before = (self.root / "README.md").read_bytes()
        manifest = (self.root / release.PLUGIN / "config.json").read_bytes()
        archive = release.package(self.root, self.root / "output")
        with zipfile.ZipFile(archive) as zip_file:
            names = zip_file.namelist()
            self.assertEqual(22, len(names))
            self.assertTrue(all(name.startswith("council_sync/") for name in names))
            self.assertFalse(any("tests/" in name for name in names))
            for name in release.DOCS:
                self.assertIn("council_sync/" + name, names)
            readme = zip_file.read("council_sync/README.md").decode()
            self.assertIn("[plugin](.)", readme)
            self.assertIn("[config](./config.json)", readme)
            self.assertIn("[CI](https://github.com/" + release.REPOSITORY + "/blob/v2.0.0/.github/workflows/ci.yml)", readme)
        self.assertEqual(before, (self.root / "README.md").read_bytes())
        self.assertEqual(manifest, (self.root / release.PLUGIN / "config.json").read_bytes())
        second = release.package(self.root, self.root / "second-output")
        self.assertEqual(archive.read_bytes(), second.read_bytes())

    def test_unexpected_test_or_private_files_inside_plugin_are_rejected(self):
        self.fixture()
        for relative in ("tests/private.php", "models/private.key", "models/test_hidden.php"):
            path = self.root / release.PLUGIN / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("Synthetic unexpected input.", encoding="utf-8")
            with self.assertRaises(release.ReleaseError):
                release.package(self.root, self.root / "output")
            path.unlink()

    def test_package_paths_obey_installer_reserved_name_and_control_guards(self):
        for name in ("models/CON.php", "models/aux.pdt", "models/file\n.php", "models/file.php."):
            self.assertFalse(release.runtime_file(name))
        self.assertTrue(release.runtime_file("models/forward.php"))

    def test_incomplete_runtime_or_changelog_cannot_package(self):
        self.fixture()
        (self.root / release.PLUGIN / "models/council_sync.php").unlink()
        with self.assertRaises(release.ReleaseError):
            release.package(self.root, self.root / "output")
        self.fixture()
        (self.root / "CHANGELOG.md").write_text("# Changelog\n\n## 1.0.0\n", encoding="utf-8")
        with self.assertRaises(release.ReleaseError):
            release.package(self.root, self.root / "output")

    def test_development_bootstrap_flags_never_enter_release_packages(self):
        self.fixture()
        manifest = self.root / release.PLUGIN / "config.json"
        manifest.write_text(json.dumps({
            "version": "2.0.0", "software_update": {"development_branch": "dev", "development_protocol": 1}
        }), encoding="utf-8")
        with self.assertRaises(release.ReleaseError):
            release.package(self.root, self.root / "output")

    def test_publication_stays_draft_until_the_asset_upload_command_completes(self):
        self.fixture()
        output = self.root / "output"
        release.package(self.root, output)
        environment = {
            "GITHUB_EVENT_NAME": "push", "GITHUB_REF": "refs/heads/main",
            "GITHUB_REPOSITORY": release.REPOSITORY, "GITHUB_SHA": "a" * 40, "GH_TOKEN": "synthetic-test-token",
        }
        with patch.dict(os.environ, environment, clear=True), patch.object(release, "api_get", return_value=None):
            with patch.object(release.subprocess, "run") as command:
                release.publish(self.root, output)
                self.assertEqual(3, command.call_count)
                self.assertIn("ref=refs/tags/v2.0.0", command.call_args_list[0].args[0])
                self.assertIn("--draft", command.call_args_list[1].args[0])
                self.assertIn("--verify-tag", command.call_args_list[1].args[0])
                self.assertIn("--draft=false", command.call_args_list[2].args[0])
            failure = release.subprocess.CalledProcessError(1, "gh")
            with patch.object(release.subprocess, "run", side_effect=[Mock(returncode=0), failure]) as command:
                with self.assertRaises(release.subprocess.CalledProcessError):
                    release.publish(self.root, output)
                self.assertEqual(2, command.call_count)


if __name__ == "__main__":
    unittest.main()
