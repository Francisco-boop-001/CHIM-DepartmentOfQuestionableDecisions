#!/usr/bin/env python3
"""Build and audit the installable prealpha.9 MO2 archive from pinned inputs."""

from __future__ import annotations

import importlib.util
from pathlib import Path


VERSION = "0.1.0-prealpha.9"
ROOT = Path(__file__).resolve().parents[1]
OUTPUT_MOD_ZIP = f"WorldOfDramaLlama-{VERSION}.zip"
OUTPUT_SOURCE_ZIP = f"WorldOfDramaLlama-{VERSION}-source.zip"
COMPONENT_DIR = "component-packages"
CHECKSUMS_NAME = "SHA256SUMS.txt"
PUBLIC_CHECKSUM_NAMES = {OUTPUT_MOD_ZIP, OUTPUT_SOURCE_ZIP}
PREALPHA8_DLL_SHA256 = "8c65d467cc3a733af204bb529f31ac5dec94b04ed4889f098069ad1b27bcb32f"
ZIP_TIMESTAMP = (2026, 10, 24, 0, 0, 0)

_BASE_PATH = Path(__file__).with_name("build-installable-prealpha8.py")
_BASE_SPEC = importlib.util.spec_from_file_location("_department_installable_prealpha8", _BASE_PATH)
if _BASE_SPEC is None or _BASE_SPEC.loader is None:
    raise RuntimeError("could not load the prealpha.8 packaging helper")
BASE_BUILDER = importlib.util.module_from_spec(_BASE_SPEC)
_BASE_SPEC.loader.exec_module(BASE_BUILDER)
_BASE_VALIDATE_DLL = BASE_BUILDER.validate_dll

QD_SERVER_SOURCES = (*BASE_BUILDER.QD_SERVER_SOURCES, "server/preprocessing.php")
PACKAGE_MANIFESTS = {
    **BASE_BUILDER.PACKAGE_MANIFESTS,
    "questionable_decisions": {
        **BASE_BUILDER.PACKAGE_MANIFESTS["questionable_decisions"],
        "sources": QD_SERVER_SOURCES,
    },
}
MO2_COMPONENT_PATHS = {
    name: f"CHIM/server-plugins/{name}/{VERSION}.dwpkg"
    for name in PACKAGE_MANIFESTS
}
MO2_FIXED_MEMBERS = {
    *BASE_BUILDER.UI_ASSETS.values(),
    BASE_BUILDER.DLL_MEMBER,
    BASE_BUILDER.INI_MEMBER,
    *(target for target, _ in BASE_BUILDER.REUSED_GAME_ASSETS.values()),
    *(f"NOTICE/{name}" for name in BASE_BUILDER.NOTICE_FILES),
    *MO2_COMPONENT_PATHS.values(),
}
COMPONENT_FILENAMES = {
    name: f"{name}-{VERSION}.dwpkg" for name in PACKAGE_MANIFESTS
}
REQUIRED_SOURCE_PATHS = {
    "packaging/build-installable-prealpha8.py",
    "packaging/tests/test_installable_prealpha8.py",
    "packaging/build-installable-prealpha9.py",
    "packaging/tests/test_installable_prealpha9.py",
    "README.md",
    "packaging/COMPANION-ONLY-TEST-PACKAGE.md",
    "pcv-companion/manifest.json",
    *QD_SERVER_SOURCES,
    *BASE_BUILDER.PCV_COMPANION_SOURCES,
    "server/tests/onboarding/bootstrap-overlay.php",
    "server/tests/onboarding/connect.php",
    "server/tests/onboarding/core-package-install.php",
    "server/tests/onboarding/emit-connect-fixtures.php",
    "server/tests/onboarding/stock-ingress.php",
    BASE_BUILDER.PCV_SOURCE_MANIFEST,
    BASE_BUILDER.INI_SOURCE,
    *(f"{BASE_BUILDER.NOTICE_DIR}/{name}" for name in BASE_BUILDER.NOTICE_FILES),
    *BASE_BUILDER.UI_ASSETS,
}

BASE_BUILDER.__doc__ = __doc__
BASE_BUILDER.VERSION = VERSION
BASE_BUILDER.ROOT = ROOT
BASE_BUILDER.OUTPUT_MOD_ZIP = OUTPUT_MOD_ZIP
BASE_BUILDER.OUTPUT_SOURCE_ZIP = OUTPUT_SOURCE_ZIP
BASE_BUILDER.COMPONENT_DIR = COMPONENT_DIR
BASE_BUILDER.CHECKSUMS_NAME = CHECKSUMS_NAME
BASE_BUILDER.PUBLIC_CHECKSUM_NAMES = PUBLIC_CHECKSUM_NAMES
BASE_BUILDER.QD_SERVER_SOURCES = QD_SERVER_SOURCES
BASE_BUILDER.PACKAGE_MANIFESTS = PACKAGE_MANIFESTS
BASE_BUILDER.MO2_COMPONENT_PATHS = MO2_COMPONENT_PATHS
BASE_BUILDER.MO2_FIXED_MEMBERS = MO2_FIXED_MEMBERS
BASE_BUILDER.COMPONENT_FILENAMES = COMPONENT_FILENAMES
BASE_BUILDER.REQUIRED_SOURCE_PATHS = REQUIRED_SOURCE_PATHS
BASE_BUILDER.ZIP_TIMESTAMP = ZIP_TIMESTAMP


def validate_dll(data: bytes) -> None:
    try:
        _BASE_VALIDATE_DLL(data)
    except BASE_BUILDER.PackageError as error:
        if BASE_BUILDER.sha256(data) == BASE_BUILDER.OLD_DLL_SHA256:
            raise BASE_BUILDER.PackageError("refusing the reused prealpha.7 DLL; pass the reviewed prealpha.9 build") from error
        raise
    if BASE_BUILDER.sha256(data) == PREALPHA8_DLL_SHA256:
        raise BASE_BUILDER.PackageError("refusing the reused prealpha.8 DLL; pass the reviewed prealpha.9 build")


BASE_BUILDER.validate_dll = validate_dll


def __getattr__(name: str):
    return getattr(BASE_BUILDER, name)


if __name__ == "__main__":
    raise SystemExit(BASE_BUILDER.main())
