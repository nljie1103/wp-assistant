#!/usr/bin/env python3
"""Optional, strictly scoped Baota standalone reverse-proxy static asset cache.

No WordPress file or private-download locations are touched. This tool refuses
unknown/customized Nginx layouts and requires an explicit root terminal action.
"""
import argparse
import difflib
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from urllib.parse import urlsplit

VHOSTS = Path("/www/server/panel/vhost/nginx")
STATE = Path("/var/lib/jlwa-media-nginx")
CACHE_ROOT = Path("/www/wwwcache")
HOST = re.compile(r"^(?=.{4,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])$")
START = "# JLWA-MEDIA-STATIC-BEGIN"
END = "# JLWA-MEDIA-STATIC-END"


def fail(message):
    raise ValueError(message)


def valid_host(raw):
    host = str(raw).lower().strip()
    if not HOST.fullmatch(host) or "." not in host or ".." in host or any(not label or len(label) > 63 or label.startswith("-") or label.endswith("-") for label in host.split(".")):
        fail("invalid host; provide one DNS hostname")
    return host


def valid_origin(raw, target):
    parts = urlsplit(str(raw))
    if parts.scheme not in ("http", "https") or not parts.hostname or parts.username or parts.password or parts.query or parts.fragment or parts.path not in ("", "/") or parts.port not in (None, 80, 443):
        fail("origin must be a plain http(s) site origin without credentials, path, or query")
    host = valid_host(parts.hostname)
    if host == target:
        fail("target and origin host are the same; refusing proxy recursion")
    return "%s://%s%s" % (parts.scheme, host, "" if parts.port is None else ":" + str(parts.port)), host


def config_files():
    if not VHOSTS.is_dir() or VHOSTS.is_symlink():
        fail("Baota Nginx vhost directory is missing or a symlink")
    for file in sorted(VHOSTS.glob("*.conf")):
        if file.is_symlink() or not file.is_file():
            continue
        yield file


def discover(host):
    matches = []
    for file in config_files():
        try:
            source = file.read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            continue
        for names in re.findall(r"(?m)^\s*server_name\s+([^;]+);", source):
            if host in names.lower().split():
                matches.append(file)
                break
    if len(matches) != 1:
        fail("expected one Nginx vhost for target %s, found %d; standalone reverse proxy required" % (host, len(matches)))
    file = matches[0]
    try:
        file.resolve().relative_to(VHOSTS.resolve())
    except ValueError:
        fail("resolved vhost escapes Baota directory")
    return file


def digest(source):
    return hashlib.sha256(source.encode("utf-8")).hexdigest()


def managed_id(host):
    return hashlib.sha256(host.encode("utf-8")).hexdigest()[:10]


def validate_proxy_vhost(source, target, origin):
    if source.count(START) or source.count(END):
        fail("managed configuration already present; use rollback or inspect existing rules")
    # Never modify a WordPress/PHP vhost, an existing Nginx cache, or
    # a site with custom regex/static locations. Those need human review.
    suspicious = [
        r"\bfastcgi_pass\b", r"enable-php", r"\bwp-config\.php\b",
        r"\bproxy_cache(?:_path|_valid|_bypass|_key)?\b",
        r"(?m)^\s*location\s+~", r"(?m)^\s*location\s+\^~\s+/(?:wp-|\s|\{)",
        r"JLWA-MANAGED-DOWNLOAD",
    ]
    if any(re.search(pattern, source, re.I) for pattern in suspicious):
        fail("vhost contains PHP/custom static cache/security rules; refusing automatic modifications")
    if len(re.findall(r"\bserver\s*\{", source)) != 1:
        fail("automatic changes only support a single standalone server block")
    names = re.findall(r"(?m)^\s*server_name\s+([^;]+);", source)
    if len(names) != 1 or target not in names[0].lower().split():
        fail("target server_name is ambiguous")
    if urlsplit(origin).hostname in names[0].lower().split():
        fail("origin and target share one server block; proxy recursion risk")
    if not re.search(r"(?m)^\s*location\s+/\s*\{", source):
        fail("standalone reverse proxy must already contain a location / block")
    # Require an existing explicit proxy to this origin. Never turn a PHP
    # site into a reverse proxy or pick a target based on HTTP_HOST.
    proxy = re.findall(r"(?m)^\s*proxy_pass\s+(https?://[^;\s]+)\s*;", source)
    if not proxy or any(item.rstrip("/") != origin for item in proxy):
        fail("target must already proxy only to the configured origin; check target/source first")
    if re.search(r"(?m)^\s*root\s+[^;]*wp-content", source):
        fail("target appears to be a WordPress application vhost")
    return True


def block(host, origin, zone, path):
    # First clause is http {} context (Baota includes vhosts into http {}).
    # nginx -t must pass before reload, otherwise original bytes are restored.
    return (
        "%s\n"
        "# Only for public static WordPress assets on this dedicated proxy entry.\n"
        "# Private downloads, cookies, authorization and query strings are never cached.\n"
        "proxy_cache_path %s levels=1:2 keys_zone=%s:20m inactive=7d max_size=1g use_temp_path=off;\n"
        "%s\n"
        % (START, path, zone, END)
    ), (
        "    %s\n"
        "    location ~* ^/(wp-content/(themes|plugins|uploads)|wp-includes)/.*\\.(css|js|woff2?|ttf|eot|jpg|jpeg|png|gif|webp|avif|svg|ico)$ {\n"
        "        proxy_pass %s;\n"
        "        proxy_set_header Host %s;\n"
        "        proxy_ssl_server_name on;\n"
        "        proxy_cache %s;\n"
        "        proxy_cache_valid 200 7d;\n"
        "        proxy_cache_valid 404 1m;\n"
        "        proxy_cache_bypass $http_authorization $http_cookie $args;\n"
        "        proxy_no_cache $http_authorization $http_cookie $args;\n"
        "        proxy_cache_lock on;\n"
        "        add_header X-JLWA-Media-Cache $upstream_cache_status always;\n"
        "    }\n"
        "    %s\n"
        % (START, origin, urlsplit(origin).netloc, zone, END)
    )


