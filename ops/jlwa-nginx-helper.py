#!/usr/bin/env python3
"""Jiuliu WP Assistant privileged Nginx helper (bundled in plugin; copied root-owned once).

Operations offered to PHP are fixed, without arbitrary command or path parameters.
An explicit root-only install pins the two permitted vhosts and OpenList loopback port.
"""
import argparse
import datetime
import difflib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import shutil
import stat
import subprocess
import sys
import tempfile

STATE = Path("/var/lib/jlwa-download")
CONFIG = Path("/etc/jlwa-download/approved.json")
TARGET = Path("/usr/local/sbin/jlwa-nginx-helper")
SUDOERS = Path("/etc/sudoers.d/jlwa-nginx-helper")
BEGIN = "# BEGIN JLWA-MANAGED-DOWNLOAD"
END = "# END JLWA-MANAGED-DOWNLOAD"
RULE = re.compile(r"(?ms)^\s*" + re.escape(BEGIN) + r".*?" + re.escape(END) + r"\s*")
PORT_RE = re.compile(r"^[1-9][0-9]{0,4}$")
HOST_RE = re.compile(r"^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")
PATH_RE = re.compile(r"^/d/(?:[\w\u4e00-\u9fff-]+/)+$")
VHOST_DIR = Path("/www/server/panel/vhost/nginx")


def fatal(message):
    raise ValueError(message)


def secure_file(path):
    path = Path(path)
    if path.is_symlink() or not path.is_file():
        fatal("refused symlink/missing file")
    st = path.stat()
    if st.st_uid != 0 or st.st_mode & 0o022:
        fatal("vhost must be root-owned and not group/world-writable")
    return st


def validate_vhost(value):
    path = Path(value)
    if str(path.parent) != str(VHOST_DIR) or not re.fullmatch(r"[A-Za-z0-9_.-]+\.conf", path.name):
        fatal("only Baota's exact Nginx vhost files are allowed")
    secure_file(path)
    return str(path)


def discover_vhost(host):
    """Find exactly one root-owned Baota vhost whose server_name contains this host."""
    if not HOST_RE.fullmatch(host):
        fatal("invalid site host")
    matches = []
    for entry in VHOST_DIR.glob("*.conf"):
        try:
            validate_vhost(str(entry))
            body = entry.read_text(encoding="utf-8")
        except (ValueError, OSError, UnicodeError):
            continue
        names = re.findall(r"(?m)^\s*server_name\s+([^;]+);", body)
        if any(host in item.split() for item in names):
            matches.append(str(entry))
    if len(matches) != 1:
        fatal("expected one matching vhost for host; found %d. Check Baota vhost setup." % len(matches))
    return matches[0]


def verify_local_only_port(port):
    # Ensure configured upstream is alive and not publicly listening.
    try:
        with socket.create_connection(("127.0.0.1", int(port)), timeout=2):
            pass
    except OSError:
        fatal("OpenList local port is not reachable on 127.0.0.1; check the host-side port")
    if not shutil.which("ss"):
        return
    try:
        response = subprocess.run(
            ["ss", "-H", "-ltn"], capture_output=True, text=True,
            timeout=5, check=True
        )
    except (OSError, subprocess.SubprocessError):
        return
    for line in response.stdout.splitlines():
        parts = line.split()
        if len(parts) < 4:
            continue
        local = parts[3]
        if local.rsplit(":", 1)[-1] != str(port):
            continue
        bound = local.rsplit(":", 1)[0].strip("[]")
        if bound not in ("127.0.0.1", "::1"):
            fatal("selected port listens on a non-loopback interface; restrict it first")


