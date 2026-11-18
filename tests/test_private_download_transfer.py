"""Integration test for the opt-in relay ticket and X-Accel handoff.

This exercises real PHP over HTTP with synthetic, non-sensitive paths. Nginx
and OpenList are not started in the test, so final file bytes must still be
tested in a deployed staging environment.
"""
import http.client
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "features" / "private-download.php"


class RelayDownloadHTTPTest(unittest.TestCase):
    def test_paid_handoff_redirects_to_configured_relay_and_redeems_ticket(self):
        if not shutil.which("php"):
            self.skipTest("PHP binary not installed")
        with tempfile.TemporaryDirectory(prefix="jlwa-relay-") as directory:
            dirname = Path(directory)
            module = str(MODULE).replace("\\", "\\\\").replace("'", "\\'")
            transient = str(dirname / "tickets").replace("\\", "\\\\").replace("'", "\\'")
            (dirname / "tickets").mkdir()
            router = dirname / "index.php"
            router.write_text(
                "<?php\n"
                "define('ABSPATH', '/');\n"
                "function get_option($key, $default = false) {\n"
                "  if ($key === 'jlwa_download_page_options') return [\n"
                "    'private_enabled' => 1,\n"
                "    'delivery_enabled' => 1,\n"
                "    'delivery_host' => 'download.example.test',\n"
                "    'private_internal_prefix' => '/_jlwa_private_openlist/',\n"
                "    'ticket_lifetime' => 180,\n"
                "  ];\n"
                "  return $default;\n"
                "}\n"
                "class JLWA_Download_Page_Feature {\n"
                "  public static function options() { return get_option('jlwa_download_page_options'); }\n"
                "}\n"
                "function add_filter(...$args) {}\n"
                "function add_action(...$args) {}\n"
                "function wp_unslash($value) { return $value; }\n"
                "function nocache_headers() { header('Cache-Control: no-store'); }\n"
                "function status_header($code) { http_response_code($code); }\n"
                "function wp_redirect($url, $code = 302, $by = '') {\n"
                "  header('Location: ' . $url, true, $code);\n"
                "}\n"
                "function wp_die($message, $title = '', $args = []) {\n"
                "  http_response_code($args['response'] ?? 500);\n"
                "  echo 'test error response'; exit;\n"
                "}\n"
                "function set_transient($key, $value, $life) {\n"
                f"  return false !== file_put_contents('{transient}/' . $key . '.json', json_encode($value));\n"
                "}\n"
                "function get_transient($key) {\n"
                f"  $file = '{transient}/' . $key . '.json';\n"
                "  return is_file($file) ? json_decode(file_get_contents($file), true) : false;\n"
                "}\n"
                f"require '{module}';\n"
                "if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/issue') {\n"
                "  $m = new ReflectionMethod('JLWA_Private_Download', 'issue_ticket');\n"
                "  $m->setAccessible(true);\n"
                "  $m->invoke(null, [\n"
                "    'uri' => '/_jlwa_private_openlist/demo/sample.zip',\n"
                "    'filename' => 'sample.zip'\n"
                "  ], JLWA_Download_Page_Feature::options());\n"
                "}\n"
                "JLWA_Private_Download::redeem_ticket();\n"
                "echo 'without valid ticket no internal file path is exposed';\n",
                encoding="utf-8",
            )
            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            server = subprocess.Popen(
                ["php", "-S", f"127.0.0.1:{port}", "-t", directory],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            def request(uri, host, headers=None):
                headers = dict(headers or {})
                headers["Host"] = host
                for attempt in range(30):
                    client = http.client.HTTPConnection("127.0.0.1", port, timeout=3)
                    try:
                        client.request("GET", uri, headers=headers)
                        response = client.getresponse()
                        status, result_headers, body = (
                            response.status, dict(response.getheaders()), response.read()
                        )
                        return status, result_headers, body
                    except OSError:
                        if attempt == 29:
                            raise
                        time.sleep(0.07)
                    finally:
                        client.close()
                raise AssertionError("PHP test server did not start")
            try:
                status, headers, body = request("/issue", "blog.example.test")
                self.assertEqual(302, status)
                target = headers.get("Location", "")
                self.assertRegex(target, r"^https://download\.example\.test/\?jlwa_dl=[a-f0-9]{48}$")
                self.assertNotIn("sample.zip", target)
                self.assertNotIn("/_jlwa_private_openlist/", target)
                self.assertIn("no-store", headers.get("Cache-Control", ""))

                parts = urlsplit(target)
                uri = parts.path + "?" + parts.query
                status, headers, body = request(uri, "download.example.test")
                self.assertEqual(200, status)
                self.assertEqual("/_jlwa_private_openlist/demo/sample.zip", headers.get("X-Accel-Redirect"))
                self.assertIn("no-store", headers.get("Cache-Control", ""))
                self.assertNotIn("Content-Disposition", headers)

                # Reuse within the configured lifetime permits Range requests
                # to the same Nginx-proxied file; Nginx itself serves the bytes.
                status, headers, body = request(
                    uri, "download.example.test", {"Range": "bytes=0-15"}
                )
                self.assertEqual(200, status)
                self.assertEqual(
                    "/_jlwa_private_openlist/demo/sample.zip",
                    headers.get("X-Accel-Redirect"),
                )

                status, headers, body = request(uri, "not-the-approved-host.example.test")
                self.assertEqual(404, status)
                self.assertNotIn("X-Accel-Redirect", headers)
                status, headers, body = request(
                    "/?jlwa_dl=" + "0" * 48, "download.example.test"
                )
                self.assertEqual(404, status)
                self.assertNotIn("X-Accel-Redirect", headers)
            finally:
                server.terminate()
                try:
                    server.communicate(timeout=3)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.communicate(timeout=3)


if __name__ == "__main__":
    unittest.main()
