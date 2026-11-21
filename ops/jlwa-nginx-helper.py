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
        fatal("ss is required to verify that the OpenList port is loopback-only")
    try:
        response = subprocess.run(
            ["ss", "-H", "-ltn"], capture_output=True, text=True,
            timeout=5, check=True
        )
    except (OSError, subprocess.SubprocessError):
        fatal("cannot verify the OpenList listening address with ss")
    matched = False
    for line in response.stdout.splitlines():
        parts = line.split()
        if len(parts) < 4:
            continue
        local = parts[3]
        if local.rsplit(":", 1)[-1] != str(port):
            continue
        matched = True
        bound = local.rsplit(":", 1)[0].strip("[]")
        if bound not in ("127.0.0.1", "::1"):
            fatal("selected port listens on a non-loopback interface; restrict it first")
    if not matched:
        fatal("cannot confirm a loopback-only listener for the selected port")


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
    if len(source.strip("/").split("/")) < 3:
        fatal("private source must identify a folder inside an OpenList mount")
    # First-time installers can approve the final public file policy directly:
    # no extra "upgrade" step is needed after the paid download passes testing.
    images_arg = getattr(args, "public_images", "") or ""
    if not images_arg:
        fatal("public image directory required: fail closed; legacy paid-only mode is not installable")
    approved_images = validate_public_images(images_arg, {"source": source})
    # install uses --host; install-auto uses --openlist-host.
    # Resolve either spelling here so both CLI modes share the same installer.
    host = (getattr(args, "host", "") or getattr(args, "openlist_host", "")).lower()
    if not HOST_RE.fullmatch(host):
        fatal("OpenList host must be a plain DNS name")
    raw_hosts = getattr(args, "openlist_hosts", "") or ""
    aliases = raw_hosts.split(",")
    if not raw_hosts or any(not HOST_RE.fullmatch(name) for name in aliases):
        fatal("all public OpenList hostnames must be explicit DNS names")
    if len(set(aliases)) != len(aliases) or host not in aliases:
        fatal("OpenList public host list must be unique and contain the primary host")
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
    manifest = {"wordpress_vhost": wp, "openlist_vhost": files,
                "port": int(args.port), "source": source,
                "host": host, "public_hosts": aliases,
                "php_user": args.php_user,
                "docker_container_detected": False,
                "public_access_policy": "strict_images_only",
                "public_images": approved_images,
                "strict_rule_revision": 2}
    # Fail before installing a root helper if either vhost cannot safely
    # accept the final rules. An existing managed block needs human review:
    # this is a clean-install path, not an implicit migration.
    for kind, vhost in (("wp", wp), ("openlist", files)):
        old = Path(vhost).read_text(encoding="utf-8")
        if BEGIN in old or END in old:
            fatal("existing JLWA Nginx rules detected; clean installer refuses overwrite")
        transform(old, kind, manifest)
    # A CDN alias in a different vhost could bypass the entire rule. Never
    # report protection unless EVERY approved public hostname shares this
    # exact OpenList configuration. Non-Baota layouts require manual review.
    for alias in aliases:
        if discover_vhost(alias) != files:
            fatal("OpenList aliases do not share one Nginx vhost; refuse incomplete protection")
    run_nginx(check=True)
    verify_local_only_port(int(args.port))
    container_id = detect_docker_container(int(args.port), getattr(args, "container", "") or "")
    manifest["docker_container_detected"] = bool(container_id)
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
    public_hosts = obj.get("public_hosts", [])
    if not public_hosts or obj["host"] not in public_hosts or len(public_hosts) != len(set(public_hosts)) or any(not HOST_RE.fullmatch(item) for item in public_hosts):
        fatal("invalid approved OpenList hostname list")
    if obj.get("public_access_policy") != "strict_images_only" or obj.get("strict_rule_revision") != 2:
        fatal("this installer only supports fresh strict image-only deployments")
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
    else:
        # All NEW installs use exactly one fail-closed public rule.
        # Apply at server rewrite phase, before any catch-all proxy locations.
        if data.get("public_access_policy") != "strict_images_only":
            fatal("unsafe public policy: strict image-only is mandatory")
        images = validate_public_images(data["public_images"], data)
        subtree = images.rstrip("/")
        text = (
            '# Strict public boundary: only GET/HEAD under one public directory.\n'
            '# Admin, API, DAV, login, other file mounts and pages are local only.\n'
            'set $jlwa_openlist_public_allowed 0;\n'
            'if ($uri ~ "^%s(?:/|$)") { set $jlwa_openlist_public_allowed 1; }\n'
            'if ($request_method !~ "^(?:GET|HEAD)$") { set $jlwa_openlist_public_allowed 0; }\n'
            'if ($request_uri ~* "%%(?:25|2e|2f|5c|00)") { set $jlwa_openlist_public_allowed 0; }\n'
            'if ($remote_addr ~ "^(?:127\\.0\\.0\\.1|::1)$") { set $jlwa_openlist_public_allowed 1; }\n'
            'if ($jlwa_openlist_public_allowed = 0) { return 403; }'
            % re.escape(subtree)
        )
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
    # Both source and public images must be real directories beneath /d/<mount>/.
    # They may use different OpenList mounts, provided that the paths never
    # overlap; the Nginx public allowlist remains exactly one subtree.
    if len(images.strip("/").split("/")) < 3:
        fatal("public images must name a directory inside an OpenList mount")
    if images.startswith(source) or source.startswith(images):
        fatal("public image folder must not overlap the private paid folder")
    return images


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


