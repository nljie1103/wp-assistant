"""Clean-install acceptance tests for the generic strict OpenList Nginx helper."""
import contextlib
import importlib.util
import io
import json
import re
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

source = Path(__file__).resolve().parents[1] / "ops" / "jlwa-nginx-helper.py"
spec = importlib.util.spec_from_file_location("jlwa_helper", source)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

WP = """server {
    listen 443 ssl;
    server_name site.example.com;
    location / { try_files $uri $uri/ /index.php?$args; }
}
"""
OPENLIST = """server {
    listen 443 ssl;
    server_name files.example.com files-cdn.example.com;
    location ^~ / { proxy_pass http://127.0.0.1:5244; }
}
"""
CONF = {"source":"/d/storage/private/", "port":5244, "host":"files.example.com",
        "public_images":"/d/storage/图片/", "public_access_policy":"strict_images_only",
        "strict_rule_revision":2}


class GenericStrictHelperTests(unittest.TestCase):
    def test_no_legacy_public_mode_exists(self):
        for policy in ("paid_only", "images_only", ""):
            with self.assertRaisesRegex(ValueError, "strict image-only is mandatory"):
                mod.snippet("openlist", dict(CONF, public_access_policy=policy))

    def test_exact_public_subtree_allows_only_read_and_local(self):
        s = mod.snippet("openlist", CONF)
        m = re.search(r'if \(\$uri ~ "([^"]+)"\)', s)
        self.assertIsNotNone(m)
        allowed = re.compile(m.group(1))
        for path in ("/d/storage/图片", "/d/storage/图片/", "/d/storage/图片/a.jpg",
                     "/d/storage/图片/sub/b.webp"):
            self.assertIsNotNone(allowed.search(path), path)
        for path in ("/", "/api/fs/list", "/api/auth/login", "/dav/", "/d/storage/private/b.zip",
                     "/p/storage/图片/b.jpg", "/d/storage/图片-extra/x.jpg",
                     "/d/another/图片/x.jpg", "/.well-known/acme-challenge/foo"):
            self.assertIsNone(allowed.search(path), path)
        self.assertIn("GET|HEAD", s)
        self.assertIn("if ($request_uri ~*", s)
        self.assertIn("127\\.0\\.0\\.1|::1", s)
        self.assertEqual(2, s.count("JLWA-MANAGED-DOWNLOAD"))

    def test_encoded_bypass_rejected_before_upstream(self):
        s=mod.snippet("openlist",CONF)
        self.assertLess(s.index("if ($request_uri ~*"), s.index("if ($remote_addr ~"))
        rex=re.compile(r"%(?:25|2e|2f|5c|00)", re.I)
        for path in ("%252e%252e", "%2e%2e", "%2f", "%5c", "%00"):
            self.assertIsNotNone(rex.search(path))
        self.assertIsNone(rex.search("%E5%9B%BE%E7%89%87/a.jpg"))

    def test_private_location_internal_and_loopback_only(self):
        s=mod.snippet("wp",CONF)
        self.assertIn("internal;",s)
        self.assertIn("proxy_pass http://127.0.0.1:5244/d/storage/private/;",s)
        self.assertIn("proxy_hide_header Location;",s)
        self.assertIn("private, no-store",s)

    def test_public_directory_validation(self):
        for bad in ("","/d/storage/private/","/d/storage/private/sub/",
                    "/d/storage/","/p/storage/图片/","/d/storage/图片%2f/",
                    "/d/storage/图片/../private/"):
            with self.assertRaises(ValueError, msg=bad):
                mod.validate_public_images(bad,CONF)
        self.assertEqual("/d/storage/图片/",mod.validate_public_images("/d/storage/图片/",CONF))
        self.assertEqual("/d/another/图片/",mod.validate_public_images("/d/another/图片/",CONF))

    def test_transform_adds_one_marker_keeps_other_locations(self):
        output=mod.transform(OPENLIST,"openlist",CONF)
        self.assertIn("files-cdn.example.com",output)
        self.assertIn("proxy_pass http://127.0.0.1:5244",output)
        self.assertEqual(output,mod.transform(output,"openlist",CONF))
        self.assertEqual(2,output.count("JLWA-MANAGED-DOWNLOAD"))

    def test_transform_rejects_unsafe_or_ambiguous_vhosts(self):
        for content in (OPENLIST+OPENLIST, OPENLIST.replace("files.example.com","other.example.com"),
                        OPENLIST.replace("server {","server {\n# BEGIN JLWA-MANAGED-DOWNLOAD"),
                        mod.transform(OPENLIST,"openlist",CONF).replace("403;","200;")):
            with self.assertRaises(ValueError):
                mod.transform(content,"openlist",CONF)
        with self.assertRaises(ValueError):
            mod.transform(WP+WP,"wp",CONF)

    def test_unique_vhost_discovery(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            (root/"wp.conf").write_text(WP)
            (root/"openlist.conf").write_text(OPENLIST)
            with patch.object(mod,"VHOST_DIR",root),patch.object(mod,"validate_vhost",side_effect=lambda p:p):
                self.assertEqual(str(root/"openlist.conf"), mod.discover_vhost("files-cdn.example.com"))
                with self.assertRaises(ValueError):mod.discover_vhost("missing.example.com")
                (root/"duplicate.conf").write_text(OPENLIST)
                with self.assertRaises(ValueError):mod.discover_vhost("files.example.com")

    def test_local_only_port_preflight_fails_closed(self):
        import socket
        with patch.object(mod.socket,"create_connection") as con, patch.object(mod.shutil,"which",return_value="/bin/ss"):
            for listener in ("LISTEN 0 512 0.0.0.0:5244 0.0.0.0:*",
                             "LISTEN 0 512 [::]:5244 [::]:*",
                             "LISTEN 0 512 127.0.0.1:9999 0.0.0.0:*"):
                with patch.object(mod.subprocess,"run",return_value=SimpleNamespace(stdout=listener)):
                    with self.assertRaises(ValueError):
                        mod.verify_local_only_port(5244)
            with patch.object(mod.subprocess,"run",return_value=SimpleNamespace(stdout="LISTEN 0 512 127.0.0.1:5244 0.0.0.0:*")):
                mod.verify_local_only_port(5244)
        with patch.object(mod.socket,"create_connection"):
            with patch.object(mod.shutil,"which",return_value=None):
                with self.assertRaisesRegex(ValueError,"ss is required"):
                    mod.verify_local_only_port(5244)

    def test_cli_requires_public_images_even_before_root_install(self):
        with patch.object(sys,"argv",["helper","install-auto","--wordpress-host","site.example.com",
                "--openlist-host","files.example.com","--port","5244",
                "--source","/d/storage/private/"]):
            with self.assertRaises(SystemExit), contextlib.redirect_stderr(io.StringIO()):
                mod.main()

    def test_fresh_install_to_protect_full_lifecycle_rollback(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            vhosts=root/"vhosts";vhosts.mkdir()
            wp=vhosts/"site.example.com.conf"
            files=vhosts/"files.example.com.conf"
            wp.write_text(WP);files.write_text(OPENLIST)
            conf=root/"etc"/"approved.json"
            helper=root/"bin"/"helper"
            sudoers=root/"sudoers"/"helper"
            helper.parent.mkdir();sudoers.parent.mkdir()
            state=root/"state"
            cmd=["helper","install-auto","--wordpress-host","site.example.com",
                "--openlist-host","files.example.com","--port","5244",
                "--source","/d/storage/private/","--php-user","www",
                "--openlist-hosts","files.example.com,files-cdn.example.com",
                "--public-images","/d/storage/图片/"]
            with (patch.object(mod,"VHOST_DIR",vhosts),patch.object(mod,"CONFIG",conf),
                  patch.object(mod,"TARGET",helper),patch.object(mod,"SUDOERS",sudoers),
                  patch.object(mod,"STATE",state),
                  patch.object(mod,"validate_vhost",side_effect=lambda p:p),
                  patch.object(mod,"read_manifest",side_effect=lambda:json.loads(conf.read_text())),
                  patch.object(mod.os,"geteuid",return_value=0),
                  patch("pwd.getpwnam",return_value=SimpleNamespace(pw_uid=33)),
                  patch.object(mod,"run_nginx") as nginx,
                  patch.object(mod,"verify_local_only_port"),
                  patch.object(mod,"detect_docker_container",return_value=""),
                  patch.object(mod.shutil,"which",return_value=None)):
                with patch.object(sys,"argv",cmd),contextlib.redirect_stdout(io.StringIO()):
                    mod.main()
                m=json.loads(conf.read_text())
                self.assertEqual("strict_images_only",m["public_access_policy"])
                self.assertEqual("/d/storage/图片/",m["public_images"])
                self.assertEqual(2,m["strict_rule_revision"])
                self.assertEqual(WP,wp.read_text())
                self.assertEqual(OPENLIST,files.read_text())
                for op in ("status","preview","prepare","confirm","protect"):
                    with patch.object(sys,"argv",["helper",op]),contextlib.redirect_stdout(io.StringIO()) as output:
                        mod.main()
                    if op=="status":
                        self.assertEqual("strict_images_only",json.loads(output.getvalue())["public_access_policy"])
                    if op=="prepare":self.assertEqual(OPENLIST,files.read_text())
                self.assertIn("if ($request_uri ~*",files.read_text())
                self.assertIn("internal;",wp.read_text())
                self.assertIn("strict_images_only",conf.read_text())
                self.assertEqual("protected",json.loads((state/"state.json").read_text())["phase"])
                self.assertTrue((state/"backups").exists())
                with patch.object(sys,"argv",["helper","rollback"]),contextlib.redirect_stdout(io.StringIO()):
                    mod.main()
                self.assertEqual(WP,wp.read_text())
                self.assertEqual(OPENLIST,files.read_text())
                self.assertEqual("rolled_back",json.loads((state/"state.json").read_text())["phase"])
                self.assertGreater(nginx.call_count,4)

    def test_missing_public_directory_writes_no_artifacts(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp)
            args=SimpleNamespace(wordpress_vhost=str(d/"wp"),openlist_vhost=str(d/"files"),
                port="5244",source="/d/storage/private/",host="files.example.com",
                openlist_hosts="files.example.com",php_user="www",container="",public_images="")
            with patch.object(mod.os,"geteuid",return_value=0),patch.object(mod,"validate_vhost",side_effect=lambda x:x):
                with self.assertRaisesRegex(ValueError,"public image directory required"):
                    mod.init_install(args)
            self.assertEqual([],list(d.iterdir()))

    def test_existing_managed_marker_rejected_before_new_root_install(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp)
            wp=d/"wp";files=d/"files"
            wp.write_text(WP)
            files.write_text(OPENLIST.replace("server {","server {\n"+mod.BEGIN+" openlist"))
            args=SimpleNamespace(wordpress_vhost=str(wp),openlist_vhost=str(files),
                 port="5244",source="/d/storage/private/",host="files.example.com",
                 openlist_hosts="files.example.com",php_user="www",container="",public_images="/d/storage/图片/")
            with (patch.object(mod.os,"geteuid",return_value=0),
                  patch.object(mod,"validate_vhost",side_effect=lambda x:x),
                  patch("pwd.getpwnam",return_value=SimpleNamespace(pw_uid=33)),
                  patch.object(mod,"CONFIG",d/"approved.json"),
                  patch.object(mod,"TARGET",d/"helper"),
                  patch.object(mod,"SUDOERS",d/"sudoers")):
                with self.assertRaisesRegex(ValueError,"existing JLWA"):
                    mod.init_install(args)
            self.assertEqual([],list(d.glob("*.json")))

    def test_preflight_rejects_complex_nginx_before_writes(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);wp=d/"wp";files=d/"files"
            wp.write_text(WP);files.write_text(OPENLIST+OPENLIST)
            args=SimpleNamespace(wordpress_vhost=str(wp),openlist_vhost=str(files),
                port="5244",source="/d/storage/private/",host="files.example.com",
                openlist_hosts="files.example.com",php_user="www",container="",public_images="/d/storage/图片/")
            with (patch.object(mod.os,"geteuid",return_value=0),
                  patch.object(mod,"validate_vhost",side_effect=lambda x:x),
                  patch("pwd.getpwnam",return_value=SimpleNamespace(pw_uid=33)),
                  patch.object(mod,"CONFIG",d/"approved.json"),patch.object(mod,"TARGET",d/"helper"),
                  patch.object(mod,"SUDOERS",d/"sudoers")):
                with self.assertRaisesRegex(ValueError,"exactly one server block"):
                    mod.init_install(args)
            self.assertFalse((d/"approved.json").exists())

    def test_nginx_failure_restores_first_mutation(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);vhost=d/"site.conf";vhost.write_text(WP)
            with patch.object(mod,"STATE",d/"state"),patch.object(mod,"run_nginx",side_effect=[ValueError("bad syntax"),None,None]):
                with self.assertRaisesRegex(ValueError,"bad syntax"):
                    mod.mutate("wp",dict(CONF,wordpress_vhost=str(vhost)),{"phase":"new","backups":{},"after":{}})
            self.assertEqual(WP,vhost.read_text())

    def test_manifest_rejects_non_strict_policy(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);c=d/"approved.json";c.write_text(json.dumps(dict(CONF, public_access_policy="paid_only",
                public_hosts=["files.example.com"],
                wordpress_vhost="/x",openlist_vhost="/y")))
            with (patch.object(mod,"CONFIG",c),patch.object(mod.os,"geteuid",return_value=0),
                  patch.object(mod,"validate_vhost",side_effect=lambda x:x),
                  patch.object(mod.CONFIG.__class__,"stat",return_value=SimpleNamespace(st_mode=c.stat().st_mode,st_uid=0))):
                with self.assertRaisesRegex(ValueError,"only supports fresh strict"):
                    mod.read_manifest()

    def test_unapproved_ops_not_exposed(self):
        for op in ("upgrade-images-only","rollback-images-only","preview-strict-public","refresh-strict-public"):
            with patch.object(sys,"argv",["helper",op]),contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaises(SystemExit):mod.main()

    def test_second_openlist_domain_on_other_vhost_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp)
            vhosts=d/"vhosts"; vhosts.mkdir()
            wp=vhosts/"site.example.com.conf"
            primary=vhosts/"files.example.com.conf"
            other=vhosts/"files-cdn.example.com.conf"
            wp.write_text(WP)
            primary.write_text(OPENLIST.replace(" files-cdn.example.com", ""))
            other.write_text(OPENLIST.replace("files.example.com files-cdn.example.com","files-cdn.example.com"))
            conf=d/"approved.json"
            helper=d/"helper"
            sudoers=d/"sudoers"
            args=SimpleNamespace(
                wordpress_vhost=str(wp),openlist_vhost=str(primary),
                host="files.example.com",openlist_hosts="files.example.com,files-cdn.example.com",
                port="5244",source="/d/storage/private/",public_images="/d/storage/图片/",
                php_user="www",container=""
            )
            with (patch.object(mod,"VHOST_DIR",vhosts),
                  patch.object(mod,"CONFIG",conf),patch.object(mod,"TARGET",helper),
                  patch.object(mod,"SUDOERS",sudoers),
                  patch.object(mod,"validate_vhost",side_effect=lambda p:p),
                  patch.object(mod.os,"geteuid",return_value=0),
                  patch("pwd.getpwnam",return_value=SimpleNamespace(pw_uid=33))):
                with self.assertRaisesRegex(ValueError,"aliases do not share"):
                    mod.init_install(args)
            self.assertFalse(conf.exists())
            self.assertFalse(helper.exists())
            self.assertFalse(sudoers.exists())
            self.assertEqual(OPENLIST.replace(" files-cdn.example.com", ""),primary.read_text())

    def test_portable_nginx_config_is_read_only_and_cross_mount(self):
        args=SimpleNamespace(
            wordpress_host="site.example.com",
            openlist_host="files.example.com",
            openlist_hosts="files.example.com,files-cdn.example.com",
            port="5244",
            source="/d/paid/private/",
            public_images="/d/public/图片/",
            php_user="www",
            container=""
        )
        with contextlib.redirect_stdout(io.StringIO()) as output:
            mod.render_portable_config(args)
        rendered=output.getvalue()
        self.assertIn("READ-ONLY GENERATED SNIPPETS",rendered)
        self.assertIn("files-cdn.example.com",rendered)
        self.assertIn("proxy_pass http://127.0.0.1:5244/d/paid/private/;",rendered)
        self.assertIn('^/d/public/图片(?:/|$)',rendered)
        self.assertIn("if ($request_uri ~*",rendered)
        self.assertEqual(4,rendered.count("JLWA-MANAGED-DOWNLOAD"))

    def test_portable_nginx_generator_requires_public_images(self):
        with patch.object(sys,"argv",[
            "helper","render-config","--wordpress-host","site.example.com",
            "--openlist-host","files.example.com","--openlist-hosts","files.example.com",
            "--port","5244","--source","/d/paid/private/"
        ]),contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                mod.main()
