#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
WebWatch tikrinimo taškas (agentas).

Ši programa veikia jūsų kompiuteryje ir parsiunčia puslapius per šio kompiuterio
interneto ryšį, kai WebWatch to paprašo. Naudinga stebint savo svetaines iš kelių
vietų arba kai hostingo serveris negali pasiekti puslapio.

Paleidimas rankiniu būdu:   python3 ww-agent.py
Įdiegti (paleisti automatiškai):   python3 ww-agent.py --install
Pašalinti:                  python3 ww-agent.py --uninstall

Reikia tik Python 3 (jokių papildomų bibliotekų).
"""

import gzip
import json
import os
import ssl
import sys
import time
import zlib
import socket
import platform
import urllib.request
import urllib.error

SERVER = "__WW_SERVER__"          # pvz. https://watch.jusu-domenas.lt/
TOKEN = "__WW_TOKEN__"
NAME = "__WW_NAME__"
VERSION = "1"

POLL_WAIT = 25                    # kiek s serveris laiko atvirą „poll“
FETCH_TIMEOUT = 45
BROWSER_HEADERS = {
    "mobile": "Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 "
              "(KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1",
    "desktop": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
               "(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36",
}


def api(action, params="", data=None, headers=None, timeout=POLL_WAIT + 15):
    url = "%sagent.php?action=%s%s" % (SERVER, action, params)
    req = urllib.request.Request(url, data=data, method="POST" if data is not None else "GET")
    req.add_header("X-Agent-Token", TOKEN)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    ctx = ssl.create_default_context()
    return urllib.request.urlopen(req, timeout=timeout, context=ctx)


def fetch(job):
    """Parsiunčia puslapį; grąžina (status, body_bytes, final_url, content_type, error)."""
    ua = job.get("ua") or BROWSER_HEADERS["desktop"]
    headers = {
        "User-Agent": ua,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "lt-LT,lt;q=0.9,en;q=0.8",
        "Accept-Encoding": "gzip, deflate",
        "Connection": "close",
    }
    for k, v in (job.get("headers") or {}).items():
        if k:
            headers[k] = v
    try:
        req = urllib.request.Request(job["url"], headers=headers)
        ctx = ssl.create_default_context()
        with urllib.request.urlopen(req, timeout=FETCH_TIMEOUT, context=ctx) as resp:
            raw = resp.read(8 * 1024 * 1024)
            enc = (resp.headers.get("Content-Encoding") or "").lower()
            if "gzip" in enc:
                raw = gzip.decompress(raw)
            elif "deflate" in enc:
                raw = zlib.decompress(raw, -zlib.MAX_WBITS)
            return resp.status, raw, resp.geturl(), resp.headers.get("Content-Type", ""), ""
    except urllib.error.HTTPError as e:
        body = b""
        try:
            body = e.read(8 * 1024 * 1024)
            if (e.headers.get("Content-Encoding") or "").lower().startswith("gzip"):
                body = gzip.decompress(body)
        except Exception:
            pass
        return e.code, body, job["url"], e.headers.get("Content-Type", "") if e.headers else "", ""
    except (urllib.error.URLError, socket.timeout, ssl.SSLError, ConnectionError) as e:
        return 0, b"", job["url"], "", str(getattr(e, "reason", e))
    except Exception as e:  # noqa
        return 0, b"", job["url"], "", str(e)


def send_result(job_id, status, body, final_url, ctype, error):
    from urllib.parse import quote
    headers = {
        "X-Status": str(status),
        "X-Final-Url": quote(final_url or "", safe=""),
        "X-Content-Type": (ctype or "")[:200],
        "X-Via": NAME[:20],
        "X-Error": quote((error or "")[:500], safe=""),
        "Content-Type": "application/octet-stream",
    }
    api("result", "&id=%d" % job_id, data=body or b"", headers=headers, timeout=60).read()


def run():
    browser = platform.system() + " " + platform.release()
    print("WebWatch tikrinimo taškas „%s“ paleistas. Serveris: %s" % (NAME, SERVER))
    print("Palikite šį langą atidarytą (arba naudokite --install automatiniam paleidimui).")
    idle_backoff = 2
    while True:
        try:
            params = "&wait=%d&os=%s&v=%s&browser=%s" % (
                POLL_WAIT, platform.system().lower(), VERSION,
                urllib.parse.quote(browser[:40]))
            resp = api("poll", params)
            data = json.loads(resp.read().decode("utf-8", "replace"))
            job = data.get("job")
            if not job:
                idle_backoff = 2
                continue
            print("→ Tikrinu: %s" % job["url"])
            status, body, final_url, ctype, error = fetch(job)
            send_result(job["id"], status, body, final_url, ctype, error)
            print("  atsakyta (HTTP %s%s)" % (status, ", klaida: " + error if error else ""))
            idle_backoff = 2
        except urllib.error.HTTPError as e:
            if e.code == 403:
                print("KLAIDA: serveris atmetė raktą (403). Sugeneruokite naują raktą WebWatch nustatymuose.")
                time.sleep(30)
            else:
                print("Serverio klaida HTTP %s – bandau vėl po %ss" % (e.code, idle_backoff))
                time.sleep(idle_backoff)
                idle_backoff = min(idle_backoff * 2, 60)
        except KeyboardInterrupt:
            print("\nSustabdyta.")
            return
        except Exception as e:  # noqa
            print("Nėra ryšio su serveriu (%s) – bandau vėl po %ss" % (e, idle_backoff))
            time.sleep(idle_backoff)
            idle_backoff = min(idle_backoff * 2, 60)


# ------------------------------------------------------------------ #
# Automatinis paleidimas (autostart)                                  #
# ------------------------------------------------------------------ #

def self_path():
    dst = os.path.join(os.path.expanduser("~"), ".wwagent", "ww-agent.py")
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    if os.path.abspath(sys.argv[0]) != dst:
        with open(dst, "w", encoding="utf-8") as f:
            f.write(open(os.path.abspath(sys.argv[0]), encoding="utf-8").read())
    return dst


def install():
    dst = self_path()
    system = platform.system()
    py = sys.executable or "python3"
    if system == "Darwin":
        label = "com.webwatch.agent"
        plist = os.path.expanduser("~/Library/LaunchAgents/%s.plist" % label)
        os.makedirs(os.path.dirname(plist), exist_ok=True)
        with open(plist, "w") as f:
            f.write("""<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>%s</string>
  <key>ProgramArguments</key><array><string>%s</string><string>%s</string></array>
  <key>RunAtLoad</key><true/><key>KeepAlive</key><true/>
  <key>StandardErrorPath</key><string>%s/.wwagent/agent.log</string>
  <key>StandardOutPath</key><string>%s/.wwagent/agent.log</string>
