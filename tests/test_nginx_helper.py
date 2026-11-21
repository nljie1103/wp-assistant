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

    def test_first_install_images_only_uses_standard_protect_not_upgrade(self):
        """A customer installing the latest version gets the final policy in
        their first root install; WordPress only uses normal six operations."""
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            vhosts = directory / "vhosts"
            vhosts.mkdir()
            wp_file = vhosts / "site.example.com.conf"
            openlist_file = vhosts / "files.example.com.conf"
            wp_file.write_text(WP_VHOST)
            openlist_file.write_text(OPENLIST_VHOST)
            config = directory / "etc" / "approved.json"
            helper = directory / "bin" / "jlwa-nginx-helper"
            sudoers = directory / "sudoers" / "jlwa-nginx-helper"
            helper.parent.mkdir()
            sudoers.parent.mkdir()
            state = directory / "state"
            images = "/d/storage/图片/"
            install = [
                "jlwa-nginx-helper.py", "install-auto",
                "--wordpress-host", "site.example.com",
                "--openlist-host", "files.example.com",
                "--port", "5244", "--source", "/d/storage/private/",
                "--php-user", "www", "--public-images", images,
            ]
            with (
                patch.object(mod, "VHOST_DIR", vhosts),
                patch.object(mod, "CONFIG", config),
                patch.object(mod, "TARGET", helper),
                patch.object(mod, "SUDOERS", sudoers),
                patch.object(mod, "STATE", state),
                patch.object(mod, "validate_vhost", side_effect=lambda value: value),
                patch.object(mod, "read_manifest", side_effect=lambda: json.loads(config.read_text())),
                patch.object(mod.os, "geteuid", return_value=0),
                patch("pwd.getpwnam", return_value=SimpleNamespace(pw_uid=33)),
                patch.object(mod, "run_nginx") as nginx,
                patch.object(mod, "verify_local_only_port"),
                patch.object(mod, "detect_docker_container", return_value=""),
                patch.object(mod.shutil, "which", return_value=None),
            ):
                with patch.object(sys, "argv", install), contextlib.redirect_stdout(io.StringIO()):
                    mod.main()
                authorized = json.loads(config.read_text())
                self.assertEqual("strict_images_only", authorized["public_access_policy"])
                self.assertEqual(images, authorized["public_images"])
                self.assertEqual(WP_VHOST, wp_file.read_text())
                self.assertEqual(OPENLIST_VHOST, openlist_file.read_text())
                for operation in ("status", "preview", "prepare", "confirm", "protect"):
                    with patch.object(sys, "argv", ["helper", operation]), contextlib.redirect_stdout(io.StringIO()) as output:
                        mod.main()
                    if operation == "status":
                        self.assertEqual("strict_images_only", json.loads(output.getvalue())["public_access_policy"])
                    if operation == "preview":
                        self.assertIn('set $jlwa_openlist_public_allowed', output.getvalue())
                        self.assertEqual(OPENLIST_VHOST, openlist_file.read_text())
                    if operation == "prepare":
                        self.assertEqual(OPENLIST_VHOST, openlist_file.read_text())
                    if operation == "protect":
                        self.assertIn("all other public routes", output.getvalue())
                final = openlist_file.read_text()
                self.assertIn('set $jlwa_openlist_public_allowed', final)
                self.assertNotIn("location ^~ /d/storage/private/", final)
                self.assertIn('internal;', wp_file.read_text())
                result = json.loads((state / "state.json").read_text())
                self.assertEqual("protected", result["phase"])
                self.assertTrue(result["confirmed"])
                self.assertTrue(Path(result["backups"]["wp"]).exists())
                self.assertTrue(Path(result["backups"]["openlist"]).exists())
                self.assertEqual("strict_images_only", json.loads(config.read_text())["public_access_policy"])
                with patch.object(sys, "argv", ["helper", "rollback"]), contextlib.redirect_stdout(io.StringIO()):
                    mod.main()
                self.assertEqual(WP_VHOST, wp_file.read_text())
                self.assertEqual(OPENLIST_VHOST, openlist_file.read_text())
                self.assertEqual("rolled_back", json.loads((state / "state.json").read_text())["phase"])

    def test_first_install_rejects_invalid_image_whitelist_before_writes(self):
        with tempfile.TemporaryDirectory() as root:
            d = Path(root)
            wp, files, manifest, helper, sudoers = (
                d / "wp.conf", d / "files.conf", d / "approved.json",
                d / "helper", d / "sudoers",
            )
            wp.write_text(WP_VHOST)
            files.write_text(OPENLIST_VHOST)
            for invalid in ("/d/storage/private/", "/d/storage/private/inside/",
                            "/d/another/images/", "/p/storage/images/",
                            "/d/storage/images%2F/"):
                args = SimpleNamespace(
                    wordpress_vhost=str(wp), openlist_vhost=str(files),
                    port="5244", source="/d/storage/private/",
                    host="files.example.com", php_user="www",
                    container="", public_images=invalid,
                )
                with (
                    patch.object(mod, "CONFIG", manifest),
                    patch.object(mod, "TARGET", helper),
                    patch.object(mod, "SUDOERS", sudoers),
                    patch.object(mod, "validate_vhost", side_effect=lambda value: value),
                ):
                    with self.assertRaises(ValueError, msg=invalid):
                        mod.init_install(args)
                self.assertFalse(manifest.exists(), invalid)
                self.assertFalse(helper.exists(), invalid)
                self.assertFalse(sudoers.exists(), invalid)

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

    def test_image_allowlist_regex_denies_all_file_mounts_except_images(self):
        import re
        config = dict(SETTINGS, public_access_policy="images_only", public_images="/d/storage/图片/")
        snippet = mod.snippet("openlist", config)
        expr = re.search(r'if \(\$uri ~ "([^"]+)"\)', snippet)
        self.assertIsNotNone(expr)
        deny = re.compile(expr.group(1))
        allowed = [
            "/d/storage/图片/", "/d/storage/图片/example.png", "/p/storage/图片/photo.png",
            "/d/storage/图片", "/p/storage/图片", "/d/storage/图片/folder/a.webp",
        ]
        denied = [
            "/d", "/p", "/d/", "/p/", "/d/storage/private/a.zip",
            "/p/storage/private/a.zip", "/d/storage/图片2/test.png",
            "/p/storage/图片-private/test.png", "/d/other/file.png",
            "/p/other/file.png", "/d/storage/私有/a", "/p/storage/私有/a",
        ]
        for path in allowed:
            self.assertIsNone(deny.search(path), path)
        for path in denied:
            self.assertIsNotNone(deny.search(path), path)
        self.assertEqual(2, snippet.count("JLWA-MANAGED-DOWNLOAD"))
        self.assertNotIn("proxy_pass", snippet)
        self.assertIn('return 403;', snippet)

    def test_image_allowlist_migration_requires_non_overlapping_same_mount(self):
        for bad in ("", "/d/storage/private/", "/d/storage/private/sub/",
                    "/d/storage/", "/d/other/图片/", "/p/storage/图片/",
                    "/d/storage/图片%2f/", "/d/storage/图片/../private/"):
            with self.assertRaises(ValueError, msg=bad):
                mod.validate_public_images(bad, SETTINGS)
        self.assertEqual("/d/storage/图片/", mod.validate_public_images("/d/storage/图片/", SETTINGS))

    def test_image_allowlist_migration_preserves_history_and_rollback(self):
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            wp_file, files_file = directory / "wp.conf", directory / "files.conf"
            original_wp, original_files = WP_VHOST, OPENLIST_VHOST
            config = dict(SETTINGS, wordpress_vhost=str(wp_file), openlist_vhost=str(files_file))
            protected_wp = mod.transform(original_wp, "wp", config)
            protected_files = mod.transform(original_files, "openlist", config)
            wp_file.write_text(protected_wp)
            files_file.write_text(protected_files)
            state_dir = directory / "state"
            state_dir.mkdir()
            (state_dir / "lock").write_text("")
            wp_back, files_back = directory / "wp.back", directory / "files.back"
            wp_back.write_text(original_wp)
            files_back.write_text(original_files)
            state = {
                "phase": "protected", "confirmed": True,
                "backups": {"wp": str(wp_back), "openlist": str(files_back)},
                "after": {"wp": mod.digest(protected_wp), "openlist": mod.digest(protected_files)},
            }
            state_file = state_dir / "state.json"
            state_file.write_text(json.dumps(state))
            manifest = directory / "approved.json"
            manifest.write_text(json.dumps(config))
            target = directory / "installed-helper"
            target.write_text("old helper")
            sudoers = directory / "sudoers"
            sudoers.write_text("approved")
            public = "/d/storage/图片/"
            with (
                patch.object(mod, "STATE", state_dir),
                patch.object(mod, "CONFIG", manifest),
                patch.object(mod, "TARGET", target),
                patch.object(mod, "SUDOERS", sudoers),
                patch.object(mod, "read_manifest", side_effect=lambda: json.loads(manifest.read_text())),
                patch.object(mod, "secure_file", return_value=None),
                patch.object(mod.os, "geteuid", return_value=0),
                patch.object(mod, "run_nginx") as nginx,
            ):
                preview = SimpleNamespace(op="preview-images-only", public_images=public)
                with contextlib.redirect_stdout(io.StringIO()) as out:
                    mod.migrate_public_images(preview)
                self.assertIn("Read-only preview", out.getvalue())
                self.assertEqual(protected_files, files_file.read_text())
                self.assertEqual("old helper", target.read_text())
                self.assertEqual(state, json.loads(state_file.read_text()))
                self.assertEqual(1, nginx.call_count)
                apply = SimpleNamespace(op="upgrade-images-only", public_images=public)
                mod.migrate_public_images(apply)
                newconf = json.loads(manifest.read_text())
                self.assertEqual("images_only", newconf["public_access_policy"])
                self.assertEqual(public, newconf["public_images"])
                self.assertIn("images_only", target.read_text())
                final = files_file.read_text()
                self.assertIn('if ($uri ~ ', final)
                self.assertNotIn("location ^~ /d/storage/private/", final)
                newest = json.loads(state_file.read_text())
                self.assertEqual(state["backups"], newest["backups"])
                self.assertEqual("protected", newest["phase"])
                self.assertTrue(newest["confirmed"])
                self.assertEqual(mod.digest(final), newest["after"]["openlist"])
                self.assertEqual(state["after"]["wp"], newest["after"]["wp"])
                self.assertEqual(protected_wp, wp_file.read_text())
                nginx.reset_mock()
                with contextlib.redirect_stdout(io.StringIO()) as out:
                    mod.migrate_public_images(apply)
                self.assertIn("already installed", out.getvalue())
                nginx.assert_not_called()
                # Image-only rollback keeps the original paid-directory deny
                # and the paid-user WordPress internal delivery intact.
                mod.rollback_public_images()
                partially_reverted = files_file.read_text()
                self.assertIn("location ^~ /d/storage/private/ { return 403; }", partially_reverted)
                self.assertIn("location ^~ /p/storage/private/ { return 403; }", partially_reverted)
                self.assertEqual(protected_wp, wp_file.read_text())
                self.assertEqual(state["backups"], json.loads(state_file.read_text())["backups"])
                self.assertEqual("paid_only", json.loads(manifest.read_text())["public_access_policy"])
                # One-time migration can be re-applied without losing the
                # original pre-install backup or a confirmed paid purchase.
                mod.migrate_public_images(apply)
                final_newconf = json.loads(manifest.read_text())
                self.assertEqual("images_only", final_newconf["public_access_policy"])
                newest = json.loads(state_file.read_text())
                self.assertEqual(state["backups"], newest["backups"])
                mod.rollback(final_newconf, newest)
                self.assertEqual(original_wp, wp_file.read_text())
                self.assertEqual(original_files, files_file.read_text())
                self.assertEqual("rolled_back", json.loads(state_file.read_text())["phase"])

    def test_image_only_nginx_check_failure_restores_every_root_owned_artifact(self):
        with tempfile.TemporaryDirectory() as root:
            d = Path(root)
            wp, files = d / "wp.conf", d / "files.conf"
            config = dict(SETTINGS, wordpress_vhost=str(wp), openlist_vhost=str(files))
            wp_body = mod.transform(WP_VHOST, "wp", config)
            files_body = mod.transform(OPENLIST_VHOST, "openlist", config)
            wp.write_text(wp_body)
            files.write_text(files_body)
            state_dir = d / "state"
            state_dir.mkdir()
            (state_dir / "lock").write_text("")
            original_wp, original_files = d / "wp.back", d / "files.back"
            original_wp.write_text(WP_VHOST)
            original_files.write_text(OPENLIST_VHOST)
            state = {
                "phase": "protected", "confirmed": True,
                "backups": {"wp": str(original_wp), "openlist": str(original_files)},
                "after": {"wp": mod.digest(wp_body), "openlist": mod.digest(files_body)},
            }
            state_text = json.dumps(state)
            (state_dir / "state.json").write_text(state_text)
            manifest, target, sudoers = d / "manifest", d / "helper", d / "sudoers"
            manifest.write_text(json.dumps(config))
            target.write_text("pinned OLD helper\n")
            sudoers.write_text("approved\n")
            original_manifest = manifest.read_text()
            original_target = target.read_text()
            with (
                patch.object(mod, "STATE", state_dir),
                patch.object(mod, "CONFIG", manifest),
                patch.object(mod, "TARGET", target),
                patch.object(mod, "SUDOERS", sudoers),
                patch.object(mod, "read_manifest", side_effect=lambda: json.loads(manifest.read_text())),
                patch.object(mod, "secure_file", return_value=None),
                patch.object(mod.os, "geteuid", return_value=0),
                patch.object(mod, "run_nginx", side_effect=[
                    None, ValueError("simulated invalid nginx rule"), None, None,
                ]),
            ):
                with self.assertRaisesRegex(ValueError, "simulated invalid"):
                    mod.migrate_public_images(SimpleNamespace(
                        op="upgrade-images-only", public_images="/d/storage/图片/",
                    ))
            self.assertEqual(files_body, files.read_text())
            self.assertEqual(wp_body, wp.read_text())
            self.assertEqual(original_target, target.read_text())
            self.assertEqual(original_manifest, manifest.read_text())
            self.assertEqual(state_text, (state_dir / "state.json").read_text())
            self.assertEqual(WP_VHOST, original_wp.read_text())
            self.assertEqual(OPENLIST_VHOST, original_files.read_text())

    def test_image_allowlist_edited_vhost_refuses_migration_without_writes(self):
        with tempfile.TemporaryDirectory() as root:
            directory = Path(root)
            source = directory / "files.conf"
            config = dict(SETTINGS, openlist_vhost=str(source))
            original = mod.transform(OPENLIST_VHOST, "openlist", config)
            source.write_text(original.replace("return 403;", "return 200;", 1))
            with self.assertRaisesRegex(ValueError, "existing paid-only managed block differs"):
                mod.images_only_proposal(source.read_text(), config, "/d/storage/图片/")

    def test_no_real_host_or_IP_hardcoded(self):
        code = source.read_text()
        for needle in ("private-host.invalid", "unexpected.example.net", "198.51.100.9"):
            self.assertNotIn(needle, code)