def render_portable_config(args):
    """Print generic, copyable snippets without root, filesystem or Nginx writes.

    Non-Baota sites must install these inside *every* corresponding Nginx
    server block themselves, and verify local-only OpenList binding.
    """
    if not PORT_RE.fullmatch(args.port) or not 1 <= int(args.port) <= 65535:
        fatal("invalid OpenList loopback port")
    source = validate_source(args.source)
    if len(source.strip("/").split("/")) < 3:
        fatal("private source must name a directory inside an OpenList mount")
    public = validate_public_images(args.public_images, {"source": source})
    host = args.openlist_host.lower()
    aliases = args.openlist_hosts.lower().split(",")
    if not HOST_RE.fullmatch(host) or host not in aliases or len(set(aliases)) != len(aliases):
        fatal("invalid OpenList hostname list")
    if any(not HOST_RE.fullmatch(name) for name in aliases):
        fatal("invalid OpenList hostname")
    config = {"port":int(args.port), "source":source, "host":host,
              "public_images":public, "public_access_policy":"strict_images_only",
              "strict_rule_revision":2}
    print("# READ-ONLY GENERATED SNIPPETS; NO AUTOMATIC INSTALLATION WAS DONE.")
    print("# Insert the first block inside the WordPress server {}, and the")
    print("# second block into EVERY OpenList public-domain server {}.")
    print("# OpenList must listen on 127.0.0.1:%d only; prevent cache bypass." % int(args.port))
    print("# Approved OpenList public hostnames: %s" % ", ".join(aliases))
    print("# Wordpress internal-only block:")
    print(snippet("wp", config))
    print("# OpenList public-domain block:")
    print(snippet("openlist", config))
    print("# Verify nginx -t, reload and GET/HEAD image 200, root/API/private 403.")
    print("# Verify previously purchased WordPress download BEFORE blocking public files.")


def main():
    parser = argparse.ArgumentParser(description="Restricted Jiuliu Nginx deployment")
    commands = parser.add_subparsers(dest="op", required=True)
    installer = commands.add_parser("install")
    installer.add_argument("--wordpress-vhost", required=True)
    installer.add_argument("--openlist-vhost", required=True)
    installer.add_argument("--port", required=True)
    installer.add_argument("--source", required=True)
    installer.add_argument("--host", required=True)
    installer.add_argument("--openlist-hosts", required=True)
    installer.add_argument("--php-user", default="www")
    installer.add_argument("--container", default="")
    installer.add_argument("--public-images", required=True)
    automatic = commands.add_parser("install-auto")
    automatic.add_argument("--wordpress-host", required=True)
    automatic.add_argument("--openlist-host", required=True)
    automatic.add_argument("--openlist-hosts", required=True)
    automatic.add_argument("--port", required=True)
    automatic.add_argument("--source", required=True)
    automatic.add_argument("--php-user", default="www")
    automatic.add_argument("--container", default="")
    automatic.add_argument("--public-images", required=True)
    portable = commands.add_parser("render-config", help="read-only portable Nginx snippet generator")
    portable.add_argument("--wordpress-host", required=True)
    portable.add_argument("--openlist-host", required=True)
    portable.add_argument("--openlist-hosts", required=True)
    portable.add_argument("--port", required=True)
    portable.add_argument("--source", required=True)
    portable.add_argument("--php-user", default="www")
    portable.add_argument("--container", default="")
    portable.add_argument("--public-images", required=True)
    for op in ("status", "preview", "prepare", "confirm", "protect", "rollback"):
        commands.add_parser(op)
    args = parser.parse_args()
    if args.op == "render-config":
        render_portable_config(args)
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
                              "approved_public_hosts": config["public_hosts"],
                              "approved_source": config["source"],
                              "approved_port": config["port"],
                              "docker_container_detected": bool(config.get("docker_container_detected")),
                              "public_access_policy": "strict_images_only",
                              "strict_rule_revision": 2,
                              "approved_public_images": config["public_images"]}, ensure_ascii=False))
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
            for alias in config["public_hosts"]:
                if discover_vhost(alias) != config["openlist_vhost"]:
                    fatal("public OpenList hostname moved to another vhost; refuse incomplete protection")
            mutate("openlist", config, state)
            state["phase"] = "protected"
            save_state(state)
            print("public OpenList now allows only approved image URL GET/HEAD; "
                  "all other public routes are local-only. Verify paid downloads, CDN and local API.")
        elif args.op == "rollback":
            rollback(config, state)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError) as exc:
        print("ERROR: " + str(exc), file=sys.stderr)
        sys.exit(1)