def proposed(source, host, origin):
    validate_proxy_vhost(source, host, origin)
    key = managed_id(host)
    path = CACHE_ROOT / ("jlwa_media_" + key)
    zone = "jlwa_media_" + key
    header, location = block(host, origin, zone, path)
    # Strictly one targeted location /; insertion before it allows regex match
    # to take effect while leaving the dynamic location / intact.
    matches = list(re.finditer(r"(?m)^(\s*)location\s+/\s*\{", source))
    if len(matches) != 1:
        fail("ambiguous root proxy location")
    pos = matches[0].start()
    # Prepending the http-context cache definition is safe only when the
    # enclosing Baota vhost is an http include; nginx -t enforces this.
    return header + source[:pos] + location + source[pos:]


def run_nginx(action):
    baota = Path("/www/server/nginx/sbin/nginx")
    binary = str(baota) if baota.is_file() else (shutil.which("nginx") or str(baota))
    command = [binary, "-t"] if action == "test" else [binary, "-s", "reload"]
    proc = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=25)
    if proc.returncode:
        fail("nginx %s failed: %s" % (action, proc.stdout[-1200:]))


def write_atomic(file, body):
    fd, filename = tempfile.mkstemp(prefix=".jlwa-media-", dir=str(file.parent))
    try:
        info = file.stat()
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            stream.write(body)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(filename, info.st_uid, info.st_gid)
        os.chmod(filename, stat.S_IMODE(info.st_mode))
        os.replace(filename, str(file))
    finally:
        if os.path.exists(filename):
            os.unlink(filename)


def safe_cache_dir(host):
    if CACHE_ROOT.is_symlink():
        fail("cache root cannot be symlink")
    CACHE_ROOT.mkdir(parents=True, exist_ok=True)
    target = CACHE_ROOT / ("jlwa_media_" + managed_id(host))
    if target.is_symlink():
        fail("cache target cannot be symlink")
    user = pwd.getpwnam("www")
    target.mkdir(mode=0o750, exist_ok=True)
    os.chown(str(target), user.pw_uid, user.pw_gid)
    os.chmod(str(target), 0o750)


def state_dir(host):
    if STATE.is_symlink():
        fail("state directory cannot be symlink")
    return STATE / managed_id(host)


def inspect(host, origin, action):
    file = discover(host)
    source = file.read_text(encoding="utf-8")
    plan = proposed(source, host, origin)
    if action == "preview":
        print("Safe candidate: %s (only standalone proxy static assets)" % file.name)
        print("This operation does not change a file.")
        for line in difflib.unified_diff(source.splitlines(), plan.splitlines(), fromfile="current", tofile="proposed", lineterm=""):
            print(line)
        return
    if os.geteuid() != 0:
        fail("apply requires root")
    directory = state_dir(host)
    if directory.exists():
        fail("prior deployment state exists; use rollback first")
    safe_cache_dir(host)
    directory.mkdir(parents=True, exist_ok=False, mode=0o700)
    saved = directory / "original.conf"
    saved.write_text(source, encoding="utf-8")
    os.chmod(saved, 0o600)
    info = {"host": host, "origin": origin, "file": str(file), "before": digest(source), "after": digest(plan)}
    (directory / "state.json").write_text(json.dumps(info), encoding="utf-8")
    os.chmod(directory / "state.json", 0o600)
    try:
        write_atomic(file, plan)
        run_nginx("test")
        run_nginx("reload")
    except Exception:
        write_atomic(file, source)
        run_nginx("test")
        shutil.rmtree(directory)
        raise
    print("Static asset proxy cache installed; dynamic pages and paid downloads unchanged.")
    print("Rollback available for exact managed configuration.")


def rollback(host):
    if os.geteuid() != 0:
        fail("rollback requires root")
    directory = state_dir(host)
    statefile = directory / "state.json"
    if not statefile.is_file():
        fail("no managed state for this host")
    state = json.loads(statefile.read_text(encoding="utf-8"))
    file = discover(host)
    if state.get("host") != host or state.get("file") != str(file):
        fail("site configuration does not match recorded state")
    current = file.read_text(encoding="utf-8")
    if digest(current) != state.get("after"):
        fail("vhost was modified after deployment; manual review required")
    previous = (directory / "original.conf").read_text(encoding="utf-8")
    if digest(previous) != state.get("before"):
        fail("backup verification failed")
    write_atomic(file, previous)
    try:
        run_nginx("test")
        run_nginx("reload")
    except Exception:
        write_atomic(file, current)
        run_nginx("test")
        raise
    shutil.rmtree(directory)
    print("Restored original Nginx vhost and reloaded.")


def main():
    parser = argparse.ArgumentParser(description="Optional Baota media static cache deployment helper")
    parser.add_argument("action", choices=("preview", "apply", "rollback"))
    parser.add_argument("--host", required=True)
    parser.add_argument("--origin", default="")
    options = parser.parse_args()
    try:
        host = valid_host(options.host)
        if options.action == "rollback":
            rollback(host)
        else:
            origin, _ = valid_origin(options.origin, host)
            inspect(host, origin, options.action)
    except (ValueError, OSError, subprocess.SubprocessError, KeyError) as error:
        print("REFUSED: %s" % error, file=sys.stderr)
        sys.exit(2)


if __name__ == "__main__":
    main()
