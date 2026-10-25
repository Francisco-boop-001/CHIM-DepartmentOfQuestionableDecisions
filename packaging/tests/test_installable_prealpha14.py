from __future__ import annotations

import importlib.util
import hashlib
import json
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "build-installable-prealpha14.py"
SPEC = importlib.util.spec_from_file_location("department_prealpha14", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("cannot load prealpha.14 package builder")
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class MinimalPackageDeltaTests(unittest.TestCase):
    def setUp(self) -> None:
        self.baseline = {name: name.encode("utf-8") for name in BUILDER.BASELINE_MO2_MEMBERS}
        self.baseline[BUILDER.DLL_MEMBER] = b"prealpha.13 DLL"
        self.baseline[BUILDER.APP_JS_MEMBER] = b"prealpha.13 app.js"
        self.baseline[BUILDER.INDEX_HTML_MEMBER] = b"prealpha.13 index.html"

    def test_only_reviewed_dll_and_ui_members_can_change(self) -> None:
        result = BUILDER._assembled_mod(
            self.baseline, b"reviewed new DLL", b"new app.js", b"new index.html"
        )
        changed = {path for path in self.baseline if result[path] != self.baseline[path]}
        self.assertEqual(changed, BUILDER.UPDATED_MEMBERS)
        for member in BUILDER.SERVER_PACKAGE_MEMBERS:
            self.assertEqual(result[member], self.baseline[member])

    def test_unchanged_native_or_ui_member_is_refused(self) -> None:
        with self.assertRaises(BUILDER.PackageError):
            BUILDER._assembled_mod(self.baseline, self.baseline[BUILDER.DLL_MEMBER], b"new app.js", b"new index.html")
        with self.assertRaises(BUILDER.PackageError):
            BUILDER._assembled_mod(self.baseline, b"new DLL", self.baseline[BUILDER.APP_JS_MEMBER], b"new index.html")
        with self.assertRaises(BUILDER.PackageError):
            BUILDER._assembled_mod(self.baseline, b"new DLL", b"new app.js", self.baseline[BUILDER.INDEX_HTML_MEMBER])


class ReceiptBindingTests(unittest.TestCase):
    def setUp(self) -> None:
        self.git = "git"
        self.commit = "a" * 40
        self.dll = b"reviewed DLL bytes"
        self.server_files = {path: b"unchanged " + path.encode() for path in BUILDER.SERVER_PACKAGE_MEMBERS}
        self.blobs = {path: hashlib.sha1(path.encode()).hexdigest() for path in BUILDER.NATIVE_SOURCE_PATHS}
        self.build = {
            "schemaVersion": 1,
            "status": "native-plugin-and-host-tests-built",
            "plugin": {
                "name": "WorldOfDramaLlama.dll",
                "bytes": len(self.dll),
                "sha256": BUILDER.BASE.sha256(self.dll),
            },
            "hostTests": {
                "count": 5,
                "result": "100% tests passed, 0 tests failed out of 5",
            },
        }
        self.build_bytes = self._json(self.build)
        self.binding_bytes = self._json({
            "schemaVersion": 1,
            "sourceCommit": self.commit,
            "dllSha256": BUILDER.BASE.sha256(self.dll),
            "sourceBlobs": self.blobs,
        })
        self.checks_bytes = self._json({
            "schemaVersion": 1,
            "sourceCommit": self.commit,
            "checks": [
                {"name": name, "status": status,
                 "receiptSha256": self._check_hash(name)}
                for name, status in BUILDER.STARTUP_CHECK_STATUSES.items()
            ],
        })

    @staticmethod
    def _json(value: dict) -> bytes:
        return json.dumps(value, sort_keys=True, separators=(",", ":")).encode()

    def _check_hash(self, name: str) -> str | None:
        if name == "skyrim-runtime":
            return None
        if name in {"native-build", "native-host-tests"}:
            return BUILDER.BASE.sha256(self.build_bytes)
        if name == "server-components":
            return BUILDER._server_bundle_hash(self.server_files)
        return "b" * 64

    def _validate(self, build: dict | None = None, binding: bytes | None = None) -> None:
        build_bytes = self._json(build if build is not None else self.build)
        with patch.object(BUILDER, "_expected_native_blobs", return_value=self.blobs):
            BUILDER._validate_receipts(
                self.git, self.commit, self.dll, build_bytes,
                binding if binding is not None else self.binding_bytes,
                self.checks_bytes, self.server_files,
            )

    def test_rejects_five_host_targets_with_failed_result(self) -> None:
        failed = {**self.build, "hostTests": {"count": 5, "result": "0% tests passed, 5 tests failed out of 5"}}
        with self.assertRaisesRegex(BUILDER.PackageError, "five passing host-test targets"):
            self._validate(failed)

    def test_rejects_dll_that_does_not_match_build_receipt(self) -> None:
        changed_plugin = {**self.build["plugin"], "sha256": "c" * 64}
        mismatched = {**self.build, "plugin": changed_plugin}
        with self.assertRaisesRegex(BUILDER.PackageError, "five passing host-test targets"):
            self._validate(mismatched)

    def test_rejects_native_source_blob_mismatch(self) -> None:
        changed_blobs = dict(self.blobs)
        changed_blobs["native/src/Plugin.cpp"] = "d" * 40
        binding = self._json({
            "schemaVersion": 1,
            "sourceCommit": self.commit,
            "dllSha256": BUILDER.BASE.sha256(self.dll),
            "sourceBlobs": changed_blobs,
        })
        with self.assertRaisesRegex(BUILDER.PackageError, "current Git blob"):
            self._validate(binding=binding)


if __name__ == "__main__":
    unittest.main()
