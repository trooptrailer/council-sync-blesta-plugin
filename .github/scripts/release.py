import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import sys
import urllib.error
import urllib.request
import zipfile


ROOT = Path(__file__).resolve().parents[2]
REPOSITORY = "trooptrailer/council-sync-blesta-plugin"
PLUGIN = "plugins/council_sync"
VERSION = re.compile(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\Z", re.ASCII)
SHA = re.compile(r"[a-f0-9]{40}\Z", re.ASCII)
DOCS = ("README.md", "LICENSE", "CHANGELOG.md")
REQUIRED = (
    "config.json", "council_sync_plugin.php", "council_sync_controller.php", "council_sync_model.php",
    "controllers/admin_main.php", "controllers/admin_software.php",
    "models/council_sync.php", "models/council_sync_software.php", "models/software_snapshot.php",
    "views/default/admin_main.pdt", "views/default/admin_resolve.pdt", "views/default/admin_history.pdt",
    "views/default/admin_software.pdt", "views/default/admin_software_finish.pdt", "views/default/software_banner.pdt",
    "language/en_us/admin_main.php", "language/en_us/admin_software.php",
    "language/en_us/council_sync.php", "language/en_us/council_sync_plugin.php",
)


class ReleaseError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def version_tuple(value):
    if not isinstance(value, str) or len(value) > 64 or not VERSION.fullmatch(value):
        raise ReleaseError("A stable X.Y.Z manifest version is required; beta/build versions cannot release.")
    return tuple(int(part) for part in value.split("."))


def manifest_version(content):
    try:
        manifest = json.loads(content)
    except (json.JSONDecodeError, UnicodeDecodeError):
        raise ReleaseError("The package manifest is not valid JSON.") from None
    if not isinstance(manifest, dict):
        raise ReleaseError("The package manifest must be an object.")
    updates = manifest.get("software_update")
    if isinstance(updates, dict) and "development_branch" in updates:
        raise ReleaseError("Development-update flags cannot enter a main release package.")
    version = manifest.get("version")
    version_tuple(version)
    return version


def read_version(root):
    return manifest_version((root / PLUGIN / "config.json").read_bytes())


def git(root, *arguments):
    result = subprocess.run(
        ["git", "--no-pager", *arguments], cwd=root,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if result.returncode:
        raise ReleaseError("Git history lookup failed.")
    return result.stdout


def changed_paths(root, base, head, event):
    if not SHA.fullmatch(head or "") or not SHA.fullmatch(base or ""):
        raise ReleaseError("Complete base/head commit IDs are required.")
    if base == "0" * 40:
        return git(root, "ls-tree", "-r", "--name-only", "-z", head).decode().split("\0")
    separator = "..." if event in ("pull_request", "merge_group") else ".."
    return git(root, "diff", "--name-only", "-z", base + separator + head).decode().split("\0")


def base_version(root, base):
    if base == "0" * 40 or not git(root, "ls-tree", base, "--", PLUGIN + "/config.json"):
        return None
    return manifest_version(git(root, "show", base + ":" + PLUGIN + "/config.json"))


def api_get(path):
    headers = {
        "Accept": "application/vnd.github+json",
        "User-Agent": "CouncilSync-release-CI",
        "X-GitHub-Api-Version": "2026-03-10",
    }
    token = os.environ.get("GH_TOKEN")
    if token:
        headers["Authorization"] = "Bearer " + token
    request = urllib.request.Request("https://api.github.com/repos/" + REPOSITORY + "/" + path, headers=headers)
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=20) as response:
            content = response.read(1048577)
            if response.status != 200 or len(content) > 1048576:
                raise ReleaseError("GitHub returned unexpected or oversized metadata.")
            result = json.loads(content)
            if not isinstance(result, dict):
                raise ReleaseError("GitHub metadata must be an object.")
            return result
    except urllib.error.HTTPError as error:
        status = error.code
        error.close()
        if status == 404:
            return None
        raise ReleaseError(f"GitHub metadata lookup failed (HTTP {status}).") from None
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, UnicodeDecodeError):
        raise ReleaseError("GitHub metadata lookup was unavailable or invalid.") from None


