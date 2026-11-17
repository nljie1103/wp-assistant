import importlib.util
import sys
import json
import contextlib
import io
from pathlib import Path
import unittest
import tempfile
from types import SimpleNamespace
from contextlib import nullcontext
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

    def test_automatic_vhost_discovery_uses_exact_server_name(self):
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            (directory / "site.conf").write_text(WP_VHOST)
            (directory / "files.conf").write_text(OPENLIST_VHOST)
            with patch.object(mod, "VHOST_DIR", directory), patch.object(mod, "validate_vhost", side_effect=lambda value: value):
                self.assertEqual(str(directory / "site.conf"), mod.discover_vhost("site.example.com"))
                self.assertEqual(str(directory / "files.conf"), mod.discover_vhost("files-cdn.example.com"))
                with self.assertRaises(ValueError):
                    mod.discover_vhost("fake-site.example.com")
                (directory / "duplicate.conf").write_text(OPENLIST_VHOST)
                with self.assertRaises(ValueError):
                    mod.discover_vhost("files.example.com")

    def test_port_must_be_loopback_and_reachable(self):
        with patch.object(mod.socket, "create_connection", return_value=nullcontext()), patch.object(mod.shutil, "which", return_value="/usr/bin/ss"):
            with patch.object(mod.subprocess, "run", return_value=SimpleNamespace(stdout="LISTEN 0 1024 127.0.0.1:5244 0.0.0.0:*\n")):
                mod.verify_local_only_port(5244)
            with patch.object(mod.subprocess, "run", return_value=SimpleNamespace(stdout="LISTEN 0 1024 0.0.0.0:5244 0.0.0.0:*\n")):
                with self.assertRaises(ValueError):
                    mod.verify_local_only_port(5244)
        with patch.object(mod.socket, "create_connection", side_effect=ConnectionRefusedError):
            with self.assertRaises(ValueError):
                mod.verify_local_only_port(5244)

    def test_docker_port_detection_and_public_port_rejection(self):
        def run(args, **kwargs):
            if args[1] == "ps":
                return SimpleNamespace(stdout="container123\n")
            return SimpleNamespace(stdout='{"5244/tcp":[{"HostIp":"127.0.0.1","HostPort":"5244"}]}')
        with patch.object(mod.shutil, "which", return_value="/usr/bin/docker"), patch.object(mod.subprocess, "run", side_effect=run):
            self.assertEqual("container123", mod.detect_docker_container(5244))
        def named_container(args, **kwargs):
            if args[1] == "ps":
                return SimpleNamespace(stdout="container123\n")
            if args[1] == "inspect" and args[2] == "--format" and args[3] == "{{.Id}}":
                return SimpleNamespace(stdout="container123-extra-long-id\n")
            return SimpleNamespace(stdout='{"5244/tcp":[{"HostIp":"127.0.0.1","HostPort":"5244"}]}')
        with patch.object(mod.shutil, "which", return_value="/usr/bin/docker"), patch.object(mod.subprocess, "run", side_effect=named_container):
            self.assertEqual("container123", mod.detect_docker_container(5244, "my-openlist"))
        def exposed(args, **kwargs):
            if args[1] == "ps":
                return SimpleNamespace(stdout="container123\n")
            return SimpleNamespace(stdout='{"5244/tcp":[{"HostIp":"0.0.0.0","HostPort":"5244"}]}')
        with patch.object(mod.shutil, "which", return_value="/usr/bin/docker"), patch.object(mod.subprocess, "run", side_effect=exposed):
            with self.assertRaises(ValueError):
                mod.detect_docker_container(5244)

    def test_install_auto_exact_cli_performs_complete_install_without_nginx_writes(self):
        """Regresses the missing args.host AttributeError in real argparse -> installer."""
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            vhosts = directory / "vhosts"
            vhosts.mkdir()
            wordpress_file = vhosts / "site.example.com.conf"
            openlist_file = vhosts / "files.example.com.conf"
            wordpress_file.write_text(WP_VHOST)
            openlist_file.write_text(OPENLIST_VHOST)
            config = directory / "etc" / "approved.json"
            target = directory / "bin" / "jlwa-nginx-helper"
            sudoers = directory / "sudoers" / "jlwa-nginx-helper"
            target.parent.mkdir()
            sudoers.parent.mkdir()
            state = directory / "state"
            cmd = [
                "jlwa-nginx-helper.py", "install-auto",
                "--wordpress-host", "site.example.com",
                "--openlist-host", "files.example.com",
                "--port", "5245",
                "--source", "/d/google/付费资源/",
                "--php-user", "www",
            ]
            with (
                patch.object(mod, "VHOST_DIR", vhosts),
                patch.object(mod, "CONFIG", config),
                patch.object(mod, "TARGET", target),
                patch.object(mod, "SUDOERS", sudoers),
                patch.object(mod, "STATE", state),
                patch.object(mod, "validate_vhost", side_effect=lambda value: value),
                patch.object(mod.os, "geteuid", return_value=0),
                patch("pwd.getpwnam", return_value=SimpleNamespace(pw_uid=33)),
                patch.object(mod, "run_nginx") as nginx,
                patch.object(mod, "verify_local_only_port") as verify,
                patch.object(mod, "detect_docker_container", return_value="abc123") as docker,
                patch.object(mod.shutil, "which", return_value=None),
                patch.object(sys, "argv", cmd),
                contextlib.redirect_stdout(io.StringIO()) as output,
            ):
                mod.main()
            manifest = json.loads(config.read_text())
            self.assertEqual("files.example.com", manifest["host"])
            self.assertEqual("/d/google/付费资源/", manifest["source"])
            self.assertEqual(5245, manifest["port"])
            self.assertTrue(manifest["docker_container_detected"])
            self.assertEqual(str(wordpress_file), manifest["wordpress_vhost"])
            self.assertEqual(str(openlist_file), manifest["openlist_vhost"])
            self.assertTrue(target.is_file())
            self.assertIn("NOPASSWD: JLWA_NGINX", sudoers.read_text())
            self.assertIn("installed;", output.getvalue())
            self.assertEqual(WP_VHOST, wordpress_file.read_text())
            self.assertEqual(OPENLIST_VHOST, openlist_file.read_text())
            nginx.assert_called_once_with(check=True)
            verify.assert_called_once_with(5245)
            docker.assert_called_once_with(5245, "")

    def test_install_auto_preflight_failure_writes_nothing(self):
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            cmd = [
                "helper", "install-auto", "--wordpress-host", "site.example.com",
                "--openlist-host", "files.example.com", "--port", "5245",
                "--source", "/d/google/付费资源/", "--php-user", "www",
            ]
            paths = (directory / "approved.json", directory / "helper", directory / "sudoers", directory / "state")
            with (
                patch.object(mod, "CONFIG", paths[0]),
                patch.object(mod, "TARGET", paths[1]),
                patch.object(mod, "SUDOERS", paths[2]),
                patch.object(mod, "STATE", paths[3]),
                patch.object(mod, "discover_vhost", side_effect=lambda host: "/mock/" + host + ".conf"),
                patch.object(mod, "validate_vhost", side_effect=lambda value: value),
                patch.object(mod.os, "geteuid", return_value=0),
                patch("pwd.getpwnam", return_value=SimpleNamespace(pw_uid=33)),
                patch.object(mod, "run_nginx"),
                patch.object(mod, "verify_local_only_port", side_effect=ValueError("unsafe public port")),
                patch.object(sys, "argv", cmd),
                contextlib.redirect_stdout(io.StringIO()),
            ):
                with self.assertRaisesRegex(ValueError, "unsafe public port"):
                    mod.main()
            for path in paths:
                self.assertFalse(path.exists(), str(path))

    def test_install_partial_write_failure_removes_only_new_install_files(self):
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            target = directory / "helper"
            config = directory / "etc" / "approved.json"
            sudoers = directory / "sudoers"
            state = directory / "state"
            sudoers.mkdir()
            real_write = mod.write_file
            def flaky_write(path, data, mode=None):
                if Path(path) == config:
                    raise OSError("simulated disk failure")
                return real_write(path, data, mode)
            args = SimpleNamespace(
                wordpress_vhost="/mock/wp.conf",
                openlist_vhost="/mock/files.conf",
                openlist_host="files.example.com",
                port="5245",
                source="/d/google/付费资源/",
                php_user="www",
                container="",
            )
            with (
                patch.object(mod, "CONFIG", config),
                patch.object(mod, "TARGET", target),
                patch.object(mod, "SUDOERS", sudoers / "helper"),
                patch.object(mod, "STATE", state),
                patch.object(mod, "validate_vhost", side_effect=lambda value: value),
                patch.object(mod.os, "geteuid", return_value=0),
                patch("pwd.getpwnam", return_value=SimpleNamespace(pw_uid=33)),
                patch.object(mod, "run_nginx"),
                patch.object(mod, "verify_local_only_port"),
                patch.object(mod, "detect_docker_container", return_value=""),
                patch.object(mod.shutil, "which", return_value=None),
                patch.object(mod, "write_file", side_effect=flaky_write),
            ):
                with self.assertRaisesRegex(OSError, "simulated disk failure"):
                    mod.init_install(args)
            self.assertFalse(target.exists())
            self.assertFalse(config.exists())
            self.assertFalse((sudoers / "helper").exists())

    def test_manual_install_also_accepts_host_without_auto_detection(self):
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            cmd = [
                "helper", "install", "--wordpress-vhost", "/mock/site.conf",
                "--openlist-vhost", "/mock/files.conf", "--host", "files.example.com",
                "--port", "5244", "--source", "/d/storage/private/", "--php-user", "www",
            ]
            with (
                patch.object(mod, "CONFIG", directory / "approved.json"),
                patch.object(mod, "TARGET", directory / "helper"),
                patch.object(mod, "SUDOERS", directory / "sudoers"),
                patch.object(mod, "STATE", directory / "state"),
                patch.object(mod, "validate_vhost", side_effect=lambda value: value),
                patch.object(mod.os, "geteuid", return_value=0),
                patch("pwd.getpwnam", return_value=SimpleNamespace(pw_uid=33)),
                patch.object(mod, "run_nginx"),
                patch.object(mod, "verify_local_only_port"),
                patch.object(mod, "detect_docker_container", return_value=""),
                patch.object(mod.shutil, "which", return_value=None),
                patch.object(sys, "argv", cmd),
                contextlib.redirect_stdout(io.StringIO()),
            ):
                mod.main()
            self.assertEqual("files.example.com", json.loads((directory / "approved.json").read_text())["host"])

    def test_no_real_host_or_IP_hardcoded(self):
        code = source.read_text()
        for needle in ("private-host.invalid", "unexpected.example.net", "198.51.100.9"):
            self.assertNotIn(needle, code)


if __name__ == "__main__":
    unittest.main()
