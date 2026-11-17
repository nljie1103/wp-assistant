import importlib.util
from pathlib import Path
import unittest
import tempfile
from unittest.mock import patch

source = Path(__file__).resolve().parents[1] / "ops" / "jlwa-nginx-helper.py"
spec = importlib.util.spec_from_file_location("jlwa_helper", source)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

SETTINGS = {
    "wordpress_vhost": "/www/server/panel/vhost/nginx/site.example.com.conf",
    "openlist_vhost": "/www/server/panel/vhost/nginx/files.example.com.conf",
    "host": "files.example.com", "port": 5244, "source": "/d/storage/private/",
}

WP_VHOST = "server {\n    listen 443 ssl;\n    server_name site.example.com;\n    location / { try_files $uri $uri/ /index.php?$args; }\n}\n"
OPENLIST_VHOST = "server {\n    listen 443 ssl;\n    server_name files.example.com files-cdn.example.com;\n    location ^~ / { proxy_pass http://127.0.0.1:5244; }\n}\n"


class NginxHelperTests(unittest.TestCase):
    def test_one_wordpress_internal_and_origin_block(self):
        text = mod.transform(WP_VHOST, "wp", SETTINGS)
        self.assertIn("internal;", text)
        self.assertIn("proxy_pass http://127.0.0.1:5244/d/storage/private/;", text)
        self.assertIn("proxy_hide_header Location;", text)
        self.assertEqual(text, mod.transform(text, "wp", SETTINGS))
        self.assertIn("location /", text)

        origin = mod.transform(OPENLIST_VHOST, "openlist", SETTINGS)
        self.assertIn("location ^~ /d/storage/private/ { return 403; }", origin)
        self.assertIn("location ^~ /p/storage/private/ { return 403; }", origin)
        self.assertEqual(origin.count("JLWA-MANAGED-DOWNLOAD"), 2)
        self.assertEqual(origin, mod.transform(origin, "openlist", SETTINGS))
        self.assertIn("files-cdn.example.com", origin)

    def test_reject_missing_host_duplicate_server_and_edited_marker(self):
        with self.assertRaises(ValueError):
            mod.transform(WP_VHOST + WP_VHOST, "wp", SETTINGS)
        with self.assertRaises(ValueError):
            mod.transform(OPENLIST_VHOST.replace("files.example.com", "not-files.example.com"), "openlist", SETTINGS)
        tampered = mod.transform(OPENLIST_VHOST, "openlist", SETTINGS).replace("403;", "200;")
        with self.assertRaises(ValueError):
            mod.transform(tampered, "openlist", SETTINGS)

    def test_bad_storage_prefix_is_rejected(self):
        for value in ("", "/d/../private/", "/d/private", "/p/private/", "/d/storage/foo%2fbar/", "/d/storage/foo//", "/d/storage/;deny all;/"):
            with self.assertRaises(ValueError, msg=value):
                mod.validate_source(value)

    def test_backup_then_rollback_restores_bytes(self):
        with tempfile.TemporaryDirectory() as root:
            original_state = mod.STATE
            mod.STATE = Path(root) / "state"
            site = Path(root) / "site.conf"
            site.write_text(WP_VHOST)
            conf = dict(SETTINGS, wordpress_vhost=str(site))
            state = {"phase": "new", "backups": {}, "after": {}}
            try:
                with patch.object(mod, "run_nginx", return_value=None):
                    self.assertTrue(mod.mutate("wp", conf, state))
                    self.assertIn("JLWA-MANAGED-DOWNLOAD", site.read_text())
                    self.assertTrue(state["backups"]["wp"])
                    mod.rollback(conf, state)
                    self.assertEqual(WP_VHOST, site.read_text())
                    self.assertEqual("rolled_back", state["phase"])
            finally:
                mod.STATE = original_state

    def test_nginx_check_failure_recovers_original_file(self):
        with tempfile.TemporaryDirectory() as root:
            original_state = mod.STATE
            mod.STATE = Path(root) / "state"
            site = Path(root) / "site.conf"
            site.write_text(WP_VHOST)
            conf = dict(SETTINGS, wordpress_vhost=str(site))
            state = {"phase": "new", "backups": {}, "after": {}}
            try:
                with patch.object(mod, "run_nginx", side_effect=[ValueError("bad syntax"), None, None]):
                    with self.assertRaises(ValueError):
                        mod.mutate("wp", conf, state)
                self.assertEqual(WP_VHOST, site.read_text())
                self.assertFalse(state["after"])
            finally:
                mod.STATE = original_state

    def test_no_real_host_or_IP_hardcoded(self):
        code = source.read_text()
        for needle in ("private-host.invalid", "unexpected.example.net", "198.51.100.9"):
            self.assertNotIn(needle, code)


if __name__ == "__main__":
    unittest.main()
