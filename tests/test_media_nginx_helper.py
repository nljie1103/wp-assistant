"""Isolated tests for optional, fail-closed Baota static-asset caching helper."""
import contextlib
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HELPER = Path(__file__).resolve().parents[1] / "ops" / "jlwa-media-nginx-helper.py"
spec = importlib.util.spec_from_file_location("jlwa_media_nginx", HELPER)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

PROXY = """server {
    listen 443 ssl;
    server_name proxy.example.com;
    location / {
        proxy_pass https://origin.example.com;
        proxy_set_header Host origin.example.com;
    }
}
"""
PHP = """server {
    listen 443 ssl;
    server_name proxy.example.com;
    root /www/wordpress;
    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \\.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; }
}
"""


class MediaNginxHelperTests(unittest.TestCase):
    def test_origin_must_differ_from_target(self):
        with self.assertRaises(ValueError):
            mod.valid_origin("https://proxy.example.com", "proxy.example.com")
        with self.assertRaises(ValueError):
            mod.valid_origin("https://origin.example.com/secret", "proxy.example.com")
        with self.assertRaises(ValueError):
            mod.valid_origin("https://user:pass@origin.example.com", "proxy.example.com")

    def test_static_only_cache_never_caches_private_or_authenticated_paths(self):
        plan = mod.proposed(PROXY, "proxy.example.com", "https://origin.example.com")
        self.assertIn("proxy_cache_path", plan)
        self.assertIn("wp-content/(themes|plugins|uploads)", plan)
        self.assertIn("proxy_cache_bypass $http_authorization $http_cookie $args", plan)
        self.assertIn("proxy_no_cache $http_authorization $http_cookie $args", plan)
        self.assertNotIn("location / {\\n        proxy_cache", plan)
        self.assertNotIn("pay-download", plan)
        self.assertIn("server_name proxy.example.com;", plan)
        self.assertIn("    location / {\n        proxy_pass https://origin.example.com;", plan)

    def test_refuse_php_or_complex_or_existing_cache(self):
        with self.assertRaisesRegex(ValueError, "PHP"):
            mod.proposed(PHP, "proxy.example.com", "https://origin.example.com")
        with self.assertRaises(ValueError):
            mod.proposed(PROXY.replace("location / {", "location ~* \\.css$ {}\\n    location / {"), "proxy.example.com", "https://origin.example.com")
        with self.assertRaises(ValueError):
            mod.proposed(PROXY.replace("proxy_pass", "proxy_cache zone;\\n        proxy_pass"), "proxy.example.com", "https://origin.example.com")
        with self.assertRaises(ValueError):
            mod.proposed(PROXY.replace("origin.example.com", "other.example.com"), "proxy.example.com", "https://origin.example.com")
        with self.assertRaises(ValueError):
            mod.proposed(PROXY.replace("proxy.example.com;", "proxy.example.com origin.example.com;"), "proxy.example.com", "https://origin.example.com")

    def test_preview_is_read_only_and_discovery_is_unique(self):
        with tempfile.TemporaryDirectory() as temp:
            vhosts = Path(temp) / "vhosts"
            vhosts.mkdir()
            site = vhosts / "proxy.example.com.conf"
            site.write_text(PROXY)
            state = Path(temp) / "state"
            with patch.object(mod, "VHOSTS", vhosts), patch.object(mod, "STATE", state):
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    mod.inspect("proxy.example.com", "https://origin.example.com", "preview")
                self.assertIn("proxy_cache_path", output.getvalue())
                self.assertEqual(PROXY, site.read_text())
                self.assertFalse(state.exists())
                (vhosts / "duplicate.conf").write_text(PROXY)
                with self.assertRaises(ValueError):
                    mod.discover("proxy.example.com")

    def test_apply_creates_backup_and_rolls_back_exactly(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            vhosts = root / "vhosts"
            vhosts.mkdir()
            site = vhosts / "proxy.conf"
            site.write_text(PROXY)
            with patch.object(mod, "VHOSTS", vhosts), patch.object(mod, "STATE", root / "state"), patch.object(mod, "safe_cache_dir"), patch.object(mod.os, "geteuid", return_value=0), patch.object(mod, "run_nginx") as nginx:
                with contextlib.redirect_stdout(io.StringIO()):
                    mod.inspect("proxy.example.com", "https://origin.example.com", "apply")
                self.assertIn(mod.START, site.read_text())
                self.assertTrue((root / "state" / mod.managed_id("proxy.example.com") / "original.conf").is_file())
                self.assertEqual(["test", "reload"], [call.args[0] for call in nginx.call_args_list])
                with contextlib.redirect_stdout(io.StringIO()):
                    mod.rollback("proxy.example.com")
                self.assertEqual(PROXY, site.read_text())
                self.assertFalse((root / "state" / mod.managed_id("proxy.example.com")).exists())

    def test_nginx_syntax_error_auto_recovers_original(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            vhosts = root / "vhosts"
            vhosts.mkdir()
            site = vhosts / "proxy.conf"
            site.write_text(PROXY)
            with patch.object(mod, "VHOSTS", vhosts), patch.object(mod, "STATE", root / "state"), patch.object(mod, "safe_cache_dir"), patch.object(mod.os, "geteuid", return_value=0), patch.object(mod, "run_nginx", side_effect=[ValueError("bad syntax"), None]):
                with self.assertRaises(ValueError):
                    mod.inspect("proxy.example.com", "https://origin.example.com", "apply")
                self.assertEqual(PROXY, site.read_text())
                self.assertFalse((root / "state" / mod.managed_id("proxy.example.com")).exists())

    def test_rollback_refuses_modified_managed_config(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            vhosts = root / "vhosts"
            vhosts.mkdir()
            site = vhosts / "proxy.conf"
            site.write_text(PROXY)
            with patch.object(mod, "VHOSTS", vhosts), patch.object(mod, "STATE", root / "state"), patch.object(mod, "safe_cache_dir"), patch.object(mod.os, "geteuid", return_value=0), patch.object(mod, "run_nginx"):
                with contextlib.redirect_stdout(io.StringIO()):
                    mod.inspect("proxy.example.com", "https://origin.example.com", "apply")
                site.write_text(site.read_text() + "\n# modified by admin\n")
                with self.assertRaisesRegex(ValueError, "modified"):
                    mod.rollback("proxy.example.com")

    def test_reload_invocation(self):
        class Result:
            returncode = 0
            stdout = ""
        with patch.object(mod.shutil, "which", return_value="/bin/nginx"), patch.object(mod.subprocess, "run", return_value=Result()) as run:
            mod.run_nginx("reload")
            self.assertEqual(["/bin/nginx", "-s", "reload"], run.call_args.args[0])
            mod.run_nginx("test")
            self.assertEqual(["/bin/nginx", "-t"], run.call_args.args[0])


if __name__ == "__main__":
    unittest.main()
