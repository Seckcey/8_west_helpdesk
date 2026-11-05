#!/usr/bin/env python3
"""Exercise private-link logging in a disposable, loopback-only Ubuntu Apache."""

import argparse
import http.client
import re
import socket
import ssl
import subprocess
import tempfile
import time
from pathlib import Path


DEPLOY = Path(__file__).resolve().parents[1] / "deploy"
APACHE = "/usr/sbin/apache2"
MODULES = (
    "mpm_event", "authz_core", "dir", "headers", "filter", "deflate",
    "expires", "rewrite", "ssl",
)
QUERY = "fixture_query_marker"
REFERER = "fixture_referer_marker"


def free_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


def safeharbor_vhost(root, port, tls):
    suffix = "-le-ssl" if tls else ""
    template = (DEPLOY / f"apache-safeharbor{suffix}.conf").read_text(encoding="utf-8")
    original_port = 443 if tls else 80
    template = template.replace(f"<VirtualHost *:{original_port}>", f"<VirtualHost 127.0.0.1:{port}>")
    template = template.replace("/srv/8west/apps/safeharbor/current/public", str(root / "www"))
    if tls:
        # Use a fixture certificate and exercise an internal rewrite on TLS.
        template = re.sub(r"(?m)^    SSLCertificateFile .*", f"    SSLCertificateFile {root}/cert.pem", template)
        template = re.sub(r"(?m)^    SSLCertificateKeyFile .*", f"    SSLCertificateKeyFile {root}/key.pem", template)
        template = template.replace(
            "    Include /etc/letsencrypt/options-ssl-apache.conf",
            "    SSLEngine on\n    RewriteEngine on\n    RewriteRule ^/?fixture-rewrite$ /ordinary.txt [L]",
        )
    return template


def id_vhost(root, port, tls, directives):
    transport = (
        f"SSLEngine on\nSSLCertificateFile {root}/cert.pem\nSSLCertificateKeyFile {root}/key.pem"
        if tls else "RewriteEngine on\nRewriteRule ^ https://id.8westit.com%{REQUEST_URI} [R=301,END]"
    )
    return f"""<VirtualHost 127.0.0.1:{port}>
ServerName id.8westit.com
DocumentRoot "{root}/www"
{directives}
{transport}
</VirtualHost>
"""


def request(port, tls, host, method, path, agent):
    client = (
        http.client.HTTPSConnection("127.0.0.1", port, timeout=3, context=ssl._create_unverified_context())
        if tls else http.client.HTTPConnection("127.0.0.1", port, timeout=3)
    )
    try:
        client.request(method, f"{path}?{QUERY}=value", headers={
            "Host": host, "Referer": f"https://{host}/old-link?{REFERER}=value",
            "User-Agent": agent, "Connection": "close",
        })
        response = client.getresponse()
        response.read()
        return response.status
    finally:
        client.close()


def verify_access(lines, agent, method, status, protocol_host=None, private=True):
    matches = [line for line in lines if line.endswith(f'"{agent}"')]
    assert len(matches) == 1, f"{agent} produced {len(matches)} access records"
    line = matches[0]
    assert re.search(rf'"{method} /\S* HTTP/1\.1" {status} \d+ ', line), line
    if protocol_host:
        assert line.startswith(f"{protocol_host} "), line
    if private:
        assert QUERY not in line and REFERER not in line and re.search(r' "-" "[^"]+"$', line), agent
    else:
        assert QUERY in line and REFERER in line, agent


