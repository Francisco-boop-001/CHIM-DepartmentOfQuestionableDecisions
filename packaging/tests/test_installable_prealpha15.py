from __future__ import annotations

import copy
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "build-installable-prealpha15.py"
SPEC = importlib.util.spec_from_file_location("department_prealpha15", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("cannot load prealpha.15 package builder")
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class CompatibilityReceiptTests(unittest.TestCase):
    def setUp(self) -> None:
        self.commit = "a" * 40
        self.blobs = {
            BUILDER.DEPARTMENT_ADAPTER: (BUILDER.ROOT / BUILDER.DEPARTMENT_ADAPTER).read_bytes(),
            BUILDER.DEPARTMENT_PROTOCOL: (BUILDER.ROOT / BUILDER.DEPARTMENT_PROTOCOL).read_bytes(),
        }
        self.receipt = {
            "schemaVersion": 1,
            "sourceCommit": self.commit,
            "ownerVersion": BUILDER.OWNER_VERSION,
            "ownerCommit": BUILDER.OWNER_COMMIT,
            "ownerClosurePins": BUILDER._literal_owner_closure_pins(self.blobs[BUILDER.DEPARTMENT_ADAPTER]),
            "adapterSha256": BUILDER.BASE.sha256(self.blobs[BUILDER.DEPARTMENT_ADAPTER]),
            "protocolSha256": BUILDER.BASE.sha256(self.blobs[BUILDER.DEPARTMENT_PROTOCOL]),
            "checks": [
                {"name": name, "status": "passed", "receiptSha256": "3" * 64}
                for name in sorted(BUILDER.CHECK_NAMES)
            ],
            "limits": dict(BUILDER.PROOF_LIMITS),
        }

    def _validate(self, receipt: dict | None = None) -> None:
        data = json.dumps(receipt if receipt is not None else self.receipt).encode("utf-8")
        with patch.object(
            BUILDER.BASE,
            "git_blob",
            side_effect=lambda _git, _commit, path: self.blobs[path],
        ):
            BUILDER._validate_compatibility_receipt("git", self.commit, data)

    def test_accepts_source_bound_focused_receipt(self) -> None:
        self._validate()

    def test_rejects_wrong_source_owner_check_or_scope(self) -> None:
        cases = []

        wrong_source = copy.deepcopy(self.receipt)
        wrong_source["sourceCommit"] = "b" * 40
        cases.append(wrong_source)

        wrong_owner = copy.deepcopy(self.receipt)
        wrong_owner["ownerVersion"] = "0.1.0-dev.23"
        cases.append(wrong_owner)

        missing_pin = copy.deepcopy(self.receipt)
        missing_pin["ownerClosurePins"].pop("manifest.json")
        cases.append(missing_pin)

        failed_check = copy.deepcopy(self.receipt)
        failed_check["checks"][0]["status"] = "failed"
        cases.append(failed_check)

        missing_check = copy.deepcopy(self.receipt)
        missing_check["checks"].pop()
        cases.append(missing_check)

        broad_scope = copy.deepcopy(self.receipt)
        broad_scope["limits"]["database"] = True
        cases.append(broad_scope)

        for receipt in cases:
            with self.subTest(receipt=receipt):
                with self.assertRaises(BUILDER.PackageError):
                    self._validate(receipt)

    def test_rejects_adapter_hash_that_does_not_match_source_commit(self) -> None:
        receipt = copy.deepcopy(self.receipt)
        receipt["adapterSha256"] = "4" * 64
        with self.assertRaisesRegex(BUILDER.PackageError, "differs from the selected source commit"):
            self._validate(receipt)

    def test_rejects_a_wrong_existing_owner_pin(self) -> None:
        receipt = copy.deepcopy(self.receipt)
        receipt["ownerClosurePins"]["lib/runtime.php"] = "4" * 64
        with self.assertRaisesRegex(BUILDER.PackageError, "differ from the selected dev.24 adapter map"):
            self._validate(receipt)


class PackageDeltaTests(unittest.TestCase):
    def test_only_the_department_dwpkg_member_is_replaced(self) -> None:
        baseline = {path: path.encode("utf-8") for path in BUILDER.BASELINE_MO2_MEMBERS}
        baseline[BUILDER.BASELINE_DPKG_MEMBER] = b"prealpha.14 Department DWPkg"
        result = BUILDER._assemble_mod(baseline, b"prealpha.15 Department DWPkg")

        self.assertEqual(set(result) - set(baseline), {BUILDER.DPKG_MEMBER})
        self.assertEqual(set(baseline) - set(result), {BUILDER.BASELINE_DPKG_MEMBER})
        self.assertEqual(
            {path for path in set(baseline) & set(result) if baseline[path] != result[path]},
            set(),
        )

    def test_refuses_an_unchanged_department_package(self) -> None:
        baseline = {path: path.encode("utf-8") for path in BUILDER.BASELINE_MO2_MEMBERS}
        baseline[BUILDER.BASELINE_DPKG_MEMBER] = b"same package"
        with self.assertRaisesRegex(BUILDER.PackageError, "DWPkg did not change"):
            BUILDER._assemble_mod(baseline, b"same package")

    def test_dpkg_delta_is_limited_to_version_adapter_and_checksums(self) -> None:
        old_outer = {
            "schema_version": 4,
            "name": "questionable_decisions",
            "version": "0.1.0-prealpha.13",
            "server": {"mutable_paths": []},
        }
        old_inner = {
            "name": "questionable_decisions",
            "version": "0.1.0-prealpha.13",
            "schema_version": 2,
            "config_url": "../ext/questionable_decisions/index.php",
        }
        old = {
            "manifest.json": BUILDER.BASE.json_bytes(old_outer),
            "server/manifest.json": BUILDER.BASE.json_bytes(old_inner),
            BUILDER.DEPARTMENT_ADAPTER: b"old adapter",
            "checksums.sha256": b"old checksums",
            "server/runtime.php": b"unchanged runtime",
        }
        current = dict(old)
        new_outer = dict(old_outer, version=BUILDER.VERSION)
        new_inner = dict(old_inner, version=BUILDER.VERSION)
        current["manifest.json"] = BUILDER.BASE.json_bytes(new_outer)
        current["server/manifest.json"] = BUILDER.BASE.json_bytes(new_inner)
        current[BUILDER.DEPARTMENT_ADAPTER] = b"new adapter"
        current["checksums.sha256"] = b"new checksums"

        BUILDER._compare_dpkg_update(old, current)
        current["server/runtime.php"] = b"unexpected runtime change"
        with self.assertRaisesRegex(BUILDER.PackageError, "must change only"):
            BUILDER._compare_dpkg_update(old, current)

    def test_current_department_component_preflight_accepts_only_prealpha15(self) -> None:
        files = {
            path: f"fixture:{path}".encode("utf-8")
            for path in BUILDER.LEGACY.QD_COMPONENT_SERVER_MEMBERS
        }
        files["manifest.json"] = BUILDER.BASE.json_bytes({
            "schema_version": 4,
            "name": "questionable_decisions",
            "version": BUILDER.VERSION,
            "server": {"mutable_paths": []},
        })
        files["server/manifest.json"] = BUILDER.BASE.json_bytes({
            "name": "questionable_decisions",
            "version": BUILDER.VERSION,
            "schema_version": 2,
            "config_url": "../ext/questionable_decisions/index.php",
        })
        payload = dict(files)
        files["checksums.sha256"] = BUILDER.BASE.make_checksums(payload)
        package = BUILDER.BASE.stable_zip_bytes(files)

        verified = BUILDER.LEGACY._verify_dpkg(package, BUILDER.VERSION)
        self.assertEqual(verified["server/manifest.json"], files["server/manifest.json"])
        with self.assertRaisesRegex(BUILDER.PackageError, "identity or schema differs"):
            BUILDER.LEGACY._verify_dpkg(package, "0.1.0-prealpha.13")

    def test_rejects_unverified_baseline_bytes(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "baseline.zip"
            path.write_bytes(b"not the pinned prealpha.14 archive")
            with self.assertRaisesRegex(BUILDER.PackageError, "exact verified prealpha.14"):
                BUILDER._read_baseline(path)


if __name__ == "__main__":
    unittest.main()