def tag_commit(reference, lookup):
    if reference is None:
        return None
    for _ in range(5):
        obj = reference.get("object") if isinstance(reference, dict) else None
        if not isinstance(obj, dict) or not isinstance(obj.get("sha"), str) or not SHA.fullmatch(obj["sha"]):
            raise ReleaseError("GitHub returned an invalid tag object.")
        if obj.get("type") == "commit":
            return obj["sha"]
        if obj.get("type") != "tag":
            break
        reference = lookup("git/tags/" + obj["sha"])
    raise ReleaseError("The release tag does not resolve to a commit.")


def complete_release(release, version):
    assets = release.get("assets")
    if not isinstance(assets, list):
        return False
    matches = [asset for asset in assets if isinstance(asset, dict) and asset.get("name") == "council-sync-" + version + ".zip"]
    return (
        len(matches) == 1 and matches[0].get("state") == "uploaded"
        and type(matches[0].get("size")) is int and 0 < matches[0]["size"] <= 16777216
        and isinstance(matches[0].get("digest"), str)
        and re.fullmatch(r"sha256:[a-f0-9]{64}", matches[0]["digest"]) is not None
    )


def release_plan(version, previous, paths, event, head, lookup=None):
    lookup = api_get if lookup is None else lookup
    version_tuple(version)
    if not any(path.startswith(PLUGIN + "/") for path in paths):
        return False, "No plugin changes; no new release is required."
    if previous is not None and version_tuple(version) < version_tuple(previous):
        raise ReleaseError("The plugin version cannot decrease from main.")
    for tag in ("v" + version, version):
        reference = lookup("git/ref/tags/" + tag)
        release = lookup("releases/tags/" + tag)
        if reference is None and release is None:
            continue
        if (
            event == "push" and reference is not None and isinstance(release, dict)
            and release.get("draft") is False and release.get("prerelease") is False
            and complete_release(release, version) and tag_commit(reference, lookup) == head
        ):
            return False, "This commit/version is already published; nothing will be overwritten."
        raise ReleaseError("This version is already tagged or released. Approve a new version before merging plugin changes.")
    return event == "push", "Plugin changes have an unpublished stable version."


def check(root):
    event = os.environ.get("GITHUB_EVENT_NAME", "")
    target = os.environ.get("TARGET_BRANCH", "").removeprefix("refs/heads/")
    if event not in ("pull_request", "merge_group", "push") or target != "main":
        publish, version, message = False, "", "No main release gate applies to this event."
    else:
        base, head = os.environ.get("BASE_SHA", ""), os.environ.get("HEAD_SHA", "")
        paths = changed_paths(root, base, head, event)
        version = read_version(root)
        publish, message = release_plan(version, base_version(root, base), paths, event, head)
        publish = publish and os.environ.get("GITHUB_REPOSITORY") == REPOSITORY
    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as stream:
            stream.write(f"publish={str(publish).lower()}\nversion={version}\n")
    print(message)


def runtime_file(relative):
    parts = PurePosixPath(relative).parts
    if (
        not parts or len(relative) > 240 or "\\" in relative or ":" in relative
        or re.search(r"[\x00-\x1f]", relative) is not None
        or any(part.startswith(".") or part.rstrip(". ") != part for part in parts)
        or any(re.match(r"^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)", part, re.I) for part in parts)
        or any(part in ("tests", "fixtures", "scratch", "env", "vendor", "credentials") for part in parts)
        or parts[-1].startswith("test_")
    ):
        return False
    if len(parts) == 1:
        return relative in ("config.json", "council_sync_plugin.php", "council_sync_controller.php", "council_sync_model.php")
    return parts[0] in ("controllers", "models", "views", "language") and PurePosixPath(relative).suffix in (".php", ".pdt")


def changelog_notes(root, version):
    text = (root / "CHANGELOG.md").read_text(encoding="utf-8")
    heading = re.search(r"(?m)^## " + re.escape(version) + r"(?: - [^\r\n]+)?\r?$", text)
    if heading is None:
        raise ReleaseError("The changelog needs an entry for the approved package version.")
    remainder = text[heading.end():]
    end = re.search(r"(?m)^## ", remainder)
    return remainder[:end.start() if end else len(remainder)].strip() + "\n"


