#!/usr/bin/env python3
"""پیش‌نمایش محلی صفحهٔ «پیگیری سفارش» (فقط توسعه).

ریشهٔ سرو = ریشهٔ مخزن تا CSS/JS افزونه با مسیرهای نسبی لود شوند،
ولی «/» مستقیم به صفحهٔ پیش‌نمایش می‌رود (بدون فهرست پوشه).

    python3 tools/preview-tracking.py [port]        # پیش‌فرض 8000
"""

import os
import sys
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANDING = "/design/preview/tracking-form/index.html"


class PreviewHandler(SimpleHTTPRequestHandler):
    # HTTP/1.1 + Content-Length صریح: پروکسی پیش‌نمایش (e2b) پاسخ‌های
    # keep-alive را بهتر عبور می‌دهد و ۳۰۲ بدون بدنه معلق نمی‌ماند.
    protocol_version = "HTTP/1.1"

    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=ROOT, **kwargs)

    def do_GET(self):
        if self.path in ("/", "/index.html"):
            self.send_response(302)
            self.send_header("Location", LANDING)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        super().do_GET()

    def do_HEAD(self):
        if self.path in ("/", "/index.html"):
            self.send_response(302)
            self.send_header("Location", LANDING)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        super().do_HEAD()

    def end_headers(self):
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def log_message(self, fmt, *args):
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))


def main():
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
    server = ThreadingHTTPServer(("0.0.0.0", port), PreviewHandler)
    print("پیش‌نمایش: http://localhost:%d%s" % (port, LANDING), flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