if __name__ == "__main__":
    unittest.main()


class StrictPublicImagesTests(unittest.TestCase):
    IMAGES = "/d/storage/图片/"

    def _config(self):
        return dict(SETTINGS, public_access_policy="images_only", public_images=self.IMAGES)

    def test_strict_rules_cover_every_public_route_not_just_files(self):
        cfg = dict(self._config(), public_access_policy="strict_images_only")
        fragment = mod.snippet("openlist", cfg)
        self.assertIn('set $jlwa_openlist_public_allowed 0;', fragment)
        self.assertIn('if ($request_method !~ "^(?:GET|HEAD)$")', fragment)
        self.assertIn('if ($remote_addr ~ "^(?:127\\.0\\.0\\.1|::1)$")', fragment)
        self.assertIn('if ($jlwa_openlist_public_allowed = 0) { return 403; }', fragment)
        import re
        m = re.search(r'if \(\$uri ~ "([^"]+)"\)', fragment)
        self.assertIsNotNone(m)
        allowed = re.compile(m.group(1))
        for url in ("/d/storage/图片", "/d/storage/图片/", "/d/storage/图片/a.jpg", "/d/storage/图片/sub/x.png"):
            self.assertIsNotNone(allowed.search(url), url)
        for url in ("/", "/api/fs/list", "/api/fs/get", "/api/auth/login", "/dav/", "/webdav/",
                    "/d/", "/d/storage/private/a.zip", "/p/storage/图片/a.jpg",
                    "/d/storage/图片-evil/payload", "/d/other/图片/a.jpg", "/.well-known/acme-challenge/x"):
            self.assertIsNone(allowed.search(url), url)

    def test_strict_upgrade_and_rollback_do_not_touch_wp_or_old_backups(self):
        with tempfile.TemporaryDirectory() as root:
            d = Path(root)
            wp, files = d/"wp.conf", d/"files.conf"
            cfg = self._config()
            cfg.update(wordpress_vhost=str(wp), openlist_vhost=str(files))
            wp_text = mod.transform(WP_VHOST, "wp", cfg) + "\n# independent Cloudflare ACL change\n"
            old_files = mod.transform(OPENLIST_VHOST, "openlist", cfg)
            wp.write_text(wp_text)
            files.write_text(old_files)
            state = d/"state"
            state.mkdir()
            (state/"lock").write_text("")
            orig_wp, orig_files = d/"original-wp", d/"original-files"
            orig_wp.write_text(WP_VHOST)
            orig_files.write_text(OPENLIST_VHOST)
            state_data = {
                "phase": "protected", "confirmed": True,
                "backups": {"wp":str(orig_wp), "openlist":str(orig_files)},
                "after": {"wp":"historical-sha-not-equal-after-cf-acl", "openlist":mod.digest(old_files)}
            }
            (state/"state.json").write_text(json.dumps(state_data))
            manifest, helper, sudoers = d/"manifest", d/"helper", d/"sudoers"
            manifest.write_text(json.dumps(cfg))
            helper.write_text("previous root installed helper")
            sudoers.write_text("original restricted sudoers")
            with (patch.object(mod,"STATE",state), patch.object(mod,"CONFIG",manifest),
                  patch.object(mod,"TARGET",helper),patch.object(mod,"SUDOERS",sudoers),
                  patch.object(mod,"secure_file"),patch.object(mod.os,"geteuid",return_value=0),
                  patch.object(mod,"read_manifest",side_effect=lambda:json.loads(manifest.read_text())),
                  patch.object(mod,"run_nginx")):
                with contextlib.redirect_stdout(io.StringIO()) as view:
                    mod.migrate_strict_images("preview-strict-public")
                self.assertIn("Strict public",view.getvalue())
                self.assertEqual(old_files,files.read_text())
                mod.migrate_strict_images("upgrade-strict-public")
                strict = json.loads(manifest.read_text())
                self.assertEqual(strict["public_access_policy"],"strict_images_only")
                self.assertEqual(wp_text,wp.read_text())
                self.assertEqual("historical-sha-not-equal-after-cf-acl",json.loads((state/"state.json").read_text())["after"]["wp"])
                self.assertIn("$jlwa_openlist_public_allowed",files.read_text())
                mod.migrate_strict_images("upgrade-strict-public")
                mod.migrate_strict_images("rollback-strict-public")
                self.assertEqual("images_only",json.loads(manifest.read_text())["public_access_policy"])
                self.assertEqual(old_files,files.read_text())
                self.assertEqual(wp_text,wp.read_text())
                self.assertEqual(WP_VHOST,orig_wp.read_text())
                self.assertEqual(OPENLIST_VHOST,orig_files.read_text())

    def test_strict_upgrade_refuses_out_of_band_source_change(self):
        cfg = self._config()
        original = mod.transform(OPENLIST_VHOST, "openlist", cfg)
        with self.assertRaisesRegex(ValueError, "managed source"):
            mod.strict_images_proposal(original.replace("return 403", "return 200"), cfg)
        with self.assertRaisesRegex(ValueError, "exactly one"):
            mod.strict_images_proposal(OPENLIST_VHOST, cfg)

    def test_strict_failed_nginx_check_restores_manifest_vhost_helper_state(self):
        with tempfile.TemporaryDirectory() as root:
            d=Path(root)
            cfg=self._config()
            wp,files=d/"wp",d/"files"
            cfg.update(wordpress_vhost=str(wp),openlist_vhost=str(files))
            pw=mod.transform(WP_VHOST,"wp",cfg)
            pf=mod.transform(OPENLIST_VHOST,"openlist",cfg)
            wp.write_text(pw)
            files.write_text(pf)
            state=d/"state";state.mkdir();(state/"lock").write_text("")
            wb,fb=d/"wb",d/"fb";wb.write_text(WP_VHOST);fb.write_text(OPENLIST_VHOST)
            (state/"state.json").write_text(json.dumps({"phase":"protected","confirmed":True,
                "backups":{"wp":str(wb),"openlist":str(fb)},
                "after":{"wp":mod.digest(pw),"openlist":mod.digest(pf)}}))
            manifest,helper,sudoers=d/"manifest",d/"helper",d/"sudoers"
            manifest.write_text(json.dumps(cfg));helper.write_text("old installed");sudoers.write_text("sudo")
            originals={x:x.read_text() for x in (wp,files,manifest,helper,state/"state.json")}
            with (patch.object(mod,"STATE",state),patch.object(mod,"CONFIG",manifest),
                  patch.object(mod,"TARGET",helper),patch.object(mod,"SUDOERS",sudoers),
                  patch.object(mod,"secure_file"),patch.object(mod.os,"geteuid",return_value=0),
                  patch.object(mod,"read_manifest",side_effect=lambda:json.loads(manifest.read_text())),
                  patch.object(mod,"run_nginx",side_effect=[None,ValueError("nginx -t fails"),None,None])):
                with self.assertRaisesRegex(ValueError,"nginx -t fails"):
                    mod.migrate_strict_images("upgrade-strict-public")
            for x,expected in originals.items():self.assertEqual(expected,x.read_text(),str(x))