def exercise(root, http_port, tls_port, include_id):
    log_dir = root / "logs"
    devices = "/portal/computer-access.php"
    invite = "/accept-employee-invite.php"
    cases = [
        ("sh-http", False, "safeharbor.8westit.com", "GET", devices, 301),
        ("sh-tls", True, "safeharbor.8westit.com", "GET", devices, 200),
        ("sh-post", True, "safeharbor.8westit.com", "POST", devices, 200),
        ("sh-encoded", True, "www.safeharbor.8westit.com", "GET", "/portal/%63omputer-access.php", 200),
        ("sh-normalized", True, "safeharbor.8westit.com", "GET", "/portal//./computer-access.php", 200),
        ("sh-path-info", True, "safeharbor.8westit.com", "GET", devices + "/extra", 200),
        ("sh-rewrite", True, "safeharbor.8westit.com", "GET", "/fixture-rewrite", 200),
        ("sh-ordinary", True, "safeharbor.8westit.com", "GET", "/ordinary.txt", 200),
        ("sh-denied", True, "safeharbor.8westit.com", "GET", "/fixture-denied/sh", 403),
        ("control", False, "control.invalid", "GET", "/ordinary.txt", 200),
    ]
    if include_id:
        cases.extend([
            ("id-http", False, "id.8westit.com", "GET", invite, 301),
            ("id-tls", True, "id.8westit.com", "GET", invite, 200),
            ("id-post", True, "id.8westit.com", "POST", invite, 200),
            ("id-encoded", True, "id.8westit.com", "GET", "/%61ccept-employee-invite.php", 200),
            ("id-reset", True, "id.8westit.com", "GET", "/reset.php", 200),
            ("id-denied", True, "id.8westit.com", "GET", "/fixture-denied/id", 403),
        ])
    for agent, tls, host, method, path, expected in cases:
        status = request(tls_port if tls else http_port, tls, host, method, path, agent)
        assert status == expected, f"{agent} returned {status}, expected {expected}"

    sh_access = (log_dir / "safeharbor-8westit-access.log").read_text().splitlines()
    shared_access = (log_dir / "other_vhosts_access.log").read_text().splitlines()
    assert len(sh_access) + len(shared_access) == len(cases), "access log total differs from request count"
    for agent, tls, host, method, _, status in cases:
        if agent.startswith("sh-"):
            verify_access(sh_access, agent, method, status)
        else:
            canonical_port = 443 if tls else 80
            verify_access(shared_access, agent, method, status, f"{host}:{canonical_port}", agent != "control")

    for filename, site in [("safeharbor-8westit-error.log", "sh")] + ([("error.log", "id")] if include_id else []):
        errors = (log_dir / filename).read_text()
        assert re.search(rf"AH01630: [^\n]+/fixture-denied/{site}(?:\n|$)", errors), errors
        assert QUERY not in errors and REFERER not in errors, site
    return len(cases)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--id-logging", type=Path, help="reviewed ID logging directives for both synthetic vhosts")
    options = parser.parse_args()
    http_port, tls_port = free_port(), free_port()
    assert http_port != tls_port, "fixture ports collided"
    with tempfile.TemporaryDirectory(prefix="safeharbor-apache-") as temporary:
        root = Path(temporary)
        root.chmod(0o755)
        (root / "logs").mkdir()
        (root / "www/portal").mkdir(parents=True)
        (root / "www/fixture-denied").mkdir()
        for path in (
            "ordinary.txt", "portal/computer-access.php", "accept-employee-invite.php",
            "reset.php", "fixture-denied/sh", "fixture-denied/id",
        ):
            (root / "www" / path).write_text("synthetic fixture\n")
        subprocess.run([
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=localhost", "-keyout", root / "key.pem", "-out", root / "cert.pem",
        ], check=True, capture_output=True)
        modules = "\n".join(f"LoadModule {name}_module /usr/lib/apache2/modules/mod_{name}.so" for name in MODULES)
        os_config = root / "fixture.conf"
        config = rf"""ServerRoot "{root}"
DefaultRuntimeDir "{root}"
PidFile "{root}/apache.pid"
Listen 127.0.0.1:{http_port}
Listen 127.0.0.1:{tls_port}
ServerName control.invalid
{modules}
User www-data
Group www-data
Define APACHE_LOG_DIR "{root}/logs"
ErrorLog "{root}/logs/error.log"
LogLevel warn
LogFormat "%v:%p %h %l %u %t \"%r\" %>s %O \"%{{Referer}}i\" \"%{{User-Agent}}i\"" vhost_combined
CustomLog "{root}/logs/other_vhosts_access.log" vhost_combined
<Directory "{root}/www">
    Require all granted
    AcceptPathInfo On
</Directory>
<Location "/fixture-denied">
    Require all denied
</Location>
<VirtualHost 127.0.0.1:{http_port}>
    ServerName control.invalid
    DocumentRoot "{root}/www"
</VirtualHost>
"""
        config += safeharbor_vhost(root, http_port, False) + safeharbor_vhost(root, tls_port, True)
        if options.id_logging:
            directives = options.id_logging.read_text(encoding="utf-8")
            config += id_vhost(root, http_port, False, directives) + id_vhost(root, tls_port, True, directives)
        os_config.write_text(config, encoding="utf-8")
        command = [APACHE, "-f", os_config]
        subprocess.run(command + ["-t"], check=True)
        subprocess.run(command + ["-S"], check=True, capture_output=True)
        with subprocess.Popen(command + ["-DFOREGROUND"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) as server:
            try:
                for _ in range(50):
                    try:
                        with socket.create_connection(("127.0.0.1", http_port), timeout=0.2):
                            break
                    except OSError:
                        if server.poll() is not None:
                            raise RuntimeError("disposable Apache exited at startup")
                        time.sleep(0.1)
                else:
                    raise RuntimeError("disposable Apache did not open its listener")
                count = exercise(root, http_port, tls_port, options.id_logging is not None)
                print(f"PASS {count} synthetic Apache requests with access and error log checks")
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == "__main__":
    main()
