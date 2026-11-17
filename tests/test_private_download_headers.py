"""End-to-end PHP HTTP header test for X-Accel-Redirect in the WordPress plugin.

PHP's CLI sapi does not emit headers. Start PHP's built-in HTTP server to
verify that Zibll's Content-Disposition was removed and only the origin will
provide a final filename during the Nginx internal proxy response.
"""
import http.client
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "features" / "private-download.php"


class PrivateDownloadHeadersHTTPTest(unittest.TestCase):
    def test_php_x_accel_handover_has_no_content_disposition(self):
        if not shutil.which("php"):
            self.skipTest("PHP binary not installed")
        with tempfile.TemporaryDirectory(prefix="jlwa-header-test-") as directory:
            module_path = str(MODULE).replace("\\", "\\\\").replace("'", "\\'")
            router = Path(directory, "index.php")
            router.write_text(
                "<?php\n"
                "define('ABSPATH', '/');\n"
                "function add_filter(...$args) {}\n"
                "function add_action(...$args) {}\n"
                "function status_header($status) { http_response_code($status); }\n"
                f"require '{module_path}';\n"
                "header('Content-Disposition: attachment; filename=\"old-zibll.zip\"');\n"
                "$reflection = new ReflectionMethod('JLWA_Private_Download', 'deliver');\n"
                "$reflection->setAccessible(true);\n"
                "$reflection->invoke(null, array('uri' => '/_jlwa_private_openlist/test.zip', 'filename' => 'test.zip'));\n"
                "echo 'body will be replaced by Nginx internal proxy';\n",
                encoding="utf-8",
            )
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            proc = subprocess.Popen(
                ["php", "-S", f"127.0.0.1:{port}", "-t", directory],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
            )
            try:
                response = None
                for _ in range(35):
                    try:
                        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=2)
                        connection.request("GET", "/")
                        response = connection.getresponse()
                        response.read()
                        break
                    except OSError:
                        time.sleep(0.08)
                    finally:
                        try:
                            connection.close()
                        except NameError:
                            pass
                self.assertIsNotNone(response, "PHP HTTP test server failed to start")
                self.assertEqual(200, response.status)
                headers = response.getheaders()
                dispositions = [v for k, v in headers if k.lower() == "content-disposition"]
                self.assertEqual([], dispositions, "PHP must not duplicate OpenList's final disposition")
                self.assertEqual("/_jlwa_private_openlist/test.zip", response.getheader("X-Accel-Redirect"))
                self.assertEqual("application/octet-stream", response.getheader("Content-Type"))
                self.assertIn("no-store", response.getheader("Cache-Control"))
            finally:
                proc.terminate()
                try:
                    proc.communicate(timeout=3)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.communicate(timeout=3)


if __name__ == "__main__":
    unittest.main()
