"""Packaging contract: installable ZIP, no development/private files, fail closed."""
import importlib.util
import tempfile
import unittest
import zipfile
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "package_plugin.py"

class PackagingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "source"
        self.root.mkdir()
        self.output = Path(self.temp.name) / "output"
        self.put("temp-control-estimate-builder.php",
                 "<?php\n/**\n * Version: 0.4.9\n */\ndefine( 'TC_ESTIMATE_VERSION', '0.4.9' );")
        for name in ["includes/class-plugin.php", "app/dist/estimate-builder.js",
                     "app/dist/estimate-builder.css"]:
            self.put(name, "example")
        spec = importlib.util.spec_from_file_location("package_plugin", SCRIPT)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)

    def put(self, name, text):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def test_installable_zip_omits_private_and_development_files(self):
        for name in ["app/node_modules/private.txt", "tests/private.txt",
                     ".env", "assets/.env", "assets/price-list.xlsx",
                     "assets/backup.zip", "includes/secret.pem"]:
            self.put(name, "DO NOT SHIP")
        self.put("assets/logo.jpg", "image")
        result = self.module.build(self.root, self.output)
        with zipfile.ZipFile(result["zip"]) as archive:
            paths = archive.namelist()
            self.assertIn("temp-control-estimate-builder/temp-control-estimate-builder.php", paths)
            self.assertIn("temp-control-estimate-builder/assets/logo.jpg", paths)
            self.assertFalse(any("private" in p or ".env" in p or ".xlsx" in p or ".pem" in p or "backup.zip" in p for p in paths))
        self.assertEqual(result["version"], "0.4.9")

    def test_missing_bundle_blocks_package(self):
        (self.root / "app/dist/estimate-builder.js").unlink()
        with self.assertRaisesRegex(ValueError, "Required file"):
            self.module.build(self.root, self.output)

    def test_version_disagreement_blocks_package(self):
        self.put("temp-control-estimate-builder.php",
                 "<?php\n * Version: 0.4.9\ndefine('TC_ESTIMATE_VERSION', '0.4.8');")
        with self.assertRaisesRegex(ValueError, "versions disagree"):
            self.module.build(self.root, self.output)

    def test_symlink_cannot_copy_outside_source(self):
        outside = Path(self.temp.name) / "private.php"
        outside.write_text("private")
        (self.root / "includes/linked.php").symlink_to(outside)
        with self.assertRaisesRegex(ValueError, "Symlinks"):
            self.module.build(self.root, self.output)

    def test_output_cannot_overwrite_source(self):
        with self.assertRaisesRegex(ValueError, "outside"):
            self.module.build(self.root, self.root / "assets/output")

if __name__ == "__main__":
    unittest.main()
