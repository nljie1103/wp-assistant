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
PORT_RE = re.compile(r"^[1-9][0-9]{1,4}$")
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
    host = args.host.lower()
    if not HOST_RE.fullmatch(host):
        fatal("OpenList host must be a plain DNS name")
    if not re.fullmatch(r"[a-z_][a-z0-9_-]{0,30}", args.php_user):
        fatal("invalid PHP service user")
    import pwd
    pwd.getpwnam(args.php_user)
    run_nginx(check=True)
    CONFIG.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    manifest = {"wordpress_vhost": wp, "openlist_vhost": files,
                "port": int(args.port), "source": source,
                "host": host, "php_user": args.php_user}
    if CONFIG.exists():
        fatal("already installed: remove only after manual review and backup")
    write_file(CONFIG, json.dumps(manifest, ensure_ascii=False), 0o600)
    # Copy binary OUT of WordPress: no PHP or plugin update can alter the root-owned helper.
    source_script = Path(__file__).resolve()
    content = source_script.read_text(encoding="utf-8")
    write_file(TARGET, content, 0o755)
    sudoers = (
        "Cmnd_Alias JLWA_NGINX = " + ", ".join(str(TARGET) + " " + op
        for op in ("status", "preview", "prepare", "confirm", "protect", "rollback")) + "\n"
        + args.php_user + " ALL=(root) NOPASSWD: JLWA_NGINX\n"
        + "Defaults!JLWA_NGINX !requiretty\n"
    )
    write_file(SUDOERS, sudoers, 0o440)
    if shutil.which("visudo"):
        subprocess.run(["visudo", "-cf", str(SUDOERS)], check=True, timeout=10)
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
    for op in ("status", "preview", "prepare", "confirm", "protect", "rollback"):
        commands.add_parser(op)
    args = parser.parse_args()
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
                              "approved_port": config["port"]}, ensure_ascii=False))
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
            print("public OpenList paid-file route blocked; verify external/CDN/API bypasses independently")
        elif args.op == "rollback":
            rollback(config, state)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError) as exc:
        print("ERROR: " + str(exc), file=sys.stderr)
        sys.exit(1)