</dict></plist>""" % (label, py, dst, os.path.expanduser("~"), os.path.expanduser("~")))
        os.system("launchctl unload '%s' 2>/dev/null; launchctl load '%s'" % (plist, plist))
        print("Įdiegta. Tikrinimo taškas veiks ir po perkrovimo (launchd).")
    elif system == "Linux":
        unit_dir = os.path.expanduser("~/.config/systemd/user")
        os.makedirs(unit_dir, exist_ok=True)
        unit = os.path.join(unit_dir, "wwagent.service")
        with open(unit, "w") as f:
            f.write("""[Unit]
Description=WebWatch tikrinimo taskas
After=network-online.target
[Service]
ExecStart=%s %s
Restart=always
RestartSec=10
[Install]
WantedBy=default.target
""" % (py, dst))
        if os.system("systemctl --user daemon-reload && systemctl --user enable --now wwagent.service") == 0:
            os.system("loginctl enable-linger $USER 2>/dev/null")
            print("Įdiegta (systemd --user). Tikrinimo taškas veiks ir po perkrovimo.")
        else:
            print("systemd nerastas. Pridėkite į autostart rankiniu būdu: %s %s" % (py, dst))
    else:
        # Windows (jei paleista per Python): registro Run raktas
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                                 r"Software\Microsoft\Windows\CurrentVersion\Run", 0, winreg.KEY_SET_VALUE)
            winreg.SetValueEx(key, "WebWatchAgent", 0, winreg.REG_SZ,
                              '"%s" "%s"' % (py, dst))
            winreg.CloseKey(key)
            print("Įdiegta. Veiks po prisijungimo prie Windows. Paleidžiu dabar…")
        except Exception as e:  # noqa
            print("Nepavyko įdiegti automatiškai (%s). Paleiskite rankiniu būdu: %s %s" % (e, py, dst))
    print("Paleidžiu tikrinimo tašką…")
    run()


def uninstall():
    system = platform.system()
    if system == "Darwin":
        plist = os.path.expanduser("~/Library/LaunchAgents/com.webwatch.agent.plist")
        os.system("launchctl unload '%s' 2>/dev/null" % plist)
        if os.path.exists(plist):
            os.remove(plist)
    elif system == "Linux":
        os.system("systemctl --user disable --now wwagent.service 2>/dev/null")
    else:
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                                 r"Software\Microsoft\Windows\CurrentVersion\Run", 0, winreg.KEY_SET_VALUE)
            winreg.DeleteValue(key, "WebWatchAgent")
            winreg.CloseKey(key)
        except Exception:
            pass
    print("Pašalinta.")


if __name__ == "__main__":
    import urllib.parse
    if "--install" in sys.argv:
        install()
    elif "--uninstall" in sys.argv:
        uninstall()
    else:
        run()