def detect_docker_container(port, selected=None):
    """Docker is optional; detect local binding and refuse public published ports."""
    if selected and not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,63}", selected):
        fatal("invalid Docker container name")
    if not shutil.which("docker"):
        if selected:
            fatal("Docker not installed or not available for selected container")
        return ""
    try:
        containers = subprocess.run(
            ["docker", "ps", "-q"], capture_output=True, text=True,
            timeout=6, check=True
        ).stdout.split()
    except (OSError, subprocess.SubprocessError):
        if selected:
            fatal("cannot inspect the selected Docker container")
        return ""
    matches = []
    for container_id in containers:
        try:
            response = subprocess.run(
                ["docker", "inspect", "--format", "{{json .NetworkSettings.Ports}}", container_id],
                capture_output=True, text=True, timeout=6, check=True
            )
            mapping = json.loads(response.stdout)
            for publishes in (mapping or {}).values():
                for publish in publishes or []:
                    if str(publish.get("HostPort", "")) != str(port):
                        continue
                    addr = str(publish.get("HostIp", ""))
                    if addr not in ("127.0.0.1", "::1"):
                        fatal("port is exposed beyond loopback; restrict Docker publish address before installing")
                    matches.append(container_id)
        except (OSError, subprocess.SubprocessError, json.JSONDecodeError):
            continue
    if len(matches) > 1:
        fatal("multiple Docker containers match the selected port")
    if selected:
        try:
            response = subprocess.run(
                ["docker", "inspect", "--format", "{{.Id}}", selected],
                capture_output=True, text=True, timeout=6, check=True
            )
            if not matches or not any(response.stdout.strip().startswith(item) for item in matches):
                fatal("specified Docker container is not bound to this local host port")
        except (OSError, subprocess.SubprocessError):
            fatal("cannot find selected Docker container")
    return matches[0] if matches else ""


def validate_source(value):
    if (not PATH_RE.fullmatch(value) or ".." in value or "//" in value
            or len(value.encode("utf-8")) > 200):
        fatal("source must be absolute /d/<mount>/<private-directory>/")
    return value


def nginx_exe():
    for path in ("/www/server/nginx/sbin/nginx", "/usr/sbin/nginx"):
        if Path(path).is_file() and os.access(path, os.X_OK):
            return path
    fatal("Nginx binary not found")


def run_nginx(check=False):
    binary = nginx_exe()
    proc = subprocess.run([binary, "-t" if check else "-s", "reload"] if not check
                          else [binary, "-t"], capture_output=True, text=True, timeout=20)
    if proc.returncode:
        fatal("nginx verification/reload failed: " + (proc.stderr or proc.stdout)[-700:])