def package(root, output):
    version = read_version(root)
    directory = root / PLUGIN
    if directory.is_symlink() or not directory.is_dir():
        raise ReleaseError("The plugin directory must be a real directory.")
    files = {}
    seen = set()
    for path in sorted(directory.rglob("*")):
        if path.is_symlink():
            raise ReleaseError("The plugin source contains a symlink.")
        if path.is_dir():
            continue
        relative = path.relative_to(directory).as_posix()
        if not path.is_file() or not runtime_file(relative) or path.stat().st_size > 8388608:
            raise ReleaseError("Unexpected non-runtime file in the plugin directory.")
        if relative.lower() in seen:
            raise ReleaseError("The plugin contains case-colliding paths.")
        seen.add(relative.lower())
        files[relative] = path.read_bytes()
    if any(name not in files for name in REQUIRED):
        raise ReleaseError("The plugin is missing a required runtime entry point.")
    for name in DOCS:
        source = root / name
        if source.is_symlink() or not source.is_file() or source.stat().st_size > 8388608:
            raise ReleaseError("A required release document is missing or is a symlink.")
        content = source.read_bytes()
        if name == "README.md":
            text = content.decode("utf-8")
            if "Current package version: " + version + "." not in text:
                raise ReleaseError("The README package version must match the manifest.")
            text = text.replace("(./plugins/council_sync/config.json)", "(./config.json)").replace(
                "(./plugins/council_sync)", "(.)"
            )
            for linked in (".github/workflows/ci.yml", ".github/scripts/release.py", ".pre-commit-config.yaml", ".gitleaks.toml"):
                text = text.replace("(./" + linked + ")", "(https://github.com/" + REPOSITORY + "/blob/v" + version + "/" + linked + ")")
            content = text.encode("utf-8")
        files[name] = content
    changelog_notes(root, version)
    if len(files) > 2000 or any(len(content) > 8388608 for content in files.values()) or sum(map(len, files.values())) > 67108864:
        raise ReleaseError("The release exceeds the updater's archive limits.")
    output.mkdir(parents=True, exist_ok=True)
    archive = output / ("council-sync-" + version + ".zip")
    with zipfile.ZipFile(archive, "x", compression=zipfile.ZIP_DEFLATED) as zip_file:
        for relative, content in sorted(files.items()):
            info = zipfile.ZipInfo("council_sync/" + relative, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zip_file.writestr(info, content)
    if archive.stat().st_size > 16777216:
        raise ReleaseError("The ZIP exceeds the updater's download limit.")
    print(archive.name + " SHA256=" + hashlib.sha256(archive.read_bytes()).hexdigest())
    return archive


def publish(root, output):
    if (
        os.environ.get("GITHUB_EVENT_NAME") != "push" or os.environ.get("GITHUB_REF") != "refs/heads/main"
        or os.environ.get("GITHUB_REPOSITORY") != REPOSITORY or not os.environ.get("GH_TOKEN")
    ):
        raise ReleaseError("Publication requires the authorized main-push workflow.")
    version = read_version(root)
    head = os.environ.get("GITHUB_SHA", "")
    if not SHA.fullmatch(head):
        raise ReleaseError("Publication requires a complete main commit ID.")
    ready, message = release_plan(version, None, [PLUGIN + "/config.json"], "push", head)
    if not ready:
        print(message)
        return
    archive = output / ("council-sync-" + version + ".zip")
    if not archive.is_file():
        raise ReleaseError("The release ZIP was not built.")
    notes = output / ("council-sync-" + version + "-notes.txt")
    notes.write_text(changelog_notes(root, version), encoding="utf-8")
    tag = "v" + version
    subprocess.run([
        "gh", "api", "--method", "POST", "repos/" + REPOSITORY + "/git/refs",
        "-f", "ref=refs/tags/" + tag, "-f", "sha=" + head, "--silent",
    ], check=True)
    subprocess.run([
        "gh", "release", "create", tag, str(archive), "--repo", REPOSITORY,
        "--verify-tag", "--title", "Council Sync " + version, "--notes-file", str(notes), "--draft",
    ], check=True)
    subprocess.run(["gh", "release", "edit", tag, "--repo", REPOSITORY, "--draft=false", "--latest"], check=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=("check", "package", "publish"))
    parser.add_argument("--output-dir", type=Path)
    arguments = parser.parse_args()
    try:
        if arguments.command == "check":
            check(ROOT)
        elif arguments.output_dir is None:
            raise ReleaseError("--output-dir is required for packaging/publication.")
        elif arguments.command == "package":
            package(ROOT, arguments.output_dir)
        else:
            publish(ROOT, arguments.output_dir)
    except (ReleaseError, OSError, subprocess.CalledProcessError) as error:
        message = str(error) if isinstance(error, ReleaseError) else "Release operation failed; inspect the job and retain any draft/tag for operator recovery."
        print("ERROR: " + message, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
