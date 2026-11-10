"""Serve only loopback TLS fixtures while running the PHP capability probe."""
import http.server
import pathlib
import ssl
import subprocess
import sys
import threading
import time


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self):
        if self.path == "/slow":
            time.sleep(1)
            return
        if self.path == "/redirect":
            self.send_response(302)
            self.send_header("Location", "/must-not-follow")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        body = b"x" * 2048 if self.path == "/large" else b"ok"
        self.send_response(200)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


certificate, key = sys.argv[1:3]
php_binary = sys.argv[3] if len(sys.argv) > 3 else "php"
server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(certificate, key)
server.socket = context.wrap_socket(server.socket, server_side=True)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    result = subprocess.run(
        [php_binary, str(pathlib.Path(__file__).with_name("transport.php")),
         str(server.server_address[1]), certificate], check=False
    )
finally:
    server.shutdown()
    server.server_close()
sys.exit(result.returncode)