def write_file(path, content, mode=None):
    path = Path(path)
    st = path.stat() if path.exists() else None
    if st is not None and path.is_symlink():
        fatal("symlink refused")
    fd, tmp = tempfile.mkstemp(prefix=".jlwa-", dir=str(path.parent))
    try:
        if st:
            os.fchmod(fd, stat.S_IMODE(st.st_mode))
            os.fchown(fd, st.st_uid, st.st_gid)
        else:
            os.fchmod(fd, mode if mode is not None else 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write(content)
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def init_install(args):
    if os.geteuid() != 0:
        fatal("run installer as root")
    wp = validate_vhost(args.wordpress_vhost)
    files = validate_vhost(args.openlist_vhost)
    if wp == files:
        fatal("WordPress and OpenList must be two separate Nginx vhost files")
    if not PORT_RE.fullmatch(args.port) or not 1 <= int(args.port) <= 65535:
        fatal("invalid loopback port")
    source = validate_source(args.source)
    # First-time installers can approve the final public file policy directly:
    # no extra "upgrade" step is needed after the paid download passes testing.
    images_arg = getattr(args, "public_images", "") or ""
    approved_images = validate_public_images(images_arg, {"source": source}) if images_arg else ""
    # install uses --host; install-auto uses --openlist-host.
    # Resolve either spelling here so both CLI modes share the same installer.
    host = (getattr(args, "host", "") or getattr(args, "openlist_host", "")).lower()
    if not HOST_RE.fullmatch(host):
        fatal("OpenList host must be a plain DNS name")
    if not re.fullmatch(r"[a-z_][a-z0-9_-]{0,30}", args.php_user):
        fatal("invalid PHP service user")
    import pwd
    pwd.getpwnam(args.php_user)
    # Complete all checks before creating any persistent install files.
    # Refuse pre-existing or half-installed infrastructure rather than overwriting it.
    if CONFIG.exists() or CONFIG.is_symlink():
        fatal("already installed: inspect existing root-approved manifest")
    if TARGET.exists() or TARGET.is_symlink() or SUDOERS.exists() or SUDOERS.is_symlink():
        fatal("existing helper/sudoers installation found; inspect it manually")
    run_nginx(check=True)
    verify_local_only_port(int(args.port))
    container_id = detect_docker_container(int(args.port), getattr(args, "container", "") or "")
    manifest = {"wordpress_vhost": wp, "openlist_vhost": files,
                "port": int(args.port), "source": source,
                "host": host, "php_user": args.php_user,
                "docker_container_detected": bool(container_id),
                "public_access_policy": "images_only" if approved_images else "paid_only"}
    if approved_images:
        manifest["public_images"] = approved_images
    # Copy binary OUT of WordPress: subsequent plugin updates cannot modify
    # the root-owned helper without another explicit administrator action.
    source_script = Path(__file__).resolve()
    content = source_script.read_text(encoding="utf-8")
    sudoers = (
        "Cmnd_Alias JLWA_NGINX = " + ", ".join(str(TARGET) + " " + op
        for op in ("status", "preview", "prepare", "confirm", "protect", "rollback")) + "\n"
        + args.php_user + " ALL=(root) NOPASSWD: JLWA_NGINX\n"
        + "Defaults!JLWA_NGINX !requiretty\n"
    )
    # Validate sudoers syntax before installing anything, then create the
    # three installation files atomically and clean up only files created here
    # if an I/O or permission problem interrupts the process.
    if shutil.which("visudo"):
        fd, sudoers_candidate = tempfile.mkstemp(prefix="jlwa-sudoers-")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                handle.write(sudoers)
            os.chmod(sudoers_candidate, 0o440)
            subprocess.run(["visudo", "-cf", sudoers_candidate], check=True, timeout=10)
        finally:
            if os.path.exists(sudoers_candidate):
                os.unlink(sudoers_candidate)

    created = []
    try:
        CONFIG.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
        for path, contents, mode in (
            (TARGET, content, 0o755),
            (CONFIG, json.dumps(manifest, ensure_ascii=False), 0o600),
            (SUDOERS, sudoers, 0o440),
        ):
            write_file(path, contents, mode)
            created.append(path)
    except Exception:
        for path in reversed(created):
            path.unlink(missing_ok=True)
        raise
    print("installed; admin operations restricted to status/preview/prepare/confirm/protect/rollback")


def read_manifest():
    if os.geteuid() != 0:
        fatal("privileged helper must run via restricted sudo")
    if not CONFIG.is_file() or CONFIG.is_symlink() or CONFIG.stat().st_uid != 0:
        fatal("root-only manifest missing")
    obj = json.loads(CONFIG.read_text(encoding="utf-8"))
    obj["wordpress_vhost"] = validate_vhost(obj["wordpress_vhost"])
    obj["openlist_vhost"] = validate_vhost(obj["openlist_vhost"])
    obj["source"] = validate_source(obj["source"])
    if not HOST_RE.fullmatch(obj["host"]) or not 1 <= obj["port"] <= 65535:
        fatal("invalid root-only manifest")
    policy = obj.get("public_access_policy", "paid_only")
    if policy not in ("paid_only", "images_only"):
        fatal("invalid root-approved public access policy")
    if policy == "images_only":
        obj["public_images"] = validate_public_images(obj.get("public_images", ""), obj)
    return obj


def snippet(kind, data):
    prefix = data["source"]
    if kind == "wp":
        text = """location ^~ /_jlwa_private_openlist/ {
    internal;
    proxy_pass http://127.0.0.1:%d%s;
    proxy_set_header Host %s;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Authorization "";
    proxy_set_header Cookie "";
    proxy_set_header Range $http_range;
    proxy_set_header If-Range $http_if_range;
    proxy_buffering off;
    proxy_request_buffering off;
    proxy_read_timeout 600s;
    proxy_redirect off;
    proxy_hide_header Location;
    proxy_intercept_errors on;
    error_page 301 302 303 307 308 =502 /__jlwa_private_upstream_error;
    add_header Cache-Control "private, no-store" always;
}
location = /__jlwa_private_upstream_error { internal; return 502; }""" % (data["port"], prefix, data["host"])
    elif data.get("public_access_policy") == "images_only":
        # Use the normalized $uri at server rewrite phase, BEFORE the site's
        # existing reverse-proxy location/caching rules. Restrict all /d and
        # /p mounts, not just today's paid folder; images alone are public.
        # Existing image proxy configuration is unchanged, including headers
        # and the upstream path. Nginx normalizes percent-encoded paths.
        images = validate_source(data.get("public_images", ""))
        image_suffix = images[len("/d/"):].rstrip("/")
        text = ( '# Public file routes are denied except the exact image subtree.\n'
                 'if ($uri ~ "^/(?:d|p)(?:$|/(?!%s(?:/|$)))") { return 403; }'
                 % re.escape(image_suffix) )
    else:
        preview = "/p/" + prefix[len("/d/"):]
        text = """location = %s { return 403; }
location ^~ %s { return 403; }
location = %s { return 403; }
location ^~ %s { return 403; }""" % (prefix.rstrip("/"), prefix, preview.rstrip("/"), preview)
    return "\n" + BEGIN + " " + kind + "\n" + text + "\n" + END + "\n"


def transform(current, kind, config):
    desired = snippet(kind, config)
    if BEGIN in current or END in current:
        if current.count(BEGIN) != 1 or current.count(END) != 1:
            fatal("unexpected managed marker; refusing to overwrite")
        original = RULE.sub("", current, count=1)
        if BEGIN in original or END in original:
            fatal("invalid managed block")
        # Only allow the exact generated contents at idempotent deployment.
        if desired not in current:
            fatal("managed block differs from expected; manual review needed")
        return current
    # Refuse complex vhost: exactly one server block, and expected server_name.
    matches = list(re.finditer(r"(?m)^\s*server\s*\{", current))
    if len(matches) != 1:
        fatal("expected exactly one server block; use manual configuration for complex vhosts")
    server_name = re.findall(r"(?m)^\s*server_name\s+([^;]+);", current)
    if not server_name:
        fatal("missing server_name")
    if kind == "openlist" and config["host"] not in (" ".join(server_name)).split():
        fatal("OpenList approved hostname is absent from server_name")
    # Any server block with one server_name is confined to the root-approved vhost.
    match = matches[0]
    position = match.end()
    return current[:position] + desired + current[position:]


def validate_public_images(images, config):
    """Restrict images to one distinct directory of the already approved mount."""
    images = validate_source(images)
    source = config["source"]
    mount = "/" + "/".join(source.strip("/").split("/")[:2]) + "/"
    if not images.startswith(mount) or images == mount:
        fatal("public images must be a directory within the approved OpenList mount")
    if images.startswith(source) or source.startswith(images):
        fatal("public image folder must not overlap the private paid folder")
    return images


def images_only_proposal(current, config, images):
    """Safely migrate an exact old paid-only managed block to image-only rules."""
    if current.count(BEGIN) != 1 or current.count(END) != 1:
        fatal("exactly one managed OpenList block is required")
    if config["host"] not in " ".join(re.findall(r"(?m)^\s*server_name\s+([^;]+);", current)).split():
        fatal("approved OpenList hostname is absent from vhost")
    if len(list(re.finditer(r"(?m)^\s*server\s*\{", current))) != 1:
        fatal("expected exactly one server block")
    old = snippet("openlist", dict(config, public_access_policy="paid_only"))
    proposed_config = dict(config, public_access_policy="images_only", public_images=images)
    new = snippet("openlist", proposed_config)
    if new in current:
        return current
    if old not in current or current.count(old) != 1:
        fatal("existing paid-only managed block differs from expected; refuse migration")
    return current.replace(old, new, 1)


def migrate_public_images(args):
    """Explicit root-only image allowlist upgrade of an already protected site.

    The installed helper is root-owned and intentionally unaffected by WordPress
    plugin updates. This command atomically upgrades that helper and its
    approved manifest, and upgrades the source vhost while retaining its
    original rollback backup. PHP never receives a generic root-write ability.
    """
    if os.geteuid() != 0:
        fatal("image-only migration requires an explicit root command")
    config = read_manifest()
    images = validate_public_images(args.public_images, config)
    statefile = STATE / "state.json"
    if not statefile.is_file() or statefile.is_symlink() or not STATE.is_dir():
        fatal("protected deployment state missing")
    secure_file(TARGET)
    secure_file(SUDOERS)
    target_source = Path(__file__).resolve()
    if target_source == TARGET or not target_source.is_file():
        fatal("use the new plugin's script, not the already installed helper")
    if config.get("public_access_policy", "paid_only") not in ("paid_only", "images_only"):
        fatal("unrecognized existing access policy")
    if config.get("public_access_policy") == "images_only":
        if config.get("public_images") != images:
            fatal("image-only already installed for a different public directory; review manually")
        state = load_state()
        source = Path(config["openlist_vhost"]).read_text(encoding="utf-8")
        if ( state.get("phase") != "protected" or
             digest(source) != state.get("after", {}).get("openlist") or
             snippet("openlist", config) not in source ):
            fatal("image-only manifest exists but deployed rule state differs; inspect manually")
        print("image-only policy already installed; no changes")
        return

    with (STATE / "lock").open("a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        state = load_state()
        if state.get("phase") != "protected" or not state.get("confirmed"):
            fatal("only a verified and already protected deployment can be migrated")
        if not state.get("backups", {}).get("openlist") or not state.get("backups", {}).get("wp"):
            fatal("original rollback backups missing")
        wp_path = Path(config["wordpress_vhost"])
        source_path = Path(config["openlist_vhost"])
        old_wp = wp_path.read_text(encoding="utf-8")
        old_source = source_path.read_text(encoding="utf-8")
        if digest(old_wp) != state.get("after", {}).get("wp"):
            fatal("WordPress vhost differs from deployment history")
        if digest(old_source) != state.get("after", {}).get("openlist"):
            fatal("OpenList vhost differs from deployment history")
        if snippet("wp", config) not in old_wp:
            fatal("WordPress internal proxy changed; refuse migration")
        proposed = images_only_proposal(old_source, config, images)
        if proposed == old_source:
            fatal("image-only rule already in Nginx while manifest is not updated")
        if not Path(state["backups"]["openlist"]).is_file():
            fatal("OpenList original rollback backup missing")
        if not Path(state["backups"]["wp"]).is_file():
            fatal("WordPress original rollback backup missing")
        # Confirm the current nginx configuration before any writes.
        run_nginx(check=True)
        if args.op == "preview-images-only":
            print("--- OpenList proposed public file allowlist ---")
            print("".join(difflib.unified_diff(
                old_source.splitlines(True), proposed.splitlines(True),
                fromfile="current", tofile="proposed"))[:12000])
            print("Read-only preview; existing Nginx, manifest, helper and backups unchanged")
            return

        old_target = TARGET.read_text(encoding="utf-8")
        old_config = CONFIG.read_text(encoding="utf-8")
        old_state = statefile.read_text(encoding="utf-8")
        new_manifest = dict(config, public_access_policy="images_only", public_images=images)
        new_state = dict(state)
        new_state["after"] = dict(state["after"], openlist=digest(proposed))
        new_state["public_access_policy"] = "images_only"
        try:
            write_file(TARGET, target_source.read_text(encoding="utf-8"))
            write_file(CONFIG, json.dumps(new_manifest, ensure_ascii=False))
            write_file(source_path, proposed)
            run_nginx(check=True)
            run_nginx(check=False)
            save_state(new_state)
        except Exception:
            # Restore exact bytes for every file before returning an error.
            write_file(source_path, old_source)
            write_file(CONFIG, old_config)
            write_file(TARGET, old_target)
            write_file(statefile, old_state)
            run_nginx(check=True)
            run_nginx(check=False)
            raise
        print("image-only public file allowlist deployed; original rollback backups retained")
        print("verify public images, denied other /d/ and /p/ paths, paid downloads and WebDAV")


def rollback_public_images():
    """Revert ONLY the public /d and /p allowlist, keeping paid-only blocking.

    Unlike the general rollback, this does not alter the WordPress internal
    proxy and does not remove the paid-resource 403 rules. A failed image
    allowlist deployment must be safely reversible without exposing paid ZIPs.
    """
    if os.geteuid() != 0:
        fatal("image-only rollback requires an explicit root command")
    config = read_manifest()
    if config.get("public_access_policy") != "images_only":
        fatal("image-only policy is not active")
    with (STATE / "lock").open("a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        state = load_state()
        source_path = Path(config["openlist_vhost"])
        wp_path = Path(config["wordpress_vhost"])
        current = source_path.read_text(encoding="utf-8")
        if (state.get("phase") != "protected" or not state.get("confirmed")
                or digest(current) != state.get("after", {}).get("openlist")
                or digest(wp_path.read_text(encoding="utf-8")) != state.get("after", {}).get("wp")):
            fatal("deployment changed since image-only upgrade; refuse unsafe rollback")
        active = snippet("openlist", config)
        original = snippet("openlist", dict(config, public_access_policy="paid_only"))
        if (current.count(BEGIN) != 1 or current.count(END) != 1
                or current.count(active) != 1):
            fatal("image-only managed block differs; refusing partial rollback")
        restored = current.replace(active, original, 1)
        manifest_before = CONFIG.read_text(encoding="utf-8")
        statefile = STATE / "state.json"
        state_before = statefile.read_text(encoding="utf-8")
        next_manifest = dict(config, public_access_policy="paid_only")
        next_manifest.pop("public_images", None)
        next_state = dict(state, public_access_policy="paid_only")
        next_state["after"] = dict(state["after"], openlist=digest(restored))
        run_nginx(check=True)
        try:
            write_file(source_path, restored)
            run_nginx(check=True)
            run_nginx(check=False)
            write_file(CONFIG, json.dumps(next_manifest, ensure_ascii=False))
            save_state(next_state)
        except Exception:
            write_file(source_path, current)
            write_file(CONFIG, manifest_before)
            write_file(statefile, state_before)
            run_nginx(check=True)
            run_nginx(check=False)
            raise
        print("image-only restriction reverted to previous paid-directory blocking")
        print("WordPress internal proxy and original rollback backups preserved")


def backup(path, label, current):
    directory = STATE / "backups"
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    timestamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%S%f")
    dest = directory / (timestamp + "-" + label + ".conf")
    write_file(dest, current, 0o600)
    return str(dest)


def load_state():
    statefile = STATE / "state.json"
    if not statefile.exists():
        return {"phase": "new", "backups": {}, "after": {}}
    return json.loads(statefile.read_text(encoding="utf-8"))


def save_state(state):
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    write_file(STATE / "state.json", json.dumps(state, ensure_ascii=False), 0o600)


def digest(body):
    return hashlib.sha256(body.encode("utf-8")).hexdigest()


def mutate(kind, config, state):
    path = Path(config["wordpress_vhost"] if kind == "wp" else config["openlist_vhost"])
    old = path.read_text(encoding="utf-8")
    new = transform(old, kind, config)
    if old == new:
        return False
    backup_file = backup(path, kind, old)
    state["backups"][kind] = backup_file
    try:
        write_file(path, new)
        run_nginx(check=True)
        run_nginx(check=False)
    except Exception:
        write_file(path, old)
        run_nginx(check=True)
        run_nginx(check=False)
        raise
    state["after"][kind] = digest(new)
    save_state(state)
    return True


def rollback(config, state):
    if not state.get("backups"):
        print("nothing to rollback")
        return
    saved = {}
    for kind, backpath in state["backups"].items():
        path = Path(config["wordpress_vhost"] if kind == "wp" else config["openlist_vhost"])
        current = path.read_text(encoding="utf-8")
        if digest(current) != state["after"].get(kind):
            fatal("vhost has been independently edited; refuse destructive rollback")
        saved[path] = (current, Path(backpath).read_text(encoding="utf-8"))
    try:
        for path, (current, orig) in saved.items():
            write_file(path, orig)
        run_nginx(check=True)
        run_nginx(check=False)
    except Exception:
        for path, (current, orig) in saved.items():
            write_file(path, current)
        run_nginx(check=True)
        run_nginx(check=False)
        raise
    state["phase"] = "rolled_back"
    state["after"] = {}
    state["backups"] = {}
    save_state(state)


def main():
    parser = argparse.ArgumentParser(description="Restricted Jiuliu Nginx deployment")
    commands = parser.add_subparsers(dest="op", required=True)
    installer = commands.add_parser("install")
    installer.add_argument("--wordpress-vhost", required=True)
    installer.add_argument("--openlist-vhost", required=True)
    installer.add_argument("--port", required=True)
    installer.add_argument("--source", required=True)
    installer.add_argument("--host", required=True)
    installer.add_argument("--php-user", default="www")
    installer.add_argument("--container", default="")
    installer.add_argument("--public-images", default="")
    automatic = commands.add_parser("install-auto")
    automatic.add_argument("--wordpress-host", required=True)
    automatic.add_argument("--openlist-host", required=True)
    automatic.add_argument("--port", required=True)
    automatic.add_argument("--source", required=True)
    automatic.add_argument("--php-user", default="www")
    automatic.add_argument("--container", default="")
    automatic.add_argument("--public-images", default="")
    for op in ("status", "preview", "prepare", "confirm", "protect", "rollback"):
        commands.add_parser(op)
    for op in ("preview-images-only", "upgrade-images-only"):
        cmd = commands.add_parser(op)
        cmd.add_argument("--public-images", required=True)
    commands.add_parser("rollback-images-only")
    args = parser.parse_args()
    if args.op in ("preview-images-only", "upgrade-images-only"):
        migrate_public_images(args)
        return
    if args.op == "rollback-images-only":
        rollback_public_images()
        return
    if args.op == "install-auto":
        if os.geteuid() != 0:
            fatal("automatic install requires root")
        wp_host = args.wordpress_host.lower()
        files_host = args.openlist_host.lower()
        args.wordpress_vhost = discover_vhost(wp_host)
        args.openlist_vhost = discover_vhost(files_host)
        print("Found distinct WordPress/OpenList Nginx configurations; verifying local port and access.")
        init_install(args)
        return
    if args.op == "install":
        init_install(args)
        return
    config = read_manifest()
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (STATE / "lock").open("a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        state = load_state()
        if args.op == "status":
            print(json.dumps({"phase": state["phase"], "wp_configured": BEGIN in Path(config["wordpress_vhost"]).read_text(),
                              "source_protected": BEGIN in Path(config["openlist_vhost"]).read_text(),
                              "confirmed": bool(state.get("confirmed")),
                              "has_backup": bool(state["backups"]),
                              "approved_host": config["host"],
                              "approved_source": config["source"],
                              "approved_port": config["port"],
                              "docker_container_detected": bool(config.get("docker_container_detected")),
                              "public_access_policy": config.get("public_access_policy", "paid_only"),
                              "approved_public_images": config.get("public_images", "")}, ensure_ascii=False))
        elif args.op == "preview":
            for kind, vhost in (("wp", config["wordpress_vhost"]), ("openlist", config["openlist_vhost"])):
                old = Path(vhost).read_text()
                new = transform(old, kind, config)
                print("--- %s ---" % kind)
                print("".join(difflib.unified_diff(old.splitlines(True), new.splitlines(True), fromfile="before", tofile="proposed"))[:8000])
        elif args.op == "prepare":
            if state["phase"] not in ("new", "rolled_back", "wp_prepared"):
                fatal("invalid stage: rollback first")
            mutate("wp", config, state)
            state["phase"] = "wp_prepared"
            state["confirmed"] = False
            save_state(state)
            print("WordPress internal proxy prepared; test a purchased download before confirmation")
        elif args.op == "confirm":
            if state["phase"] != "wp_prepared":
                fatal("WordPress proxy must be prepared before buyer verification")
            state["confirmed"] = True
            save_state(state)
            print("operator explicitly confirmed bought-user download; this is not proof of end-to-end transfer")
        elif args.op == "protect":
            if state["phase"] != "wp_prepared" or not state.get("confirmed"):
                fatal("requires prepared internal proxy and explicit purchase-download confirmation")
            mutate("openlist", config, state)
            state["phase"] = "protected"
            save_state(state)
            if config.get("public_access_policy") == "images_only":
                print("public OpenList file routes now allow only the approved image directory; "
                      "verify real images, paid downloads, CDN and API separately")
            else:
                print("public OpenList paid-file route blocked; verify external/CDN/API bypasses independently")
        elif args.op == "rollback":
            rollback(config, state)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError) as exc:
        print("ERROR: " + str(exc), file=sys.stderr)
        sys.exit(1)
